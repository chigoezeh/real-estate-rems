<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\OrganizationChargeType;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Money;
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

    public function test_active_sale_charge_policies_are_calculated_and_snapshotted_on_installments(): void
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
            'code' => 'HV-POLICY',
        ]);
        $unit = $project->units()->create([
            'code' => 'P-13',
            'unit_type' => 'land',
            'area_sqm' => '200.00',
            'base_price' => '2000000.00',
            'status' => 'available',
        ]);

        $fixedCharge = $this->createSaleCharge($organization->id, 'Infrastructure levy', 'development_levy', 'fixed', '600.00');
        $percentageCharge = $this->createSaleCharge($organization->id, 'Title processing', 'title_processing', 'percentage', '2.50');
        $areaCharge = $this->createSaleCharge($organization->id, 'Survey service', 'survey_fee', 'per_area', '25.50');
        $recurringCharge = $this->createSaleCharge($organization->id, 'Installment administration', 'infrastructure', 'fixed', '1000.00', true);
        $manualCharge = $this->createSaleCharge($organization->id, 'Deed processing', 'deed_fee', 'manual_quote', '0.00');

        $this->actingAs($owner)->post(route('subscriptions.store', absolute: false), [
            'client_id' => $client->id,
            'property_unit_id' => $unit->id,
            'payment_plan_months' => 3,
            'charge_quotes' => [$manualCharge->id => '900.00'],
        ])->assertRedirect(route('subscriptions.index', absolute: false));

        $subscription = Subscription::query()->firstOrFail();
        $chargeTotal = array_sum(array_map(
            fn (array $charge): int => Money::toMinorUnits($charge['total_amount']),
            $subscription->charge_snapshot ?? [],
        ));
        $this->assertSame('59600.00', Money::fromMinorUnits($chargeTotal));
        $this->assertSame('600.00', $subscription->development_fee);
        $this->assertSame('900.00', $subscription->deed_fee);
        $this->assertSame('5100.00', $subscription->survey_fee);

        foreach ([
            $fixedCharge->id => '600.00',
            $percentageCharge->id => '50000.00',
            $areaCharge->id => '5100.00',
            $recurringCharge->id => '3000.00',
            $manualCharge->id => '900.00',
        ] as $chargeTypeId => $expectedTotal) {
            $schedules = $subscription->installmentSchedules()->where('charge_type_id', $chargeTypeId)->get();
            $this->assertSame(3, $schedules->count());
            $this->assertSame(
                $expectedTotal,
                Money::fromMinorUnits($schedules->sum(fn ($schedule): int => Money::toMinorUnits($schedule->amount))),
            );
            $this->assertSame(1, $schedules->pluck('charge_name_snapshot')->unique()->count());
        }

        $recurringSnapshot = collect($subscription->charge_snapshot)->firstWhere('charge_type_id', $recurringCharge->id);
        $this->assertSame('repeat_each_installment', $recurringSnapshot['schedule_behavior']);
        $this->assertSame('3000.00', $recurringSnapshot['total_amount']);

        $fixedCharge->update(['amount' => '999999.00', 'is_active' => false]);
        $this->assertSame('200.00', $subscription->installmentSchedules()
            ->where('charge_type_id', $fixedCharge->id)
            ->firstOrFail()
            ->amount);
        $this->assertSame('600.00', collect($subscription->fresh()->charge_snapshot)
            ->firstWhere('charge_type_id', $fixedCharge->id)['total_amount']);
    }

    public function test_manual_sale_charge_requires_a_contract_quote(): void
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
            'code' => 'HV-MANUAL',
        ]);
        $unit = $project->units()->create([
            'code' => 'P-14',
            'unit_type' => 'land',
            'base_price' => '2000000.00',
            'status' => 'available',
        ]);
        $manualCharge = $this->createSaleCharge($organization->id, 'Manual legal review', 'other', 'manual_quote', '0.00');

        $this->actingAs($owner)->post(route('subscriptions.store', absolute: false), [
            'client_id' => $client->id,
            'property_unit_id' => $unit->id,
            'payment_plan_months' => 3,
        ])->assertSessionHasErrors("charge_quotes.{$manualCharge->id}");

        $this->assertDatabaseMissing('subscriptions', ['property_unit_id' => $unit->id]);
    }

    private function createSaleCharge(
        string $organizationId,
        string $name,
        string $category,
        string $method,
        string $amount,
        bool $recurring = false,
    ): OrganizationChargeType {
        return OrganizationChargeType::create([
            'organization_id' => $organizationId,
            'name' => $name,
            'category' => $category,
            'classification' => 'company_defined',
            'calculation_method' => $method,
            'amount' => $amount,
            'is_recurring' => $recurring,
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
        ])->assertRedirect(route('dashboard', absolute: false));

        return User::query()->where('email', 'ada@example.test')->firstOrFail();
    }
}
