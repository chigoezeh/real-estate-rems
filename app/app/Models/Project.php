<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'name',
    'code',
    'location',
    'district_or_area',
    'street_address',
    'postal_code',
    'country_code',
    'state_id',
    'province_id',
    'city_id',
    'developer_organization_id',
    'description',
    'status',
    'settings',
])]
class Project extends Model
{
    use HasUuids;

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }

    /** @return BelongsTo<State, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /** @return BelongsTo<Province, $this> */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function developer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'developer_organization_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<PropertyUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(PropertyUnit::class);
    }

    /** @return HasMany<PropertyPrototype, $this> */
    public function prototypes(): HasMany
    {
        return $this->hasMany(PropertyPrototype::class);
    }

    /** @return HasMany<LandTitleRecord, $this> */
    public function titleRecords(): HasMany
    {
        return $this->hasMany(LandTitleRecord::class);
    }

    /** @return BelongsToMany<Amenity, $this> */
    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'project_amenity')->withPivot('notes');
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['settings' => 'array'];
    }
}
