<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'tenancy_id',
    'rent_schedule_id',
    'contacted_by_user_id',
    'channel',
    'notes',
])]
class RentContactLog extends Model
{
    use HasUuids;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Tenancy, $this> */
    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    /** @return BelongsTo<RentSchedule, $this> */
    public function rentSchedule(): BelongsTo
    {
        return $this->belongsTo(RentSchedule::class);
    }

    /** @return BelongsTo<User, $this> */
    public function contactedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contacted_by_user_id');
    }
}
