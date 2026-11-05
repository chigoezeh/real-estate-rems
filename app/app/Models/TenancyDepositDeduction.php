<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reconciliation_id',
    'rent_schedule_id',
    'category',
    'description',
    'amount',
    'evidence_reference',
])]
class TenancyDepositDeduction extends Model
{
    use HasUuids;

    /** @return BelongsTo<TenancyDepositReconciliation, $this> */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(TenancyDepositReconciliation::class, 'reconciliation_id');
    }

    /** @return BelongsTo<RentSchedule, $this> */
    public function rentSchedule(): BelongsTo
    {
        return $this->belongsTo(RentSchedule::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
