<?php

namespace App\Domain\Services;

use App\Models\AuditLog;
use App\Models\RentPayment;
use App\Models\RentSchedule;
use App\Models\Tenancy;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class RentPaymentService
{
    /**
     * @param  array<string, string|int>  $allocations  Schedule IDs mapped to amounts.
     */
    public function review(
        RentPayment $payment,
        User $reviewer,
        string $decision,
        ?string $rejectionReason = null,
        array $allocations = [],
    ): void {
        abort_unless(in_array($decision, ['approved', 'rejected'], true), 422, 'Invalid payment decision.');

        DB::transaction(function () use ($payment, $reviewer, $decision, $rejectionReason, $allocations): void {
            $lockedPayment = RentPayment::query()->lockForUpdate()->findOrFail($payment->id);
            abort_unless($lockedPayment->status === 'pending', 409, 'This rent payment has already been reviewed.');

            if ($decision === 'approved') {
                $this->approve($lockedPayment, $allocations);
            } else {
                abort_unless(trim((string) $rejectionReason) !== '', 422, 'A rejection reason is required.');
            }

            $lockedPayment->update([
                'status' => $decision,
                'rejection_reason' => $decision === 'rejected' ? $rejectionReason : null,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'received_at' => $decision === 'approved' ? now() : null,
            ]);

            AuditLog::create([
                'organization_id' => $lockedPayment->organization_id,
                'user_id' => $reviewer->id,
                'action' => "rent_payment.{$decision}",
                'subject_type' => RentPayment::class,
                'subject_id' => $lockedPayment->id,
                'metadata' => [
                    'amount' => $lockedPayment->amount,
                    'reason' => $decision === 'rejected' ? $rejectionReason : null,
                    'allocation_count' => count($allocations),
                ],
            ]);
        });
    }

    /**
     * @param  array<string, string|int>  $allocations
     */
    private function approve(RentPayment $payment, array $allocations): void
    {
        $storedAllocations = DB::table('rent_payment_allocations')
            ->where('rent_payment_id', $payment->id)
            ->pluck('amount', 'rent_schedule_id')
            ->all();

        if ($allocations === []) {
            $allocations = $storedAllocations;
        }

        abort_unless($allocations !== [], 422, 'Approved rent payments must be allocated to rent charges.');
        $tenancy = Tenancy::query()->lockForUpdate()->findOrFail($payment->tenancy_id);
        abort_unless($tenancy->organization_id === $payment->organization_id, 404);

        $allocationCents = [];
        $totalCents = 0;

        foreach ($allocations as $scheduleId => $amount) {
            $cents = Money::toMinorUnits($amount);
            abort_unless($cents > 0, 422, 'Payment allocations must be greater than zero.');
            $allocationCents[(string) $scheduleId] = $cents;
            abort_unless($totalCents <= PHP_INT_MAX - $cents, 422, 'Payment allocation amount exceeds the supported range.');
            $totalCents += $cents;
        }

        abort_unless($totalCents === Money::toMinorUnits($payment->amount), 422, 'Payment allocations must equal the approved payment amount.');

        if ($storedAllocations !== []) {
            $storedAllocationCents = [];

            foreach ($storedAllocations as $scheduleId => $amount) {
                $storedAllocationCents[$scheduleId] = Money::toMinorUnits($amount);
            }

            ksort($storedAllocationCents);
            ksort($allocationCents);
            abort_unless($storedAllocationCents === $allocationCents, 409, 'Payment allocations changed after submission.');
        }

        $schedules = RentSchedule::query()
            ->where('tenancy_id', $payment->tenancy_id)
            ->whereIn('id', array_keys($allocationCents))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        abort_unless($schedules->count() === count($allocationCents), 404, 'One or more rent charges do not belong to this tenancy.');
        abort_unless(
            ! $schedules->contains(fn (RentSchedule $schedule): bool => $schedule->status === 'written_off'),
            409,
            'A written-off rent charge cannot receive a payment.',
        );

        if ($tenancy->depositReconciliation()->whereIn('status', ['finalized', 'refunded'])->exists()) {
            abort_unless(
                ! $schedules->contains(fn (RentSchedule $schedule): bool => $schedule->category === 'security_deposit'),
                409,
                'Security-deposit payments cannot be approved after the reconciliation is finalized.',
            );
        }

        foreach ($allocationCents as $scheduleId => $amountCents) {
            $schedule = $schedules->get($scheduleId);
            $alreadyAllocatedCents = $this->reservedAllocationCents($scheduleId, $payment->id);
            $netAmountDueCents = $schedule->totalDueCents();

            abort_unless(
                $alreadyAllocatedCents + $amountCents <= $netAmountDueCents,
                409,
                'Approval would exceed a rent charge balance.',
            );
        }

        foreach ($allocationCents as $scheduleId => $amountCents) {
            if ($storedAllocations === []) {
                DB::table('rent_payment_allocations')->insert([
                    'rent_payment_id' => $payment->id,
                    'rent_schedule_id' => $scheduleId,
                    'amount' => Money::fromMinorUnits($amountCents),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $schedule = $schedules->get($scheduleId);
            $paidCents = $this->approvedAllocationCents($scheduleId) + $amountCents;
            $netAmountDueCents = $schedule->totalDueCents();
            $schedule->update([
                'status' => $paidCents >= $netAmountDueCents
                    ? 'paid'
                    : ($paidCents > 0 ? 'partially_paid' : 'unpaid'),
            ]);
        }
    }

    private function approvedAllocationCents(string $scheduleId): int
    {
        $amounts = DB::table('rent_payment_allocations')
            ->join('rent_payments', 'rent_payments.id', '=', 'rent_payment_allocations.rent_payment_id')
            ->where('rent_payment_allocations.rent_schedule_id', $scheduleId)
            ->where('rent_payments.status', 'approved')
            ->pluck('rent_payment_allocations.amount');

        return $amounts->sum(fn (string $amount): int => Money::toMinorUnits($amount));
    }

    private function reservedAllocationCents(string $scheduleId, string $exceptPaymentId): int
    {
        $amounts = DB::table('rent_payment_allocations')
            ->join('rent_payments', 'rent_payments.id', '=', 'rent_payment_allocations.rent_payment_id')
            ->where('rent_payment_allocations.rent_schedule_id', $scheduleId)
            ->where('rent_payments.id', '!=', $exceptPaymentId)
            ->whereIn('rent_payments.status', ['pending', 'approved'])
            ->pluck('rent_payment_allocations.amount');

        return $amounts->sum(fn (string $amount): int => Money::toMinorUnits($amount));
    }
}
