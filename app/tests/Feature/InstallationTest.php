<?php

namespace Tests\Feature;

use App\Models\InstallationState;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
