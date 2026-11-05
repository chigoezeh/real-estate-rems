<?php

namespace Tests\Feature;

use App\Domain\Services\RentPaymentService;
use App\Models\Client;
use App\Models\OrganizationChargeType;
use App\Models\Project;
use App\Models\PropertyUnit;
use App\Models\RentPayment;
use App\Models\RentSchedule;
use App\Models\Tenancy;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_rent_payments_are_allocated_once_and_cannot_overpay_a_charge(): void
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
            'code' => 'HV-RENT',
        ]);
        $property = $project->units()->create([
            'code' => 'A-12',
            'unit_type' => 'apartment',
            'base_price' => 100000000,
            'status' => 'available',
        ]);
        $tenancy = Tenancy::create([
            'organization_id' => $organization->id,
            'property_unit_id' => $property->id,
            'tenant_client_id' => $client->id,
            'created_by_user_id' => $owner->id,
            'tenancy_number' => 'TEN-2026-001',
            'status' => 'active',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
            'rent_frequency' => 'monthly',
            'rent_amount' => 1000,
            'security_deposit' => 100,
            'service_charge' => 50,
        ]);
        $rent = $this->createSchedule($tenancy, 'rent', '100.00');
        $serviceCharge = $this->createSchedule($tenancy, 'service_charge', '50.00');
        $payment = $this->createPayment($organization->id, $tenancy->id, '120.00', 'RENT-001');
        $service = app(RentPaymentService::class);

        $service->review($payment, $owner, 'approved', allocations: [
            $rent->id => '100.00',
            $serviceCharge->id => '20.00',
        ]);

        $this->assertSame('approved', $payment->fresh()->status);
        $this->assertSame('paid', $rent->fresh()->status);
        $this->assertSame('partially_paid', $serviceCharge->fresh()->status);
        $this->assertSame(2, $payment->fresh()->schedules()->count());
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'rent_payment.approved',
            'subject_id' => $payment->id,
        ]);

        $excessPayment = $this->createPayment($organization->id, $tenancy->id, '31.00', 'RENT-002');

        try {
            $service->review($excessPayment, $owner, 'approved', allocations: [
                $serviceCharge->id => '31.00',
            ]);
            $this->fail('An allocation greater than the outstanding charge should be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame('pending', $excessPayment->fresh()->status);
        $this->assertSame(2, $payment->fresh()->schedules()->count());
        $this->assertDatabaseMissing('rent_payment_allocations', [
            'rent_payment_id' => $excessPayment->id,
        ]);
    }

    public function test_tenancy_activation_generates_rent_service_and_deposit_schedules(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $client = $this->createVerifiedClient($owner, $organization->id);
        $property = $this->createRentableProperty($organization->id);
        $start = today()->endOfMonth();
        $end = $start->copy()->addMonthsNoOverflow(3)->subDay();

        $this->actingAs($owner)->post(route('tenancies.store', absolute: false), [
            'client_id' => $client->id,
            'property_unit_id' => $property->id,
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
            'rent_frequency' => 'monthly',
            'rent_amount' => '1000.00',
            'security_deposit' => '250.00',
            'service_charge' => '75.00',
        ])->assertRedirect(route('tenancies.index', absolute: false));

        $tenancy = Tenancy::query()->firstOrFail();
        $this->assertSame('draft', $tenancy->status);

        $this->actingAs($owner)->patch(route('tenancies.activate', $tenancy, absolute: false))
            ->assertRedirect(route('tenancies.index', absolute: false));

        $this->assertSame('active', $tenancy->fresh()->status);
        $this->assertSame('occupied', $property->fresh()->rental_status);
        $this->assertSame(3, $tenancy->rentSchedules()->where('category', 'rent')->count());
        $this->assertSame(3, $tenancy->rentSchedules()->where('category', 'service_charge')->count());
        $this->assertSame(1, $tenancy->rentSchedules()->where('category', 'security_deposit')->count());
        $this->assertSame(
            $start->copy()->addMonthsNoOverflow(2)->toDateString(),
            $tenancy->rentSchedules()->where('category', 'rent')->orderBy('period_starts_on')->skip(2)->firstOrFail()->period_starts_on->toDateString(),
        );
    }

    public function test_rental_charge_policies_are_snapshotted_and_generate_recurring_or_one_time_schedules(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $client = $this->createVerifiedClient($owner, $organization->id);
        $property = $this->createRentableProperty($organization->id);
        $property->update(['area_sqm' => '80.41']);
        $start = today()->startOfMonth();
        $end = $start->copy()->addMonthsNoOverflow(3)->subDay();

        $serviceCharge = $this->createRentalCharge($organization->id, 'Estate service charge', 'service_charge', 'fixed', '75.00', true);
        $areaCharge = $this->createRentalCharge($organization->id, 'Common area levy', 'infrastructure', 'per_area', '2.50', true);
        $depositCharge = $this->createRentalCharge($organization->id, 'Security deposit', 'security_deposit', 'manual_quote', '0.00');

        $this->actingAs($owner)->post(route('tenancies.store', absolute: false), [
            'client_id' => $client->id,
            'property_unit_id' => $property->id,
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
            'rent_frequency' => 'monthly',
            'rent_amount' => '1000.00',
            'charge_quotes' => [$depositCharge->id => '125.00'],
        ])->assertRedirect(route('tenancies.index', absolute: false));

        $tenancy = Tenancy::query()->firstOrFail();
        $this->assertSame('125.00', $tenancy->security_deposit);
        $this->assertSame('75.00', $tenancy->service_charge);
        $chargeTotal = array_sum(array_map(
            fn (array $charge): int => Money::toMinorUnits($charge['total_amount']),
            $tenancy->terms_snapshot['charges'],
        ));
        $this->assertSame('953.09', Money::fromMinorUnits($chargeTotal));

        $serviceCharge->update(['amount' => '999.00', 'is_active' => false]);
        $this->actingAs($owner)->patch(route('tenancies.activate', $tenancy, absolute: false))
            ->assertRedirect(route('tenancies.index', absolute: false));

        $this->assertSame(3, $tenancy->rentSchedules()->where('category', 'rent')->count());
        $serviceSchedules = $tenancy->rentSchedules()->where('charge_type_id', $serviceCharge->id)->get();
        $areaSchedules = $tenancy->rentSchedules()->where('charge_type_id', $areaCharge->id)->get();
        $depositSchedules = $tenancy->rentSchedules()->where('charge_type_id', $depositCharge->id)->get();
        $this->assertCount(3, $serviceSchedules);
        $this->assertCount(3, $areaSchedules);
        $this->assertCount(1, $depositSchedules);
        $this->assertSame('225.00', Money::fromMinorUnits(
            $serviceSchedules->sum(fn (RentSchedule $schedule): int => Money::toMinorUnits($schedule->amount_due)),
        ));
        $this->assertSame('603.09', Money::fromMinorUnits(
            $areaSchedules->sum(fn (RentSchedule $schedule): int => Money::toMinorUnits($schedule->amount_due)),
        ));
        $this->assertSame('125.00', $depositSchedules->firstOrFail()->amount_due);
        $this->assertSame('Estate service charge', $serviceSchedules->firstOrFail()->charge_name_snapshot);
        $this->assertSame('75.00', $serviceSchedules->firstOrFail()->charge_policy_snapshot['calculated_amount']);
    }

    public function test_rent_payment_submission_reserves_balance_and_proof_is_private(): void
    {
        Storage::fake('local');
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $client = $this->createVerifiedClient($owner, $organization->id);
        $property = $this->createRentableProperty($organization->id);
        $tenancy = $this->createActiveTenancy($owner, $organization->id, $client, $property);
        $schedule = $this->createSchedule($tenancy, 'rent', '100.00');

        $this->actingAs($owner)->post(route('rent-payments.store', absolute: false), [
            'rent_schedule_id' => $schedule->id,
            'amount' => '60.00',
            'payment_channel' => 'bank_transfer',
            'transaction_reference' => 'BANK-RENT-001',
            'proof_document' => UploadedFile::fake()->create('payment.pdf', 12, 'application/pdf'),
        ])->assertRedirect(route('rent-payments.index', absolute: false));

        $payment = RentPayment::query()->firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertEquals(60, $payment->schedules()->firstOrFail()->pivot->amount);
        $this->actingAs($owner)->get(route('rent-payments.proof', $payment, absolute: false))->assertOk();

        $this->actingAs($owner)->patch(route('rent-payments.review', $payment, absolute: false), [
            'decision' => 'approved',
        ])->assertRedirect(route('rent-payments.index', absolute: false));

        $this->assertSame('approved', $payment->fresh()->status);
        $this->assertSame('partially_paid', $schedule->fresh()->status);

        $this->actingAs($owner)->post(route('rent-payments.store', absolute: false), [
            'rent_schedule_id' => $schedule->id,
            'amount' => '50.00',
            'payment_channel' => 'bank_transfer',
            'transaction_reference' => 'BANK-RENT-002',
            'proof_document' => UploadedFile::fake()->create('payment.pdf', 12, 'application/pdf'),
        ])->assertStatus(409);
    }

    private function createSchedule(Tenancy $tenancy, string $category, string $amount): RentSchedule
    {
        static $periodIndex = 0;
        $periodIndex++;
        $start = sprintf('2026-%02d-01', $periodIndex);

        return RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'category' => $category,
            'period_starts_on' => $start,
            'period_ends_on' => date('Y-m-t', strtotime($start)),
            'due_on' => $start,
            'amount_due' => $amount,
        ]);
    }

    private function createPayment(string $organizationId, string $tenancyId, string $amount, string $reference): RentPayment
    {
        return RentPayment::create([
            'organization_id' => $organizationId,
            'tenancy_id' => $tenancyId,
            'amount' => $amount,
            'payment_channel' => 'bank_transfer',
            'transaction_reference' => $reference,
        ]);
    }

    private function installOwner(): User
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

        return User::query()->where('email', 'ada@example.test')->firstOrFail();
    }

    private function createVerifiedClient(User $owner, string $organizationId): Client
    {
        return Client::create([
            'organization_id' => $organizationId,
            'created_by_user_id' => $owner->id,
            'full_name' => 'Chinwe Okafor',
            'phone' => '+2348012345678',
            'identity_type' => 'nin',
            'identity_number' => '12345678901',
            'kyc_status' => 'verified',
        ]);
    }

    private function createRentableProperty(string $organizationId): PropertyUnit
    {
        $project = Project::create([
            'organization_id' => $organizationId,
            'name' => 'Harbour View',
            'code' => 'HV-RENT',
        ]);

        return $project->units()->create([
            'code' => 'A-12',
            'unit_type' => 'apartment',
            'base_price' => 100000000,
            'status' => 'available',
            'rental_status' => 'available',
        ]);
    }

    private function createActiveTenancy(User $owner, string $organizationId, Client $client, PropertyUnit $property): Tenancy
    {
        return Tenancy::create([
            'organization_id' => $organizationId,
            'property_unit_id' => $property->id,
            'tenant_client_id' => $client->id,
            'created_by_user_id' => $owner->id,
            'tenancy_number' => 'TEN-'.strtoupper(substr($property->id, 0, 8)),
            'status' => 'active',
            'starts_on' => today()->toDateString(),
            'ends_on' => today()->addYear()->toDateString(),
            'rent_frequency' => 'monthly',
            'rent_amount' => 1000,
            'security_deposit' => 100,
            'service_charge' => 50,
        ]);
    }

    private function createRentalCharge(
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
            'rules' => ['applies_to' => ['rentals']],
        ]);
    }
}
