<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InstallmentSchedule;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_approval_credits_only_its_installment_and_audits_the_decision(): void
    {
        Storage::fake('local');
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
        ]);
        $unit = $project->units()->create(['code' => 'P-12', 'unit_type' => 'land', 'base_price' => 3000, 'status' => 'under_installment']);
        $subscription = Subscription::create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'property_unit_id' => $unit->id,
            'created_by_user_id' => $owner->id,
            'subscription_number' => 'SUB-2026-PAYMENT1',
            'payment_plan_months' => 3,
            'land_price' => 3000,
            'development_fee' => 900,
            'deed_fee' => 0,
            'survey_fee' => 0,
            'status' => 'active',
        ]);
        $schedule = InstallmentSchedule::create([
            'subscription_id' => $subscription->id,
            'category' => 'land_installment',
            'installment_number' => 1,
            'due_date' => today(),
            'amount' => 1000,
            'status' => 'unpaid',
        ]);

        $this->actingAs($owner)
            ->post(route('payments.store', absolute: false), [
                'installment_schedule_id' => $schedule->id,
                'amount' => '400.00',
                'payment_method' => 'bank_transfer',
                'transaction_reference' => 'BANK-REF-001',
                'proof_document' => UploadedFile::fake()->create('deposit.pdf', 32, 'application/pdf'),
            ])
            ->assertRedirect(route('payments.index', absolute: false));

        $payment = Payment::query()->firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertSame('land_installment', $payment->payment_category);
        $this->assertSame('0.00', $schedule->fresh()->amount_paid);
        Storage::disk('local')->assertExists($payment->proof_document_path);

        $this->actingAs($owner)
            ->patch(route('payments.review', $payment, absolute: false), ['decision' => 'approved'])
            ->assertRedirect(route('payments.index', absolute: false));

        $this->assertSame('approved', $payment->fresh()->status);
        $this->assertSame('400.00', $schedule->fresh()->amount_paid);
        $this->assertSame('partially_paid', $schedule->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'payment.approved',
            'subject_id' => $payment->id,
        ]);

        $this->actingAs($owner)
            ->post(route('payments.store', absolute: false), [
                'installment_schedule_id' => $schedule->id,
                'amount' => '600.00',
                'payment_method' => 'cash_deposit',
                'proof_document' => UploadedFile::fake()->create('deposit.pdf', 32, 'application/pdf'),
            ])
            ->assertRedirect(route('payments.index', absolute: false));

        $secondPayment = Payment::query()->where('status', 'pending')->firstOrFail();
        $this->actingAs($owner)
            ->patch(route('payments.review', $secondPayment, absolute: false), [
                'decision' => 'rejected',
                'rejection_reason' => 'The deposit reference could not be verified.',
            ])
            ->assertRedirect(route('payments.index', absolute: false));

        $this->assertSame('400.00', $schedule->fresh()->amount_paid);
        $this->assertSame('partially_paid', $schedule->fresh()->status);
        $this->assertSame('The deposit reference could not be verified.', $secondPayment->fresh()->rejection_reason);
        $this->actingAs($owner)
            ->patch(route('payments.review', $secondPayment, absolute: false), ['decision' => 'approved'])
            ->assertStatus(409);

        $this->actingAs($owner)
            ->get(route('payments.index', absolute: false))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payments/Index')
                ->has('payments', 2)
                ->has('schedules', 1)
            );
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
