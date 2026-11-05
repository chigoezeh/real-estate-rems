<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
