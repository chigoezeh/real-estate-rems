<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['is_installed', 'installed_at'])]
class InstallationState extends Model
{
    use HasUuids;

    public static function isInstalled(): bool
    {
        return (bool) static::query()->value('is_installed');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_installed' => 'boolean',
            'installed_at' => 'datetime',
        ];
    }
}
