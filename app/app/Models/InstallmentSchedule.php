<?php

namespace App\Models;

use Database\Factories\InstallmentScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'subscription_id',
    'charge_type_id',
    'charge_name_snapshot',
    'charge_classification_snapshot',
    'charge_calculation_method_snapshot',
    'charge_policy_snapshot',
    'category',
    'installment_number',
    'due_date',
    'amount',
    'amount_paid',
    'status',
])]
class InstallmentSchedule extends Model
{
    /** @use HasFactory<InstallmentScheduleFactory> */
    use HasFactory, HasUuids;

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<OrganizationChargeType, $this> */
    public function chargeType(): BelongsTo
    {
        return $this->belongsTo(OrganizationChargeType::class, 'charge_type_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'installment_number' => 'integer',
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'charge_policy_snapshot' => 'array',
        ];
    }
}
