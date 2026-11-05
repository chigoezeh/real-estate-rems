<?php

namespace Tests\Feature;

use App\Models\InstallationState;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DevelopmentDemoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstallationTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_run_setup_creates_the_company_and_owner_account(): void
    {
        $response = $this->post('/setup', [
            'organization_name' => 'Northstar Properties',
            'country' => 'NG',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'administrator_name' => 'Ada Okafor',
            'administrator_email' => 'ada@example.test',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ]);

        $administrator = User::query()->where('email', 'ada@example.test')->firstOrFail();
        $organization = Organization::query()->where('name', 'Northstar Properties')->firstOrFail();

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($administrator);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $administrator->id);
        $this->assertSame('NGN', $organization->currency);
        $this->assertSame('company', $organization->kind);
        $this->assertTrue(InstallationState::isInstalled());
        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseHas('roles', [
            'organization_id' => $organization->id,
            'key' => 'owner',
        ]);
        $this->assertTrue($administrator->hasPermission('organizations.manage'));
    }

    public function test_installation_can_only_be_claimed_once_and_public_registration_is_closed(): void
    {
        $payload = [
            'organization_name' => 'Northstar Properties',
            'country' => 'NG',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'administrator_name' => 'Ada Okafor',
            'administrator_email' => 'ada@example.test',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ];

        $this->post('/setup', $payload)->assertRedirect(route('dashboard', absolute: false));
        $this->post('/setup', [...$payload, 'administrator_email' => 'other@example.test'])
            ->assertRedirect(route('login', absolute: false));

        $this->assertDatabaseCount('users', 1);
        $this->get('/register')->assertNotFound();
        $this->get('/setup')->assertRedirect(route('login', absolute: false));
    }

    public function test_setup_rejects_missing_company_and_weak_password(): void
    {
        $this->from('/setup')->post('/setup', [
            'organization_name' => '',
            'country' => 'NG',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'administrator_name' => 'Ada Okafor',
            'administrator_email' => 'ada@example.test',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors(['organization_name', 'password']);

        $this->assertFalse(InstallationState::isInstalled());
        $this->assertDatabaseCount('users', 0);
    }

    public function test_development_setup_can_seed_synthetic_demo_workflows(): void
    {
        $this->app['env'] = 'local';
        $this->withoutMiddleware(PreventRequestForgery::class);
        Storage::fake('local');

        $this->get('/setup')->assertInertia(fn (Assert $page) => $page
            ->component('Setup')
            ->where('canSeedDemoData', true));

        $this->post('/setup', [
            'organization_name' => 'Northstar Properties',
            'country' => 'NG',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'administrator_name' => 'Ada Okafor',
            'administrator_email' => 'ada@example.test',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
            'populate_demo_data' => true,
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseCount('organizations', 2);
        $this->assertDatabaseCount('clients', 14);
        $this->assertDatabaseCount('projects', 4);
        $this->assertDatabaseCount('property_units', 48);
        $this->assertDatabaseCount('subscriptions', 4);
        $this->assertDatabaseCount('tenancies', 5);
        $this->assertDatabaseCount('inspections', 8);
        $this->assertDatabaseCount('maintenance_requests', 6);
        $this->assertDatabaseCount('maintenance_work_orders', 3);
        $this->assertDatabaseCount('land_title_records', 16);
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('payments', 9);
        $this->assertDatabaseCount('rent_payments', 3);
        $this->assertDatabaseCount('organization_charge_types', 10);
        $this->assertDatabaseHas('audit_logs', ['action' => 'development_demo_data.seeded']);
        Storage::disk('local')->assertExists('demo-seed/payment-proof.png');

        app(DevelopmentDemoSeeder::class)->run();
        $this->assertDatabaseCount('clients', 14);
        $this->assertDatabaseCount('property_allocations', 0);
    }

    public function test_demo_seeding_is_hidden_and_rejected_outside_local_environment(): void
    {
        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->get('/setup')->assertInertia(fn (Assert $page) => $page
            ->component('Setup')
            ->where('canSeedDemoData', false));

        $this->post('/setup', [
            'organization_name' => 'Northstar Properties',
            'country' => 'NG',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'administrator_name' => 'Ada Okafor',
            'administrator_email' => 'ada@example.test',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
            'populate_demo_data' => true,
        ])->assertForbidden();

        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('users', 0);
    }
}
