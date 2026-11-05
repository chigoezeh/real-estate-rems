<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenancy_id',
    'prepared_by_user_id',
    'finalized_by_user_id',
    'status',
    'deposit_received_amount',
    'deduction_total',
    'refund_due_amount',
    'finalized_at',
    'refunded_at',
    'refund_reference',
    'refund_notes',
])]
class TenancyDepositReconciliation extends Model
{
    use HasUuids;

    /** @return BelongsTo<Tenancy, $this> */
    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    /** @return BelongsTo<User, $this> */
    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by_user_id');
    }

    /** @return HasMany<TenancyDepositDeduction, $this> */
    public function deductions(): HasMany
    {
        return $this->hasMany(TenancyDepositDeduction::class, 'reconciliation_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'deposit_received_amount' => 'decimal:2',
            'deduction_total' => 'decimal:2',
            'refund_due_amount' => 'decimal:2',
            'finalized_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }
}
