<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\InstallmentSchedule;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasPermission('payments.view'), 403);
        $organizationIds = Organization::accessibleIdsFor($user, 'payments.view');

        return Inertia::render('Payments/Index', [
            'payments' => Payment::query()
                ->with([
                    'organization:id,currency',
                    'subscription:id,subscription_number,client_id',
                    'subscription.client:id,full_name',
                    'installmentSchedule:id,category,installment_number,amount,amount_paid',
                    'submittedBy:id,name',
                    'reviewedBy:id,name',
                ])
                ->whereIn('organization_id', $organizationIds)
                ->latest()
                ->limit(200)
                ->get()
                ->map(fn (Payment $payment): array => [
                    'id' => $payment->id,
                    'currency' => $payment->organization->currency,
                    'subscriptionNumber' => $payment->subscription->subscription_number,
                    'clientName' => $payment->subscription->client->full_name,
                    'category' => $payment->payment_category,
                    'installmentNumber' => $payment->installmentSchedule->installment_number,
                    'amount' => $payment->amount,
                    'paymentMethod' => $payment->payment_method,
                    'transactionReference' => $payment->transaction_reference,
                    'status' => $payment->status,
                    'rejectionReason' => $payment->rejection_reason,
                    'submittedBy' => $payment->submittedBy?->name,
                    'reviewedBy' => $payment->reviewedBy?->name,
                    'createdAt' => $payment->created_at?->toIso8601String(),
                    'reviewedAt' => $payment->reviewed_at?->toIso8601String(),
                ])
                ->values(),
            'schedules' => InstallmentSchedule::query()
                ->with(['subscription:id,organization_id,subscription_number,client_id', 'subscription.client:id,full_name', 'subscription.organization:id,currency'])
                ->whereHas('subscription', fn ($query) => $query->whereIn('organization_id', Organization::accessibleIdsFor($user, 'payments.submit')))
                ->whereColumn('amount_paid', '<', 'amount')
                ->orderBy('due_date')
                ->limit(500)
                ->get()
                ->map(fn (InstallmentSchedule $schedule): array => [
                    'id' => $schedule->id,
                    'currency' => $schedule->subscription->organization->currency,
                    'subscriptionNumber' => $schedule->subscription->subscription_number,
                    'clientName' => $schedule->subscription->client->full_name,
                    'category' => $schedule->category,
                    'installmentNumber' => $schedule->installment_number,
                    'dueDate' => $schedule->due_date->toDateString(),
                    'amount' => $schedule->amount,
                    'amountPaid' => $schedule->amount_paid,
                    'outstanding' => $this->formatCents($this->moneyToCents($schedule->amount) - $this->moneyToCents($schedule->amount_paid)),
                ])
                ->values(),
            'canSubmit' => $user->hasPermission('payments.submit'),
            'canReview' => $user->hasPermission('payments.review'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('payments.submit'), 403);

        $validated = $request->validate([
            'installment_schedule_id' => ['required', 'uuid', 'exists:installment_schedules,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'payment_method' => ['required', Rule::in(['bank_transfer', 'cash_deposit', 'cheque', 'other'])],
            'transaction_reference' => ['nullable', 'string', 'max:100'],
            'proof_document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $schedule = InstallmentSchedule::query()->with('subscription')->findOrFail($validated['installment_schedule_id']);
        $organizationIds = Organization::accessibleIdsFor($user, 'payments.submit');
        abort_unless(in_array($schedule->subscription->organization_id, $organizationIds, true), 404);

        $proofPath = $validated['proof_document']->store("payments/{$schedule->subscription_id}", 'local');
        abort_unless($proofPath !== false, 500);

        try {
            DB::transaction(function () use ($request, $user, $validated, $schedule, $proofPath): void {
                $lockedSchedule = InstallmentSchedule::query()->lockForUpdate()->findOrFail($schedule->id);
                $subscription = Subscription::query()->findOrFail($lockedSchedule->subscription_id);
                $outstandingCents = $this->moneyToCents($lockedSchedule->amount) - $this->moneyToCents($lockedSchedule->amount_paid);
                $pendingCents = Payment::query()
                    ->where('installment_schedule_id', $lockedSchedule->id)
                    ->where('status', 'pending')
                    ->get(['amount'])
                    ->sum(fn (Payment $payment): int => $this->moneyToCents($payment->amount));
                $paymentCents = $this->moneyToCents($validated['amount']);
                abort_unless($paymentCents + $pendingCents <= $outstandingCents, 409, 'Submitted payments exceed the outstanding installment balance.');

                $payment = Payment::create([
                    'organization_id' => $subscription->organization_id,
                    'subscription_id' => $subscription->id,
                    'installment_schedule_id' => $lockedSchedule->id,
                    'submitted_by_user_id' => $user->id,
                    'payment_category' => $lockedSchedule->category,
                    'amount' => $this->formatCents($paymentCents),
                    'payment_method' => $validated['payment_method'],
                    'transaction_reference' => $validated['transaction_reference'] ?? null,
                    'proof_document_path' => $proofPath,
                    'status' => 'pending',
                ]);

                $this->audit($request, $user, $payment, 'payment.submitted', ['amount' => $payment->amount]);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($proofPath);
            throw $exception;
        }

        return redirect()->route('payments.index');
    }

    public function review(Request $request, Payment $payment): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('payments.review'), 403);
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'rejection_reason' => ['nullable', 'required_if:decision,rejected', 'string', 'max:2000'],
        ]);
        $organizationIds = Organization::accessibleIdsFor($user, 'payments.review');
        abort_unless(in_array($payment->organization_id, $organizationIds, true), 404);

        DB::transaction(function () use ($request, $user, $payment, $validated): void {
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            abort_unless($lockedPayment->status === 'pending', 409, 'This payment has already been reviewed.');
            $schedule = InstallmentSchedule::query()->lockForUpdate()->findOrFail($lockedPayment->installment_schedule_id);

            if ($validated['decision'] === 'approved') {
                $paidCents = $this->moneyToCents($schedule->amount_paid) + $this->moneyToCents($lockedPayment->amount);
                abort_unless($paidCents <= $this->moneyToCents($schedule->amount), 409, 'Approval would exceed the installment balance.');
                $schedule->update([
                    'amount_paid' => $this->formatCents($paidCents),
                    'status' => $paidCents === $this->moneyToCents($schedule->amount) ? 'paid' : 'partially_paid',
                ]);
            }

            $lockedPayment->update([
                'status' => $validated['decision'],
                'rejection_reason' => $validated['decision'] === 'rejected' ? $validated['rejection_reason'] : null,
                'reviewed_by_user_id' => $user->id,
                'reviewed_at' => now(),
            ]);
            $this->audit($request, $user, $lockedPayment, "payment.{$validated['decision']}", [
                'amount' => $lockedPayment->amount,
                'reason' => $validated['rejection_reason'] ?? null,
            ]);
        });

        return redirect()->route('payments.index');
    }

    public function downloadProof(Request $request, Payment $payment): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('payments.review'), 403);
        abort_unless(in_array($payment->organization_id, Organization::accessibleIdsFor($user, 'payments.review'), true), 404);
        $extension = pathinfo($payment->proof_document_path, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($payment->proof_document_path, "payment-proof.{$extension}");
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Request $request, User $user, Payment $payment, string $action, array $metadata): void
    {
        AuditLog::create([
            'organization_id' => $payment->organization_id,
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => Payment::class,
            'subject_id' => $payment->id,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    private function moneyToCents(float|int|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function formatCents(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }
}
