<?php

namespace Tests\Feature;

use App\Models\LandTitleRecord;
use App\Models\PropertyAllocation;
use App\Models\PropertyUnit;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PropertyAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fully_paid_subscription_with_verified_title_requires_authorized_allocation_approval(): void
    {
        $owner = $this->installDemoOwner();
        $subscription = Subscription::query()->where('subscription_number', 'DEMO-SUB-'.now()->format('Y').'-0001')->firstOrFail();
        $unit = PropertyUnit::query()->findOrFail($subscription->property_unit_id);
        foreach ($subscription->installmentSchedules as $schedule) {
            $approvedAmount = DB::table('payments')
                ->where('installment_schedule_id', $schedule->id)
                ->where('status', 'approved')
                ->sum('amount');
            $this->assertSame(Money::toMinorUnits($schedule->amount), Money::toMinorUnits((string) $approvedAmount));
            $this->assertSame(Money::toMinorUnits($schedule->amount), Money::toMinorUnits($schedule->amount_paid));
        }

        $this->actingAs($owner)
            ->from(route('subscriptions.index', absolute: false))
            ->post(route('subscriptions.allocations.store', $subscription, absolute: false))
            ->assertRedirect(route('subscriptions.index', absolute: false))
            ->assertSessionDoesntHaveErrors();

        $allocation = PropertyAllocation::query()->where('subscription_id', $subscription->id)->firstOrFail();
        $this->assertSame('pending_approval', $allocation->status);
        $this->assertSame('under_installment', $unit->fresh()->status);

        $this->actingAs($owner)
            ->patch(route('property-allocations.approve', $allocation, absolute: false))
            ->assertRedirect(route('subscriptions.index', absolute: false));

        $this->assertSame('approved', $allocation->fresh()->status);
        $this->assertSame('completed', $subscription->fresh()->status);
        $this->assertSame('allocated', $unit->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'property_allocation.approved',
            'subject_id' => $allocation->id,
        ]);

        $this->actingAs($owner)
            ->post(route('subscriptions.allocations.store', $subscription, absolute: false))
            ->assertStatus(409);

        $this->actingAs($owner)
            ->get(route('subscriptions.index', absolute: false))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Subscriptions/Index')
                ->where('subscriptions', fn ($subscriptions): bool => $subscriptions
                    ->contains(fn (array $item): bool => $item['id'] === $subscription->id
                        && $item['allocation']['status'] === 'approved'
                        && $item['canRequestAllocation'] === false))
            );
    }

    public function test_allocation_is_blocked_until_every_installment_is_fully_paid(): void
    {
        $owner = $this->installDemoOwner();
        $subscription = Subscription::query()->where('subscription_number', 'DEMO-SUB-'.now()->format('Y').'-0002')->firstOrFail();

        $this->actingAs($owner)
            ->from(route('subscriptions.index', absolute: false))
            ->post(route('subscriptions.allocations.store', $subscription, absolute: false))
            ->assertRedirect(route('subscriptions.index', absolute: false))
            ->assertSessionHasErrors('allocation');

        $this->assertDatabaseCount('property_allocations', 0);
    }

    public function test_allocation_is_blocked_when_the_property_title_is_not_verified(): void
    {
        $owner = $this->installDemoOwner();
        $subscription = Subscription::query()->where('subscription_number', 'DEMO-SUB-'.now()->format('Y').'-0001')->firstOrFail();
        LandTitleRecord::query()
            ->where('property_unit_id', $subscription->property_unit_id)
            ->update([
                'verification_status' => 'pending',
                'verified_by_user_id' => null,
                'verified_at' => null,
            ]);

        $this->actingAs($owner)
            ->from(route('subscriptions.index', absolute: false))
            ->post(route('subscriptions.allocations.store', $subscription, absolute: false))
            ->assertRedirect(route('subscriptions.index', absolute: false))
            ->assertSessionHasErrors('allocation');

        $this->assertDatabaseCount('property_allocations', 0);
    }

    public function test_rejected_allocation_can_be_resubmitted_for_review(): void
    {
        $owner = $this->installDemoOwner();
        $subscription = Subscription::query()->where('subscription_number', 'DEMO-SUB-'.now()->format('Y').'-0001')->firstOrFail();
        $this->actingAs($owner)
            ->post(route('subscriptions.allocations.store', $subscription, absolute: false))
            ->assertRedirect();
        $allocation = PropertyAllocation::query()->where('subscription_id', $subscription->id)->firstOrFail();

        $this->actingAs($owner)
            ->patch(route('property-allocations.reject', $allocation, absolute: false), [
                'rejection_reason' => 'Confirm the boundary beacon reference before issue.',
            ])
            ->assertRedirect();

        $this->assertSame('rejected', $allocation->fresh()->status);
        $this->assertSame('under_installment', $subscription->propertyUnit->fresh()->status);

        $this->actingAs($owner)
            ->post(route('subscriptions.allocations.store', $subscription, absolute: false))
            ->assertRedirect();

        $this->assertDatabaseCount('property_allocations', 2);
        $this->assertDatabaseHas('property_allocations', [
            'subscription_id' => $subscription->id,
            'status' => 'pending_approval',
        ]);
    }

    private function installDemoOwner(): User
    {
        $this->app['env'] = 'local';
        $this->withoutMiddleware(PreventRequestForgery::class);
        Storage::fake('local');
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

        return User::query()->where('email', 'ada@example.test')->firstOrFail();
    }
}
