<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'project_id',
    'name',
    'bedrooms',
    'bathrooms',
    'toilets',
    'living_rooms',
    'has_bq',
    'floor_area_sqm',
    'base_price',
    'description',
])]
class PropertyPrototype extends Model
{
    use HasUuids;

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<PropertyUnit, $this> */
    public function propertyUnits(): HasMany
    {
        return $this->hasMany(PropertyUnit::class, 'prototype_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bedrooms' => 'integer',
            'bathrooms' => 'integer',
            'toilets' => 'integer',
            'living_rooms' => 'integer',
            'has_bq' => 'boolean',
            'floor_area_sqm' => 'decimal:2',
            'base_price' => 'decimal:2',
        ];
    }
}
