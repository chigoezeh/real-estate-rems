<?php

namespace App\Domain\Services;

use App\Models\AuditLog;
use App\Models\LateFeeAssessment;
use App\Models\OrganizationLatePaymentPolicy;
use App\Models\RentSchedule;
use App\Models\Tenancy;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LateFeeAssessmentService
{
    /**
     * @param  list<string>  $organizationIds
     */
    public function assess(array $organizationIds, ?Carbon $asOf = null): int
    {
        $asOf ??= today();
        $assessmentCount = 0;
        $policies = OrganizationLatePaymentPolicy::query()
            ->whereIn('organization_id', $organizationIds)
            ->where('is_active', true)
            ->orderBy('organization_id')
            ->orderBy('id')
            ->get();

        foreach ($policies as $policy) {
            RentSchedule::query()
                ->whereHas('tenancy', fn ($query) => $query
                    ->where('organization_id', $policy->organization_id)
                    ->where('status', 'active'))
                ->whereIn('category', $policy->applies_to)
                ->whereDate('due_on', '<', $asOf->toDateString())
                ->orderBy('id')
                ->select('id', 'tenancy_id')
                ->chunkById(100, function (Collection $schedules) use ($policy, $asOf, &$assessmentCount): void {
                    foreach ($schedules as $schedule) {
                        if ($this->assessSchedule($policy, $schedule->tenancy_id, $schedule->id, $asOf)) {
                            $assessmentCount++;
                        }
                    }
                });
        }

        return $assessmentCount;
    }

    private function assessSchedule(
        OrganizationLatePaymentPolicy $policy,
        string $tenancyId,
        string $scheduleId,
        Carbon $asOf,
    ): bool {
        return DB::transaction(function () use ($policy, $tenancyId, $scheduleId, $asOf): bool {
            $tenancy = Tenancy::query()->lockForUpdate()->findOrFail($tenancyId);
            abort_unless($tenancy->organization_id === $policy->organization_id && $tenancy->status === 'active', 409);

            $schedule = RentSchedule::query()->lockForUpdate()->findOrFail($scheduleId);
            abort_unless($schedule->tenancy_id === $tenancy->id && in_array($schedule->category, $policy->applies_to, true), 409);

            $elapsedDays = $schedule->due_on->startOfDay()->diffInDays($asOf->copy()->startOfDay());
            if ($elapsedDays <= $policy->grace_period_days || in_array($schedule->status, ['paid', 'written_off'], true)) {
                return false;
            }

            $lastAssessment = LateFeeAssessment::query()
                ->where('rent_schedule_id', $schedule->id)
                ->where('policy_id', $policy->id)
                ->orderByDesc('occurrence')
                ->first();
            if ($lastAssessment === null) {
                $occurrence = 1;
            } else {
                if ($policy->recurrence_interval_days === null
                    || $lastAssessment->assessed_on->copy()->addDays($policy->recurrence_interval_days)->gt($asOf)) {
                    return false;
                }
                $occurrence = $lastAssessment->occurrence + 1;
            }

            if ($occurrence > $policy->maximum_occurrences) {
                return false;
            }

            $approvedPaymentsCents = DB::table('rent_payment_allocations')
                ->join('rent_payments', 'rent_payments.id', '=', 'rent_payment_allocations.rent_payment_id')
                ->where('rent_payment_allocations.rent_schedule_id', $schedule->id)
                ->where('rent_payments.status', 'approved')
                ->pluck('rent_payment_allocations.amount')
                ->sum(fn (string $amount): int => Money::toMinorUnits($amount));
            $principalBalanceCents = max(
                0,
                Money::toMinorUnits($schedule->amount_due)
                    - $approvedPaymentsCents
                    - $schedule->reconciledDepositDeductionCents(),
            );
            if ($principalBalanceCents === 0) {
                return false;
            }

            $assessedCents = $policy->assessments()
                ->where('rent_schedule_id', $schedule->id)
                ->pluck('amount')
                ->sum(fn (string $amount): int => Money::toMinorUnits($amount));
            $capCents = $policy->maximum_fee_amount === null
                ? PHP_INT_MAX
                : Money::toMinorUnits($policy->maximum_fee_amount);
            $capRemainingCents = max(0, $capCents - $assessedCents);
            if ($capRemainingCents === 0) {
                return false;
            }

            $configuredFeeCents = $policy->calculation_method === 'percentage'
                ? Money::percentageOf($principalBalanceCents, $policy->amount)
                : Money::toMinorUnits($policy->amount);
            $feeCents = min($configuredFeeCents, $principalBalanceCents, $capRemainingCents);
            if ($feeCents === 0) {
                return false;
            }

            $snapshot = [
                'policy_id' => $policy->id,
                'policy_name' => $policy->name,
                'grace_period_days' => $policy->grace_period_days,
                'calculation_method' => $policy->calculation_method,
                'configured_amount' => $policy->amount,
                'recurrence_interval_days' => $policy->recurrence_interval_days,
                'maximum_occurrences' => $policy->maximum_occurrences,
                'maximum_fee_amount' => $policy->maximum_fee_amount,
                'principal_balance' => Money::fromMinorUnits($principalBalanceCents),
            ];
            $assessment = LateFeeAssessment::create([
                'rent_schedule_id' => $schedule->id,
                'policy_id' => $policy->id,
                'occurrence' => $occurrence,
                'assessed_on' => $asOf->toDateString(),
                'amount' => Money::fromMinorUnits($feeCents),
                'policy_snapshot' => $snapshot,
            ]);

            AuditLog::create([
                'organization_id' => $policy->organization_id,
                'action' => 'late_fee.assessed',
                'subject_type' => RentSchedule::class,
                'subject_id' => $schedule->id,
                'metadata' => [
                    ...$snapshot,
                    'assessment_id' => $assessment->id,
                    'occurrence' => $occurrence,
                    'amount' => Money::fromMinorUnits($feeCents),
                    'assessed_on' => $asOf->toDateString(),
                ],
            ]);

            return true;
        });
    }
}
