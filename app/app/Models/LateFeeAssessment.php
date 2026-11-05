<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'rent_schedule_id',
    'policy_id',
    'occurrence',
    'assessed_on',
    'amount',
    'policy_snapshot',
])]
class LateFeeAssessment extends Model
{
    use HasUuids;

    /** @return BelongsTo<RentSchedule, $this> */
    public function rentSchedule(): BelongsTo
    {
        return $this->belongsTo(RentSchedule::class);
    }

    /** @return BelongsTo<OrganizationLatePaymentPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(OrganizationLatePaymentPolicy::class, 'policy_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurrence' => 'integer',
            'assessed_on' => 'date',
            'amount' => 'decimal:2',
            'policy_snapshot' => 'array',
        ];
    }
}
