<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'organization_id',
    'tenancy_id',
    'submitted_by_user_id',
    'reviewed_by_user_id',
    'amount',
    'payment_channel',
    'transaction_reference',
    'proof_document_path',
    'status',
    'rejection_reason',
    'reviewed_at',
    'received_at',
])]
class RentPayment extends Model
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

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /** @return BelongsToMany<RentSchedule, $this> */
    public function schedules(): BelongsToMany
    {
        return $this->belongsToMany(RentSchedule::class, 'rent_payment_allocations')
            ->withPivot('amount')
            ->withTimestamps();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
