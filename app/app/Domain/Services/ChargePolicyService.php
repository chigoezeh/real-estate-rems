<?php

namespace App\Domain\Services;

use App\Models\OrganizationChargeType;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ChargePolicyService
{
    /**
     * @param  array<int, string>  $organizationIds
     * @return Collection<int, OrganizationChargeType>
     */
    public function activeForOrganizations(array $organizationIds, string $applicability, bool $lock = false): Collection
    {
        if ($organizationIds === []) {
            return collect();
        }

        $query = OrganizationChargeType::query()
            ->whereIn('organization_id', $organizationIds)
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->whereNull('effective_from')
                ->orWhereDate('effective_from', '<=', today()))
            ->where(fn ($query) => $query
                ->whereNull('effective_until')
                ->orWhereDate('effective_until', '>=', today()))
            ->orderBy('name');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()
            ->filter(fn (OrganizationChargeType $charge): bool => in_array(
                $applicability,
                $charge->rules['applies_to'] ?? [],
                true,
            ))
            ->values();
    }

    /**
     * @param  Collection<int, OrganizationChargeType>  $policies
     * @return array<string, string>
     */
    public function validateQuotes(mixed $submittedQuotes, Collection $policies): array
    {
        if ($submittedQuotes === null) {
            $submittedQuotes = [];
        }

        if (! is_array($submittedQuotes)) {
            throw ValidationException::withMessages([
                'charge_quotes' => 'Charge quotes must be provided as a list of amounts.',
            ]);
        }

        $manualPolicyIds = $policies
            ->where('calculation_method', 'manual_quote')
            ->pluck('id')
            ->all();
        $rules = ['charge_quotes' => ['array']];
        foreach ($manualPolicyIds as $policyId) {
            $rules["charge_quotes.{$policyId}"] = ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'];
        }

        $validated = Validator::make(['charge_quotes' => $submittedQuotes], $rules)->validate();
        $unknownPolicyIds = array_diff(array_keys($submittedQuotes), $manualPolicyIds);

        if ($unknownPolicyIds !== []) {
            throw ValidationException::withMessages([
                'charge_quotes' => 'Quotes may only be entered for active manual-quote policies.',
            ]);
        }

        return $validated['charge_quotes'] ?? [];
    }

    /**
     * @param  array<string, string>  $quotes
     */
    public function calculate(
        OrganizationChargeType $policy,
        int $baseAmount,
        ?string $area,
        array $quotes,
    ): int {
        return match ($policy->calculation_method) {
            'fixed' => Money::toMinorUnits($policy->amount),
            'percentage' => Money::percentageOf($baseAmount, $policy->amount),
            'per_area' => $area === null
                ? throw ValidationException::withMessages(['property_unit_id' => "An area is required to calculate {$policy->name}."])
                : Money::perArea(Money::toMinorUnits($policy->amount), $area),
            'manual_quote' => Money::toMinorUnits($quotes[$policy->id]),
            default => throw new \LogicException('Unsupported charge calculation method.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(
        OrganizationChargeType $policy,
        int $amount,
        int $totalAmount,
        int $baseAmount,
        ?string $area,
    ): array {
        return [
            'charge_type_id' => $policy->id,
            'name' => $policy->name,
            'category' => $policy->category,
            'classification' => $policy->classification,
            'calculation_method' => $policy->calculation_method,
            'policy_amount' => $policy->amount,
            'calculated_amount' => Money::fromMinorUnits($amount),
            'total_amount' => Money::fromMinorUnits($totalAmount),
            'base_amount' => Money::fromMinorUnits($baseAmount),
            'area_sqm' => $area,
            'is_recurring' => $policy->is_recurring,
            'applies_to' => $policy->rules['applies_to'] ?? [],
            'effective_from' => $policy->effective_from?->toDateString(),
            'effective_until' => $policy->effective_until?->toDateString(),
        ];
    }
}
