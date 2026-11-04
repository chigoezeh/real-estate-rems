<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasPermission('users.manage'), 403);

        $organizationIds = Organization::accessibleIdsFor($user, 'users.manage');

        return Inertia::render('Roles/Index', [
            'organizations' => Organization::query()
                ->whereIn('id', $organizationIds)
                ->orderBy('name')
                ->get(['id', 'name']),
            'roles' => Role::query()
                ->with(['organization:id,name', 'permissions' => fn ($query) => $query->orderBy('key')])
                ->whereIn('organization_id', $organizationIds)
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'key' => $role->key,
                    'description' => $role->description,
                    'isSystem' => $role->is_system,
                    'organization' => $role->organization?->only(['id', 'name']),
                    'permissions' => $role->permissions->pluck('key')->values(),
                ])
                ->values(),
            'permissions' => Permission::query()
                ->orderBy('key')
                ->get(['key', 'label', 'description']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('users.manage'), 403);

        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::exists('permissions', 'key')],
        ]);

        $organizationIds = Organization::accessibleIdsFor($user, 'users.manage');
        abort_unless(in_array($validated['organization_id'], $organizationIds, true), 404);

        $organization = Organization::query()->findOrFail($validated['organization_id']);
        $key = Str::slug($validated['name']);
        $this->validateRoleKey($organization, $key);

        DB::transaction(function () use ($request, $user, $organization, $validated, $key): void {
            $role = Role::create([
                'organization_id' => $organization->id,
                'name' => $validated['name'],
                'key' => $key,
                'description' => $validated['description'] ?? null,
                'is_system' => false,
            ]);
            $role->permissions()->sync($this->permissionIds($validated['permissions']));

            $this->audit($request, $user, $role, 'role.created', $validated['permissions']);
        });

        return redirect()->route('roles.index');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('users.manage'), 403);

        $organizationIds = Organization::accessibleIdsFor($user, 'users.manage');
        abort_unless($role->organization_id !== null && in_array($role->organization_id, $organizationIds, true), 404);
        abort_if($role->is_system, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::exists('permissions', 'key')],
        ]);

        $organization = Organization::query()->findOrFail($role->organization_id);
        $key = Str::slug($validated['name']);
        $this->validateRoleKey($organization, $key, $role);

        DB::transaction(function () use ($request, $user, $role, $validated, $key): void {
            $role->update([
                'name' => $validated['name'],
                'key' => $key,
                'description' => $validated['description'] ?? null,
            ]);
            $role->permissions()->sync($this->permissionIds($validated['permissions']));

            $this->audit($request, $user, $role, 'role.updated', $validated['permissions']);
        });

        return redirect()->route('roles.index');
    }

    private function validateRoleKey(Organization $organization, string $key, ?Role $role = null): void
    {
        $uniqueRule = Rule::unique('roles', 'key')->where('organization_id', $organization->id);
        if ($role !== null) {
            $uniqueRule->ignore($role->id);
        }

        $validator = Validator::make([
            'role_key' => $key,
        ], [
            'role_key' => ['required', 'string', 'max:80', $uniqueRule],
        ], [
            'role_key.required' => 'Enter a role name containing letters or numbers.',
            'role_key.unique' => 'A role with this name already exists in this organization.',
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages([
                'name' => $validator->errors()->first('role_key'),
            ]);
        }
    }

    /** @param array<int, string> $permissionKeys
     * @return array<int, string>
     */
    private function permissionIds(array $permissionKeys): array
    {
        return Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all();
    }

    /** @param array<int, string> $permissionKeys */
    private function audit(Request $request, User $user, Role $role, string $action, array $permissionKeys): void
    {
        AuditLog::create([
            'organization_id' => $role->organization_id,
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => Role::class,
            'subject_id' => $role->id,
            'metadata' => ['name' => $role->name, 'permissions' => $permissionKeys],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
