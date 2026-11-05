<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasPermission('users.manage'), 403);

        $organizationIds = Organization::accessibleIdsFor($user, 'users.manage');

        return Inertia::render('Staff/Index', [
            'organizations' => Organization::query()
                ->whereIn('id', $organizationIds)
                ->orderBy('name')
                ->get(['id', 'name']),
            'roles' => Role::query()
                ->whereIn('organization_id', $organizationIds)
                ->orderBy('name')
                ->get(['id', 'organization_id', 'name', 'is_system']),
            'members' => DB::table('organization_user')
                ->join('users', 'organization_user.user_id', '=', 'users.id')
                ->join('organizations', 'organization_user.organization_id', '=', 'organizations.id')
                ->join('roles', 'organization_user.role_id', '=', 'roles.id')
                ->whereIn('organization_user.organization_id', $organizationIds)
                ->orderBy('organizations.name')
                ->orderBy('users.name')
                ->get([
                    'users.id',
                    'users.name',
                    'users.email',
                    'organization_user.organization_id',
                    'organization_user.role_id',
                    'organization_user.status',
                    'organizations.name as organization_name',
                    'roles.name as role_name',
                ])
                ->map(fn (object $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'organizationId' => $member->organization_id,
                    'organizationName' => $member->organization_name,
                    'roleId' => $member->role_id,
                    'roleName' => $member->role_name,
                    'status' => $member->status,
                    'isSelf' => $member->id === $user->id,
                ])
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('users.manage'), 403);

        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'role_id' => [
                'required',
                'uuid',
                Rule::exists('roles', 'id')->where('organization_id', $request->input('organization_id')),
            ],
        ]);

        $organizationIds = Organization::accessibleIdsFor($user, 'users.manage');
        abort_unless(in_array($validated['organization_id'], $organizationIds, true), 404);

        [$staff, $isNew] = DB::transaction(function () use ($request, $user, $validated): array {
            $organization = Organization::query()->findOrFail($validated['organization_id']);
            $staff = User::query()->where('email', $validated['email'])->lockForUpdate()->first();
            $isNew = $staff === null;

            if ($isNew) {
                $staff = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => Str::random(64),
                ]);
            }

            $organization->members()->syncWithoutDetaching([
                $staff->id => ['role_id' => $validated['role_id'], 'status' => 'active'],
            ]);

            AuditLog::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'action' => $isNew ? 'staff.invited' : 'staff.assigned',
                'subject_type' => User::class,
                'subject_id' => $staff->id,
                'metadata' => ['email' => $staff->email, 'role_id' => $validated['role_id']],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return [$staff, $isNew];
        });

        if ($isNew) {
            Password::sendResetLink(['email' => $staff->email]);
        }

        return redirect()->route('staff.index');
    }

    public function update(Request $request, User $staff): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('users.manage'), 403);
        abort_if($staff->is($user), 403);

        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'role_id' => [
                'required',
                'uuid',
                Rule::exists('roles', 'id')->where('organization_id', $request->input('organization_id')),
            ],
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);

        $organizationIds = Organization::accessibleIdsFor($user, 'users.manage');
        abort_unless(in_array($validated['organization_id'], $organizationIds, true), 404);

        $organization = Organization::query()->findOrFail($validated['organization_id']);
        $membership = DB::table('organization_user')
            ->where('organization_id', $organization->id)
            ->where('user_id', $staff->id)
            ->first();
        abort_unless($membership !== null, 404);

        DB::transaction(function () use ($request, $user, $organization, $staff, $membership, $validated): void {
            $organization->members()->updateExistingPivot($staff->id, [
                'role_id' => $validated['role_id'],
                'status' => $validated['status'],
            ]);

            AuditLog::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'action' => 'staff.membership_updated',
                'subject_type' => User::class,
                'subject_id' => $staff->id,
                'metadata' => [
                    'email' => $staff->email,
                    'old_role_id' => $membership->role_id,
                    'role_id' => $validated['role_id'],
                    'old_status' => $membership->status,
                    'status' => $validated['status'],
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        return redirect()->route('staff.index');
    }
}
