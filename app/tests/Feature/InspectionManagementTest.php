<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inspection;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InspectionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_schedule_and_assign_an_inspection(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $client = Client::factory()->forOrganization($organization)->create(['created_by_user_id' => $owner->id]);
        $project = Project::create([
            'organization_id' => $organization->id,
            'name' => 'Harbour View',
            'code' => 'HV-01',
        ]);

        $this->actingAs($owner)
            ->post(route('inspections.store', absolute: false), [
                'client_id' => $client->id,
                'project_id' => $project->id,
                'scheduled_at' => now()->addDays(3)->startOfHour()->toDateTimeLocalString(),
                'assigned_to_user_id' => $owner->id,
                'notes' => 'Meet at the main gate.',
            ])
            ->assertRedirect(route('inspections.index', absolute: false));

        $inspection = Inspection::query()->firstOrFail();
        $this->assertSame($organization->id, $inspection->organization_id);
        $this->assertSame('scheduled', $inspection->status);
        $this->assertSame($owner->id, $inspection->assigned_to_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'inspection.scheduled',
            'subject_id' => $inspection->id,
        ]);

        $this->actingAs($owner)
            ->get(route('inspections.index', absolute: false))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inspections/Index')
                ->where('inspections.0.id', $inspection->id)
                ->where('inspections.0.clientName', $client->full_name));

        $this->actingAs($owner)
            ->patch(route('inspections.update', $inspection, absolute: false), [
                'assigned_to_user_id' => null,
                'status' => 'confirmed',
            ])
            ->assertRedirect(route('inspections.index', absolute: false));

        $this->assertSame('confirmed', $inspection->fresh()->status);
        $this->assertNull($inspection->fresh()->assigned_to_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'inspection.updated',
            'subject_id' => $inspection->id,
        ]);
    }

    public function test_inspection_cannot_be_assigned_to_a_user_outside_its_organization(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $client = Client::factory()->forOrganization($organization)->create(['created_by_user_id' => $owner->id]);
        $project = Project::create([
            'organization_id' => $organization->id,
            'name' => 'Harbour View',
            'code' => 'HV-01',
        ]);
        $otherOrganization = Organization::create([
            'name' => 'Other Company',
            'kind' => 'company',
            'country' => 'NG',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'locale' => 'en',
        ]);
        $otherRole = Role::create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Site Officer',
            'key' => 'site-officer',
        ]);
        $outsider = User::factory()->create();
        $otherOrganization->members()->attach($outsider->id, ['role_id' => $otherRole->id, 'status' => 'active']);

        $this->actingAs($owner)
            ->post(route('inspections.store', absolute: false), [
                'client_id' => $client->id,
                'project_id' => $project->id,
                'scheduled_at' => now()->addDays(3)->startOfHour()->toDateTimeLocalString(),
                'assigned_to_user_id' => $outsider->id,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('inspections', 0);

        $this->actingAs($owner)
            ->post(route('inspections.store', absolute: false), [
                'client_id' => $client->id,
                'project_id' => $project->id,
                'scheduled_at' => now()->addDays(3)->startOfHour()->toDateTimeLocalString(),
                'assigned_to_user_id' => $owner->id,
            ])
            ->assertRedirect(route('inspections.index', absolute: false));
        $inspection = Inspection::query()->firstOrFail();

        $this->actingAs($owner)
            ->patch(route('inspections.update', $inspection, absolute: false), ['assigned_to_user_id' => $outsider->id])
            ->assertNotFound();

        $this->assertSame($owner->id, $inspection->fresh()->assigned_to_user_id);
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
        ])->assertRedirect(route('dashboard', absolute: false));

        return User::query()->where('email', 'ada@example.test')->firstOrFail();
    }
}
