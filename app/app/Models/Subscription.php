<?php

namespace App\Models;

use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'organization_id',
    'client_id',
    'project_id',
    'property_unit_id',
    'created_by_user_id',
    'subscription_number',
    'payment_plan_months',
    'land_price',
    'development_fee',
    'deed_fee',
    'survey_fee',
    'interest_amount',
    'charge_snapshot',
    'status',
])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory, HasUuids;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<PropertyUnit, $this> */
    public function propertyUnit(): BelongsTo
    {
        return $this->belongsTo(PropertyUnit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<InstallmentSchedule, $this> */
    public function installmentSchedules(): HasMany
    {
        return $this->hasMany(InstallmentSchedule::class);
    }

    /** @return HasMany<PropertyAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PropertyAllocation::class);
    }

    /** @return HasOne<PropertyAllocation, $this> */
    public function latestAllocation(): HasOne
    {
        return $this->hasOne(PropertyAllocation::class)->latestOfMany('requested_at');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payment_plan_months' => 'integer',
            'land_price' => 'decimal:2',
            'development_fee' => 'decimal:2',
            'deed_fee' => 'decimal:2',
            'survey_fee' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'charge_snapshot' => 'array',
        ];
    }
}
