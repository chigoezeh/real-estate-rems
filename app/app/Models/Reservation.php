<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'project_id', 'property_unit_id', 'reserved_by_user_id', 'status', 'reserved_until', 'released_at'])]
class Reservation extends Model
{
    use HasUuids;

    /** @return BelongsTo<PropertyUnit, $this> */
    public function propertyUnit(): BelongsTo
    {
        return $this->belongsTo(PropertyUnit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reservedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reserved_by_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reserved_until' => 'datetime',
            'released_at' => 'datetime',
        ];
    }
}
