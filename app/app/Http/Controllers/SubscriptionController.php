<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\InstallmentSchedule;
use App\Models\Organization;
use App\Models\Project;
use App\Models\PropertyUnit;
use App\Models\Reservation;
use App\Models\Subscription;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    private const SCHEDULE_CATEGORIES = [
        'land_installment' => 'land_price',
        'development_levy' => 'development_fee',
        'deed_fee' => 'deed_fee',
        'survey_fee' => 'survey_fee',
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasPermission('subscriptions.view'), 403);
        $organizationIds = Organization::accessibleIdsFor($user, 'subscriptions.view');

        $subscriptions = Subscription::query()
            ->with(['client:id,full_name,phone', 'project:id,name', 'propertyUnit:id,code,phase,block'])
            ->whereIn('organization_id', $organizationIds)
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (Subscription $subscription): array => [
                'id' => $subscription->id,
                'number' => $subscription->subscription_number,
                'clientName' => $subscription->client->full_name,
                'clientPhone' => $subscription->client->phone,
                'projectName' => $subscription->project->name,
                'unitCode' => trim($subscription->propertyUnit->phase.' '.$subscription->propertyUnit->block.' '.$subscription->propertyUnit->code),
                'paymentPlanMonths' => $subscription->payment_plan_months,
                'landPrice' => $subscription->land_price,
                'statutoryFees' => Money::fromMinorUnits(
                    Money::toMinorUnits($subscription->development_fee)
                    + Money::toMinorUnits($subscription->deed_fee)
                    + Money::toMinorUnits($subscription->survey_fee),
                ),
                'status' => $subscription->status,
                'createdAt' => $subscription->created_at?->toIso8601String(),
            ])
            ->values();

        $manageableOrganizationIds = Organization::accessibleIdsFor($user, 'subscriptions.manage');

        return Inertia::render('Subscriptions/Index', [
            'subscriptions' => $subscriptions,
            'clients' => Client::query()
                ->whereIn('organization_id', $manageableOrganizationIds)
                ->where('kyc_status', 'verified')
                ->orderBy('full_name')
                ->get(['id', 'organization_id', 'full_name', 'phone'])
                ->map(fn (Client $client): array => [
                    'id' => $client->id,
                    'organizationId' => $client->organization_id,
                    'fullName' => $client->full_name,
                    'phone' => $client->phone,
                ])
                ->values(),
            'projects' => Project::query()
                ->with(['organization:id,name,currency', 'units' => fn ($query) => $query
                    ->whereIn('status', ['available', 'reserved'])
                    ->orderBy('phase')
                    ->orderBy('block')
                    ->orderBy('code')])
                ->whereIn('organization_id', $manageableOrganizationIds)
                ->where('status', 'active')
                ->orderBy('name')
                ->get()
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'organizationId' => $project->organization_id,
                    'organizationName' => $project->organization->name,
                    'currency' => $project->organization->currency,
                    'name' => $project->name,
                    'units' => $project->units->map(fn (PropertyUnit $unit): array => [
                        'id' => $unit->id,
                        'label' => trim($unit->phase.' '.$unit->block.' '.$unit->code),
                        'status' => $unit->status,
                        'effectivePrice' => $this->effectiveLandPrice($unit),
                    ])->values(),
                ])
                ->values(),
            'canManage' => $user->hasPermission('subscriptions.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('subscriptions.manage'), 403);

        $validated = $request->validate([
            'client_id' => ['required', 'uuid', 'exists:clients,id'],
            'property_unit_id' => ['required', 'uuid', 'exists:property_units,id'],
            'payment_plan_months' => ['required', Rule::in([1, 3, 6, 12, 24])],
            'development_fee' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'deed_fee' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'survey_fee' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        $client = Client::query()->findOrFail($validated['client_id']);
        $unit = PropertyUnit::query()->with('project')->findOrFail($validated['property_unit_id']);
        $organizationIds = Organization::accessibleIdsFor($user, 'subscriptions.manage');
        abort_unless($client->organization_id === $unit->project->organization_id, 404);
        abort_unless(in_array($client->organization_id, $organizationIds, true), 404);
        abort_unless($client->kyc_status === 'verified', 422, 'Client KYC must be verified before creating a subscription.');

        DB::transaction(function () use ($request, $user, $validated, $client, $unit): void {
            $lockedUnit = PropertyUnit::query()->lockForUpdate()->findOrFail($unit->id);
            $project = Project::query()->findOrFail($lockedUnit->project_id);
            abort_unless($project->organization_id === $client->organization_id, 404);
            abort_unless($project->status === 'active', 409, 'Subscriptions cannot be created for an inactive project.');
            abort_unless($lockedUnit->status === 'available' || $lockedUnit->status === 'reserved', 409, 'This unit is not available for subscription.');
            abort_unless(! $lockedUnit->subscriptions()->where('status', 'active')->exists(), 409, 'This unit already has an active subscription.');

            $reservation = Reservation::query()
                ->where('property_unit_id', $lockedUnit->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->latest('created_at')
                ->first();

            if ($reservation !== null) {
                abort_unless($reservation->reserved_until !== null && $reservation->reserved_until->isPast(), 409, 'This unit has an active reservation.');
                $reservation->update(['status' => 'expired', 'released_at' => now()]);
            } else {
                abort_unless($lockedUnit->status === 'available', 409, 'This unit is not available for subscription.');
            }

            $landPriceCents = $this->effectiveLandPriceCents($lockedUnit);
            $months = (int) $validated['payment_plan_months'];
            $subscription = Subscription::create([
                'organization_id' => $project->organization_id,
                'client_id' => $client->id,
                'project_id' => $project->id,
                'property_unit_id' => $lockedUnit->id,
                'created_by_user_id' => $user->id,
                'subscription_number' => 'SUB-'.now()->format('Y').'-'.Str::upper(Str::random(8)),
                'payment_plan_months' => $months,
                'land_price' => Money::fromMinorUnits($landPriceCents),
                'development_fee' => $validated['development_fee'],
                'deed_fee' => $validated['deed_fee'],
                'survey_fee' => $validated['survey_fee'],
                'interest_amount' => 0,
                'status' => 'active',
            ]);

            $totals = [
                'land_installment' => $landPriceCents,
                'development_levy' => Money::toMinorUnits($validated['development_fee']),
                'deed_fee' => Money::toMinorUnits($validated['deed_fee']),
                'survey_fee' => Money::toMinorUnits($validated['survey_fee']),
            ];

            foreach ($totals as $category => $totalCents) {
                if ($totalCents === 0) {
                    continue;
                }

                foreach ($this->splitCents($totalCents, $months) as $index => $amountCents) {
                    InstallmentSchedule::create([
                        'subscription_id' => $subscription->id,
                        'category' => $category,
                        'installment_number' => $index + 1,
                        'due_date' => $months === 1 ? today() : today()->addMonthsNoOverflow($index + 1),
                        'amount' => Money::fromMinorUnits($amountCents),
                        'status' => 'unpaid',
                    ]);
                }
            }

            $lockedUnit->update(['status' => 'under_installment']);

            AuditLog::create([
                'organization_id' => $project->organization_id,
                'user_id' => $user->id,
                'action' => 'subscription.created',
                'subject_type' => Subscription::class,
                'subject_id' => $subscription->id,
                'metadata' => [
                    'subscription_number' => $subscription->subscription_number,
                    'client_id' => $client->id,
                    'property_unit_id' => $lockedUnit->id,
                    'payment_plan_months' => $months,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        return redirect()->route('subscriptions.index');
    }

    private function effectiveLandPrice(PropertyUnit $unit): string
    {
        return Money::fromMinorUnits($this->effectiveLandPriceCents($unit));
    }

    private function effectiveLandPriceCents(PropertyUnit $unit): int
    {
        $basePriceCents = Money::toMinorUnits($unit->base_price);
        $cornerPremiumCents = $unit->is_corner
            ? Money::percentageOf($basePriceCents, $unit->corner_premium_percent)
            : 0;

        return $basePriceCents + $cornerPremiumCents + Money::toMinorUnits($unit->topography_premium_amount);
    }

    /** @return array<int, int> */
    private function splitCents(int $totalCents, int $installments): array
    {
        $baseAmount = intdiv($totalCents, $installments);
        $remainder = $totalCents % $installments;

        return array_map(
            fn (int $index): int => $baseAmount + ($index < $remainder ? 1 : 0),
            range(0, $installments - 1),
        );
    }
}
