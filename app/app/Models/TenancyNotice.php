<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenancy_id',
    'recorded_by_user_id',
    'completed_by_user_id',
    'notice_type',
    'notice_on',
    'proposed_vacate_on',
    'reason',
    'status',
    'handed_over_on',
    'handover_notes',
])]
class TenancyNotice extends Model
{
    use HasUuids;

    /** @return BelongsTo<Tenancy, $this> */
    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'notice_on' => 'date',
            'proposed_vacate_on' => 'date',
            'handed_over_on' => 'date',
        ];
    }
}
