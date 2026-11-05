<?php

namespace App\Http\Controllers;

use App\Domain\Services\RentPaymentService;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\RentPayment;
use App\Models\RentSchedule;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class RentPaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasPermission('rent_payments.view'), 403);
        $organizationIds = Organization::accessibleIdsFor($user, 'rent_payments.view');
        $submittableOrganizationIds = Organization::accessibleIdsFor($user, 'rent_payments.submit');
        $reviewableOrganizationIds = Organization::accessibleIdsFor($user, 'rent_payments.review');

        $schedules = RentSchedule::query()
            ->with(['tenancy.organization:id,currency', 'tenancy.tenant:id,full_name', 'tenancy.propertyUnit:id,project_id,phase,block,code', 'tenancy.propertyUnit.project:id,name', 'payments'])
            ->whereHas('tenancy', fn ($query) => $query->whereIn('organization_id', $organizationIds)->whereIn('status', ['active', 'completed']))
            ->where('status', '!=', 'paid')
            ->orderBy('due_on')
            ->limit(500)
            ->get()
            ->map(function (RentSchedule $schedule) use ($submittableOrganizationIds): array {
                $paidCents = $schedule->payments
                    ->where('status', 'approved')
                    ->sum(fn (RentPayment $payment): int => Money::toMinorUnits($payment->pivot->amount));
                $pendingCents = $schedule->payments
                    ->where('status', 'pending')
                    ->sum(fn (RentPayment $payment): int => Money::toMinorUnits($payment->pivot->amount));
                $amountDueCents = Money::toMinorUnits($schedule->amount_due);
                $arrearsCents = $schedule->due_on->lt(today())
                    ? max(0, $amountDueCents - $paidCents)
                    : 0;

                return [
                    'id' => $schedule->id,
                    'tenancyNumber' => $schedule->tenancy->tenancy_number,
                    'tenantName' => $schedule->tenancy->tenant->full_name,
                    'property' => $schedule->tenancy->propertyUnit->project->name.' · '.trim($schedule->tenancy->propertyUnit->phase.' '.$schedule->tenancy->propertyUnit->block.' '.$schedule->tenancy->propertyUnit->code),
                    'category' => $schedule->category,
                    'chargeName' => $schedule->charge_name_snapshot,
                    'dueOn' => $schedule->due_on->toDateString(),
                    'amountDue' => $schedule->amount_due,
                    'amountPaid' => Money::fromMinorUnits($paidCents),
                    'pendingAmount' => Money::fromMinorUnits($pendingCents),
                    'arrearsAmount' => Money::fromMinorUnits($arrearsCents),
                    'availableAmount' => Money::fromMinorUnits(max(0, $amountDueCents - $paidCents - $pendingCents)),
                    'currency' => $schedule->tenancy->organization->currency,
                    'canSubmit' => in_array($schedule->tenancy->organization_id, $submittableOrganizationIds, true),
                ];
            })
            ->filter(fn (array $schedule): bool => Money::toMinorUnits($schedule['availableAmount']) > 0
                || Money::toMinorUnits($schedule['arrearsAmount']) > 0)
            ->values();

        return Inertia::render('RentPayments/Index', [
            'schedules' => $schedules,
            'payments' => RentPayment::query()
                ->with(['tenancy.organization:id,currency', 'tenancy.tenant:id,full_name', 'tenancy.propertyUnit:id,project_id,phase,block,code', 'tenancy.propertyUnit.project:id,name', 'submittedBy:id,name', 'reviewedBy:id,name'])
                ->whereIn('organization_id', $organizationIds)
                ->latest()
                ->limit(200)
                ->get()
                ->map(fn (RentPayment $payment): array => [
                    'id' => $payment->id,
                    'tenancyNumber' => $payment->tenancy->tenancy_number,
                    'tenantName' => $payment->tenancy->tenant->full_name,
                    'property' => $payment->tenancy->propertyUnit->project->name.' · '.trim($payment->tenancy->propertyUnit->phase.' '.$payment->tenancy->propertyUnit->block.' '.$payment->tenancy->propertyUnit->code),
                    'amount' => $payment->amount,
                    'currency' => $payment->tenancy->organization->currency,
                    'channel' => $payment->payment_channel,
                    'reference' => $payment->transaction_reference,
                    'status' => $payment->status,
                    'rejectionReason' => $payment->rejection_reason,
                    'submittedBy' => $payment->submittedBy?->name,
                    'reviewedBy' => $payment->reviewedBy?->name,
                    'proofUrl' => $payment->proof_document_path && (
                        in_array($payment->organization_id, $reviewableOrganizationIds, true)
                        || ($payment->submitted_by_user_id === $user->id && in_array($payment->organization_id, $submittableOrganizationIds, true))
                    ) ? route('rent-payments.proof', $payment) : null,
                    'canReview' => in_array($payment->organization_id, $reviewableOrganizationIds, true),
                    'createdAt' => $payment->created_at?->toIso8601String(),
                ])
                ->values(),
            'canSubmit' => $user->hasPermission('rent_payments.submit'),
            'canReview' => $user->hasPermission('rent_payments.review'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('rent_payments.submit'), 403);

        $validated = $request->validate([
            'rent_schedule_id' => ['required', 'uuid', 'exists:rent_schedules,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'payment_channel' => ['required', Rule::in(['bank_transfer', 'cash_deposit', 'cheque', 'other'])],
            'transaction_reference' => ['nullable', 'string', 'max:120'],
            'proof_document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $schedule = RentSchedule::query()->with('tenancy')->findOrFail($validated['rent_schedule_id']);
        $organizationIds = Organization::accessibleIdsFor($user, 'rent_payments.submit');
        abort_unless(in_array($schedule->tenancy->organization_id, $organizationIds, true), 404);
        $validated = $request->validate([
            'transaction_reference' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('rent_payments', 'transaction_reference')
                    ->where('organization_id', $schedule->tenancy->organization_id),
            ],
        ]) + $validated;

        $proofPath = $validated['proof_document']->store("rent-payments/{$schedule->tenancy_id}", 'local');
        abort_unless($proofPath !== false, 500);

        try {
            DB::transaction(function () use ($request, $user, $validated, $schedule, $proofPath): void {
                $lockedSchedule = RentSchedule::query()->lockForUpdate()->findOrFail($schedule->id);
                $tenancy = $lockedSchedule->tenancy()->lockForUpdate()->firstOrFail();
                abort_unless(in_array($tenancy->status, ['active', 'completed'], true), 409, 'Rent can only be collected for active or completed tenancy charges.');

                $allocatedAmounts = DB::table('rent_payment_allocations')
                    ->join('rent_payments', 'rent_payments.id', '=', 'rent_payment_allocations.rent_payment_id')
                    ->where('rent_payment_allocations.rent_schedule_id', $lockedSchedule->id)
                    ->whereIn('rent_payments.status', ['pending', 'approved'])
                    ->pluck('rent_payment_allocations.amount');
                $reservedCents = $allocatedAmounts->sum(fn (string $amount): int => Money::toMinorUnits($amount));
                $outstandingCents = Money::toMinorUnits($lockedSchedule->amount_due) - $reservedCents;
                $paymentCents = Money::toMinorUnits($validated['amount']);
                abort_unless($paymentCents <= $outstandingCents, 409, 'Submitted payments exceed the unallocated rent charge balance.');

                $payment = RentPayment::create([
                    'organization_id' => $tenancy->organization_id,
                    'tenancy_id' => $tenancy->id,
                    'submitted_by_user_id' => $user->id,
                    'amount' => Money::fromMinorUnits($paymentCents),
                    'payment_channel' => $validated['payment_channel'],
                    'transaction_reference' => $validated['transaction_reference'] ?? null,
                    'proof_document_path' => $proofPath,
                    'status' => 'pending',
                ]);

                DB::table('rent_payment_allocations')->insert([
                    'rent_payment_id' => $payment->id,
                    'rent_schedule_id' => $lockedSchedule->id,
                    'amount' => Money::fromMinorUnits($paymentCents),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->audit($request, $user, $payment, 'rent_payment.submitted', ['amount' => $payment->amount]);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($proofPath);
            throw $exception;
        }

        return redirect()->route('rent-payments.index')->with('success', 'Rent payment submitted for review.');
    }

    public function review(Request $request, RentPayment $rentPayment, RentPaymentService $service): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('rent_payments.review'), 403);
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'rejection_reason' => ['nullable', 'required_if:decision,rejected', 'string', 'max:2000'],
        ]);
        abort_unless(in_array($rentPayment->organization_id, Organization::accessibleIdsFor($user, 'rent_payments.review'), true), 404);

        $allocations = DB::table('rent_payment_allocations')
            ->where('rent_payment_id', $rentPayment->id)
            ->pluck('amount', 'rent_schedule_id')
            ->all();

        $service->review(
            $rentPayment,
            $user,
            $validated['decision'],
            $validated['rejection_reason'] ?? null,
            $allocations,
        );

        return redirect()->route('rent-payments.index')->with('success', "Rent payment {$validated['decision']}.");
    }

    public function downloadProof(Request $request, RentPayment $rentPayment): StreamedResponse
    {
        $user = $request->user();
        $canReview = $user->hasPermission('rent_payments.review')
            && in_array($rentPayment->organization_id, Organization::accessibleIdsFor($user, 'rent_payments.review'), true);
        $isSubmitter = $rentPayment->submitted_by_user_id === $user->id
            && in_array($rentPayment->organization_id, Organization::accessibleIdsFor($user, 'rent_payments.submit'), true);
        abort_unless($canReview || $isSubmitter, 404);
        abort_unless($rentPayment->proof_document_path !== null, 404);

        return Storage::disk('local')->download(
            $rentPayment->proof_document_path,
            'rent-payment-proof.'.pathinfo($rentPayment->proof_document_path, PATHINFO_EXTENSION),
        );
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Request $request, User $user, RentPayment $payment, string $action, array $metadata): void
    {
        AuditLog::create([
            'organization_id' => $payment->organization_id,
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => RentPayment::class,
            'subject_id' => $payment->id,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
