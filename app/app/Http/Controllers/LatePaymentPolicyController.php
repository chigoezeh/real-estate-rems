<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationLatePaymentPolicy;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LatePaymentPolicyController extends Controller
{
    private const APPLICABLE_CHARGES = ['rent', 'service_charge'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasPermission('charges.manage'), 403);
        $organizationIds = Organization::accessibleIdsFor($user, 'charges.manage');

        return Inertia::render('LatePaymentPolicies/Index', [
            'organizations' => Organization::query()
                ->whereIn('id', $organizationIds)
                ->orderBy('name')
                ->get(['id', 'name', 'currency'])
                ->map(fn (Organization $organization): array => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'currency' => $organization->currency,
                ])
                ->values(),
            'policies' => OrganizationLatePaymentPolicy::query()
                ->with('organization:id,name,currency')
                ->whereIn('organization_id', $organizationIds)
                ->orderBy('organization_id')
                ->orderBy('name')
                ->get()
                ->map(fn (OrganizationLatePaymentPolicy $policy): array => [
                    'id' => $policy->id,
                    'organizationId' => $policy->organization_id,
                    'organizationName' => $policy->organization->name,
                    'currency' => $policy->organization->currency,
                    'name' => $policy->name,
                    'gracePeriodDays' => $policy->grace_period_days,
                    'calculationMethod' => $policy->calculation_method,
                    'amount' => $policy->amount,
                    'recurrenceIntervalDays' => $policy->recurrence_interval_days,
                    'maximumOccurrences' => $policy->maximum_occurrences,
                    'maximumFeeAmount' => $policy->maximum_fee_amount,
                    'appliesTo' => $policy->applies_to,
                    'isActive' => $policy->is_active,
                ])
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('charges.manage'), 403);
        $organization = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
        ]);
        abort_unless(
            in_array($organization['organization_id'], Organization::accessibleIdsFor($user, 'charges.manage'), true),
            404,
        );
        $validated = $this->validatePolicy($request);

        DB::transaction(function () use ($request, $user, $organization, $validated): void {
            $policy = OrganizationLatePaymentPolicy::create([
                ...$validated,
                'organization_id' => $organization['organization_id'],
                'is_active' => true,
            ]);
            $this->audit($request, $user, $policy, 'late_payment_policy.created');
        });

        return redirect()->route('late-payment-policies.index')->with('success', 'Late-payment policy created.');
    }

    public function update(Request $request, OrganizationLatePaymentPolicy $policy): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('charges.manage'), 403);
        abort_unless(
            in_array($policy->organization_id, Organization::accessibleIdsFor($user, 'charges.manage'), true),
            404,
        );
        $validated = $this->validatePolicy($request, $policy);

        DB::transaction(function () use ($request, $user, $policy, $validated): void {
            $before = $policy->only([
                'name',
                'grace_period_days',
                'calculation_method',
                'amount',
                'recurrence_interval_days',
                'maximum_occurrences',
                'maximum_fee_amount',
                'applies_to',
                'is_active',
            ]);
            $policy->update($validated);
            $this->audit($request, $user, $policy, 'late_payment_policy.updated', [
                'before' => $before,
                'after' => $policy->only(array_keys($before)),
            ]);
        });

        return redirect()->route('late-payment-policies.index')->with('success', 'Late-payment policy updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePolicy(Request $request, ?OrganizationLatePaymentPolicy $policy = null): array
    {
        $organizationId = $policy?->organization_id ?? (string) $request->input('organization_id');
        $nameRule = Rule::unique('organization_late_payment_policies', 'name')
            ->where('organization_id', $organizationId);
        if ($policy !== null) {
            $nameRule->ignore($policy->id);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                $nameRule,
            ],
            'grace_period_days' => ['required', 'integer', 'min:0', 'max:365'],
            'calculation_method' => ['required', Rule::in(['fixed', 'percentage'])],
            'amount' => [
                'required',
                'numeric',
                'gt:0',
                'max:'.($request->input('calculation_method') === 'percentage' ? '100' : '9999999999999.99'),
                'decimal:0,2',
            ],
            'recurrence_interval_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'maximum_occurrences' => ['required', 'integer', 'min:1', 'max:100'],
            'maximum_fee_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999.99', 'decimal:0,2'],
            'applies_to' => ['required', 'array', 'min:1', 'max:2'],
            'applies_to.*' => ['required', 'distinct', Rule::in(self::APPLICABLE_CHARGES)],
            ...($policy === null ? [] : ['is_active' => ['required', 'boolean']]),
        ]);
        $recurrenceIntervalDays = array_key_exists('recurrence_interval_days', $validated)
            ? $validated['recurrence_interval_days']
            : $policy?->recurrence_interval_days;
        if ($recurrenceIntervalDays === null && (int) $validated['maximum_occurrences'] !== 1) {
            throw ValidationException::withMessages([
                'maximum_occurrences' => 'A non-recurring fee must have exactly one occurrence.',
            ]);
        }

        return $validated;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(
        Request $request,
        User $user,
        OrganizationLatePaymentPolicy $policy,
        string $action,
        array $metadata = [],
    ): void {
        AuditLog::create([
            'organization_id' => $policy->organization_id,
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => OrganizationLatePaymentPolicy::class,
            'subject_id' => $policy->id,
            'metadata' => [
                'name' => $policy->name,
                ...$metadata,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
