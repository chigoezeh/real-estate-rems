<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenancy_id',
    'charge_type_id',
    'charge_name_snapshot',
    'charge_classification_snapshot',
    'charge_calculation_method_snapshot',
    'charge_policy_snapshot',
    'category',
    'period_starts_on',
    'period_ends_on',
    'due_on',
    'amount_due',
    'status',
])]
class RentSchedule extends Model
{
    use HasUuids;

    /** @return BelongsTo<Tenancy, $this> */
    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    /** @return BelongsTo<OrganizationChargeType, $this> */
    public function chargeType(): BelongsTo
    {
        return $this->belongsTo(OrganizationChargeType::class, 'charge_type_id');
    }

    /** @return BelongsToMany<RentPayment, $this> */
    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(RentPayment::class, 'rent_payment_allocations')
            ->withPivot('amount')
            ->withTimestamps();
    }

    /** @return HasMany<TenancyDepositDeduction, $this> */
    public function depositDeductions(): HasMany
    {
        return $this->hasMany(TenancyDepositDeduction::class);
    }

    /** @return HasMany<LateFeeAssessment, $this> */
    public function lateFeeAssessments(): HasMany
    {
        return $this->hasMany(LateFeeAssessment::class);
    }

    /** @return HasMany<RentContactLog, $this> */
    public function contactLogs(): HasMany
    {
        return $this->hasMany(RentContactLog::class);
    }

    public function lateFeeCents(): int
    {
        $assessments = $this->relationLoaded('lateFeeAssessments')
            ? $this->lateFeeAssessments
            : $this->lateFeeAssessments()->get(['amount']);

        return $assessments->sum(fn (LateFeeAssessment $assessment): int => Money::toMinorUnits($assessment->amount));
    }

    public function reconciledDepositDeductionCents(): int
    {
        $deductions = $this->relationLoaded('depositDeductions')
            ? $this->depositDeductions->filter(fn (TenancyDepositDeduction $deduction): bool => in_array(
                $deduction->reconciliation->status,
                ['finalized', 'refunded'],
                true,
            ))
            : $this->depositDeductions()
                ->whereHas('reconciliation', fn ($query) => $query->whereIn('status', ['finalized', 'refunded']))
                ->get(['amount']);

        return $deductions->sum(fn (TenancyDepositDeduction $deduction): int => Money::toMinorUnits($deduction->amount));
    }

    public function totalDueCents(): int
    {
        return max(
            0,
            Money::toMinorUnits($this->amount_due)
                + $this->lateFeeCents()
                - $this->reconciledDepositDeductionCents(),
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_starts_on' => 'date',
            'period_ends_on' => 'date',
            'due_on' => 'date',
            'amount_due' => 'decimal:2',
            'charge_policy_snapshot' => 'array',
        ];
    }
}
