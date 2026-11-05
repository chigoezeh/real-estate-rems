<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationChargeType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChargeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_and_create_an_organization_charge_policy(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();

        $this->actingAs($owner)
            ->get('/charges')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Charges/Index')
                ->has('organizations', 1)
                ->has('charges', 0));

        $this->actingAs($owner)->post('/charges', [
            'organization_id' => $organization->id,
            'name' => '  Estate Infrastructure Levy  ',
            'category' => 'infrastructure',
            'classification' => 'company_defined',
            'calculation_method' => 'fixed',
            'amount' => '125000.00',
            'is_recurring' => false,
            'effective_from' => '2026-01-01',
            'effective_until' => '2026-12-31',
            'applies_to' => ['sales', 'rentals'],
        ])->assertRedirect(route('charges.index', absolute: false));

        $charge = OrganizationChargeType::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame('Estate Infrastructure Levy', $charge->name);
        $this->assertSame(['applies_to' => ['sales', 'rentals']], $charge->rules);
        $this->assertTrue($charge->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'charge_type.created',
            'subject_id' => $charge->id,
        ]);
    }

    public function test_owner_can_update_charge_policy_and_audit_the_change(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $charge = $this->createCharge($organization);

        $this->actingAs($owner)->put(route('charges.update', $charge, absolute: false), [
            'name' => 'Revised Infrastructure Levy',
            'category' => 'infrastructure',
            'classification' => 'contractual',
            'calculation_method' => 'percentage',
            'amount' => '2.50',
            'is_recurring' => true,
            'is_active' => false,
            'effective_from' => null,
            'effective_until' => null,
            'applies_to' => ['sales'],
        ])->assertRedirect(route('charges.index', absolute: false));

        $charge->refresh();
        $this->assertSame('Revised Infrastructure Levy', $charge->name);
        $this->assertSame('percentage', $charge->calculation_method);
        $this->assertFalse($charge->is_active);
        $this->assertSame(['applies_to' => ['sales']], $charge->rules);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'charge_type.updated',
            'subject_id' => $charge->id,
        ]);
    }

    public function test_owner_cannot_manage_an_unrelated_organization_charge(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $unrelatedOrganization = Organization::create([
            'name' => 'Unrelated Realty',
            'kind' => 'company',
            'country' => 'NG',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'locale' => 'en',
        ]);
        $charge = $this->createCharge($unrelatedOrganization);

        $this->actingAs($owner)->put(route('charges.update', $charge, absolute: false), [
            'name' => 'Unauthorized Change',
            'category' => 'infrastructure',
            'classification' => 'contractual',
            'calculation_method' => 'fixed',
            'amount' => '1000.00',
            'is_recurring' => false,
            'is_active' => true,
            'effective_from' => null,
            'effective_until' => null,
            'applies_to' => ['sales'],
        ])->assertNotFound();

        $this->actingAs($owner)->post('/charges', [
            'organization_id' => $unrelatedOrganization->id,
            'name' => 'Unauthorized Charge',
            'category' => 'infrastructure',
            'classification' => 'company_defined',
            'calculation_method' => 'fixed',
            'amount' => '1000.00',
            'is_recurring' => false,
            'applies_to' => ['sales'],
        ])->assertNotFound();

        $this->assertDatabaseMissing('organization_charge_types', [
            'organization_id' => $organization->id,
            'name' => 'Unauthorized Charge',
        ]);
        $this->assertDatabaseMissing('organization_charge_types', [
            'id' => $charge->id,
            'name' => 'Unauthorized Change',
        ]);
    }

    public function test_charge_names_must_be_unique_within_the_organization(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $this->createCharge($organization);

        $this->actingAs($owner)->post('/charges', [
            'organization_id' => $organization->id,
            'name' => 'Estate Infrastructure Levy',
            'category' => 'infrastructure',
            'classification' => 'company_defined',
            'calculation_method' => 'fixed',
            'amount' => '1000.00',
            'is_recurring' => false,
            'applies_to' => ['sales'],
        ])->assertSessionHasErrors('name');

        $this->assertSame(1, OrganizationChargeType::query()->where('organization_id', $organization->id)->count());
    }

    public function test_charge_policy_rejects_invalid_dates_and_duplicate_applicability(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();

        $this->actingAs($owner)->post('/charges', [
            'organization_id' => $organization->id,
            'name' => 'Invalid Charge',
            'category' => 'infrastructure',
            'classification' => 'company_defined',
            'calculation_method' => 'fixed',
            'amount' => '100.00',
            'is_recurring' => false,
            'effective_from' => '2026-12-31',
            'effective_until' => '2026-01-01',
            'applies_to' => ['sales', 'sales'],
        ])->assertSessionHasErrors(['effective_until', 'applies_to.1']);

        $this->assertDatabaseMissing('organization_charge_types', ['name' => 'Invalid Charge']);
    }

    public function test_user_without_charge_management_permission_is_forbidden(): void
    {
        $this->installOwner();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/charges')->assertForbidden();
    }

    private function createCharge(Organization $organization): OrganizationChargeType
    {
        return OrganizationChargeType::create([
            'organization_id' => $organization->id,
            'name' => 'Estate Infrastructure Levy',
            'category' => 'infrastructure',
            'classification' => 'company_defined',
            'calculation_method' => 'fixed',
            'amount' => '125000.00',
            'is_recurring' => false,
            'is_active' => true,
            'rules' => ['applies_to' => ['sales']],
        ]);
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
