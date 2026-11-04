<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_a_scoped_child_organization(): void
    {
        $owner = $this->installOwner();
        $root = $owner->organizations()->firstOrFail();

        $response = $this->actingAs($owner)->post('/organizations', [
            'name' => 'Northstar Abuja Branch',
            'kind' => 'branch',
            'parent_id' => $root->id,
        ]);

        $response->assertRedirect(route('organizations.index', absolute: false));
        $this->assertDatabaseHas('organizations', [
            'name' => 'Northstar Abuja Branch',
            'kind' => 'branch',
            'parent_id' => $root->id,
            'currency' => 'NGN',
        ]);
    }

    public function test_owner_cannot_create_children_under_an_unrelated_organization(): void
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

        $this->actingAs($owner)->post('/organizations', [
            'name' => 'Unauthorized Branch',
            'kind' => 'branch',
            'parent_id' => $unrelatedOrganization->id,
        ])->assertNotFound();

        $this->assertDatabaseMissing('organizations', ['name' => 'Unauthorized Branch']);
    }

    public function test_user_without_organization_management_permission_is_forbidden(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $role = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Viewer',
            'key' => 'viewer',
        ]);
        $user = User::factory()->create();
        $organization->members()->attach($user->id, [
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $this->actingAs($user)->post('/organizations', [
            'name' => 'Unauthorized Branch',
            'kind' => 'branch',
            'parent_id' => $organization->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('organizations', ['name' => 'Unauthorized Branch']);
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
