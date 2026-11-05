<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'project_id',
    'prototype_id',
    'phase',
    'block',
    'code',
    'plot_number',
    'house_number',
    'beacon_number',
    'unit_type',
    'usage',
    'area_sqm',
    'base_price',
    'title_type',
    'title_status',
    'finishing_state',
    'rental_status',
    'status',
    'is_corner',
    'corner_premium_percent',
    'topography_premium_amount',
    'attributes',
])]
class PropertyUnit extends Model
{
    use HasUuids;

    /** @return BelongsTo<PropertyPrototype, $this> */
    public function prototype(): BelongsTo
    {
        return $this->belongsTo(PropertyPrototype::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<Reservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return HasMany<LandTitleRecord, $this> */
    public function titleRecords(): HasMany
    {
        return $this->hasMany(LandTitleRecord::class);
    }

    /** @return HasMany<Tenancy, $this> */
    public function tenancies(): HasMany
    {
        return $this->hasMany(Tenancy::class);
    }

    /** @return HasMany<MaintenanceRequest, $this> */
    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'area_sqm' => 'decimal:2',
            'base_price' => 'decimal:2',
            'is_corner' => 'boolean',
            'corner_premium_percent' => 'decimal:2',
            'topography_premium_amount' => 'decimal:2',
            'attributes' => 'array',
        ];
    }
}
