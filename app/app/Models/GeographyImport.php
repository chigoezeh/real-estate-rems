<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['source_key', 'source_url', 'source_version', 'license', 'imported_at', 'metadata'])]
class GeographyImport extends Model
{
    use HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
