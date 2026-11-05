<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PropertyUnit;
use App\Models\RentSchedule;
use App\Models\Tenancy;
use App\Models\TenancyDepositDeduction;
use App\Models\TenancyDepositReconciliation;
use App\Models\TenancyNotice;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TenancyLifecycleController extends Controller
{
    private const DEDUCTION_CATEGORIES = [
        'unpaid_rent',
        'damage',
        'utilities',
        'other',
    ];

    public function storeNotice(Request $request, Tenancy $tenancy): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeTenancy($user, $tenancy);

        $validated = $request->validate([
            'notice_type' => ['required', Rule::in(['tenant', 'landlord', 'mutual_agreement'])],
            'notice_on' => ['required', 'date', 'before_or_equal:today'],
            'proposed_vacate_on' => ['required', 'date', 'after_or_equal:notice_on'],
            'reason' => ['required', 'string', 'max:5000'],
        ]);

        $cancelledRenewals = DB::transaction(function () use ($request, $user, $tenancy, $validated): int {
            $lockedTenancy = Tenancy::query()->lockForUpdate()->findOrFail($tenancy->id);
            abort_unless($lockedTenancy->status === 'active', 409, 'A notice can only be recorded for an active tenancy.');
            abort_unless(
                Carbon::parse($validated['notice_on'])->gte($lockedTenancy->starts_on),
                422,
                'The notice date cannot be before the tenancy began.',
            );
            abort_unless(
                ! $lockedTenancy->notices()->where('status', 'served')->exists(),
                409,
                'Withdraw or complete the existing notice before recording another.',
            );

            $notice = $lockedTenancy->notices()->create([
                ...$validated,
                'recorded_by_user_id' => $user->id,
                'status' => 'served',
            ]);

            $draftRenewals = $lockedTenancy->renewals()->where('status', 'draft')->lockForUpdate()->get();
            foreach ($draftRenewals as $renewal) {
                $renewal->update(['status' => 'cancelled']);
                $this->audit($request, $user, $renewal, 'tenancy.renewal_cancelled_by_notice', [
                    'notice_id' => $notice->id,
                ]);
            }

            $this->audit($request, $user, $lockedTenancy, 'tenancy.notice_served', [
                'notice_id' => $notice->id,
                'notice_type' => $notice->notice_type,
                'notice_on' => $notice->notice_on->toDateString(),
                'proposed_vacate_on' => $notice->proposed_vacate_on->toDateString(),
            ]);

            return $draftRenewals->count();
        });

        $message = $cancelledRenewals > 0
            ? 'Tenancy notice recorded and the pending renewal draft was canceled.'
            : 'Tenancy notice recorded.';

        return redirect()->route('tenancies.index')->with('success', $message);
    }

    public function withdrawNotice(Request $request, TenancyNotice $tenancyNotice): RedirectResponse
    {
        $user = $request->user();
        $tenancy = $tenancyNotice->tenancy;
        $this->authorizeTenancy($user, $tenancy);

        DB::transaction(function () use ($request, $user, $tenancyNotice): void {
            $lockedTenancy = Tenancy::query()->lockForUpdate()->findOrFail($tenancyNotice->tenancy_id);
            $notice = TenancyNotice::query()->lockForUpdate()->findOrFail($tenancyNotice->id);
            abort_unless($notice->status === 'served' && $lockedTenancy->status === 'active', 409, 'Only an open notice for an active tenancy can be withdrawn.');

            $notice->update(['status' => 'withdrawn']);
            $this->audit($request, $user, $lockedTenancy, 'tenancy.notice_withdrawn', [
                'notice_id' => $notice->id,
            ]);
        });

        return redirect()->route('tenancies.index')->with('success', 'Tenancy notice withdrawn.');
    }

    public function completeHandover(Request $request, TenancyNotice $tenancyNotice): RedirectResponse
    {
        $user = $request->user();
        $tenancy = $tenancyNotice->tenancy;
        $this->authorizeTenancy($user, $tenancy);

        $validated = $request->validate([
            'handed_over_on' => ['required', 'date', 'before_or_equal:today'],
            'handover_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($request, $user, $tenancyNotice, $validated): void {
            $lockedTenancy = Tenancy::query()->lockForUpdate()->findOrFail($tenancyNotice->tenancy_id);
            $notice = TenancyNotice::query()->lockForUpdate()->findOrFail($tenancyNotice->id);
            abort_unless($notice->status === 'served' && $lockedTenancy->status === 'active', 409, 'Only an open notice for an active tenancy can be handed over.');

            $handoverDate = Carbon::parse($validated['handed_over_on']);
            abort_unless($handoverDate->gte($lockedTenancy->starts_on), 422, 'The handover date cannot be before the tenancy began.');
            if ($notice->notice_type !== 'mutual_agreement') {
                abort_unless(
                    $handoverDate->gte($notice->proposed_vacate_on),
                    422,
                    'An early handover requires recording a mutual-agreement notice.',
                );
            }

            $otherActiveTenancyExists = Tenancy::query()
                ->where('property_unit_id', $lockedTenancy->property_unit_id)
                ->where('status', 'active')
                ->where('id', '!=', $lockedTenancy->id)
                ->exists();
            abort_unless(! $otherActiveTenancyExists, 409, 'Another active tenancy is recorded for this property.');

            $property = PropertyUnit::query()->lockForUpdate()->findOrFail($lockedTenancy->property_unit_id);
            $notice->update([
                'status' => 'completed',
                'handed_over_on' => $handoverDate->toDateString(),
                'handover_notes' => $validated['handover_notes'] ?? null,
                'completed_by_user_id' => $user->id,
            ]);
            $lockedTenancy->update(['status' => 'completed']);
            $property->update(['rental_status' => 'available']);

            $this->audit($request, $user, $lockedTenancy, 'tenancy.handover_completed', [
                'notice_id' => $notice->id,
                'handed_over_on' => $handoverDate->toDateString(),
                'property_unit_id' => $property->id,
            ]);
        });

        return redirect()->route('tenancies.index')->with('success', 'Handover recorded and property marked available for rent.');
    }

    public function storeDepositReconciliation(Request $request, Tenancy $tenancy): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeTenancy($user, $tenancy);
        $validated = $this->validateDeductions($request);

        $this->saveDepositDraft($request, $user, $tenancy, $validated['deductions']);

        return redirect()->route('tenancies.index')->with('success', 'Deposit reconciliation draft saved.');
    }

    public function updateDepositReconciliation(Request $request, TenancyDepositReconciliation $reconciliation): RedirectResponse
    {
        $user = $request->user();
        $tenancy = $reconciliation->tenancy;
        $this->authorizeTenancy($user, $tenancy);
        $validated = $this->validateDeductions($request);

        $this->saveDepositDraft($request, $user, $tenancy, $validated['deductions']);

        return redirect()->route('tenancies.index')->with('success', 'Deposit reconciliation draft updated.');
    }

    public function finalizeDepositReconciliation(Request $request, TenancyDepositReconciliation $reconciliation): RedirectResponse
    {
        $user = $request->user();
        $tenancy = $reconciliation->tenancy;
        $this->authorizeTenancy($user, $tenancy);

        DB::transaction(function () use ($request, $user, $tenancy, $reconciliation): void {
            $lockedTenancy = Tenancy::query()->lockForUpdate()->findOrFail($tenancy->id);
            $this->ensureHandoverCompleted($lockedTenancy);
            $lockedReconciliation = TenancyDepositReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            abort_unless($lockedReconciliation->tenancy_id === $lockedTenancy->id, 404);
            abort_unless($lockedReconciliation->status === 'draft', 409, 'Only a draft deposit reconciliation can be finalized.');

            $depositSchedules = RentSchedule::query()
                ->where('tenancy_id', $lockedTenancy->id)
                ->where('category', 'security_deposit')
                ->lockForUpdate()
                ->get(['id']);
            $scheduleIds = $depositSchedules->pluck('id');
            $hasPendingDepositPayment = $scheduleIds->isNotEmpty()
                && DB::table('rent_payment_allocations')
                    ->join('rent_payments', 'rent_payments.id', '=', 'rent_payment_allocations.rent_payment_id')
                    ->whereIn('rent_payment_allocations.rent_schedule_id', $scheduleIds)
                    ->where('rent_payments.status', 'pending')
                    ->exists();
            abort_unless(! $hasPendingDepositPayment, 409, 'Review pending security-deposit payments before finalizing this reconciliation.');

            $rentDeductions = $lockedReconciliation->deductions()
                ->get(['category', 'rent_schedule_id', 'amount'])
                ->map(fn (TenancyDepositDeduction $deduction): array => [
                    'category' => $deduction->category,
                    'rent_schedule_id' => $deduction->rent_schedule_id,
                    'amount' => $deduction->amount,
                ])
                ->all();
            $rentOffsetsBySchedule = $this->validateRentOffsets($lockedTenancy, $rentDeductions);
            $receivedCents = $this->approvedDepositCents($scheduleIds)
                + Money::toMinorUnits($lockedTenancy->security_deposit_carried_forward);
            $deductionCents = $this->deductionCents($lockedReconciliation);
            if ($deductionCents > $receivedCents) {
                throw ValidationException::withMessages([
                    'deductions' => 'Total deductions cannot exceed the security deposit actually received.',
                ]);
            }
            $uncollectedDepositCents = 0;
            foreach ($depositSchedules as $depositSchedule) {
                $schedule = RentSchedule::query()->lockForUpdate()->findOrFail($depositSchedule->id);
                $uncollectedCents = max(
                    0,
                    Money::toMinorUnits($schedule->amount_due) - $this->approvedScheduleCents($schedule->id),
                );
                $uncollectedDepositCents += $uncollectedCents;
                $schedule->update([
                    'status' => $uncollectedCents > 0 ? 'written_off' : 'paid',
                ]);
            }

            $lockedReconciliation->update([
                'status' => 'finalized',
                'deposit_received_amount' => Money::fromMinorUnits($receivedCents),
                'deduction_total' => Money::fromMinorUnits($deductionCents),
                'refund_due_amount' => Money::fromMinorUnits($receivedCents - $deductionCents),
                'finalized_by_user_id' => $user->id,
                'finalized_at' => now(),
            ]);
            foreach (array_keys($rentOffsetsBySchedule) as $scheduleId) {
                $schedule = RentSchedule::query()->lockForUpdate()->findOrFail($scheduleId);
                $paidCents = $this->approvedScheduleCents($scheduleId);
                $netDueCents = max(0, Money::toMinorUnits($schedule->amount_due) - $schedule->reconciledDepositDeductionCents());
                $schedule->update([
                    'status' => $paidCents >= $netDueCents
                        ? 'paid'
                        : ($paidCents > 0 ? 'partially_paid' : 'unpaid'),
                ]);
            }

            $this->audit($request, $user, $lockedTenancy, 'tenancy.deposit_reconciliation_finalized', [
                'reconciliation_id' => $lockedReconciliation->id,
                'deposit_received_amount' => Money::fromMinorUnits($receivedCents),
                'deduction_total' => Money::fromMinorUnits($deductionCents),
                'refund_due_amount' => Money::fromMinorUnits($receivedCents - $deductionCents),
                'uncollected_deposit_written_off' => Money::fromMinorUnits($uncollectedDepositCents),
            ]);
        });

        return redirect()->route('tenancies.index')->with('success', 'Deposit reconciliation finalized.');
    }

    public function recordDepositRefund(Request $request, TenancyDepositReconciliation $reconciliation): RedirectResponse
    {
        $user = $request->user();
        $tenancy = $reconciliation->tenancy;
        $this->authorizeTenancy($user, $tenancy);
        $validated = $request->validate([
            'refund_reference' => ['required', 'string', 'max:120'],
            'refund_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $user, $tenancy, $reconciliation, $validated): void {
            $lockedTenancy = Tenancy::query()->lockForUpdate()->findOrFail($tenancy->id);
            $this->ensureHandoverCompleted($lockedTenancy);
            $lockedReconciliation = TenancyDepositReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            abort_unless($lockedReconciliation->tenancy_id === $lockedTenancy->id, 404);
            abort_unless(
                $lockedReconciliation->status === 'finalized'
                    && Money::toMinorUnits($lockedReconciliation->refund_due_amount) > 0,
                409,
                'Only a finalized reconciliation with a refund due can be marked as refunded.',
            );

            $lockedReconciliation->update([
                'status' => 'refunded',
                'refunded_at' => now(),
                'refund_reference' => $validated['refund_reference'],
                'refund_notes' => $validated['refund_notes'] ?? null,
            ]);
            $this->audit($request, $user, $lockedTenancy, 'tenancy.deposit_refunded', [
                'reconciliation_id' => $lockedReconciliation->id,
                'amount' => $lockedReconciliation->refund_due_amount,
                'reference' => $validated['refund_reference'],
            ]);
        });

        return redirect()->route('tenancies.index')->with('success', 'Deposit refund recorded.');
    }

    /**
     * @return array{deductions: list<array{category: string, description: string, amount: string, evidence_reference?: string|null, rent_schedule_id?: string|null}>}
     */
    private function validateDeductions(Request $request): array
    {
        return $request->validate([
            'deductions' => ['present', 'array', 'max:30'],
            'deductions.*.category' => ['required', Rule::in(self::DEDUCTION_CATEGORIES)],
            'deductions.*.description' => ['required', 'string', 'max:180'],
            'deductions.*.amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99', 'decimal:0,2'],
            'deductions.*.evidence_reference' => ['nullable', 'string', 'max:200'],
            'deductions.*.rent_schedule_id' => ['nullable', 'uuid', 'exists:rent_schedules,id'],
        ]);
    }

    /**
     * @param  list<array{category: string, description: string, amount: string, evidence_reference?: string|null, rent_schedule_id?: string|null}>  $deductions
     */
    private function saveDepositDraft(Request $request, User $user, Tenancy $tenancy, array $deductions): void
    {
        DB::transaction(function () use ($request, $user, $tenancy, $deductions): void {
            $lockedTenancy = Tenancy::query()->lockForUpdate()->findOrFail($tenancy->id);
            $this->ensureHandoverCompleted($lockedTenancy);
            $reconciliation = TenancyDepositReconciliation::query()
                ->where('tenancy_id', $lockedTenancy->id)
                ->lockForUpdate()
                ->first();
            abort_unless($reconciliation === null || $reconciliation->status === 'draft', 409, 'A finalized deposit reconciliation cannot be changed.');

            $scheduleIds = RentSchedule::query()
                ->where('tenancy_id', $lockedTenancy->id)
                ->where('category', 'security_deposit')
                ->lockForUpdate()
                ->pluck('id');
            $receivedCents = $this->approvedDepositCents($scheduleIds)
                + Money::toMinorUnits($lockedTenancy->security_deposit_carried_forward);
            $deductionCents = array_sum(array_map(
                fn (array $deduction): int => Money::toMinorUnits($deduction['amount']),
                $deductions,
            ));
            if ($deductionCents > $receivedCents) {
                throw ValidationException::withMessages([
                    'deductions' => 'Total deductions cannot exceed the security deposit actually received.',
                ]);
            }
            $this->validateRentOffsets($lockedTenancy, $deductions);

            $reconciliation ??= new TenancyDepositReconciliation([
                'tenancy_id' => $lockedTenancy->id,
                'prepared_by_user_id' => $user->id,
                'status' => 'draft',
            ]);
            $reconciliation->fill([
                'deposit_received_amount' => Money::fromMinorUnits($receivedCents),
                'deduction_total' => Money::fromMinorUnits($deductionCents),
                'refund_due_amount' => Money::fromMinorUnits($receivedCents - $deductionCents),
            ]);
            $reconciliation->save();
            $reconciliation->deductions()->delete();
            foreach ($deductions as $deduction) {
                $reconciliation->deductions()->create($deduction);
            }

            $this->audit($request, $user, $lockedTenancy, 'tenancy.deposit_reconciliation_drafted', [
                'reconciliation_id' => $reconciliation->id,
                'deposit_received_amount' => Money::fromMinorUnits($receivedCents),
                'deduction_total' => Money::fromMinorUnits($deductionCents),
                'refund_due_amount' => Money::fromMinorUnits($receivedCents - $deductionCents),
            ]);
        });
    }

    /**
     * @param  Collection<int, string>  $scheduleIds
     */
    private function approvedDepositCents($scheduleIds): int
    {
        if ($scheduleIds->isEmpty()) {
            return 0;
        }

        return DB::table('rent_payment_allocations')
            ->join('rent_payments', 'rent_payments.id', '=', 'rent_payment_allocations.rent_payment_id')
            ->whereIn('rent_payment_allocations.rent_schedule_id', $scheduleIds)
            ->where('rent_payments.status', 'approved')
            ->pluck('rent_payment_allocations.amount')
            ->sum(fn (string $amount): int => Money::toMinorUnits($amount));
    }

    private function deductionCents(TenancyDepositReconciliation $reconciliation): int
    {
        return $reconciliation->deductions()
            ->pluck('amount')
            ->sum(fn (string $amount): int => Money::toMinorUnits($amount));
    }

    /**
     * @param  list<array{category: string, amount: string, rent_schedule_id?: string|null}>  $deductions
     * @return array<string, int>
     */
    private function validateRentOffsets(Tenancy $tenancy, array $deductions): array
    {
        $offsetsBySchedule = [];
        foreach ($deductions as $index => $deduction) {
            $scheduleId = $deduction['rent_schedule_id'] ?? null;
            if ($deduction['category'] === 'unpaid_rent') {
                if (! is_string($scheduleId) || $scheduleId === '') {
                    throw ValidationException::withMessages([
                        "deductions.{$index}.rent_schedule_id" => 'Select the rent charge that this deposit deduction will settle.',
                    ]);
                }
                $offsetsBySchedule[$scheduleId] = ($offsetsBySchedule[$scheduleId] ?? 0)
                    + Money::toMinorUnits($deduction['amount']);
            } elseif ($scheduleId !== null && $scheduleId !== '') {
                throw ValidationException::withMessages([
                    "deductions.{$index}.rent_schedule_id" => 'A rent charge can only be selected for an unpaid-rent deduction.',
                ]);
            }
        }

        if ($offsetsBySchedule === []) {
            return [];
        }

        $schedules = RentSchedule::query()
            ->whereIn('id', array_keys($offsetsBySchedule))
            ->where('tenancy_id', $tenancy->id)
            ->where('category', 'rent')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        if ($schedules->count() !== count($offsetsBySchedule)) {
            throw ValidationException::withMessages([
                'deductions' => 'Unpaid-rent deductions must reference a rent charge on this tenancy.',
            ]);
        }

        $handoverDate = $tenancy->notices()->where('status', 'completed')->firstOrFail()->handed_over_on;
        foreach ($offsetsBySchedule as $scheduleId => $offsetCents) {
            $schedule = $schedules->get($scheduleId);
            if ($schedule->due_on->gt($handoverDate)) {
                throw ValidationException::withMessages([
                    'deductions' => 'Only rent charges due on or before handover can be settled from the deposit.',
                ]);
            }

            $hasPendingPayment = DB::table('rent_payment_allocations')
                ->join('rent_payments', 'rent_payments.id', '=', 'rent_payment_allocations.rent_payment_id')
                ->where('rent_payment_allocations.rent_schedule_id', $scheduleId)
                ->where('rent_payments.status', 'pending')
                ->exists();
            abort_unless(! $hasPendingPayment, 409, 'Review pending rent payments before offsetting a charge against the deposit.');

            $availableCents = Money::toMinorUnits($schedule->amount_due)
                - $this->approvedScheduleCents($scheduleId)
                - $schedule->reconciledDepositDeductionCents();
            if ($offsetCents > $availableCents) {
                throw ValidationException::withMessages([
                    'deductions' => 'An unpaid-rent deduction cannot exceed the selected charge’s outstanding balance.',
                ]);
            }
        }

        return $offsetsBySchedule;
    }

    private function approvedScheduleCents(string $scheduleId): int
    {
        return DB::table('rent_payment_allocations')
            ->join('rent_payments', 'rent_payments.id', '=', 'rent_payment_allocations.rent_payment_id')
            ->where('rent_payment_allocations.rent_schedule_id', $scheduleId)
            ->where('rent_payments.status', 'approved')
            ->pluck('rent_payment_allocations.amount')
            ->sum(fn (string $amount): int => Money::toMinorUnits($amount));
    }

    private function ensureHandoverCompleted(Tenancy $tenancy): void
    {
        abort_unless(
            $tenancy->status === 'completed'
                && $tenancy->notices()->where('status', 'completed')->exists(),
            409,
            'Complete and record the tenancy handover before reconciling its deposit.',
        );
    }

    private function authorizeTenancy(User $user, Tenancy $tenancy): void
    {
        abort_unless($user->hasPermission('tenancies.manage'), 403);
        abort_unless(
            in_array($tenancy->organization_id, Organization::accessibleIdsFor($user, 'tenancies.manage'), true),
            404,
        );
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Request $request, User $user, Tenancy $tenancy, string $action, array $metadata): void
    {
        AuditLog::create([
            'organization_id' => $tenancy->organization_id,
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => Tenancy::class,
            'subject_id' => $tenancy->id,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
