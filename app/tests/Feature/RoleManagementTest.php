<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_roles_and_create_a_custom_role(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();

        $this->actingAs($owner)
            ->get('/access/roles')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Roles/Index')
                ->has('roles', 1)
                ->has('organizations', 1)
                ->where('auth.canManageUsers', true));

        $this->actingAs($owner)->post('/access/roles', [
            'organization_id' => $organization->id,
            'name' => 'Sales Manager',
            'description' => 'Manages sales operations.',
            'permissions' => ['organizations.view'],
        ])->assertRedirect(route('roles.index', absolute: false));

        $role = Role::query()->where('key', 'sales-manager')->firstOrFail();
        $this->assertSame($organization->id, $role->organization_id);
        $this->assertSame(['organizations.view'], $role->permissions()->pluck('key')->all());
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'role.created',
            'subject_id' => $role->id,
        ]);
    }

    public function test_owner_can_update_custom_role_permissions(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $role = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Operations Viewer',
            'key' => 'operations-viewer',
        ]);

        $this->actingAs($owner)->put(route('roles.update', $role, absolute: false), [
            'name' => 'Operations Lead',
            'description' => 'Coordinates day-to-day operations.',
            'permissions' => ['organizations.view', 'audit.view'],
        ])->assertRedirect(route('roles.index', absolute: false));

        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'Operations Lead', 'key' => 'operations-lead']);
        $this->assertEqualsCanonicalizing(
            ['audit.view', 'organizations.view'],
            $role->fresh()->permissions()->pluck('key')->all(),
        );
    }

    public function test_owner_cannot_manage_roles_for_an_unrelated_organization(): void
    {
        $owner = $this->installOwner();
        $unrelatedOrganization = Organization::create([
            'name' => 'Unrelated Realty',
            'kind' => 'company',
            'country' => 'NG',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'locale' => 'en',
        ]);

        $this->actingAs($owner)->post('/access/roles', [
            'organization_id' => $unrelatedOrganization->id,
            'name' => 'Unauthorized Role',
            'permissions' => [],
        ])->assertNotFound();

        $this->assertDatabaseMissing('roles', ['name' => 'Unauthorized Role']);
    }

    public function test_user_without_user_management_permission_cannot_access_roles(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $role = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Viewer',
            'key' => 'viewer',
        ]);
        $role->permissions()->attach(Permission::query()->where('key', 'organizations.view')->value('id'));
        $user = User::factory()->create();
        $organization->members()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($user)->get('/access/roles')->assertForbidden();
        $this->actingAs($user)->post('/access/roles', [
            'organization_id' => $organization->id,
            'name' => 'Unauthorized Role',
            'permissions' => [],
        ])->assertForbidden();
    }

    public function test_system_owner_role_cannot_be_changed(): void
    {
        $owner = $this->installOwner();
        $role = $owner->organizations()->firstOrFail()->roles()->where('key', 'owner')->firstOrFail();

        $this->actingAs($owner)->put(route('roles.update', $role, absolute: false), [
            'name' => 'Restricted Owner',
            'description' => null,
            'permissions' => [],
        ])->assertForbidden();

        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'Owner']);
    }

    private function installOwner(): User
    {
        $this->post('/setup', [
            'organization_name' => 'Northstar Properties',
            'country' => 'NG',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'administrator_name' => 'Ada Okafor',
            'administrator_email' => 'ada@example.test',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ]);

        return User::query()->where('email', 'ada@example.test')->firstOrFail();
    }
}
