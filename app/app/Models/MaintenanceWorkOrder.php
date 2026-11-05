<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'maintenance_request_id',
    'assigned_to_user_id',
    'approved_by_user_id',
    'vendor_name',
    'status',
    'estimated_cost',
    'actual_cost',
    'approved_at',
    'scheduled_at',
    'completed_at',
    'completion_notes',
])]
class MaintenanceWorkOrder extends Model
{
    use HasUuids;

    /** @return BelongsTo<MaintenanceRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'estimated_cost' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'approved_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
