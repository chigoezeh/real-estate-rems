<?php

namespace App\Http\Controllers;

use App\Domain\Services\PropertyAllocationService;
use App\Models\AuditLog;
use App\Models\LandTitleRecord;
use App\Models\Organization;
use App\Models\PropertyAllocation;
use App\Models\PropertyUnit;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PropertyAllocationController extends Controller
{
    public function __construct(private readonly PropertyAllocationService $allocationService) {}

    public function store(Request $request, Subscription $subscription): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('subscriptions.manage'), 403);
        abort_unless(in_array(
            $subscription->organization_id,
            Organization::accessibleIdsFor($user, 'subscriptions.manage'),
            true,
        ), 404);

        DB::transaction(function () use ($request, $user, $subscription): void {
            $lockedSubscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $existingAllocation = PropertyAllocation::query()
                ->where('subscription_id', $lockedSubscription->id)
                ->whereIn('status', ['pending_approval', 'approved'])
                ->exists();
            abort_if($existingAllocation, 409, 'This subscription already has an active or completed allocation.');

            $eligibility = $this->allocationService->eligibility($lockedSubscription, true);
            $titleRecord = $this->requireVerifiedTitle($eligibility);

            $unitHasAllocation = PropertyAllocation::query()
                ->where('property_unit_id', $lockedSubscription->property_unit_id)
                ->where('status', 'approved')
                ->exists();
            abort_if($unitHasAllocation, 409, 'This property already has a completed allocation.');

            $allocation = PropertyAllocation::create([
                'organization_id' => $lockedSubscription->organization_id,
                'subscription_id' => $lockedSubscription->id,
                'property_unit_id' => $lockedSubscription->property_unit_id,
                'land_title_record_id' => $titleRecord->id,
                'requested_by_user_id' => $user->id,
                'allocation_reference' => 'ALLOC-'.now()->format('Y').'-'.Str::upper(Str::random(8)),
                'status' => 'pending_approval',
                'requested_at' => now(),
            ]);

            $this->audit($request, $user, $allocation, 'property_allocation.requested');
        });

        return back();
    }

    public function approve(Request $request, PropertyAllocation $propertyAllocation): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('inventory.manage'), 403);
        abort_unless(in_array(
            $propertyAllocation->organization_id,
            Organization::accessibleIdsFor($user, 'inventory.manage'),
            true,
        ), 404);

        DB::transaction(function () use ($request, $user, $propertyAllocation): void {
            $allocation = PropertyAllocation::query()->lockForUpdate()->findOrFail($propertyAllocation->id);
            abort_unless($allocation->status === 'pending_approval', 409, 'This allocation request is no longer pending.');
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($allocation->subscription_id);
            $eligibility = $this->allocationService->eligibility($subscription, true);
            $titleRecord = $this->requireVerifiedTitle($eligibility);

            $unitHasAllocation = PropertyAllocation::query()
                ->where('property_unit_id', $allocation->property_unit_id)
                ->where('status', 'approved')
                ->where('id', '!=', $allocation->id)
                ->exists();
            abort_if($unitHasAllocation, 409, 'This property already has a completed allocation.');

            $allocation->update([
                'land_title_record_id' => $titleRecord->id,
                'status' => 'approved',
                'reviewed_by_user_id' => $user->id,
                'reviewed_at' => now(),
                'allocated_at' => today(),
            ]);
            PropertyUnit::query()->whereKey($allocation->property_unit_id)->update(['status' => 'allocated']);
            $subscription->update(['status' => 'completed']);

            $this->audit($request, $user, $allocation, 'property_allocation.approved');
        });

        return back();
    }

    public function reject(Request $request, PropertyAllocation $propertyAllocation): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('inventory.manage'), 403);
        abort_unless(in_array(
            $propertyAllocation->organization_id,
            Organization::accessibleIdsFor($user, 'inventory.manage'),
            true,
        ), 404);
        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $user, $propertyAllocation, $validated): void {
            $allocation = PropertyAllocation::query()->lockForUpdate()->findOrFail($propertyAllocation->id);
            abort_unless($allocation->status === 'pending_approval', 409, 'This allocation request is no longer pending.');

            $allocation->update([
                'status' => 'rejected',
                'reviewed_by_user_id' => $user->id,
                'reviewed_at' => now(),
                'rejection_reason' => $validated['rejection_reason'],
            ]);

            $this->audit($request, $user, $allocation, 'property_allocation.rejected', [
                'reason' => $validated['rejection_reason'],
            ]);
        });

        return back();
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Request $request, User $user, PropertyAllocation $allocation, string $action, array $metadata = []): void
    {
        AuditLog::create([
            'organization_id' => $allocation->organization_id,
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => PropertyAllocation::class,
            'subject_id' => $allocation->id,
            'metadata' => [
                'allocation_reference' => $allocation->allocation_reference,
                'subscription_id' => $allocation->subscription_id,
                'property_unit_id' => $allocation->property_unit_id,
                ...$metadata,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * @param  array{reason: ?string, titleRecord: ?LandTitleRecord}  $eligibility
     */
    private function requireVerifiedTitle(array $eligibility): LandTitleRecord
    {
        if ($eligibility['reason'] !== null) {
            throw ValidationException::withMessages(['allocation' => $eligibility['reason']]);
        }

        return $eligibility['titleRecord']
            ?? throw ValidationException::withMessages(['allocation' => 'A verified property title record is required before allocation.']);
    }
}
