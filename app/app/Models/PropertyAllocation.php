<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'subscription_id',
    'property_unit_id',
    'land_title_record_id',
    'requested_by_user_id',
    'reviewed_by_user_id',
    'allocation_reference',
    'status',
    'requested_at',
    'reviewed_at',
    'allocated_at',
    'rejection_reason',
    'notes',
])]
class PropertyAllocation extends Model
{
    use HasUuids;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<PropertyUnit, $this> */
    public function propertyUnit(): BelongsTo
    {
        return $this->belongsTo(PropertyUnit::class);
    }

    /** @return BelongsTo<LandTitleRecord, $this> */
    public function landTitleRecord(): BelongsTo
    {
        return $this->belongsTo(LandTitleRecord::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'allocated_at' => 'date',
        ];
    }
}
