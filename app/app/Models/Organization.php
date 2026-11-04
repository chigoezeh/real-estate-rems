<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['parent_id', 'name', 'kind', 'registration_number', 'email', 'phone', 'address', 'country', 'currency', 'timezone', 'locale', 'branding', 'settings', 'is_active'])]
class Organization extends Model
{
    use HasUuids;

    /** @return BelongsTo<Organization, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'parent_id');
    }

    /** @return HasMany<Organization, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Organization::class, 'parent_id');
    }

    /** @return HasMany<Role, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role_id', 'status'])
            ->withTimestamps();
    }

    /**
     * Get the organizations covered by a user's permission assignment.
     *
     * @return array<int, string>
     */
    public static function accessibleIdsFor(User $user, string $permissionKey): array
    {
        $organizationIds = DB::table('organization_user')
            ->join('role_permission', 'organization_user.role_id', '=', 'role_permission.role_id')
            ->join('permissions', 'role_permission.permission_id', '=', 'permissions.id')
            ->where('organization_user.user_id', $user->getKey())
            ->where('organization_user.status', 'active')
            ->where('permissions.key', $permissionKey)
            ->pluck('organization_user.organization_id')
            ->all();

        $accessibleIds = $organizationIds;
        $pendingIds = $organizationIds;

        while ($pendingIds !== []) {
            $childIds = static::query()
                ->whereIn('parent_id', $pendingIds)
                ->pluck('id')
                ->reject(fn (string $organizationId): bool => in_array($organizationId, $accessibleIds, true))
                ->all();

            $accessibleIds = array_merge($accessibleIds, $childIds);
            $pendingIds = $childIds;
        }

        return $accessibleIds;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'branding' => 'array',
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
