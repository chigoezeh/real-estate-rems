<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'name',
    'grace_period_days',
    'calculation_method',
    'amount',
    'recurrence_interval_days',
    'maximum_occurrences',
    'maximum_fee_amount',
    'applies_to',
    'is_active',
])]
class OrganizationLatePaymentPolicy extends Model
{
    use HasUuids;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<LateFeeAssessment, $this> */
    public function assessments(): HasMany
    {
        return $this->hasMany(LateFeeAssessment::class, 'policy_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'grace_period_days' => 'integer',
            'amount' => 'decimal:2',
            'recurrence_interval_days' => 'integer',
            'maximum_occurrences' => 'integer',
            'maximum_fee_amount' => 'decimal:2',
            'applies_to' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
