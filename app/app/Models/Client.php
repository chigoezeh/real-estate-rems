<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'portal_user_id',
    'created_by_user_id',
    'kyc_reviewed_by_user_id',
    'full_name',
    'email',
    'phone',
    'address',
    'next_of_kin_name',
    'next_of_kin_phone',
    'identity_type',
    'identity_number',
    'identity_document_path',
    'kyc_status',
    'kyc_reviewed_at',
    'kyc_rejection_reason',
])]
#[Hidden(['identity_number', 'identity_document_path'])]
class Client extends Model
{
    /** @use HasFactory<\Database\Factories\ClientFactory> */
    use HasFactory, HasUuids;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'portal_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function kycReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kyc_reviewed_by_user_id');
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'identity_number' => 'encrypted',
            'kyc_reviewed_at' => 'datetime',
        ];
    }
}
