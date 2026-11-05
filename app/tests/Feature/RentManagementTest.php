<?php

namespace Tests\Feature;

use App\Domain\Services\LateFeeAssessmentService;
use App\Domain\Services\RentPaymentService;
use App\Models\Client;
use App\Models\OrganizationChargeType;
use App\Models\OrganizationLatePaymentPolicy;
use App\Models\Project;
use App\Models\PropertyUnit;
use App\Models\RentPayment;
use App\Models\RentSchedule;
use App\Models\Tenancy;
use App\Models\TenancyDepositReconciliation;
use App\Models\TenancyNotice;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_recurring_late_payment_policy_requires_a_single_occurrence(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $policy = [
            'organization_id' => $organization->id,
            'name' => 'Late rent',
            'grace_period_days' => '3',
            'calculation_method' => 'fixed',
            'amount' => '25.00',
            'maximum_occurrences' => '2',
            'maximum_fee_amount' => '',
            'applies_to' => ['rent'],
        ];

        $this->actingAs($owner)
            ->from(route('late-payment-policies.index', absolute: false))
            ->post(route('late-payment-policies.store', absolute: false), $policy)
            ->assertSessionHasErrors('maximum_occurrences');

        $this->actingAs($owner)
            ->post(route('late-payment-policies.store', absolute: false), [
                ...$policy,
                'name' => 'Single late fee',
                'maximum_occurrences' => '1',
            ])
            ->assertRedirect(route('late-payment-policies.index', absolute: false));

        $this->assertDatabaseHas('organization_late_payment_policies', [
            'organization_id' => $organization->id,
            'name' => 'Single late fee',
            'recurrence_interval_days' => null,
            'maximum_occurrences' => 1,
        ]);
    }

    public function test_late_fees_respect_grace_recurrence_cap_and_rent_payment_ledger(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $client = $this->createVerifiedClient($owner, $organization->id);
        $property = $this->createRentableProperty($organization->id);
        $tenancy = $this->createActiveTenancy($owner, $organization->id, $client, $property);
        $schedule = $this->createSchedule($tenancy, 'rent', '1000.00');
        $schedule->update(['due_on' => today()->subDays(10)->toDateString()]);
        $policy = OrganizationLatePaymentPolicy::create([
            'organization_id' => $organization->id,
            'name' => 'Rent arrears policy',
            'grace_period_days' => 5,
            'calculation_method' => 'percentage',
            'amount' => '2.50',
            'recurrence_interval_days' => 5,
            'maximum_occurrences' => 3,
            'maximum_fee_amount' => '40.00',
            'applies_to' => ['rent'],
            'is_active' => true,
        ]);
        $service = app(LateFeeAssessmentService::class);

        $this->assertSame(1, $service->assess([$organization->id]));
        $this->assertSame(0, $service->assess([$organization->id]));
        $this->assertSame(1, $service->assess([$organization->id], today()->addDays(5)));
        $this->assertSame(0, $service->assess([$organization->id], today()->addDays(10)));

        $this->assertSame(2, $schedule->lateFeeAssessments()->count());
        $this->assertSame('40.00', Money::fromMinorUnits($schedule->lateFeeCents()));
        $this->assertSame('15.00', $schedule->lateFeeAssessments()->orderByDesc('occurrence')->firstOrFail()->amount);
        $this->assertSame($policy->id, $schedule->lateFeeAssessments()->firstOrFail()->policy_snapshot['policy_id']);

        $payment = $this->createPayment($organization->id, $tenancy->id, '1040.00', 'RENT-WITH-LATE-FEES');
        app(RentPaymentService::class)->review($payment, $owner, 'approved', allocations: [
            $schedule->id => '1040.00',
        ]);
        $this->assertSame('paid', $schedule->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'late_fee.assessed',
            'subject_id' => $schedule->id,
        ]);

        $this->actingAs($owner)
            ->get(route('rent-payments.index', absolute: false))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedules', [])
                ->where('payments.0.status', 'approved'));
    }

    public function test_rent_arrears_contact_attempts_are_logged_and_scoped(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $client = $this->createVerifiedClient($owner, $organization->id);
        $property = $this->createRentableProperty($organization->id);
        $tenancy = $this->createActiveTenancy($owner, $organization->id, $client, $property);
        $schedule = $this->createSchedule($tenancy, 'rent', '1000.00');
        $schedule->update(['due_on' => today()->subDay()->toDateString()]);

        $this->actingAs($owner)->post(route('rent-schedules.contact-logs.store', $schedule, absolute: false), [
            'channel' => 'whatsapp',
            'notes' => 'Tenant advised they will pay on Friday.',
        ])->assertRedirect(route('rent-payments.index', absolute: false));

        $this->assertDatabaseHas('rent_contact_logs', [
            'organization_id' => $organization->id,
            'tenancy_id' => $tenancy->id,
            'rent_schedule_id' => $schedule->id,
            'contacted_by_user_id' => $owner->id,
            'channel' => 'whatsapp',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'rent_arrears.contact_logged',
            'subject_id' => $schedule->id,
        ]);
        $this->actingAs($owner)
            ->get(route('rent-payments.index', absolute: false))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedules.0.contactCount', 1)
                ->where('schedules.0.lastContactChannel', 'whatsapp'));
    }

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

    public function test_renewal_is_a_separate_linked_draft_and_completes_the_prior_term_on_activation(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $client = $this->createVerifiedClient($owner, $organization->id);
        $property = $this->createRentableProperty($organization->id);
        $tenancy = $this->createActiveTenancy($owner, $organization->id, $client, $property);
        $sourceDepositSchedule = $this->createSchedule($tenancy, 'security_deposit', '100.00');
        $sourceDepositPayment = $this->createPayment($organization->id, $tenancy->id, '100.00', 'RENEWAL-DEPOSIT-001');
        app(RentPaymentService::class)->review($sourceDepositPayment, $owner, 'approved', allocations: [
            $sourceDepositSchedule->id => '100.00',
        ]);
        $currentEnd = today()->addDays(30);
        $tenancy->update(['ends_on' => $currentEnd->toDateString()]);
        $property->update(['rental_status' => 'occupied']);
        $renewalStart = $currentEnd->copy()->addDay();
        $renewalEnd = $renewalStart->copy()->addYearNoOverflow()->subDay();
        $renewalData = [
            'client_id' => $client->id,
            'property_unit_id' => $property->id,
            'renewal_of_tenancy_id' => $tenancy->id,
            'starts_on' => $renewalStart->toDateString(),
            'ends_on' => $renewalEnd->toDateString(),
            'rent_frequency' => 'annual',
            'rent_amount' => '1200.00',
            'security_deposit' => '200.00',
            'service_charge' => '75.00',
        ];

        $this->actingAs($owner)
            ->get(route('tenancies.index', absolute: false))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenancies/Index')
                ->has('tenancies', 1)
                ->where('tenancies.0.renewal.startsOn', $renewalStart->toDateString())
                ->where('tenancies.0.renewal.endsOn', $renewalEnd->toDateString())
                ->where('tenancies.0.depositReceivedAmount', '100.00')
                ->where('tenancies.0.renewal.property.id', $property->id));

        $this->actingAs($owner)->post(route('tenancies.store', absolute: false), [
            ...$renewalData,
            'starts_on' => $renewalStart->copy()->addDay()->toDateString(),
        ])->assertStatus(422);

        $this->actingAs($owner)->post(route('tenancies.store', absolute: false), $renewalData)
            ->assertRedirect(route('tenancies.index', absolute: false));

        $renewal = Tenancy::query()->where('renewal_of_tenancy_id', $tenancy->id)->firstOrFail();
        $this->assertSame('draft', $renewal->status);
        $this->assertSame($tenancy->id, $renewal->renewalOf->id);
        $this->assertSame('1000.00', $tenancy->fresh()->rent_amount);
        $this->assertSame('occupied', $property->fresh()->rental_status);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'tenancy.renewal_drafted',
            'subject_id' => $renewal->id,
        ]);
        $this->actingAs($owner)
            ->get(route('tenancies.index', absolute: false))
            ->assertInertia(fn (Assert $page) => $page->where('tenancies.0.renewal', null));

        $this->actingAs($owner)->post(route('tenancies.store', absolute: false), $renewalData)
            ->assertStatus(409);

        $this->actingAs($owner)->patch(route('tenancies.activate', $renewal, absolute: false))
            ->assertStatus(409);

        $this->travelTo($renewalStart);
        $this->actingAs($owner)->patch(route('tenancies.activate', $renewal, absolute: false))
            ->assertRedirect(route('tenancies.index', absolute: false));

        $this->assertSame('completed', $tenancy->fresh()->status);
        $this->assertSame('active', $renewal->fresh()->status);
        $this->assertSame('100.00', $renewal->fresh()->security_deposit_carried_forward);
        $this->assertSame('100.00', $renewal->rentSchedules()->where('category', 'security_deposit')->firstOrFail()->amount_due);
        $this->assertSame(1, $renewal->rentSchedules()->where('category', 'rent')->count());
        $this->assertSame('occupied', $property->fresh()->rental_status);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'tenancy.renewed',
            'subject_id' => $tenancy->id,
        ]);
    }

    public function test_notice_handover_and_deposit_reconciliation_use_approved_receipts_and_auditable_refunds(): void
    {
        Storage::fake('local');
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $client = $this->createVerifiedClient($owner, $organization->id);
        $property = $this->createRentableProperty($organization->id);
        $tenancy = $this->createActiveTenancy($owner, $organization->id, $client, $property);
        $depositSchedule = $this->createSchedule($tenancy, 'security_deposit', '500.00');
        $approvedPayment = $this->createPayment($organization->id, $tenancy->id, '400.00', 'DEPOSIT-APPROVED');
        app(RentPaymentService::class)->review($approvedPayment, $owner, 'approved', allocations: [
            $depositSchedule->id => '400.00',
        ]);
        $pendingPayment = $this->createPayment($organization->id, $tenancy->id, '25.00', 'DEPOSIT-PENDING');
        $pendingPayment->schedules()->attach($depositSchedule->id, ['amount' => '25.00']);
        $finalRentSchedule = $this->createSchedule($tenancy, 'rent', '100.00');
        $finalRentSchedule->update(['due_on' => today()->subDay()->toDateString()]);
        $finalRentPayment = $this->createPayment($organization->id, $tenancy->id, '40.00', 'FINAL-RENT-PARTIAL');
        app(RentPaymentService::class)->review($finalRentPayment, $owner, 'approved', allocations: [
            $finalRentSchedule->id => '40.00',
        ]);

        $noticeData = [
            'notice_type' => 'tenant',
            'notice_on' => today()->toDateString(),
            'proposed_vacate_on' => today()->toDateString(),
            'reason' => 'Tenant is relocating for work.',
        ];
        $this->actingAs($owner)->post(route('tenancies.notices.store', $tenancy, absolute: false), $noticeData)
            ->assertRedirect(route('tenancies.index', absolute: false));
        $notice = TenancyNotice::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        $this->actingAs($owner)->post(route('tenancies.notices.store', $tenancy, absolute: false), $noticeData)
            ->assertStatus(409);

        $this->actingAs($owner)->patch(route('tenancy-notices.handover', $notice, absolute: false), [
            'handed_over_on' => today()->toDateString(),
            'handover_notes' => 'Keys returned and inspection completed.',
        ])->assertRedirect(route('tenancies.index', absolute: false));
        $this->assertSame('completed', $tenancy->fresh()->status);
        $this->assertSame('available', $property->fresh()->rental_status);
        $this->assertSame('completed', $notice->fresh()->status);

        $this->actingAs($owner)->post(route('tenancies.deposit-reconciliation.store', $tenancy, absolute: false), [
            'deductions' => [[
                'category' => 'damage',
                'description' => 'Repair of damaged door',
                'amount' => '401.00',
                'evidence_reference' => 'INSPECTION-12',
            ]],
        ])->assertSessionHasErrors('deductions');
        $this->assertDatabaseCount('tenancy_deposit_reconciliations', 0);

        $this->actingAs($owner)->post(route('tenancies.deposit-reconciliation.store', $tenancy, absolute: false), [
            'deductions' => [
                [
                    'category' => 'damage',
                    'description' => 'Repair of damaged door',
                    'amount' => '250.00',
                    'evidence_reference' => 'INSPECTION-12',
                ],
                [
                    'category' => 'unpaid_rent',
                    'description' => 'Final rent balance',
                    'amount' => '50.00',
                    'evidence_reference' => null,
                    'rent_schedule_id' => $finalRentSchedule->id,
                ],
            ],
        ])->assertRedirect(route('tenancies.index', absolute: false));

        $reconciliation = TenancyDepositReconciliation::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        $this->actingAs($owner)->patch(route('tenancies.deposit-reconciliation.finalize', $reconciliation, absolute: false))
            ->assertStatus(409);
        $this->assertSame('draft', $reconciliation->fresh()->status);

        app(RentPaymentService::class)->review($pendingPayment, $owner, 'rejected', 'Unverified duplicate receipt.');
        $this->actingAs($owner)->patch(route('tenancies.deposit-reconciliation.finalize', $reconciliation, absolute: false))
            ->assertRedirect(route('tenancies.index', absolute: false));
        $reconciliation->refresh();
        $this->assertSame('finalized', $reconciliation->status);
        $this->assertSame('400.00', $reconciliation->deposit_received_amount);
        $this->assertSame('300.00', $reconciliation->deduction_total);
        $this->assertSame('100.00', $reconciliation->refund_due_amount);
        $this->assertCount(2, $reconciliation->deductions);
        $this->assertSame('partially_paid', $finalRentSchedule->fresh()->status);
        $this->assertSame('written_off', $depositSchedule->fresh()->status);
        $this->assertSame($finalRentSchedule->id, $reconciliation->deductions()->where('category', 'unpaid_rent')->firstOrFail()->rent_schedule_id);

        $this->actingAs($owner)
            ->get(route('rent-payments.index', absolute: false))
            ->assertInertia(fn (Assert $page) => $page
                ->has('schedules', 1)
                ->where('schedules.0.amountDue', '50.00')
                ->where('schedules.0.amountPaid', '40.00')
                ->where('schedules.0.arrearsAmount', '10.00'));

        $this->actingAs($owner)->patch(route('tenancies.deposit-reconciliation.refund', $reconciliation, absolute: false), [
            'refund_reference' => 'REFUND-BANK-001',
            'refund_notes' => 'Returned by bank transfer.',
        ])->assertRedirect(route('tenancies.index', absolute: false));
        $this->assertSame('refunded', $reconciliation->fresh()->status);
        $this->assertSame('REFUND-BANK-001', $reconciliation->fresh()->refund_reference);

        $latePendingPayment = $this->createPayment($organization->id, $tenancy->id, '25.00', 'DEPOSIT-PENDING-AFTER-FINAL');
        $latePendingPayment->schedules()->attach($depositSchedule->id, ['amount' => '25.00']);
        try {
            app(RentPaymentService::class)->review($latePendingPayment, $owner, 'approved', allocations: [
                $depositSchedule->id => '25.00',
            ]);
            $this->fail('A security-deposit receipt cannot be approved after reconciliation.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->actingAs($owner)->post(route('rent-payments.store', absolute: false), [
            'rent_schedule_id' => $depositSchedule->id,
            'amount' => '50.00',
            'payment_channel' => 'bank_transfer',
            'transaction_reference' => 'DEPOSIT-LATE',
            'proof_document' => UploadedFile::fake()->create('payment.pdf', 12, 'application/pdf'),
        ])->assertStatus(409);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'tenancy.handover_completed',
            'subject_id' => $tenancy->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'tenancy.deposit_refunded',
            'subject_id' => $tenancy->id,
        ]);
    }

    public function test_early_handover_requires_a_mutual_agreement_notice(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $client = $this->createVerifiedClient($owner, $organization->id);
        $property = $this->createRentableProperty($organization->id);
        $tenancy = $this->createActiveTenancy($owner, $organization->id, $client, $property);
        $property->update(['rental_status' => 'occupied']);
        $renewalStart = $tenancy->ends_on->copy()->addDay();
        $this->actingAs($owner)->post(route('tenancies.store', absolute: false), [
            'client_id' => $client->id,
            'property_unit_id' => $property->id,
            'renewal_of_tenancy_id' => $tenancy->id,
            'starts_on' => $renewalStart->toDateString(),
            'ends_on' => $renewalStart->copy()->addYearNoOverflow()->subDay()->toDateString(),
            'rent_frequency' => 'annual',
            'rent_amount' => '1100.00',
            'security_deposit' => '100.00',
            'service_charge' => '50.00',
        ])->assertRedirect(route('tenancies.index', absolute: false));
        $renewal = Tenancy::query()->where('renewal_of_tenancy_id', $tenancy->id)->firstOrFail();
        $noticeData = [
            'notice_type' => 'tenant',
            'notice_on' => today()->toDateString(),
            'proposed_vacate_on' => today()->addDay()->toDateString(),
            'reason' => 'Tenant requested an early departure.',
        ];

        $this->actingAs($owner)->post(route('tenancies.notices.store', $tenancy, absolute: false), $noticeData)
            ->assertRedirect(route('tenancies.index', absolute: false));
        $this->assertSame('cancelled', $renewal->fresh()->status);
        $notice = TenancyNotice::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        $this->actingAs($owner)->patch(route('tenancy-notices.handover', $notice, absolute: false), [
            'handed_over_on' => today()->toDateString(),
        ])->assertStatus(422);
        $this->assertSame('active', $tenancy->fresh()->status);
        $this->assertSame('occupied', $property->fresh()->rental_status);

        $this->actingAs($owner)->patch(route('tenancy-notices.withdraw', $notice, absolute: false))
            ->assertRedirect(route('tenancies.index', absolute: false));
        $this->actingAs($owner)->post(route('tenancies.notices.store', $tenancy, absolute: false), [
            ...$noticeData,
            'notice_type' => 'mutual_agreement',
        ])->assertRedirect(route('tenancies.index', absolute: false));
        $mutualNotice = TenancyNotice::query()->where('tenancy_id', $tenancy->id)->where('status', 'served')->firstOrFail();
        $this->actingAs($owner)->patch(route('tenancy-notices.handover', $mutualNotice, absolute: false), [
            'handed_over_on' => today()->toDateString(),
            'handover_notes' => 'Early surrender agreed by both parties.',
        ])->assertRedirect(route('tenancies.index', absolute: false));
        $this->assertSame('completed', $tenancy->fresh()->status);
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

    public function test_rent_payment_index_counts_only_approved_receipts_toward_overdue_balances(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $client = $this->createVerifiedClient($owner, $organization->id);
        $property = $this->createRentableProperty($organization->id);
        $tenancy = $this->createActiveTenancy($owner, $organization->id, $client, $property);
        $schedule = $this->createSchedule($tenancy, 'rent', '100.00');
        $schedule->update(['due_on' => today()->subDay()->toDateString()]);

        $approvedPayment = $this->createPayment($organization->id, $tenancy->id, '40.00', 'RENT-APPROVED');
        app(RentPaymentService::class)->review($approvedPayment, $owner, 'approved', allocations: [
            $schedule->id => '40.00',
        ]);

        $pendingPayment = $this->createPayment($organization->id, $tenancy->id, '60.00', 'RENT-PENDING');
        $pendingPayment->schedules()->attach($schedule->id, ['amount' => '60.00']);

        $this->actingAs($owner)
            ->get(route('rent-payments.index', absolute: false))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('RentPayments/Index')
                ->has('schedules', 1)
                ->where('schedules.0.amountPaid', '40.00')
                ->where('schedules.0.pendingAmount', '60.00')
                ->where('schedules.0.arrearsAmount', '60.00')
                ->where('schedules.0.availableAmount', '0.00'));
    }

    public function test_completed_tenancy_charges_remain_visible_and_payable(): void
    {
        Storage::fake('local');
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $client = $this->createVerifiedClient($owner, $organization->id);
        $property = $this->createRentableProperty($organization->id);
        $tenancy = $this->createActiveTenancy($owner, $organization->id, $client, $property);
        $tenancy->update(['status' => 'completed']);
        $schedule = $this->createSchedule($tenancy, 'rent', '100.00');
        $schedule->update(['due_on' => today()->subDay()->toDateString()]);

        $this->actingAs($owner)->post(route('rent-payments.store', absolute: false), [
            'rent_schedule_id' => $schedule->id,
            'amount' => '100.00',
            'payment_channel' => 'bank_transfer',
            'transaction_reference' => 'COMPLETED-TENANCY-001',
            'proof_document' => UploadedFile::fake()->create('payment.pdf', 12, 'application/pdf'),
        ])->assertRedirect(route('rent-payments.index', absolute: false));

        $this->actingAs($owner)
            ->get(route('rent-payments.index', absolute: false))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('RentPayments/Index')
                ->has('schedules', 1)
                ->where('schedules.0.arrearsAmount', '100.00')
                ->where('schedules.0.pendingAmount', '100.00')
                ->where('schedules.0.availableAmount', '0.00'));
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
