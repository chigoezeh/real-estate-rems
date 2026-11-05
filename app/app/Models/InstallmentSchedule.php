<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['subscription_id', 'category', 'installment_number', 'due_date', 'amount', 'amount_paid', 'status'])]
class InstallmentSchedule extends Model
{
    /** @use HasFactory<\Database\Factories\InstallmentScheduleFactory> */
    use HasFactory, HasUuids;

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'installment_number' => 'integer',
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
        ];
    }
}
