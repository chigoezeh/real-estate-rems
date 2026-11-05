<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'project_id',
    'property_unit_id',
    'document_type',
    'reference_number',
    'issuing_authority',
    'jurisdiction',
    'status',
    'instrument_date',
    'registered_at',
    'survey_number',
    'document_path',
    'verification_status',
    'verified_by_user_id',
    'verified_at',
    'verification_notes',
])]
class LandTitleRecord extends Model
{
    use HasUuids;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<PropertyUnit, $this> */
    public function propertyUnit(): BelongsTo
    {
        return $this->belongsTo(PropertyUnit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'instrument_date' => 'date',
            'registered_at' => 'date',
            'verified_at' => 'datetime',
        ];
    }
}
