<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Project;
use App\Models\PropertyUnit;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_a_project_with_custom_title_types(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();

        $this->actingAs($owner)->post('/projects', [
            'organization_id' => $organization->id,
            'name' => 'Harbour View',
            'code' => 'hv-01',
            'location' => 'Lagos',
            'title_types' => ['Governor Consent', 'Registered Survey'],
        ])->assertRedirect();

        $project = Project::query()->where('code', 'HV-01')->firstOrFail();
        $this->assertSame(['Governor Consent', 'Registered Survey'], $project->settings['title_types']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'project.created', 'subject_id' => $project->id]);
    }

    public function test_owner_can_view_only_inventory_projects_in_their_organization_scope(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $project = $this->createProject($organization);
        $unrelatedOrganization = Organization::create([
            'name' => 'Unrelated Realty',
            'kind' => 'company',
            'country' => 'NG',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'locale' => 'en',
        ]);
        $this->createProject($unrelatedOrganization);

        $this->actingAs($owner)
            ->get(route('inventory.index', absolute: false))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inventory/Index')
                ->has('projects', 1)
                ->where('projects.0.id', $project->id)
                ->where('canManage', true)
                ->where('canReserve', true)
                ->where('auth.canViewInventory', true));
    }

    public function test_inventory_actions_are_scoped_per_organization(): void
    {
        $owner = $this->installOwner();
        $root = $owner->organizations()->firstOrFail();
        $managedOrganization = Organization::create([
            'parent_id' => $root->id,
            'name' => 'Managed Branch',
            'kind' => 'branch',
            'country' => 'NG',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'locale' => 'en',
        ]);
        $viewOnlyOrganization = Organization::create([
            'parent_id' => $root->id,
            'name' => 'View Only Branch',
            'kind' => 'branch',
            'country' => 'NG',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'locale' => 'en',
        ]);
        $viewerRole = $root->roles()->create(['name' => 'Inventory Viewer', 'key' => 'inventory-viewer']);
        $viewerRole->permissions()->attach(Permission::query()->where('key', 'inventory.view')->value('id'));
        $managerRole = $managedOrganization->roles()->create(['name' => 'Inventory Manager', 'key' => 'inventory-manager']);
        $managerRole->permissions()->attach(Permission::query()->whereIn('key', ['inventory.view', 'inventory.manage', 'inventory.reserve'])->pluck('id'));
        $user = User::factory()->create();
        $root->members()->attach($user->id, ['role_id' => $viewerRole->id, 'status' => 'active']);
        $managedOrganization->members()->attach($user->id, ['role_id' => $managerRole->id, 'status' => 'active']);
        $managedProject = $this->createProject($managedOrganization);
        $managedProject->update(['name' => 'A Managed Project']);
        $viewOnlyProject = $this->createProject($viewOnlyOrganization);
        $viewOnlyProject->update(['name' => 'B View Project']);

        $this->actingAs($user)
            ->get(route('inventory.index', absolute: false))
            ->assertInertia(fn (Assert $page) => $page
                ->has('projects', 2)
                ->where('projects.0.id', $managedProject->id)
                ->where('projects.0.canManage', true)
                ->where('projects.0.canReserve', true)
                ->where('projects.1.id', $viewOnlyProject->id)
                ->where('projects.1.canManage', false)
                ->where('projects.1.canReserve', false));
    }

    public function test_owner_can_update_project_status_labels_and_reservation_duration(): void
    {
        $owner = $this->installOwner();
        $project = $this->createProject($owner->organizations()->firstOrFail());

        $this->actingAs($owner)->put(route('projects.update', $project, absolute: false), [
            'name' => 'Harbour View Estates',
            'code' => 'HV-02',
            'location' => 'Lagos Island',
            'description' => 'Residential and mixed-use properties.',
            'title_types' => ['Governor Consent', 'Freehold'],
            'status_labels' => [
                'available' => 'Open',
                'reserved' => 'On hold',
                'under_installment' => 'Payment plan',
                'allocated' => 'Assigned',
                'sold' => 'Closed',
            ],
            'reservation_hours' => 36,
        ])->assertRedirect(route('inventory.index', ['project_id' => $project->id], absolute: false));

        $this->assertSame('HV-02', $project->fresh()->code);
        $this->assertSame('Open', $project->fresh()->settings['status_labels']['available']);
        $this->assertSame(36, $project->fresh()->settings['reservation_hours']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'project.updated', 'subject_id' => $project->id]);
    }

    public function test_owner_can_create_a_unit_and_reserve_it(): void
    {
        $owner = $this->installOwner();
        $project = $this->createProject($owner->organizations()->firstOrFail());

        $this->actingAs($owner)->post(route('projects.units.store', $project, absolute: false), [
            'phase' => 'Phase 1',
            'block' => 'A',
            'code' => 'Plot 12',
            'unit_type' => 'land',
            'usage' => 'residential',
            'area_sqm' => 450,
            'base_price' => 25000000,
            'title_type' => 'Governor Consent',
            'title_status' => 'In process',
            'is_corner' => true,
            'corner_premium_percent' => 10,
            'topography_premium_amount' => 500000,
        ])->assertRedirect();

        $unit = PropertyUnit::query()->where('project_id', $project->id)->firstOrFail();
        $this->actingAs($owner)->post(route('inventory.reserve', $unit, absolute: false))->assertRedirect();

        $this->assertDatabaseHas('property_units', ['id' => $unit->id, 'status' => 'reserved']);
        $this->assertDatabaseHas('reservations', [
            'property_unit_id' => $unit->id,
            'reserved_by_user_id' => $owner->id,
            'status' => 'active',
        ]);
    }

    public function test_an_active_reservation_prevents_a_second_reservation(): void
    {
        $owner = $this->installOwner();
        $project = $this->createProject($owner->organizations()->firstOrFail());
        $unit = $this->createUnit($project);

        $this->actingAs($owner)->post(route('inventory.reserve', $unit, absolute: false))->assertRedirect();
        $this->actingAs($owner)->post(route('inventory.reserve', $unit, absolute: false))->assertConflict();

        $this->assertSame(1, Reservation::query()->where('property_unit_id', $unit->id)->where('status', 'active')->count());
    }

    public function test_project_reservation_duration_controls_the_hold_expiration(): void
    {
        $owner = $this->installOwner();
        $project = $this->createProject($owner->organizations()->firstOrFail());
        $project->update(['settings' => array_merge($project->settings, ['reservation_hours' => 36])]);
        $unit = $this->createUnit($project);
        $startedAt = now();

        $this->actingAs($owner)->post(route('inventory.reserve', $unit, absolute: false))->assertRedirect();

        $reservation = Reservation::query()->where('property_unit_id', $unit->id)->firstOrFail();
        $this->assertTrue($reservation->reserved_until->between($startedAt->copy()->addHours(36)->subMinute(), $startedAt->copy()->addHours(36)->addMinute()));
    }

    public function test_expired_reservation_can_be_reclaimed_atomically(): void
    {
        $owner = $this->installOwner();
        $project = $this->createProject($owner->organizations()->firstOrFail());
        $unit = $this->createUnit($project, ['status' => 'reserved']);
        Reservation::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'property_unit_id' => $unit->id,
            'reserved_by_user_id' => $owner->id,
            'status' => 'active',
            'reserved_until' => now()->subMinute(),
        ]);

        $this->actingAs($owner)->post(route('inventory.reserve', $unit, absolute: false))->assertRedirect();

        $this->assertDatabaseHas('reservations', [
            'property_unit_id' => $unit->id,
            'status' => 'expired',
        ]);
        $this->assertSame(1, Reservation::query()->where('property_unit_id', $unit->id)->where('status', 'active')->count());
    }

    public function test_owner_can_release_a_reservation(): void
    {
        $owner = $this->installOwner();
        $project = $this->createProject($owner->organizations()->firstOrFail());
        $unit = $this->createUnit($project);

        $this->actingAs($owner)->post(route('inventory.reserve', $unit, absolute: false));
        $this->actingAs($owner)->post(route('inventory.release', $unit, absolute: false))->assertRedirect();

        $this->assertDatabaseHas('property_units', ['id' => $unit->id, 'status' => 'available']);
        $this->assertDatabaseHas('reservations', ['property_unit_id' => $unit->id, 'status' => 'released']);
    }

    public function test_inventory_changes_are_scoped_to_the_owner_organization(): void
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
        $unrelatedProject = $this->createProject($unrelatedOrganization);
        $unrelatedUnit = $this->createUnit($unrelatedProject);

        $this->actingAs($owner)->post('/projects', [
            'organization_id' => $unrelatedOrganization->id,
            'name' => 'Unauthorized Estate',
            'code' => 'NOPE',
            'title_types' => ['Freehold'],
        ])->assertNotFound();
        $this->actingAs($owner)->post(route('inventory.reserve', $unrelatedUnit, absolute: false))->assertNotFound();
    }

    public function test_non_manager_cannot_create_inventory(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $user = User::factory()->create();
        $viewerRole = $organization->roles()->create(['name' => 'Inventory Viewer', 'key' => 'inventory-viewer']);
        $viewerRole->permissions()->attach($organization->roles()->where('key', 'owner')->firstOrFail()->permissions()->where('key', 'inventory.view')->value('permissions.id'));
        $organization->members()->attach($user->id, ['role_id' => $viewerRole->id, 'status' => 'active']);

        $this->actingAs($user)->post('/projects', [
            'organization_id' => $organization->id,
            'name' => 'Unauthorized Project',
            'code' => 'NOPE',
            'title_types' => ['Freehold'],
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

    private function createProject(Organization $organization): Project
    {
        return Project::create([
            'organization_id' => $organization->id,
            'name' => 'Harbour View',
            'code' => 'HV-01',
            'settings' => ['title_types' => ['Governor Consent']],
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createUnit(Project $project, array $overrides = []): PropertyUnit
    {
        return $project->units()->create([
            'code' => 'P-12',
            'unit_type' => 'land',
            'base_price' => 25000000,
            'status' => 'available',
            ...$overrides,
        ]);
    }
}
