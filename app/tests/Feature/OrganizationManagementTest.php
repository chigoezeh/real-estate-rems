<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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

    public function test_owner_can_update_organization_profile_and_branding(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();

        $this->actingAs($owner)->put(route('organizations.update', $organization, absolute: false), [
            'name' => 'Northstar Realty Group',
            'registration_number' => 'RC-402010',
            'email' => 'office@example.test',
            'phone' => '+234 800 123 4567',
            'address' => '12 Marina Road, Lagos',
            'country' => 'ng',
            'currency' => 'ngn',
            'timezone' => 'Africa/Lagos',
            'locale' => 'en-NG',
            'branding' => [
                'display_name' => 'Northstar',
                'primary_color' => '#17483a',
                'accent_color' => '#d8ef75',
            ],
        ])->assertRedirect(route('organizations.index', absolute: false));

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'name' => 'Northstar Realty Group',
            'country' => 'NG',
            'currency' => 'NGN',
            'email' => 'office@example.test',
        ]);
        $this->assertSame('Northstar', $organization->fresh()->branding['display_name']);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'organization.updated',
            'subject_id' => $organization->id,
        ]);
    }

    public function test_owner_cannot_update_an_unrelated_organization(): void
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

        $this->actingAs($owner)->put(route('organizations.update', $unrelatedOrganization, absolute: false), [
            'name' => 'Compromised Realty',
            'country' => 'NG',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'locale' => 'en',
            'branding' => ['primary_color' => '#17352d', 'accent_color' => '#d6f36a'],
        ])->assertNotFound();

        $this->assertDatabaseMissing('organizations', ['name' => 'Compromised Realty']);
    }

    public function test_owner_cannot_save_invalid_brand_colors(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();

        $this->actingAs($owner)->put(route('organizations.update', $organization, absolute: false), [
            'name' => $organization->name,
            'country' => 'NG',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'locale' => 'en',
            'branding' => ['primary_color' => 'green', 'accent_color' => '#d6f36a'],
        ])->assertSessionHasErrors('branding.primary_color');
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

    public function test_owner_can_list_their_tree_without_unrelated_organizations(): void
    {
        $owner = $this->installOwner();
        $root = $owner->organizations()->firstOrFail();

        Organization::create([
            'parent_id' => $root->id,
            'name' => 'Northstar Abuja Branch',
            'kind' => 'branch',
            'country' => $root->country,
            'currency' => $root->currency,
            'timezone' => $root->timezone,
            'locale' => $root->locale,
        ]);
        Organization::create([
            'name' => 'Unrelated Realty',
            'kind' => 'company',
            'country' => 'NG',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'locale' => 'en',
        ]);

        $this->actingAs($owner)
            ->get('/organizations')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Organizations/Index')
                ->has('organizations', 2)
                ->where('canCreate', true));
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
