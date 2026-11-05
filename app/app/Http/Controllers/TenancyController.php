<?php

namespace App\Http\Controllers;

use App\Domain\Services\ChargePolicyService;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationChargeType;
use App\Models\PropertyUnit;
use App\Models\RentSchedule;
use App\Models\Tenancy;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TenancyController extends Controller
{
    public function __construct(private readonly ChargePolicyService $chargePolicyService) {}

    private const FREQUENCIES = [
        'monthly' => 1,
        'quarterly' => 3,
        'semi_annual' => 6,
        'annual' => 12,
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasPermission('tenancies.view'), 403);

        $organizationIds = Organization::accessibleIdsFor($user, 'tenancies.view');
        $manageableOrganizationIds = Organization::accessibleIdsFor($user, 'tenancies.manage');
        $chargesByOrganization = $this->chargePolicyService
            ->activeForOrganizations($manageableOrganizationIds, 'rentals')
            ->groupBy('organization_id');
        $chargePoliciesForOrganization = fn (string $organizationId): array => $chargesByOrganization
            ->get($organizationId, collect())
            ->map(fn (OrganizationChargeType $charge): array => $this->policyForClient($charge))
            ->values()
            ->all();

        return Inertia::render('Tenancies/Index', [
            'tenancies' => Tenancy::query()
                ->with([
                    'organization:id,currency',
                    'tenant:id,full_name,phone',
                    'propertyUnit:id,project_id,phase,block,code,unit_type,area_sqm',
                    'propertyUnit.project:id,name',
                    'renewalOf:id,tenancy_number',
                ])
                ->withCount(['renewals as draft_renewals_count' => fn ($query) => $query->where('status', 'draft')])
                ->whereIn('organization_id', $organizationIds)
                ->latest()
                ->limit(200)
                ->get()
                ->map(function (Tenancy $tenancy) use ($manageableOrganizationIds, $chargePoliciesForOrganization): array {
                    $canManageTenancy = in_array($tenancy->organization_id, $manageableOrganizationIds, true);
                    $renewalStartsOn = $tenancy->ends_on?->copy()->addDay();
                    $canRenew = $canManageTenancy
                        && $tenancy->status === 'active'
                        && $renewalStartsOn !== null
                        && $tenancy->ends_on->gte(today())
                        && $tenancy->draft_renewals_count === 0;

                    return [
                        'id' => $tenancy->id,
                        'number' => $tenancy->tenancy_number,
                        'status' => $tenancy->status,
                        'tenantName' => $tenancy->tenant->full_name,
                        'tenantPhone' => $tenancy->tenant->phone,
                        'property' => $tenancy->propertyUnit->project->name.' · '.trim($tenancy->propertyUnit->phase.' '.$tenancy->propertyUnit->block.' '.$tenancy->propertyUnit->code),
                        'startsOn' => $tenancy->starts_on->toDateString(),
                        'endsOn' => $tenancy->ends_on?->toDateString(),
                        'frequency' => $tenancy->rent_frequency,
                        'rentAmount' => $tenancy->rent_amount,
                        'securityDeposit' => $tenancy->security_deposit,
                        'serviceCharge' => $tenancy->service_charge,
                        'additionalChargesTotal' => $this->tenancyChargeTotal($tenancy),
                        'additionalCharges' => $tenancy->terms_snapshot['charges'] ?? [],
                        'currency' => $tenancy->organization->currency,
                        'renewalOfNumber' => $tenancy->renewalOf?->tenancy_number,
                        'renewal' => $canRenew ? [
                            'clientId' => $tenancy->tenant_client_id,
                            'startsOn' => $renewalStartsOn->toDateString(),
                            'endsOn' => $renewalStartsOn->copy()->addYearNoOverflow()->subDay()->toDateString(),
                            'property' => [
                                'id' => $tenancy->propertyUnit->id,
                                'organizationId' => $tenancy->organization_id,
                                'projectName' => $tenancy->propertyUnit->project->name,
                                'currency' => $tenancy->organization->currency,
                                'label' => trim($tenancy->propertyUnit->phase.' '.$tenancy->propertyUnit->block.' '.$tenancy->propertyUnit->code),
                                'unitType' => $tenancy->propertyUnit->unit_type,
                                'areaSqm' => $tenancy->propertyUnit->area_sqm,
                                'chargePolicies' => $chargePoliciesForOrganization($tenancy->organization_id),
                            ],
                        ] : null,
                        'canManage' => $canManageTenancy,
                    ];
                })
                ->values(),
            'clients' => Client::query()
                ->whereIn('organization_id', $manageableOrganizationIds)
                ->where('kyc_status', 'verified')
                ->orderBy('full_name')
                ->get(['id', 'organization_id', 'full_name', 'phone'])
                ->map(fn (Client $client): array => [
                    'id' => $client->id,
                    'organizationId' => $client->organization_id,
                    'name' => $client->full_name,
                    'phone' => $client->phone,
                ])
                ->values(),
            'properties' => PropertyUnit::query()
                ->with(['project:id,organization_id,name', 'project.organization:id,currency'])
                ->whereHas('project', fn ($query) => $query->whereIn('organization_id', $manageableOrganizationIds)->where('status', 'active'))
                ->where('status', 'available')
                ->where('rental_status', 'available')
                ->orderBy('code')
                ->get()
                ->map(fn (PropertyUnit $property): array => [
                    'id' => $property->id,
                    'organizationId' => $property->project->organization_id,
                    'projectName' => $property->project->name,
                    'currency' => $property->project->organization->currency,
                    'label' => trim($property->phase.' '.$property->block.' '.$property->code),
                    'unitType' => $property->unit_type,
                    'areaSqm' => $property->area_sqm,
                    'chargePolicies' => $chargePoliciesForOrganization($property->project->organization_id),
                ])
                ->values(),
            'canManage' => $user->hasPermission('tenancies.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('tenancies.manage'), 403);

        $validated = $request->validate([
            'client_id' => ['required', 'uuid', 'exists:clients,id'],
            'property_unit_id' => ['required', 'uuid', 'exists:property_units,id'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'rent_frequency' => ['required', Rule::in(array_keys(self::FREQUENCIES))],
            'rent_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'security_deposit' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'service_charge' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'renewal_of_tenancy_id' => ['nullable', 'uuid', 'exists:tenancies,id'],
        ]);

        $property = PropertyUnit::query()->with('project')->findOrFail($validated['property_unit_id']);
        $client = Client::query()->findOrFail($validated['client_id']);
        $renewalOfTenancyId = $validated['renewal_of_tenancy_id'] ?? null;
        $renewalOfTenancy = $renewalOfTenancyId
            ? Tenancy::query()->findOrFail($renewalOfTenancyId)
            : null;
        $organizationId = $renewalOfTenancy?->organization_id ?? $property->project->organization_id;
        abort_unless(in_array($organizationId, Organization::accessibleIdsFor($user, 'tenancies.manage'), true), 404);
        abort_unless($property->project->organization_id === $organizationId, 404);
        abort_unless($client->organization_id === $organizationId, 404);
        if ($renewalOfTenancy !== null) {
            $this->assertRenewalSource(
                $renewalOfTenancy,
                $organizationId,
                $property->id,
                $client->id,
                $validated['starts_on'],
            );
            abort_unless($renewalOfTenancy->ends_on->gte(today()), 422, 'A renewal draft must be created before the current term ends.');
        }
        abort_unless($client->kyc_status === 'verified', 422, 'Verified client KYC is required before creating a tenancy.');
        abort_unless(
            Carbon::parse($validated['starts_on'])->addMonthsNoOverflow(60)->gte(Carbon::parse($validated['ends_on'])),
            422,
            'The initial tenancy term cannot exceed five years.',
        );

        $tenancy = DB::transaction(function () use ($request, $user, $validated, $property, $organizationId, $renewalOfTenancyId): Tenancy {
            $lockedRenewal = $renewalOfTenancyId
                ? Tenancy::query()->lockForUpdate()->findOrFail($renewalOfTenancyId)
                : null;
            $lockedProperty = PropertyUnit::query()->lockForUpdate()->findOrFail($property->id);
            if ($lockedRenewal !== null) {
                $this->assertRenewalSource(
                    $lockedRenewal,
                    $organizationId,
                    $lockedProperty->id,
                    $validated['client_id'],
                    $validated['starts_on'],
                );
                abort_unless($lockedRenewal->ends_on->gte(today()), 409, 'The current tenancy term has ended; create a new tenancy instead.');
                abort_unless(! $lockedRenewal->renewals()->where('status', 'draft')->exists(), 409, 'A renewal draft already exists for this tenancy.');
                abort_unless(
                    $lockedProperty->status === 'available' && $lockedProperty->rental_status === 'occupied',
                    409,
                    'The property is no longer occupied by the tenancy being renewed.',
                );
            } else {
                abort_unless($lockedProperty->status === 'available' && $lockedProperty->rental_status === 'available', 409, 'This property is not listed as available for rent.');
            }

            $policies = $this->chargePolicyService->activeForOrganizations([$organizationId], 'rentals', true);
            $quotes = $this->chargePolicyService->validateQuotes($request->input('charge_quotes'), $policies);
            $chargeSnapshots = [];
            $securityDepositCents = 0;
            $serviceChargeCents = 0;
            if ($policies->isEmpty()) {
                $legacyCharges = $request->validate([
                    'security_deposit' => ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
                    'service_charge' => ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
                ]);
                $securityDepositCents = Money::toMinorUnits($legacyCharges['security_deposit']);
                $serviceChargeCents = Money::toMinorUnits($legacyCharges['service_charge']);
            } else {
                $periodCount = $this->rentPeriodCount(
                    $validated['starts_on'],
                    $validated['ends_on'],
                    self::FREQUENCIES[$validated['rent_frequency']],
                );
                $rentAmountCents = Money::toMinorUnits($validated['rent_amount']);
                foreach ($policies as $policy) {
                    $amount = $this->chargePolicyService->calculate(
                        $policy,
                        $rentAmountCents,
                        $lockedProperty->area_sqm,
                        $quotes,
                    );
                    $total = $policy->is_recurring
                        ? Money::multiplyByInteger($amount, $periodCount)
                        : $amount;
                    $snapshot = $this->chargePolicyService->snapshot(
                        $policy,
                        $amount,
                        $total,
                        $rentAmountCents,
                        $lockedProperty->area_sqm,
                    );
                    $snapshot['schedule_behavior'] = $policy->is_recurring ? 'repeat_each_period' : 'first_period_only';
                    $chargeSnapshots[] = $snapshot;

                    if ($policy->category === 'security_deposit') {
                        $securityDepositCents += $total;
                    }
                    if ($policy->category === 'service_charge' && $policy->is_recurring) {
                        $serviceChargeCents += $amount;
                    }
                }
            }

            $tenancy = Tenancy::create([
                'organization_id' => $organizationId,
                'property_unit_id' => $lockedProperty->id,
                'tenant_client_id' => $validated['client_id'],
                'created_by_user_id' => $user->id,
                'renewal_of_tenancy_id' => $lockedRenewal?->id,
                'tenancy_number' => 'TEN-'.now()->format('Y').'-'.Str::upper(Str::random(8)),
                'status' => 'draft',
                'starts_on' => $validated['starts_on'],
                'ends_on' => $validated['ends_on'],
                'rent_frequency' => $validated['rent_frequency'],
                'rent_amount' => $validated['rent_amount'],
                'security_deposit' => Money::fromMinorUnits($securityDepositCents),
                'service_charge' => Money::fromMinorUnits($serviceChargeCents),
                'terms_snapshot' => [
                    'rent_frequency' => $validated['rent_frequency'],
                    'rent_amount' => $validated['rent_amount'],
                    'security_deposit' => Money::fromMinorUnits($securityDepositCents),
                    'service_charge' => Money::fromMinorUnits($serviceChargeCents),
                    'charges' => $chargeSnapshots,
                ],
            ]);

            $this->audit(
                $request,
                $user,
                $tenancy,
                $lockedRenewal ? 'tenancy.renewal_drafted' : 'tenancy.created',
                [
                    'status' => 'draft',
                    'renewal_of_tenancy_id' => $lockedRenewal?->id,
                ],
            );

            return $tenancy;
        });

        $message = $renewalOfTenancyId
            ? "Renewal for tenancy {$renewalOfTenancy?->tenancy_number} was saved as a draft."
            : "Tenancy {$tenancy->tenancy_number} was created as a draft.";

        return redirect()->route('tenancies.index')->with('success', $message);
    }

    public function activate(Request $request, Tenancy $tenancy): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('tenancies.manage'), 403);
        abort_unless(in_array($tenancy->organization_id, Organization::accessibleIdsFor($user, 'tenancies.manage'), true), 404);

        DB::transaction(function () use ($request, $user, $tenancy): void {
            $lockedTenancy = Tenancy::query()->lockForUpdate()->findOrFail($tenancy->id);
            abort_unless($lockedTenancy->status === 'draft', 409, 'Only draft tenancies can be activated.');

            $renewalSource = $lockedTenancy->renewal_of_tenancy_id
                ? Tenancy::query()->lockForUpdate()->findOrFail($lockedTenancy->renewal_of_tenancy_id)
                : null;
            if ($renewalSource !== null) {
                $this->assertRenewalSource(
                    $renewalSource,
                    $lockedTenancy->organization_id,
                    $lockedTenancy->property_unit_id,
                    $lockedTenancy->tenant_client_id,
                    $lockedTenancy->starts_on->toDateString(),
                );
                abort_unless(
                    $lockedTenancy->starts_on->lte(today()) && $lockedTenancy->ends_on?->gte(today()),
                    409,
                    'A renewal can only be activated during its new tenancy term.',
                );
            }

            $property = PropertyUnit::query()->lockForUpdate()->findOrFail($lockedTenancy->property_unit_id);
            if ($renewalSource !== null) {
                abort_unless($property->status === 'available' && $property->rental_status === 'occupied', 409, 'The property is no longer occupied by the tenancy being renewed.');
            } else {
                abort_unless($property->status === 'available' && $property->rental_status === 'available', 409, 'This property is no longer available for rent.');
            }
            $this->ensureNoActiveOverlap($lockedTenancy);
            $this->createRentSchedules($lockedTenancy);

            $lockedTenancy->update(['status' => 'active']);
            if ($renewalSource !== null) {
                $renewalSource->update(['status' => 'completed']);
                $this->audit($request, $user, $renewalSource, 'tenancy.renewed', [
                    'renewed_by_tenancy_id' => $lockedTenancy->id,
                ]);
            } else {
                $property->update(['rental_status' => 'occupied']);
            }
            $this->audit($request, $user, $lockedTenancy, 'tenancy.activated', [
                'starts_on' => $lockedTenancy->starts_on->toDateString(),
                'ends_on' => $lockedTenancy->ends_on?->toDateString(),
                'schedule_count' => $lockedTenancy->rentSchedules()->count(),
            ]);
        });

        return redirect()->route('tenancies.index')->with('success', 'Tenancy activated and rent charges scheduled.');
    }

    private function ensureNoActiveOverlap(Tenancy $tenancy): void
    {
        $overlapExists = Tenancy::query()
            ->where('property_unit_id', $tenancy->property_unit_id)
            ->where('status', 'active')
            ->whereDate('starts_on', '<=', $tenancy->ends_on)
            ->where(fn ($query) => $query
                ->whereNull('ends_on')
                ->orWhereDate('ends_on', '>=', $tenancy->starts_on))
            ->exists();

        abort_unless(! $overlapExists, 409, 'This property already has an active tenancy during the selected dates.');
    }

    private function assertRenewalSource(
        Tenancy $source,
        string $organizationId,
        string $propertyUnitId,
        string $clientId,
        string $startsOn,
    ): void {
        abort_unless(
            $source->status === 'active'
                && $source->ends_on !== null
                && $source->organization_id === $organizationId
                && $source->property_unit_id === $propertyUnitId
                && $source->tenant_client_id === $clientId,
            422,
            'A renewal must retain the active tenancy’s organization, property, and tenant.',
        );
        abort_unless(
            $startsOn === $source->ends_on->copy()->addDay()->toDateString(),
            422,
            'A renewal must start the day after the current tenancy ends.',
        );
    }

    private function createRentSchedules(Tenancy $tenancy): void
    {
        $months = self::FREQUENCIES[$tenancy->rent_frequency];
        $termStart = Carbon::parse($tenancy->starts_on);
        $periodStart = $termStart->copy();
        $termEnd = Carbon::parse($tenancy->ends_on);
        $periodCount = 0;

        while ($periodStart->lte($termEnd)) {
            $periodCount++;
            abort_unless($periodCount <= 60, 422, 'The tenancy creates too many rent periods.');

            $periodEnd = $periodStart->copy()->addMonthsNoOverflow($months)->subDay();
            if ($periodEnd->gt($termEnd)) {
                $periodEnd = $termEnd->copy();
            }

            $period = [
                'period_starts_on' => $periodStart->toDateString(),
                'period_ends_on' => $periodEnd->toDateString(),
                'due_on' => $periodStart->toDateString(),
            ];

            RentSchedule::create([
                'tenancy_id' => $tenancy->id,
                ...$period,
                'category' => 'rent',
                'amount_due' => $tenancy->rent_amount,
                'status' => 'unpaid',
            ]);

            $chargeSnapshots = $tenancy->terms_snapshot['charges'] ?? [];
            if ($chargeSnapshots === [] && Money::toMinorUnits($tenancy->service_charge) > 0) {
                RentSchedule::create([
                    'tenancy_id' => $tenancy->id,
                    ...$period,
                    'category' => 'service_charge',
                    'amount_due' => $tenancy->service_charge,
                    'status' => 'unpaid',
                ]);
            }

            if ($chargeSnapshots === [] && $periodCount === 1 && Money::toMinorUnits($tenancy->security_deposit) > 0) {
                RentSchedule::create([
                    'tenancy_id' => $tenancy->id,
                    ...$period,
                    'category' => 'security_deposit',
                    'amount_due' => $tenancy->security_deposit,
                    'status' => 'unpaid',
                ]);
            }

            if ($periodCount === 1) {
                foreach ($chargeSnapshots as $charge) {
                    if (($charge['schedule_behavior'] ?? 'first_period_only') !== 'repeat_each_period'
                        && Money::toMinorUnits($charge['calculated_amount']) > 0) {
                        $this->createPolicyRentSchedule($tenancy, $charge, $period, $charge['calculated_amount']);
                    }
                }
            }

            foreach ($chargeSnapshots as $charge) {
                if (($charge['schedule_behavior'] ?? '') === 'repeat_each_period'
                    && Money::toMinorUnits($charge['calculated_amount']) > 0) {
                    $this->createPolicyRentSchedule($tenancy, $charge, $period, $charge['calculated_amount']);
                }
            }

            $periodStart = $termStart->copy()->addMonthsNoOverflow($months * $periodCount);
        }
    }

    /**
     * @param  array<string, mixed>  $charge
     * @param  array<string, string>  $period
     */
    private function createPolicyRentSchedule(Tenancy $tenancy, array $charge, array $period, string $amount): void
    {
        RentSchedule::create([
            'tenancy_id' => $tenancy->id,
            'charge_type_id' => $charge['charge_type_id'],
            'charge_name_snapshot' => $charge['name'],
            'charge_classification_snapshot' => $charge['classification'],
            'charge_calculation_method_snapshot' => $charge['calculation_method'],
            'charge_policy_snapshot' => $charge,
            'category' => $charge['category'],
            ...$period,
            'amount_due' => $amount,
            'status' => 'unpaid',
        ]);
    }

    private function rentPeriodCount(string $startsOn, string $endsOn, int $months): int
    {
        $termStart = Carbon::parse($startsOn);
        $termEnd = Carbon::parse($endsOn);
        $periodStart = $termStart->copy();
        $periodCount = 0;

        while ($periodStart->lte($termEnd)) {
            $periodCount++;
            abort_unless($periodCount <= 60, 422, 'The tenancy creates too many rent periods.');
            $periodStart = $termStart->copy()->addMonthsNoOverflow($months * $periodCount);
        }

        return $periodCount;
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

    private function tenancyChargeTotal(Tenancy $tenancy): string
    {
        $total = array_sum(array_map(
            fn (array $charge): int => Money::toMinorUnits($charge['total_amount'] ?? '0'),
            $tenancy->terms_snapshot['charges'] ?? [],
        ));

        return Money::fromMinorUnits($total);
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Request $request, User $user, Tenancy $tenancy, string $action, array $metadata): void
    {
        AuditLog::create([
            'organization_id' => $tenancy->organization_id,
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => Tenancy::class,
            'subject_id' => $tenancy->id,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
