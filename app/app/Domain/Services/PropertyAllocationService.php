<?php

namespace App\Domain\Services;

use App\Models\InstallmentSchedule;
use App\Models\LandTitleRecord;
use App\Models\Payment;
use App\Models\PropertyUnit;
use App\Models\Subscription;
use App\Support\Money;

class PropertyAllocationService
{
    /**
     * @return array{reason: ?string, titleRecord: ?LandTitleRecord}
     */
    public function eligibility(Subscription $subscription, bool $lock = false): array
    {
        if ($subscription->status !== 'active') {
            return ['reason' => 'Only active subscriptions can be allocated.', 'titleRecord' => null];
        }

        $unitQuery = PropertyUnit::query()->whereKey($subscription->property_unit_id);
        if ($lock) {
            $unitQuery->lockForUpdate();
        }
        $unit = $unitQuery->first();

        if ($unit === null || $unit->status !== 'under_installment') {
            return ['reason' => 'The property must be under an active subscription.', 'titleRecord' => null];
        }

        $scheduleQuery = InstallmentSchedule::query()
            ->where('subscription_id', $subscription->id)
            ->orderBy('id');
        if ($lock) {
            $scheduleQuery->lockForUpdate();
        }
        $schedules = $scheduleQuery->get(['id', 'amount', 'amount_paid']);

        if ($schedules->isEmpty()) {
            return ['reason' => 'The subscription has no payment schedules to settle.', 'titleRecord' => null];
        }

        $approvedPayments = Payment::query()
            ->whereIn('installment_schedule_id', $schedules->pluck('id'))
            ->where('status', 'approved')
            ->get(['installment_schedule_id', 'amount'])
            ->groupBy('installment_schedule_id')
            ->map(fn ($payments): int => $payments->sum(
                fn (Payment $payment): int => Money::toMinorUnits($payment->amount),
            ));

        foreach ($schedules as $schedule) {
            $dueCents = Money::toMinorUnits($schedule->amount);
            $recordedPaidCents = Money::toMinorUnits($schedule->amount_paid);
            $ledgerPaidCents = $approvedPayments->get($schedule->id, 0);

            if ($recordedPaidCents !== $ledgerPaidCents) {
                return ['reason' => 'The approved payment ledger and schedule balances must be reconciled before allocation.', 'titleRecord' => null];
            }

            if ($ledgerPaidCents !== $dueCents) {
                return ['reason' => 'All subscription installments and charges must be fully paid before allocation.', 'titleRecord' => null];
            }
        }

        $titleQuery = LandTitleRecord::query()
            ->where('organization_id', $subscription->organization_id)
            ->where('property_unit_id', $unit->id)
            ->where('verification_status', 'verified')
            ->whereNotNull('verified_by_user_id')
            ->whereNotNull('verified_at')
            ->orderByDesc('verified_at');
        if ($lock) {
            $titleQuery->lockForUpdate();
        }
        $titleRecord = $titleQuery->first();

        if ($titleRecord === null) {
            return ['reason' => 'A verified title record for this property is required before allocation.', 'titleRecord' => null];
        }

        return ['reason' => null, 'titleRecord' => $titleRecord];
    }
}
