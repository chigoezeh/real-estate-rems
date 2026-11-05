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
        $tenancies = Tenancy::query()
            ->with([
                'organization:id,currency',
                'tenant:id,full_name,phone',
                'propertyUnit:id,project_id,phase,block,code,unit_type,area_sqm',
                'propertyUnit.project:id,name',
                'renewalOf:id,tenancy_number',
                'renewals:id,tenancy_number,renewal_of_tenancy_id,status',
                'notices',
                'depositReconciliation.deductions',
            ])
            ->withCount(['renewals as draft_renewals_count' => fn ($query) => $query->where('status', 'draft')])
            ->whereIn('organization_id', $organizationIds)
            ->latest()
            ->limit(200)
            ->get();
        $depositAmountsByTenancy = DB::table('rent_schedules')
            ->join('rent_payment_allocations', 'rent_payment_allocations.rent_schedule_id', '=', 'rent_schedules.id')
            ->join('rent_payments', 'rent_payments.id', '=', 'rent_payment_allocations.rent_payment_id')
            ->where('rent_schedules.category', 'security_deposit')
            ->where('rent_payments.status', 'approved')
            ->whereIn('rent_schedules.tenancy_id', $tenancies->pluck('id'))
            ->get(['rent_schedules.tenancy_id', 'rent_payment_allocations.amount'])
            ->groupBy('tenancy_id')
            ->map(fn ($allocations): int => $allocations->sum(
                fn ($allocation): int => Money::toMinorUnits((string) $allocation->amount),
            ));
        $rentSchedulesByTenancy = RentSchedule::query()
            ->with(['payments', 'depositDeductions.reconciliation'])
            ->whereIn('tenancy_id', $tenancies->pluck('id'))
            ->where('category', 'rent')
            ->get()
            ->groupBy('tenancy_id');

        return Inertia::render('Tenancies/Index', [
            'tenancies' => $tenancies
                ->map(function (Tenancy $tenancy) use ($manageableOrganizationIds, $chargePoliciesForOrganization, $depositAmountsByTenancy, $rentSchedulesByTenancy): array {
                    $canManageTenancy = in_array($tenancy->organization_id, $manageableOrganizationIds, true);
                    $renewalStartsOn = $tenancy->ends_on?->copy()->addDay();
                    $canRenew = $canManageTenancy
                        && $tenancy->status === 'active'
                        && $renewalStartsOn !== null
                        && $tenancy->ends_on->gte(today())
                        && $tenancy->draft_renewals_count === 0
                        && ! $tenancy->notices->contains('status', 'served');
                    $handoverDate = $tenancy->notices->firstWhere('status', 'completed')?->handed_over_on;
                    $unpaidRentSchedules = $handoverDate === null ? [] : $rentSchedulesByTenancy
                        ->get($tenancy->id, collect())
                        ->filter(fn (RentSchedule $schedule): bool => $schedule->due_on->lte($handoverDate))
                        ->map(function (RentSchedule $schedule): array {
                            $paidCents = $schedule->payments
                                ->where('status', 'approved')
                                ->sum(fn (RentPayment $payment): int => Money::toMinorUnits($payment->pivot->amount));
                            $pendingCents = $schedule->payments
                                ->where('status', 'pending')
                                ->sum(fn (RentPayment $payment): int => Money::toMinorUnits($payment->pivot->amount));
                            $deductedCents = $schedule->depositDeductions
                                ->filter(fn ($deduction): bool => in_array($deduction->reconciliation->status, ['finalized', 'refunded'], true))
                                ->sum(fn ($deduction): int => Money::toMinorUnits($deduction->amount));
                            $balanceCents = max(
                                0,
                                Money::toMinorUnits($schedule->amount_due) - $paidCents - $pendingCents - $deductedCents,
                            );

                            return [
                                'id' => $schedule->id,
                                'period' => $schedule->period_starts_on->toDateString().' – '.$schedule->period_ends_on->toDateString(),
                                'amountDue' => Money::fromMinorUnits($balanceCents),
                                'pendingAmount' => Money::fromMinorUnits($pendingCents),
                            ];
                        })
                        ->filter(fn (array $schedule): bool => Money::toMinorUnits($schedule['amountDue']) > 0 && Money::toMinorUnits($schedule['pendingAmount']) === 0)
                        ->values()
                        ->all();

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
                        'unpaidRentSchedules' => $unpaidRentSchedules,
                        'depositReceivedAmount' => Money::fromMinorUnits(
                            ($depositAmountsByTenancy->get($tenancy->id) ?? 0)
                                + Money::toMinorUnits($tenancy->security_deposit_carried_forward),
                        ),
                        'depositCarriedForwardAmount' => $tenancy->security_deposit_carried_forward,
                        'notices' => $tenancy->notices
                            ->sortByDesc('notice_on')
                            ->map(fn ($notice): array => [
                                'id' => $notice->id,
                                'type' => $notice->notice_type,
                                'noticeOn' => $notice->notice_on->toDateString(),
                                'proposedVacateOn' => $notice->proposed_vacate_on->toDateString(),
                                'reason' => $notice->reason,
                                'status' => $notice->status,
                                'handedOverOn' => $notice->handed_over_on?->toDateString(),
                                'handoverNotes' => $notice->handover_notes,
                            ])
                            ->values(),
                        'depositReconciliation' => $tenancy->depositReconciliation === null ? null : [
                            'id' => $tenancy->depositReconciliation->id,
                            'status' => $tenancy->depositReconciliation->status,
                            'depositReceivedAmount' => $tenancy->depositReconciliation->deposit_received_amount,
                            'deductionTotal' => $tenancy->depositReconciliation->deduction_total,
                            'refundDueAmount' => $tenancy->depositReconciliation->refund_due_amount,
                            'refundedAt' => $tenancy->depositReconciliation->refunded_at?->toIso8601String(),
                            'refundReference' => $tenancy->depositReconciliation->refund_reference,
                            'refundNotes' => $tenancy->depositReconciliation->refund_notes,
                            'deductions' => $tenancy->depositReconciliation->deductions
                                ->map(fn ($deduction): array => [
                                    'category' => $deduction->category,
                                    'description' => $deduction->description,
                                    'amount' => $deduction->amount,
                                    'evidence_reference' => $deduction->evidence_reference,
                                    'rent_schedule_id' => $deduction->rent_schedule_id,
                                ])
                                ->values(),
                        ],
                        'currency' => $tenancy->organization->currency,
                        'renewalOfNumber' => $tenancy->renewalOf?->tenancy_number,
                        'renewedByNumber' => $tenancy->renewals->firstWhere('status', 'active')?->tenancy_number,
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
            abort_unless(
                ! $renewalOfTenancy->notices()->where('status', 'served')->exists(),
                409,
                'A tenancy with an open termination notice cannot be renewed.',
            );
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
                abort_unless(
                    ! $lockedRenewal->notices()->where('status', 'served')->exists(),
                    409,
                    'A tenancy with an open termination notice cannot be renewed.',
                );
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
            $renewalSource = $tenancy->renewal_of_tenancy_id
                ? Tenancy::query()->lockForUpdate()->findOrFail($tenancy->renewal_of_tenancy_id)
                : null;
            $lockedTenancy = Tenancy::query()->lockForUpdate()->findOrFail($tenancy->id);
            abort_unless($lockedTenancy->status === 'draft', 409, 'Only draft tenancies can be activated.');
            abort_unless($lockedTenancy->renewal_of_tenancy_id === $renewalSource?->id, 409, 'The renewal source changed before activation.');
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
                abort_unless(
                    ! $renewalSource->notices()->where('status', 'served')->exists(),
                    409,
                    'A tenancy with an open termination notice cannot be renewed.',
                );
            }

            $property = PropertyUnit::query()->lockForUpdate()->findOrFail($lockedTenancy->property_unit_id);
            if ($renewalSource !== null) {
                abort_unless($property->status === 'available' && $property->rental_status === 'occupied', 409, 'The property is no longer occupied by the tenancy being renewed.');
            } else {
                abort_unless($property->status === 'available' && $property->rental_status === 'available', 409, 'This property is no longer available for rent.');
            }
            $this->ensureNoActiveOverlap($lockedTenancy);
            $depositCarriedForwardCents = $renewalSource === null
                ? 0
                : $this->depositCarriedForwardCents($renewalSource);
            $lockedTenancy->update([
                'security_deposit_carried_forward' => Money::fromMinorUnits($depositCarriedForwardCents),
            ]);
            $this->createRentSchedules($lockedTenancy, $depositCarriedForwardCents);

            $lockedTenancy->update(['status' => 'active']);
            if ($renewalSource !== null) {
                $renewalSource->update(['status' => 'completed']);
                $this->audit($request, $user, $renewalSource, 'tenancy.renewed', [
                    'renewed_by_tenancy_id' => $lockedTenancy->id,
                    'deposit_carried_forward' => Money::fromMinorUnits($depositCarriedForwardCents),
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

    private function depositCarriedForwardCents(Tenancy $source): int
    {
        $schedules = RentSchedule::query()
            ->where('tenancy_id', $source->id)
            ->where('category', 'security_deposit')
            ->lockForUpdate()
            ->get(['id', 'amount_due']);
        $scheduleIds = $schedules->pluck('id');
        if ($scheduleIds->isEmpty()) {
            return Money::toMinorUnits($source->security_deposit_carried_forward);
        }

        $hasPendingPayment = DB::table('rent_payment_allocations')
            ->join('rent_payments', 'rent_payments.id', '=', 'rent_payment_allocations.rent_payment_id')
            ->whereIn('rent_payment_allocations.rent_schedule_id', $scheduleIds)
            ->where('rent_payments.status', 'pending')
            ->exists();
        abort_unless(! $hasPendingPayment, 409, 'Review pending security-deposit payments before activating this renewal.');

        $paidBySchedule = [];
        $allocations = DB::table('rent_payment_allocations')
            ->join('rent_payments', 'rent_payments.id', '=', 'rent_payment_allocations.rent_payment_id')
            ->whereIn('rent_payment_allocations.rent_schedule_id', $scheduleIds)
            ->where('rent_payments.status', 'approved')
            ->get(['rent_payment_allocations.rent_schedule_id', 'rent_payment_allocations.amount']);
        foreach ($allocations as $allocation) {
            $scheduleId = $allocation->rent_schedule_id;
            $paidBySchedule[$scheduleId] = ($paidBySchedule[$scheduleId] ?? 0)
                + Money::toMinorUnits((string) $allocation->amount);
        }

        $totalCents = 0;
        foreach ($schedules as $schedule) {
            $paidCents = $paidBySchedule[$schedule->id] ?? 0;
            abort_unless(
                $paidCents >= Money::toMinorUnits($schedule->amount_due),
                409,
                'Settle all security-deposit charges before activating this renewal.',
            );
            $totalCents += $paidCents;
        }

        return $totalCents + Money::toMinorUnits($source->security_deposit_carried_forward);
    }

    private function createRentSchedules(Tenancy $tenancy, int $depositCreditCents = 0): void
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
                $depositAmountCents = max(0, Money::toMinorUnits($tenancy->security_deposit) - $depositCreditCents);
                if ($depositAmountCents > 0) {
                    RentSchedule::create([
                        'tenancy_id' => $tenancy->id,
                        ...$period,
                        'category' => 'security_deposit',
                        'amount_due' => Money::fromMinorUnits($depositAmountCents),
                        'status' => 'unpaid',
                    ]);
                }
                $depositCreditCents = max(0, $depositCreditCents - Money::toMinorUnits($tenancy->security_deposit));
            }

            if ($periodCount === 1) {
                foreach ($chargeSnapshots as $charge) {
                    if (($charge['schedule_behavior'] ?? 'first_period_only') !== 'repeat_each_period') {
                        $amountCents = Money::toMinorUnits($charge['calculated_amount']);
                        if ($charge['category'] === 'security_deposit') {
                            $creditCents = min($depositCreditCents, $amountCents);
                            $amountCents -= $creditCents;
                            $depositCreditCents -= $creditCents;
                        }
                        if ($amountCents > 0) {
                            $this->createPolicyRentSchedule($tenancy, $charge, $period, Money::fromMinorUnits($amountCents));
                        }
                    }
                }
            }

            foreach ($chargeSnapshots as $charge) {
                if (($charge['schedule_behavior'] ?? '') === 'repeat_each_period') {
                    $amountCents = Money::toMinorUnits($charge['calculated_amount']);
                    if ($charge['category'] === 'security_deposit') {
                        $creditCents = min($depositCreditCents, $amountCents);
                        $amountCents -= $creditCents;
                        $depositCreditCents -= $creditCents;
                    }
                    if ($amountCents > 0) {
                        $this->createPolicyRentSchedule($tenancy, $charge, $period, Money::fromMinorUnits($amountCents));
                    }
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
