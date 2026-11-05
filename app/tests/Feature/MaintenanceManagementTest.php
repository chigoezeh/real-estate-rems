<?php

namespace Tests\Feature;

use App\Models\MaintenanceRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_maintenance_work_order_requires_approval_before_completion(): void
    {
        $this->post('/setup', [
            'organization_name' => 'Coastal Realty',
            'country' => 'NG',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'administrator_name' => 'Ada Okafor',
            'administrator_email' => 'ada@example.test',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ]);
        $owner = User::query()->where('email', 'ada@example.test')->firstOrFail();
        $organization = $owner->organizations()->firstOrFail();
        $project = Project::create([
            'organization_id' => $organization->id,
            'name' => 'Coastal Apartments',
            'code' => 'CA-MAINT',
        ]);
        $property = $project->units()->create([
            'code' => 'A-01',
            'unit_type' => 'apartment',
            'base_price' => 50000000,
            'status' => 'available',
        ]);

        $this->actingAs($owner)->post(route('maintenance.requests.store', absolute: false), [
            'property_unit_id' => $property->id,
            'category' => 'plumbing',
            'priority' => 'high',
            'title' => 'Leaking kitchen pipe',
            'description' => 'Water is leaking under the kitchen sink.',
        ])->assertRedirect(route('maintenance.index', absolute: false));

        $maintenanceRequest = MaintenanceRequest::query()->firstOrFail();
        $this->assertSame('open', $maintenanceRequest->status);

        $this->actingAs($owner)->post(route('maintenance.requests.work-orders.store', $maintenanceRequest, absolute: false), [
            'vendor_name' => 'Reliable Plumbing Ltd',
            'estimated_cost' => '25000.00',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertRedirect(route('maintenance.index', absolute: false));

        $workOrder = $maintenanceRequest->workOrders()->firstOrFail();
        $this->assertSame('pending_approval', $workOrder->status);

        $this->actingAs($owner)->patch(route('maintenance.work-orders.approve', $workOrder, absolute: false))
            ->assertRedirect(route('maintenance.index', absolute: false));
        $this->assertSame('approved', $workOrder->fresh()->status);

        $this->actingAs($owner)->patch(route('maintenance.work-orders.complete', $workOrder, absolute: false), [
            'actual_cost' => '24500.00',
            'completion_notes' => 'Pipe replaced and leak tested.',
        ])->assertRedirect(route('maintenance.index', absolute: false));

        $this->assertSame('completed', $workOrder->fresh()->status);
        $this->assertSame('resolved', $maintenanceRequest->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'maintenance.work_order.completed',
            'subject_id' => $workOrder->id,
        ]);
    }
}
