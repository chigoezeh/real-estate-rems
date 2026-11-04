<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_staff_memberships_and_invite_a_new_staff_member(): void
    {
        Notification::fake();
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $role = $organization->roles()->where('key', 'owner')->firstOrFail();

        $this->actingAs($owner)
            ->get('/staff')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Staff/Index')
                ->has('members', 1)
                ->where('members.0.isSelf', true));

        $this->actingAs($owner)->post('/staff', [
            'organization_id' => $organization->id,
            'name' => 'Tomi Adebayo',
            'email' => 'tomi@example.test',
            'role_id' => $role->id,
        ])->assertRedirect(route('staff.index', absolute: false));

        $staff = User::query()->where('email', 'tomi@example.test')->firstOrFail();
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $staff->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $this->assertFalse(Hash::check('a-strong-password', $staff->password));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff.invited',
            'subject_id' => $staff->id,
        ]);
        Notification::assertSentTo($staff, ResetPassword::class);
    }

    public function test_owner_can_assign_an_existing_user_to_an_organization(): void
    {
        Notification::fake();
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $role = $organization->roles()->where('key', 'owner')->firstOrFail();
        $staff = User::factory()->create(['email' => 'existing@example.test']);

        $this->actingAs($owner)->post('/staff', [
            'organization_id' => $organization->id,
            'name' => $staff->name,
            'email' => $staff->email,
            'role_id' => $role->id,
        ])->assertRedirect(route('staff.index', absolute: false));

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $staff->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        Notification::assertNothingSent();
    }

    public function test_owner_can_change_staff_role_and_suspend_membership(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $ownerRole = $organization->roles()->where('key', 'owner')->firstOrFail();
        $viewerRole = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Viewer',
            'key' => 'viewer',
        ]);
        $staff = User::factory()->create();
        $organization->members()->attach($staff->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        $this->actingAs($owner)->patch(route('staff.update', $staff, absolute: false), [
            'organization_id' => $organization->id,
            'role_id' => $viewerRole->id,
            'status' => 'suspended',
        ])->assertRedirect(route('staff.index', absolute: false));

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $staff->id,
            'role_id' => $viewerRole->id,
            'status' => 'suspended',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff.membership_updated',
            'subject_id' => $staff->id,
        ]);
    }

    public function test_owner_cannot_invite_to_an_unrelated_organization(): void
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
        $role = Role::create([
            'organization_id' => $unrelatedOrganization->id,
            'name' => 'Branch Manager',
            'key' => 'branch-manager',
        ]);

        $this->actingAs($owner)->post('/staff', [
            'organization_id' => $unrelatedOrganization->id,
            'name' => 'Unauthorized Staff',
            'email' => 'unauthorized@example.test',
            'role_id' => $role->id,
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'unauthorized@example.test']);
    }

    public function test_user_without_user_management_permission_cannot_access_staff(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $role = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Viewer',
            'key' => 'viewer',
        ]);
        $user = User::factory()->create();
        $organization->members()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($user)->get('/staff')->assertForbidden();
        $this->actingAs($user)->post('/staff', [
            'organization_id' => $organization->id,
            'name' => 'Unauthorized Staff',
            'email' => 'unauthorized@example.test',
            'role_id' => $role->id,
        ])->assertForbidden();
    }

    public function test_manager_cannot_change_own_membership(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $role = $organization->roles()->where('key', 'owner')->firstOrFail();

        $this->actingAs($owner)->patch(route('staff.update', $owner, absolute: false), [
            'organization_id' => $organization->id,
            'role_id' => $role->id,
            'status' => 'suspended',
        ])->assertForbidden();
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
