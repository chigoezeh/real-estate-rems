<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationChargeType;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ChargeController extends Controller
{
    private const CATEGORIES = [
        'sale',
        'rental',
        'service_charge',
        'security_deposit',
        'development_levy',
        'deed_fee',
        'survey_fee',
        'title_processing',
        'infrastructure',
        'maintenance',
        'late_payment',
        'other',
    ];

    private const CLASSIFICATIONS = ['statutory', 'contractual', 'company_defined'];

    private const CALCULATION_METHODS = ['fixed', 'percentage', 'per_area', 'manual_quote'];

    private const APPLICABILITY = ['sales', 'rentals', 'maintenance'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasPermission('charges.manage'), 403);
        $organizationIds = Organization::accessibleIdsFor($user, 'charges.manage');

        return Inertia::render('Charges/Index', [
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
            'charges' => OrganizationChargeType::query()
                ->with('organization:id,name,currency')
                ->whereIn('organization_id', $organizationIds)
                ->orderBy('name')
                ->get()
                ->map(fn (OrganizationChargeType $charge): array => [
                    'id' => $charge->id,
                    'organizationId' => $charge->organization_id,
                    'organizationName' => $charge->organization->name,
                    'currency' => $charge->organization->currency,
                    'name' => $charge->name,
                    'category' => $charge->category,
                    'classification' => $charge->classification,
                    'calculationMethod' => $charge->calculation_method,
                    'amount' => $charge->amount,
                    'isRecurring' => $charge->is_recurring,
                    'isActive' => $charge->is_active,
                    'effectiveFrom' => $charge->effective_from?->toDateString(),
                    'effectiveUntil' => $charge->effective_until?->toDateString(),
                    'appliesTo' => $charge->rules['applies_to'] ?? [],
                ])
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('charges.manage'), 403);
        $request->merge(['name' => trim((string) $request->input('name', ''))]);
        $organization = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
        ]);
        $organizationIds = Organization::accessibleIdsFor($user, 'charges.manage');
        abort_unless(in_array($organization['organization_id'], $organizationIds, true), 404);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('organization_charge_types', 'name')
                    ->where('organization_id', $organization['organization_id']),
            ],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'classification' => ['required', Rule::in(self::CLASSIFICATIONS)],
            'calculation_method' => ['required', Rule::in(self::CALCULATION_METHODS)],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'is_recurring' => ['required', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'applies_to' => ['required', 'array', 'min:1', 'max:3'],
            'applies_to.*' => ['required', 'distinct', Rule::in(self::APPLICABILITY)],
        ]);

        DB::transaction(function () use ($request, $user, $organization, $validated): void {
            $charge = OrganizationChargeType::create([
                ...$this->chargeAttributes($validated),
                'organization_id' => $organization['organization_id'],
                'is_active' => true,
            ]);
            $this->audit($request, $user, $charge, 'charge_type.created', [
                'category' => $charge->category,
                'applies_to' => $charge->rules['applies_to'],
            ]);
        });

        return redirect()->route('charges.index')->with('success', 'Charge policy created.');
    }

    public function update(Request $request, OrganizationChargeType $organizationChargeType): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('charges.manage'), 403);
        $organizationIds = Organization::accessibleIdsFor($user, 'charges.manage');
        abort_unless(in_array($organizationChargeType->organization_id, $organizationIds, true), 404);
        $request->merge(['name' => trim((string) $request->input('name', ''))]);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('organization_charge_types', 'name')
                    ->where('organization_id', $organizationChargeType->organization_id)
                    ->ignore($organizationChargeType->id),
            ],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'classification' => ['required', Rule::in(self::CLASSIFICATIONS)],
            'calculation_method' => ['required', Rule::in(self::CALCULATION_METHODS)],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'is_recurring' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'applies_to' => ['required', 'array', 'min:1', 'max:3'],
            'applies_to.*' => ['required', 'distinct', Rule::in(self::APPLICABILITY)],
        ]);

        $oldAttributes = $organizationChargeType->only([
            'name', 'category', 'classification', 'calculation_method', 'amount', 'is_recurring', 'is_active',
        ]);
        DB::transaction(function () use ($request, $user, $organizationChargeType, $validated, $oldAttributes): void {
            $organizationChargeType->update($this->chargeAttributes($validated));
            $this->audit($request, $user, $organizationChargeType, 'charge_type.updated', [
                'before' => $oldAttributes,
                'after' => $organizationChargeType->only(array_keys($oldAttributes)),
                'applies_to' => $organizationChargeType->rules['applies_to'],
            ]);
        });

        return redirect()->route('charges.index')->with('success', 'Charge policy updated.');
    }

    /** @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function chargeAttributes(array $validated): array
    {
        return [
            'name' => trim($validated['name']),
            'category' => $validated['category'],
            'classification' => $validated['classification'],
            'calculation_method' => $validated['calculation_method'],
            'amount' => $validated['amount'],
            'is_recurring' => $validated['is_recurring'],
            'effective_from' => $validated['effective_from'] ?? null,
            'effective_until' => $validated['effective_until'] ?? null,
            'rules' => ['applies_to' => array_values($validated['applies_to'])],
            ...(array_key_exists('is_active', $validated) ? ['is_active' => $validated['is_active']] : []),
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Request $request, User $user, OrganizationChargeType $charge, string $action, array $metadata): void
    {
        AuditLog::create([
            'organization_id' => $charge->organization_id,
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => OrganizationChargeType::class,
            'subject_id' => $charge->id,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
