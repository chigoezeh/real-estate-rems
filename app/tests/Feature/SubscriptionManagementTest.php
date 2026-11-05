<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\PropertyUnit;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SubscriptionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_client_can_subscribe_to_an_available_unit_with_separate_fee_schedules(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $client = Client::create([
            'organization_id' => $organization->id,
            'created_by_user_id' => $owner->id,
            'full_name' => 'Chinwe Okafor',
            'phone' => '+2348012345678',
            'identity_type' => 'nin',
            'identity_number' => '12345678901',
            'kyc_status' => 'verified',
        ]);
        $project = Project::create([
            'organization_id' => $organization->id,
            'name' => 'Harbour View',
            'code' => 'HV-01',
            'settings' => [],
        ]);
        $unit = $project->units()->create([
            'code' => 'P-12',
            'unit_type' => 'land',
            'base_price' => 25000000,
            'is_corner' => true,
            'corner_premium_percent' => 10,
            'topography_premium_amount' => 500000,
            'status' => 'available',
        ]);

        $this->actingAs($owner)
            ->post(route('subscriptions.store', absolute: false), [
                'organization_id' => $organization->id,
                'client_id' => $client->id,
                'property_unit_id' => $unit->id,
                'payment_plan_months' => 3,
                'development_fee' => 600000,
                'deed_fee' => 300000,
                'survey_fee' => 150000,
            ])
            ->assertRedirect(route('subscriptions.index', absolute: false));

        $subscription = Subscription::query()->firstOrFail();
        $schedules = $subscription->installmentSchedules;
        $this->assertSame('28000000.00', $subscription->land_price);
        $this->assertSame(12, $schedules->count());
        $this->assertSame(28000000.0, (float) $schedules->where('category', 'land_installment')->sum('amount'));
        $this->assertSame(600000.0, (float) $schedules->where('category', 'development_levy')->sum('amount'));
        $this->assertSame(300000.0, (float) $schedules->where('category', 'deed_fee')->sum('amount'));
        $this->assertSame(150000.0, (float) $schedules->where('category', 'survey_fee')->sum('amount'));
        $this->assertSame('under_installment', $unit->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'subscription.created',
            'subject_id' => $subscription->id,
        ]);

        $this->actingAs($owner)
            ->get(route('subscriptions.index', absolute: false))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Subscriptions/Index')
                ->where('subscriptions.0.id', $subscription->id));
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
