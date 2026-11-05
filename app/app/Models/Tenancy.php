<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'organization_id',
    'property_unit_id',
    'tenant_client_id',
    'created_by_user_id',
    'renewal_of_tenancy_id',
    'tenancy_number',
    'status',
    'starts_on',
    'ends_on',
    'rent_frequency',
    'rent_amount',
    'security_deposit',
    'security_deposit_carried_forward',
    'service_charge',
    'next_review_on',
    'agreement_path',
    'terms_snapshot',
    'policy_snapshot',
])]
class Tenancy extends Model
{
    use HasUuids;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<PropertyUnit, $this> */
    public function propertyUnit(): BelongsTo
    {
        return $this->belongsTo(PropertyUnit::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'tenant_client_id');
    }

    /** @return BelongsTo<Tenancy, $this> */
    public function renewalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewal_of_tenancy_id');
    }

    /** @return HasMany<Tenancy, $this> */
    public function renewals(): HasMany
    {
        return $this->hasMany(self::class, 'renewal_of_tenancy_id');
    }

    /** @return HasMany<TenancyNotice, $this> */
    public function notices(): HasMany
    {
        return $this->hasMany(TenancyNotice::class);
    }

    /** @return HasOne<TenancyDepositReconciliation, $this> */
    public function depositReconciliation(): HasOne
    {
        return $this->hasOne(TenancyDepositReconciliation::class);
    }

    /** @return HasMany<RentSchedule, $this> */
    public function rentSchedules(): HasMany
    {
        return $this->hasMany(RentSchedule::class);
    }

    /** @return HasMany<RentPayment, $this> */
    public function rentPayments(): HasMany
    {
        return $this->hasMany(RentPayment::class);
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
            'starts_on' => 'date',
            'ends_on' => 'date',
            'next_review_on' => 'date',
            'rent_amount' => 'decimal:2',
            'security_deposit' => 'decimal:2',
            'security_deposit_carried_forward' => 'decimal:2',
            'service_charge' => 'decimal:2',
            'terms_snapshot' => 'array',
            'policy_snapshot' => 'array',
        ];
    }
}
