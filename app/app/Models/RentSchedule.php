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

    public function reconciledDepositDeductionCents(): int
    {
        return $this->depositDeductions()
            ->whereHas('reconciliation', fn ($query) => $query->whereIn('status', ['finalized', 'refunded']))
            ->pluck('amount')
            ->sum(fn (string $amount): int => Money::toMinorUnits($amount));
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
