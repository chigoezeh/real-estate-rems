<?php

namespace App\Http\Controllers;

use App\Domain\Services\ChargePolicyService;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\InstallmentSchedule;
use App\Models\Organization;
use App\Models\OrganizationChargeType;
use App\Models\Payment;
use App\Models\Project;
use App\Models\PropertyUnit;
use App\Models\Reservation;
use App\Models\Subscription;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function __construct(private readonly ChargePolicyService $chargePolicyService) {}

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
            ->with([
                'client:id,full_name,phone',
                'project:id,name',
                'propertyUnit:id,code,phase,block,status',
                'propertyUnit.titleRecords' => fn ($query) => $query
                    ->where('verification_status', 'verified')
                    ->whereNotNull('verified_by_user_id')
                    ->whereNotNull('verified_at')
                    ->select(['id', 'organization_id', 'property_unit_id', 'reference_number']),
                'latestAllocation:id,subscription_id,land_title_record_id,allocation_reference,status,allocated_at,rejection_reason',
                'latestAllocation.landTitleRecord:id,reference_number',
            ])
            ->whereIn('organization_id', $organizationIds)
            ->latest()
            ->limit(100)
            ->get();
        $allocationSummaries = $this->allocationSummaries($subscriptions);
        $canManageAllocations = $user->hasPermission('inventory.manage');
        $subscriptions = $subscriptions->map(function (Subscription $subscription) use ($allocationSummaries, $user, $canManageAllocations): array {
            $latestAllocation = $subscription->latestAllocation;
            $summary = $allocationSummaries[$subscription->id] ?? [
                'hasSchedules' => false,
                'fullyPaid' => false,
                'ledgerReconciled' => true,
            ];
            $hasVerifiedTitle = $subscription->propertyUnit->titleRecords
                ->contains(fn ($title): bool => $title->organization_id === $subscription->organization_id);
            $allocationBlocker = match (true) {
                $subscription->status !== 'active' => 'Only active subscriptions can be allocated.',
                $subscription->propertyUnit->status !== 'under_installment' => 'The property is not under an active subscription.',
                ! $summary['hasSchedules'] => 'The subscription has no payment schedules.',
                ! $summary['ledgerReconciled'] => 'Payment ledger reconciliation is required.',
                ! $summary['fullyPaid'] => 'All installments and charges must be fully paid.',
                ! $hasVerifiedTitle => 'A verified property title record is required.',
                default => null,
            };
            $hasActiveAllocation = in_array($latestAllocation?->status, ['pending_approval', 'approved'], true);

            return [
                'id' => $subscription->id,
                'number' => $subscription->subscription_number,
                'clientName' => $subscription->client->full_name,
                'clientPhone' => $subscription->client->phone,
                'projectName' => $subscription->project->name,
                'unitCode' => trim($subscription->propertyUnit->phase.' '.$subscription->propertyUnit->block.' '.$subscription->propertyUnit->code),
                'paymentPlanMonths' => $subscription->payment_plan_months,
                'landPrice' => $subscription->land_price,
                'additionalChargesTotal' => $this->subscriptionChargeTotal($subscription),
                'charges' => $subscription->charge_snapshot ?? [],
                'status' => $subscription->status,
                'createdAt' => $subscription->created_at?->toIso8601String(),
                'allocation' => $latestAllocation === null ? null : [
                    'id' => $latestAllocation->id,
                    'reference' => $latestAllocation->allocation_reference,
                    'status' => $latestAllocation->status,
                    'titleReference' => $latestAllocation->landTitleRecord?->reference_number,
                    'allocatedAt' => $latestAllocation->allocated_at?->toDateString(),
                    'rejectionReason' => $latestAllocation->rejection_reason,
                ],
                'canRequestAllocation' => $user->hasPermission('subscriptions.manage')
                    && $allocationBlocker === null
                    && ! $hasActiveAllocation,
                'allocationBlocker' => $hasActiveAllocation ? null : $allocationBlocker,
                'canReviewAllocation' => $canManageAllocations && $latestAllocation?->status === 'pending_approval',
            ];
        })
            ->values();

        $manageableOrganizationIds = Organization::accessibleIdsFor($user, 'subscriptions.manage');
        $chargesByOrganization = $this->chargePolicyService
            ->activeForOrganizations($manageableOrganizationIds, 'sales')
            ->groupBy('organization_id');

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
                        'areaSqm' => $unit->area_sqm,
                    ])->values(),
                    'chargePolicies' => $chargesByOrganization
                        ->get($project->organization_id, collect())
                        ->map(fn (OrganizationChargeType $charge): array => $this->policyForClient($charge))
                        ->values(),
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
            'development_fee' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'deed_fee' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'survey_fee' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
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
            $policies = $this->chargePolicyService->activeForOrganizations([$project->organization_id], 'sales', true);
            $quotes = $this->chargePolicyService->validateQuotes($request->input('charge_quotes'), $policies);
            $chargeLines = [];
            $chargeSnapshots = [];

            if ($policies->isEmpty()) {
                $legacyFees = $request->validate([
                    'development_fee' => ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
                    'deed_fee' => ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
                    'survey_fee' => ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
                ]);
                foreach ([
                    'development_levy' => ['Development levy', 'development_fee'],
                    'deed_fee' => ['Deed preparation', 'deed_fee'],
                    'survey_fee' => ['Survey plan', 'survey_fee'],
                ] as $category => [$name, $field]) {
                    $amount = Money::toMinorUnits($legacyFees[$field]);
                    $snapshot = [
                        'name' => $name,
                        'category' => $category,
                        'classification' => 'legacy_company_defined',
                        'calculation_method' => 'fixed',
                        'calculated_amount' => Money::fromMinorUnits($amount),
                        'total_amount' => Money::fromMinorUnits($amount),
                        'is_recurring' => false,
                        'schedule_behavior' => 'split_across_plan',
                        'legacy_input' => true,
                    ];
                    $chargeSnapshots[] = $snapshot;
                    $chargeLines[] = [
                        'charge_type_id' => null,
                        'category' => $category,
                        'name' => $name,
                        'classification' => 'legacy_company_defined',
                        'calculation_method' => 'fixed',
                        'snapshot' => $snapshot,
                        'installment_amounts' => $this->splitCents($amount, $months),
                    ];
                }
            } else {
                foreach ($policies as $policy) {
                    $amount = $this->chargePolicyService->calculate(
                        $policy,
                        $landPriceCents,
                        $lockedUnit->area_sqm,
                        $quotes,
                    );
                    $total = $policy->is_recurring
                        ? Money::multiplyByInteger($amount, $months)
                        : $amount;
                    $snapshot = $this->chargePolicyService->snapshot(
                        $policy,
                        $amount,
                        $total,
                        $landPriceCents,
                        $lockedUnit->area_sqm,
                    );
                    $snapshot['schedule_behavior'] = $policy->is_recurring
                        ? 'repeat_each_installment'
                        : 'split_across_plan';
                    $chargeSnapshots[] = $snapshot;
                    $chargeLines[] = [
                        'charge_type_id' => $policy->id,
                        'category' => $policy->category,
                        'name' => $policy->name,
                        'classification' => $policy->classification,
                        'calculation_method' => $policy->calculation_method,
                        'snapshot' => $snapshot,
                        'installment_amounts' => $policy->is_recurring
                            ? array_fill(0, $months, $amount)
                            : $this->splitCents($amount, $months),
                    ];
                }
            }

            $legacyFeeTotals = [
                'development_fee' => 0,
                'deed_fee' => 0,
                'survey_fee' => 0,
            ];
            foreach ($chargeSnapshots as $snapshot) {
                $legacyField = match ($snapshot['category']) {
                    'development_levy' => 'development_fee',
                    'deed_fee' => 'deed_fee',
                    'survey_fee' => 'survey_fee',
                    default => null,
                };
                if ($legacyField !== null) {
                    $legacyFeeTotals[$legacyField] += Money::toMinorUnits($snapshot['total_amount']);
                }
            }

            $subscription = Subscription::create([
                'organization_id' => $project->organization_id,
                'client_id' => $client->id,
                'project_id' => $project->id,
                'property_unit_id' => $lockedUnit->id,
                'created_by_user_id' => $user->id,
                'subscription_number' => 'SUB-'.now()->format('Y').'-'.Str::upper(Str::random(8)),
                'payment_plan_months' => $months,
                'land_price' => Money::fromMinorUnits($landPriceCents),
                'development_fee' => Money::fromMinorUnits($legacyFeeTotals['development_fee']),
                'deed_fee' => Money::fromMinorUnits($legacyFeeTotals['deed_fee']),
                'survey_fee' => Money::fromMinorUnits($legacyFeeTotals['survey_fee']),
                'interest_amount' => 0,
                'charge_snapshot' => $chargeSnapshots,
                'status' => 'active',
            ]);

            foreach ($this->splitCents($landPriceCents, $months) as $index => $amountCents) {
                InstallmentSchedule::create([
                    'subscription_id' => $subscription->id,
                    'category' => 'land_installment',
                    'installment_number' => $index + 1,
                    'due_date' => $months === 1 ? today() : today()->addMonthsNoOverflow($index + 1),
                    'amount' => Money::fromMinorUnits($amountCents),
                    'status' => 'unpaid',
                ]);
            }

            foreach ($chargeLines as $chargeLine) {
                foreach ($chargeLine['installment_amounts'] as $index => $amountCents) {
                    if ($amountCents === 0) {
                        continue;
                    }

                    InstallmentSchedule::create([
                        'subscription_id' => $subscription->id,
                        'charge_type_id' => $chargeLine['charge_type_id'],
                        'charge_name_snapshot' => $chargeLine['name'],
                        'charge_classification_snapshot' => $chargeLine['classification'],
                        'charge_calculation_method_snapshot' => $chargeLine['calculation_method'],
                        'charge_policy_snapshot' => $chargeLine['snapshot'],
                        'category' => $chargeLine['category'],
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
                    'charge_count' => count($chargeSnapshots),
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

    /**
     * @param  Collection<int, Subscription>  $subscriptions
     * @return array<string, array{hasSchedules: bool, fullyPaid: bool, ledgerReconciled: bool}>
     */
    private function allocationSummaries(Collection $subscriptions): array
    {
        if ($subscriptions->isEmpty()) {
            return [];
        }

        $schedules = InstallmentSchedule::query()
            ->whereIn('subscription_id', $subscriptions->pluck('id'))
            ->get(['id', 'subscription_id', 'amount', 'amount_paid']);
        $approvedPayments = Payment::query()
            ->whereIn('installment_schedule_id', $schedules->pluck('id'))
            ->where('status', 'approved')
            ->get(['installment_schedule_id', 'amount'])
            ->groupBy('installment_schedule_id')
            ->map(fn (Collection $payments): int => $payments->sum(
                fn (Payment $payment): int => Money::toMinorUnits($payment->amount),
            ));
        $summaries = $subscriptions->mapWithKeys(fn (Subscription $subscription): array => [
            $subscription->id => [
                'hasSchedules' => false,
                'fullyPaid' => true,
                'ledgerReconciled' => true,
            ],
        ])->all();

        foreach ($schedules as $schedule) {
            $summary = &$summaries[$schedule->subscription_id];
            $summary['hasSchedules'] = true;
            $amountCents = Money::toMinorUnits($schedule->amount);
            $recordedPaidCents = Money::toMinorUnits($schedule->amount_paid);
            $ledgerPaidCents = $approvedPayments->get($schedule->id, 0);
            $summary['ledgerReconciled'] = $summary['ledgerReconciled']
                && $recordedPaidCents === $ledgerPaidCents;
            $summary['fullyPaid'] = $summary['fullyPaid'] && $ledgerPaidCents === $amountCents;
            unset($summary);
        }

        return $summaries;
    }

    private function effectiveLandPriceCents(PropertyUnit $unit): int
    {
        $basePriceCents = Money::toMinorUnits($unit->base_price);
        $cornerPremiumCents = $unit->is_corner
            ? Money::percentageOf($basePriceCents, $unit->corner_premium_percent)
            : 0;

        return $basePriceCents + $cornerPremiumCents + Money::toMinorUnits($unit->topography_premium_amount);
    }

    private function subscriptionChargeTotal(Subscription $subscription): string
    {
        if ($subscription->charge_snapshot !== null) {
            $total = array_sum(array_map(
                fn (array $charge): int => Money::toMinorUnits($charge['total_amount'] ?? '0'),
                $subscription->charge_snapshot,
            ));

            return Money::fromMinorUnits($total);
        }

        return Money::fromMinorUnits(
            Money::toMinorUnits($subscription->development_fee)
            + Money::toMinorUnits($subscription->deed_fee)
            + Money::toMinorUnits($subscription->survey_fee),
        );
    }

    /** @return array<string, mixed> */
    private function policyForClient(OrganizationChargeType $charge): array
    {
        return [
            'id' => $charge->id,
            'name' => $charge->name,
            'category' => $charge->category,
            'classification' => $charge->classification,
            'calculationMethod' => $charge->calculation_method,
            'amount' => $charge->amount,
            'isRecurring' => $charge->is_recurring,
        ];
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
