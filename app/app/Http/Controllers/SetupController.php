<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\InstallationState;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SetupController extends Controller
{
    public function create(): Response|RedirectResponse
    {
        if (InstallationState::isInstalled()) {
            return redirect()->route('login');
        }

        return Inertia::render('Setup');
    }

    public function store(Request $request): RedirectResponse
    {
        if (InstallationState::isInstalled()) {
            return redirect()->route('login');
        }

        $validated = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'size:2'],
            'currency' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'timezone'],
            'administrator_name' => ['required', 'string', 'max:255'],
            'administrator_email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $administrator = DB::transaction(function () use ($request, $validated): ?User {
            $installationState = InstallationState::query()->lockForUpdate()->firstOrFail();

            if ($installationState->is_installed) {
                return null;
            }

            $organization = Organization::create([
                'name' => $validated['organization_name'],
                'kind' => 'company',
                'country' => strtoupper($validated['country']),
                'currency' => strtoupper($validated['currency']),
                'timezone' => $validated['timezone'],
                'locale' => 'en',
            ]);

            $administrator = User::create([
                'name' => $validated['administrator_name'],
                'email' => $validated['administrator_email'],
                'password' => $validated['password'],
                'email_verified_at' => now(),
            ]);

            $ownerRole = Role::create([
                'organization_id' => $organization->id,
                'name' => 'Owner',
                'key' => 'owner',
                'description' => 'Full access to this installation and its organization branches.',
                'is_system' => true,
            ]);

            $permissionLabels = [
                'organizations.view' => 'View organizations',
                'organizations.manage' => 'Manage organizations',
                'users.manage' => 'Manage users and roles',
                'settings.manage' => 'Manage company settings',
                'audit.view' => 'View audit history',
                'inventory.view' => 'View property inventory',
                'inventory.manage' => 'Manage projects and inventory',
                'inventory.reserve' => 'Reserve available inventory',
                'clients.view' => 'View clients',
                'clients.manage' => 'Manage client records',
                'clients.kyc.review' => 'Review client KYC documents',
                'subscriptions.view' => 'View subscriptions and installment schedules',
                'subscriptions.manage' => 'Create and manage subscriptions',
                'payments.view' => 'View payment ledger',
                'payments.submit' => 'Submit payment records and proof',
                'payments.review' => 'Approve or reject submitted payments',
            ];

            $permissionIds = [];

            foreach ($permissionLabels as $permissionKey => $label) {
                $permission = Permission::query()->firstOrCreate(
                    ['key' => $permissionKey],
                    ['label' => $label],
                );
                $permissionIds[] = $permission->id;
            }

            $ownerRole->permissions()->sync($permissionIds);
            $organization->members()->attach($administrator->id, [
                'role_id' => $ownerRole->id,
                'status' => 'active',
            ]);

            AuditLog::create([
                'organization_id' => $organization->id,
                'user_id' => $administrator->id,
                'action' => 'installation.completed',
                'subject_type' => Organization::class,
                'subject_id' => $organization->id,
                'metadata' => ['administrator_email' => $administrator->email],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            $installationState->update([
                'is_installed' => true,
                'installed_at' => now(),
            ]);

            return $administrator;
        });

        if ($administrator === null) {
            return redirect()->route('login');
        }

        Auth::login($administrator);

        return redirect()->route('dashboard');
    }
}
