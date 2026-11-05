<?php

namespace Database\Seeders;

use App\Domain\Services\ChargePolicyService;
use App\Models\Amenity;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Client;
use App\Models\Inspection;
use App\Models\InstallmentSchedule;
use App\Models\LandTitleRecord;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceWorkOrder;
use App\Models\Organization;
use App\Models\OrganizationChargeType;
use App\Models\Payment;
use App\Models\Project;
use App\Models\PropertyPrototype;
use App\Models\PropertyUnit;
use App\Models\Province;
use App\Models\RentPayment;
use App\Models\RentSchedule;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\State;
use App\Models\Subscription;
use App\Models\Tenancy;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DevelopmentDemoSeeder extends Seeder
{
    private const PROOF_PATH = 'demo-seed/payment-proof.png';

    public function run(): void
    {
        $this->ensureDevelopmentEnvironment();
        $organization = Organization::query()->whereNull('parent_id')->orderBy('created_at')->firstOrFail();
        $owner = $organization->members()->wherePivot('status', 'active')->firstOrFail();

        $this->seed($organization, $owner);
    }

    public function seed(Organization $organization, User $owner): void
    {
        $this->ensureDevelopmentEnvironment();

        if ($this->wasSeeded($organization)) {
            return;
        }

        DB::transaction(function () use ($organization, $owner): void {
            if ($this->wasSeeded($organization)) {
                return;
            }

            $branch = $this->createBranch($organization, $owner);
            $clients = $this->createClients($organization, $owner);
            $projects = $this->createProjects($organization, $branch, $owner);
            $policies = $this->createChargePolicies($organization);
            $this->createProofFile();
            $subscriptions = $this->createSubscriptions($organization, $owner, $clients, $projects, $policies);
            $this->createReservation($organization, $owner, $projects);
            $this->createInspections($organization, $owner, $clients, $projects);
            $tenancies = $this->createTenancies($organization, $owner, $clients, $projects, $policies);
            $this->createRentPayments($organization, $owner, $tenancies);
            $this->createMaintenanceRecords($organization, $owner, $clients, $projects, $tenancies);

            AuditLog::create([
                'organization_id' => $organization->id,
                'user_id' => $owner->id,
                'action' => 'development_demo_data.seeded',
                'subject_type' => Organization::class,
                'subject_id' => $organization->id,
                'metadata' => [
                    'branch_id' => $branch->id,
                    'client_count' => $clients->count(),
                    'project_count' => count($projects),
                    'subscription_count' => count($subscriptions),
                    'tenancy_count' => count($tenancies),
                ],
            ]);
        });
    }

    private function ensureDevelopmentEnvironment(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Development demo data can only be seeded when APP_ENV is local.');
        }
    }

    private function wasSeeded(Organization $organization): bool
    {
        return AuditLog::query()
            ->where('organization_id', $organization->id)
            ->where('action', 'development_demo_data.seeded')
            ->exists();
    }

    private function createBranch(Organization $organization, User $owner): Organization
    {
        $branch = Organization::create([
            'parent_id' => $organization->id,
            'name' => 'Northstar Abuja Branch',
            'kind' => 'branch',
            'country' => $organization->country,
            'currency' => $organization->currency,
            'timezone' => $organization->timezone,
            'locale' => $organization->locale,
            'is_active' => true,
        ]);
        $ownerRole = $organization->roles()->where('key', 'owner')->firstOrFail();
        $branchRole = Role::create([
            'organization_id' => $branch->id,
            'name' => 'Branch Manager',
            'key' => 'branch-manager',
            'description' => 'Demo role with branch management access.',
            'is_system' => false,
        ]);
        $branchRole->permissions()->sync($ownerRole->permissions()->pluck('permissions.id'));
        $branch->members()->attach($owner->id, [
            'role_id' => $branchRole->id,
            'status' => 'active',
        ]);

        return $branch;
    }

    /** @return Collection<int, Client> */
    private function createClients(Organization $organization, User $owner): Collection
    {
        $names = [
            'Adaeze Okafor',
            'Chinedu Eze',
            'Bisi Akinola',
            'Tunde Balogun',
            'Amaka Nwosu',
            'Ibrahim Musa',
            'Zainab Bello',
            'Kelechi Obi',
            'Fatima Yusuf',
            'Emeka Umeh',
            'Ngozi Ibe',
            'Seyi Adeyemi',
            'Amina Sule',
            'Obinna Nwachukwu',
        ];

        return collect($names)->values()->map(function (string $name, int $index) use ($organization, $owner): Client {
            $status = match (true) {
                $index < 9 => 'verified',
                $index < 12 => 'pending',
                default => 'rejected',
            };

            return Client::create([
                'organization_id' => $organization->id,
                'created_by_user_id' => $owner->id,
                'kyc_reviewed_by_user_id' => $status === 'pending' ? null : $owner->id,
                'full_name' => $name,
                'email' => 'demo.client.'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).'@example.test',
                'phone' => '+234800000'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'address' => 'Demo address '.($index + 1).', Nigeria',
                'next_of_kin_name' => 'Demo Contact '.($index + 1),
                'next_of_kin_phone' => '+234801000'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'identity_type' => 'nin',
                'identity_number' => '990000000'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'kyc_status' => $status,
                'kyc_reviewed_at' => $status === 'pending' ? null : now()->subDays($index + 1),
                'kyc_rejection_reason' => $status === 'rejected' ? 'Demo record awaiting corrected identity information.' : null,
            ]);
        });
    }

    /**
     * @return array<int, array{project: Project, units: array<int, PropertyUnit>}>
     */
    private function createProjects(Organization $organization, Organization $branch, User $owner): array
    {
        return [
            $this->createProject($organization, 'Lagos Coastal Gardens', 'DEMO-LAGOS', 'Lagos', 'Lekki', $owner),
            $this->createProject($organization, 'Abuja Savanna Residences', 'DEMO-ABUJA', 'Federal Capital Territory', 'Abuja', $owner),
            $this->createProject($organization, 'Port Harcourt Waterfront', 'DEMO-RIVERS', 'Rivers', 'Port Harcourt', $owner),
            $this->createProject($branch, 'Abuja Branch Heights', 'DEMO-BRANCH', 'Federal Capital Territory', 'Gwarinpa', $owner),
        ];
    }

    /** @return array{project: Project, units: array<int, PropertyUnit>} */
    private function createProject(Organization $organization, string $name, string $code, string $stateName, string $cityName, User $owner): array
    {
        $state = State::query()->where('country_code', 'NG')->where('name', $stateName)->firstOrFail();
        $province = Province::query()->where('state_id', $state->id)->orderBy('name')->firstOrFail();
        $city = City::query()->firstOrCreate(
            ['country_code' => 'NG', 'state_id' => $state->id, 'name' => $cityName],
            ['place_type' => 'city'],
        );
        $city->provinces()->syncWithoutDetaching([$province->id]);

        $project = Project::create([
            'organization_id' => $organization->id,
            'developer_organization_id' => $organization->id,
            'name' => $name,
            'code' => $code,
            'location' => $cityName.', '.$stateName,
            'district_or_area' => $cityName,
            'street_address' => 'Demo Estate Road',
            'country_code' => 'NG',
            'state_id' => $state->id,
            'province_id' => $province->id,
            'city_id' => $city->id,
            'description' => 'Synthetic development records created for local testing only.',
            'status' => 'active',
            'settings' => ['demo_data' => true],
        ]);

        $landPrototype = PropertyPrototype::create([
            'project_id' => $project->id,
            'name' => 'Residential plot',
            'bedrooms' => 0,
            'bathrooms' => 0,
            'toilets' => 0,
            'living_rooms' => 0,
            'has_bq' => false,
            'floor_area_sqm' => 450,
            'base_price' => 25000000,
            'description' => 'Serviced residential plot.',
        ]);
        $housePrototype = PropertyPrototype::create([
            'project_id' => $project->id,
            'name' => 'Three-bedroom terrace',
            'bedrooms' => 3,
            'bathrooms' => 3,
            'toilets' => 4,
            'living_rooms' => 1,
            'has_bq' => true,
            'floor_area_sqm' => 185,
            'base_price' => 95000000,
            'description' => 'Terrace home in a managed estate.',
        ]);
        $apartmentPrototype = PropertyPrototype::create([
            'project_id' => $project->id,
            'name' => 'Two-bedroom apartment',
            'bedrooms' => 2,
            'bathrooms' => 2,
            'toilets' => 2,
            'living_rooms' => 1,
            'has_bq' => false,
            'floor_area_sqm' => 110,
            'base_price' => 65000000,
            'description' => 'Apartment with estate facilities.',
        ]);

        $units = [];
        for ($index = 1; $index <= 12; $index++) {
            $isLand = $index <= 4;
            $isHouse = $index > 4 && $index <= 8;
            $type = $isLand ? 'land' : ($isHouse ? 'house' : 'apartment');
            $number = str_pad((string) ($isLand ? $index : ($isHouse ? $index - 4 : $index - 8)), 2, '0', STR_PAD_LEFT);
            $units[] = $project->units()->create([
                'prototype_id' => $isLand ? $landPrototype->id : ($isHouse ? $housePrototype->id : $apartmentPrototype->id),
                'phase' => 'Phase 1',
                'block' => $isLand ? 'Plot' : ($isHouse ? 'Terrace A' : 'Block B'),
                'code' => ($isLand ? 'P-' : ($isHouse ? 'H-' : 'A-')).$number,
                'plot_number' => $isLand ? $number : null,
                'house_number' => $isHouse ? $number : null,
                'beacon_number' => $isLand ? 'DEMO-BN-'.$code.'-'.$number : null,
                'unit_type' => $type,
                'usage' => $isLand ? 'residential' : 'residential',
                'area_sqm' => $isLand ? 450 : ($isHouse ? 185 : 110),
                'base_price' => $isLand ? 25000000 + ($index * 250000) : ($isHouse ? 95000000 : 65000000),
                'title_type' => $isLand ? 'certificate_of_occupancy' : 'deed_of_assignment',
                'title_status' => $index % 3 === 0 ? 'verified' : 'pending',
                'finishing_state' => $isHouse || ! $isLand ? ($index % 2 === 0 ? 'finished' : 'semi_finished') : null,
                'rental_status' => $isLand ? 'not_listed' : 'available',
                'status' => 'available',
                'is_corner' => $isLand && $index === 1,
                'corner_premium_percent' => $isLand && $index === 1 ? 5 : 0,
                'topography_premium_amount' => $isLand && $index === 2 ? 150000 : 0,
                'attributes' => ['demo_data' => true],
            ]);
        }

        foreach ([
            ['name' => 'Paved access roads', 'category' => 'infrastructure'],
            ['name' => 'Perimeter fencing', 'category' => 'security'],
            ['name' => 'Estate water supply', 'category' => 'utilities'],
        ] as $amenityData) {
            $amenity = Amenity::query()->firstOrCreate(
                ['name' => $amenityData['name']],
                ['category' => $amenityData['category']],
            );
            $project->amenities()->syncWithoutDetaching([
                $amenity->id => ['notes' => 'Demo amenity'],
            ]);
        }

        foreach (array_slice($units, 0, 4) as $index => $unit) {
            LandTitleRecord::create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'property_unit_id' => $unit->id,
                'document_type' => $index % 2 === 0 ? 'registered_survey' : 'certificate_of_occupancy',
                'reference_number' => 'DEMO-TITLE-'.$code.'-'.$index,
                'issuing_authority' => $state->name.' Land Registry',
                'jurisdiction' => $state->name,
                'status' => 'recorded',
                'instrument_date' => today()->subYears(2 + $index),
                'registered_at' => today()->subYears(1 + $index),
                'survey_number' => 'DEMO-SUR-'.$code.'-'.$index,
                'verification_status' => $index === 0 ? 'verified' : 'pending',
                'verified_by_user_id' => $index === 0 ? $owner->id : null,
                'verified_at' => $index === 0 ? now()->subMonths(3) : null,
                'verification_notes' => 'Synthetic title record for local workflow testing.',
            ]);
        }

        return ['project' => $project, 'units' => $units];
    }

    /** @return Collection<int, OrganizationChargeType> */
    private function createChargePolicies(Organization $organization): Collection
    {
        $policies = [
            ['Estate infrastructure levy', 'development_levy', 'fixed', '325000.00', false, ['sales']],
            ['Title processing percentage', 'title_processing', 'percentage', '1.50', false, ['sales']],
            ['Survey fee by area', 'survey_fee', 'per_area', '450.00', false, ['sales']],
            ['Deed preparation quote', 'deed_fee', 'manual_quote', '0.00', false, ['sales']],
            ['Installment administration', 'other', 'fixed', '5000.00', true, ['sales']],
            ['Estate service charge', 'service_charge', 'fixed', '55000.00', true, ['rentals']],
            ['Security deposit', 'security_deposit', 'fixed', '250000.00', false, ['rentals']],
            ['Waste management per area', 'other', 'per_area', '60.00', true, ['rentals']],
            ['Move-in coordination', 'other', 'manual_quote', '0.00', false, ['rentals']],
            ['Maintenance call-out', 'maintenance', 'fixed', '25000.00', false, ['maintenance']],
        ];

        foreach ($policies as [$name, $category, $method, $amount, $recurring, $appliesTo]) {
            OrganizationChargeType::create([
                'organization_id' => $organization->id,
                'name' => $name,
                'category' => $category,
                'classification' => 'company_defined',
                'calculation_method' => $method,
                'amount' => $amount,
                'is_recurring' => $recurring,
                'is_active' => true,
                'effective_from' => today()->subMonth(),
                'rules' => ['applies_to' => $appliesTo],
            ]);
        }

        return OrganizationChargeType::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  Collection<int, Client>  $clients
     * @param  array<int, array{project: Project, units: array<int, PropertyUnit>}>  $projects
     * @param  Collection<int, OrganizationChargeType>  $allPolicies
     * @return array<int, Subscription>
     */
    private function createSubscriptions(Organization $organization, User $owner, Collection $clients, array $projects, Collection $allPolicies): array
    {
        $units = collect($projects)
            ->filter(fn (array $item): bool => $item['project']->organization_id === $organization->id)
            ->flatMap(fn (array $item): array => $item['units'])
            ->where('unit_type', 'land')
            ->values();
        $policies = $allPolicies->filter(fn (OrganizationChargeType $policy): bool => in_array('sales', $policy->rules['applies_to'] ?? [], true))->values();
        $manualQuotes = $policies->where('calculation_method', 'manual_quote');
        $verifiedClients = $clients->where('kyc_status', 'verified')->values();
        $subscriptions = [];

        foreach (range(0, 3) as $index) {
            $unit = $units[$index];
            $project = $unit->project()->firstOrFail();
            $months = [1, 6, 12, 24][$index];
            $basePrice = Money::toMinorUnits($unit->base_price);
            $effectivePrice = $basePrice
                + ($unit->is_corner ? Money::percentageOf($basePrice, $unit->corner_premium_percent) : 0)
                + Money::toMinorUnits($unit->topography_premium_amount);
            $quotes = $manualQuotes->mapWithKeys(fn (OrganizationChargeType $policy): array => [
                $policy->id => Money::fromMinorUnits(100000 + ($index * 10000)),
            ])->all();
            $chargeSnapshots = [];
            $chargeLines = [];
            $legacyFeeTotals = ['development_fee' => 0, 'deed_fee' => 0, 'survey_fee' => 0];

            foreach ($policies as $policy) {
                $amount = app(ChargePolicyService::class)->calculate($policy, $effectivePrice, $unit->area_sqm, $quotes);
                $total = $policy->is_recurring ? Money::multiplyByInteger($amount, $months) : $amount;
                $snapshot = app(ChargePolicyService::class)->snapshot($policy, $amount, $total, $effectivePrice, $unit->area_sqm);
                $snapshot['schedule_behavior'] = $policy->is_recurring ? 'repeat_each_installment' : 'split_across_plan';
                $chargeSnapshots[] = $snapshot;
                $chargeLines[] = [
                    'policy' => $policy,
                    'amounts' => $policy->is_recurring
                        ? array_fill(0, $months, $amount)
                        : $this->splitCents($amount, $months),
                    'snapshot' => $snapshot,
                ];

                $legacyField = match ($policy->category) {
                    'development_levy' => 'development_fee',
                    'deed_fee' => 'deed_fee',
                    'survey_fee' => 'survey_fee',
                    default => null,
                };
                if ($legacyField !== null) {
                    $legacyFeeTotals[$legacyField] += $total;
                }
            }

            $subscription = Subscription::create([
                'organization_id' => $organization->id,
                'client_id' => $verifiedClients[$index]->id,
                'project_id' => $project->id,
                'property_unit_id' => $unit->id,
                'created_by_user_id' => $owner->id,
                'subscription_number' => 'DEMO-SUB-'.now()->format('Y').'-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'payment_plan_months' => $months,
                'land_price' => Money::fromMinorUnits($effectivePrice),
                'development_fee' => Money::fromMinorUnits($legacyFeeTotals['development_fee']),
                'deed_fee' => Money::fromMinorUnits($legacyFeeTotals['deed_fee']),
                'survey_fee' => Money::fromMinorUnits($legacyFeeTotals['survey_fee']),
                'interest_amount' => '0.00',
                'charge_snapshot' => $chargeSnapshots,
                'status' => 'active',
            ]);

            foreach ($this->splitCents($effectivePrice, $months) as $period => $amount) {
                InstallmentSchedule::create([
                    'subscription_id' => $subscription->id,
                    'category' => 'land_installment',
                    'installment_number' => $period + 1,
                    'due_date' => today()->addMonthsNoOverflow($period),
                    'amount' => Money::fromMinorUnits($amount),
                    'status' => 'unpaid',
                ]);
            }

            foreach ($chargeLines as $line) {
                foreach ($line['amounts'] as $period => $amount) {
                    if ($amount === 0) {
                        continue;
                    }

                    $policy = $line['policy'];
                    InstallmentSchedule::create([
                        'subscription_id' => $subscription->id,
                        'charge_type_id' => $policy->id,
                        'charge_name_snapshot' => $policy->name,
                        'charge_classification_snapshot' => $policy->classification,
                        'charge_calculation_method_snapshot' => $policy->calculation_method,
                        'charge_policy_snapshot' => $line['snapshot'],
                        'category' => $policy->category,
                        'installment_number' => $period + 1,
                        'due_date' => today()->addMonthsNoOverflow($period),
                        'amount' => Money::fromMinorUnits($amount),
                        'status' => 'unpaid',
                    ]);
                }
            }

            $unit->update(['status' => 'under_installment']);
            $subscriptions[] = $subscription;
        }

        foreach ($subscriptions as $index => $subscription) {
            if ($index === 0) {
                foreach ($subscription->installmentSchedules()->orderBy('category')->get() as $scheduleIndex => $schedule) {
                    $payment = Payment::create([
                        'organization_id' => $organization->id,
                        'subscription_id' => $subscription->id,
                        'installment_schedule_id' => $schedule->id,
                        'submitted_by_user_id' => $owner->id,
                        'reviewed_by_user_id' => $owner->id,
                        'payment_category' => $schedule->category,
                        'amount' => $schedule->amount,
                        'payment_method' => 'bank_transfer',
                        'transaction_reference' => 'DEMO-PAY-FULL-'.str_pad((string) ($scheduleIndex + 1), 4, '0', STR_PAD_LEFT),
                        'proof_document_path' => self::PROOF_PATH,
                        'status' => 'approved',
                        'reviewed_at' => now()->subDays(6 - $scheduleIndex),
                    ]);
                    $schedule->update([
                        'amount_paid' => $payment->amount,
                        'status' => 'paid',
                    ]);
                }

                continue;
            }

            $schedule = $subscription->installmentSchedules()->where('category', 'land_installment')->orderBy('installment_number')->firstOrFail();
            $paymentAmount = Money::fromMinorUnits(Money::percentageOf(Money::toMinorUnits($schedule->amount), '25.00'));
            $status = ['approved', 'pending', 'rejected', 'approved'][$index];
            $payment = Payment::create([
                'organization_id' => $organization->id,
                'subscription_id' => $subscription->id,
                'installment_schedule_id' => $schedule->id,
                'submitted_by_user_id' => $owner->id,
                'reviewed_by_user_id' => $status === 'pending' ? null : $owner->id,
                'payment_category' => $schedule->category,
                'amount' => $paymentAmount,
                'payment_method' => 'bank_transfer',
                'transaction_reference' => 'DEMO-PAY-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'proof_document_path' => self::PROOF_PATH,
                'status' => $status,
                'rejection_reason' => $status === 'rejected' ? 'Demo rejection for payment-review testing.' : null,
                'reviewed_at' => $status === 'pending' ? null : now()->subDays($index),
            ]);

            if ($payment->status === 'approved') {
                $paidCents = Money::toMinorUnits($payment->amount);
                $schedule->update([
                    'amount_paid' => Money::fromMinorUnits($paidCents),
                    'status' => $paidCents === Money::toMinorUnits($schedule->amount) ? 'paid' : 'partially_paid',
                ]);
            }
        }

        return $subscriptions;
    }

    /**
     * @param  array<int, array{project: Project, units: array<int, PropertyUnit>}>  $projects
     */
    private function createReservation(Organization $organization, User $owner, array $projects): void
    {
        $unit = collect($projects)
            ->filter(fn (array $item): bool => $item['project']->organization_id === $organization->id)
            ->flatMap(fn (array $item): array => $item['units'])
            ->first(fn (PropertyUnit $property): bool => $property->unit_type === 'land' && $property->status === 'available');

        if ($unit === null) {
            throw new RuntimeException('A demo land unit could not be found for the reservation.');
        }

        Reservation::create([
            'organization_id' => $organization->id,
            'project_id' => $unit->project_id,
            'property_unit_id' => $unit->id,
            'reserved_by_user_id' => $owner->id,
            'status' => 'active',
            'reserved_until' => now()->addDays(7),
        ]);
        $unit->update(['status' => 'reserved']);
    }

    /**
     * @param  Collection<int, Client>  $clients
     * @param  array<int, array{project: Project, units: array<int, PropertyUnit>}>  $projects
     */
    private function createInspections(Organization $organization, User $owner, Collection $clients, array $projects): void
    {
        $projects = collect($projects)->filter(fn (array $item): bool => $item['project']->organization_id === $organization->id)->values();
        $verifiedClients = $clients->where('kyc_status', 'verified')->values();

        for ($index = 0; $index < 8; $index++) {
            $project = $projects[$index % $projects->count()]['project'];
            Inspection::create([
                'organization_id' => $organization->id,
                'client_id' => $verifiedClients[$index]->id,
                'project_id' => $project->id,
                'assigned_to_user_id' => $owner->id,
                'created_by_user_id' => $owner->id,
                'scheduled_at' => $index < 3 ? now()->addDays($index + 1) : now()->subDays($index),
                'status' => $index < 3 ? 'scheduled' : 'completed',
                'notes' => $index < 3 ? 'Confirm access and bring identification.' : 'Demo inspection completed; notes recorded.',
            ]);
        }
    }

    /**
     * @param  Collection<int, Client>  $clients
     * @param  array<int, array{project: Project, units: array<int, PropertyUnit>}>  $projects
     * @param  Collection<int, OrganizationChargeType>  $allPolicies
     * @return array<int, Tenancy>
     */
    private function createTenancies(Organization $organization, User $owner, Collection $clients, array $projects, Collection $allPolicies): array
    {
        $units = collect($projects)
            ->filter(fn (array $item): bool => $item['project']->organization_id === $organization->id)
            ->flatMap(fn (array $item): array => $item['units'])
            ->filter(fn (PropertyUnit $unit): bool => in_array($unit->unit_type, ['house', 'apartment'], true))
            ->values();
        $verifiedClients = $clients->where('kyc_status', 'verified')->values();
        $policies = $allPolicies->filter(fn (OrganizationChargeType $policy): bool => in_array('rentals', $policy->rules['applies_to'] ?? [], true))->values();
        $tenancies = [];

        for ($index = 0; $index < 5; $index++) {
            $unit = $units[$index];
            $isActive = $index < 3;
            $isDraft = $index === 3;
            $startsOn = match (true) {
                $isActive => today()->subMonthsNoOverflow(2)->startOfMonth(),
                $isDraft => today()->addMonth()->startOfMonth(),
                default => today()->subYears(2)->startOfMonth(),
            };
            $endsOn = match (true) {
                $isActive => $startsOn->copy()->addYear()->subDay(),
                $isDraft => $startsOn->copy()->addYear()->subDay(),
                default => today()->subYear()->subDay(),
            };
            $rentAmount = 120000 + ($index * 15000);
            $periodCount = $isActive || $isDraft ? 12 : 0;
            $quotes = $policies->where('calculation_method', 'manual_quote')->mapWithKeys(fn (OrganizationChargeType $policy): array => [
                $policy->id => Money::fromMinorUnits(25000 + ($index * 2500)),
            ])->all();
            $chargeSnapshots = [];
            $securityDepositCents = 0;
            $serviceChargeCents = 0;

            foreach ($policies as $policy) {
                $amount = app(ChargePolicyService::class)->calculate(
                    $policy,
                    Money::toMinorUnits($rentAmount),
                    $unit->area_sqm,
                    $quotes,
                );
                $total = $policy->is_recurring ? Money::multiplyByInteger($amount, max(1, $periodCount)) : $amount;
                $snapshot = app(ChargePolicyService::class)->snapshot(
                    $policy,
                    $amount,
                    $total,
                    Money::toMinorUnits($rentAmount),
                    $unit->area_sqm,
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

            $tenancy = Tenancy::create([
                'organization_id' => $organization->id,
                'property_unit_id' => $unit->id,
                'tenant_client_id' => $verifiedClients[$index]->id,
                'created_by_user_id' => $owner->id,
                'tenancy_number' => 'DEMO-TEN-'.now()->format('Y').'-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'status' => $isActive ? 'active' : ($isDraft ? 'draft' : 'ended'),
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'rent_frequency' => 'monthly',
                'rent_amount' => Money::fromMinorUnits(Money::toMinorUnits($rentAmount)),
                'security_deposit' => Money::fromMinorUnits($securityDepositCents),
                'service_charge' => Money::fromMinorUnits($serviceChargeCents),
                'next_review_on' => $isActive ? $endsOn->copy()->subMonths(2) : null,
                'terms_snapshot' => [
                    'rent_frequency' => 'monthly',
                    'rent_amount' => Money::fromMinorUnits(Money::toMinorUnits($rentAmount)),
                    'security_deposit' => Money::fromMinorUnits($securityDepositCents),
                    'service_charge' => Money::fromMinorUnits($serviceChargeCents),
                    'charges' => $chargeSnapshots,
                ],
                'policy_snapshot' => ['source' => 'development_demo_data'],
            ]);

            if ($isActive) {
                $unit->update(['rental_status' => 'occupied']);
                $this->createRentSchedules($tenancy, $chargeSnapshots);
            } elseif (! $isDraft) {
                $unit->update(['rental_status' => 'available']);
            }

            $tenancies[] = $tenancy;
        }

        return $tenancies;
    }

    /**
     * @param  array<int, array<string, mixed>>  $chargeSnapshots
     */
    private function createRentSchedules(Tenancy $tenancy, array $chargeSnapshots): void
    {
        $periodStart = Carbon::parse($tenancy->starts_on);
        $termEnd = Carbon::parse($tenancy->ends_on);
        $periodCount = 0;

        while ($periodStart->lte($termEnd)) {
            $periodCount++;
            $periodEnd = $periodStart->copy()->addMonthNoOverflow()->subDay();
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

            foreach ($chargeSnapshots as $charge) {
                $isRecurring = ($charge['schedule_behavior'] ?? '') === 'repeat_each_period';
                if (! $isRecurring && $periodCount > 1) {
                    continue;
                }
                if (Money::toMinorUnits($charge['calculated_amount']) === 0) {
                    continue;
                }

                RentSchedule::create([
                    'tenancy_id' => $tenancy->id,
                    'charge_type_id' => $charge['charge_type_id'],
                    'charge_name_snapshot' => $charge['name'],
                    'charge_classification_snapshot' => $charge['classification'],
                    'charge_calculation_method_snapshot' => $charge['calculation_method'],
                    'charge_policy_snapshot' => $charge,
                    'category' => $charge['category'],
                    ...$period,
                    'amount_due' => $charge['calculated_amount'],
                    'status' => 'unpaid',
                ]);
            }

            $periodStart = Carbon::parse($tenancy->starts_on)->addMonthsNoOverflow($periodCount);
        }
    }

    /** @param array<int, Tenancy> $tenancies */
    private function createRentPayments(Organization $organization, User $owner, array $tenancies): void
    {
        foreach (array_slice($tenancies, 0, 2) as $index => $tenancy) {
            $schedule = $tenancy->rentSchedules()->where('category', 'rent')->orderBy('due_on')->firstOrFail();
            $amountCents = Money::percentageOf(Money::toMinorUnits($schedule->amount_due), '40.00');
            $payment = RentPayment::create([
                'organization_id' => $organization->id,
                'tenancy_id' => $tenancy->id,
                'submitted_by_user_id' => $owner->id,
                'reviewed_by_user_id' => $owner->id,
                'amount' => Money::fromMinorUnits($amountCents),
                'payment_channel' => 'bank_transfer',
                'transaction_reference' => 'DEMO-RENT-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'proof_document_path' => self::PROOF_PATH,
                'status' => 'approved',
                'reviewed_at' => now()->subDays($index + 1),
                'received_at' => now()->subDays($index + 1),
            ]);
            DB::table('rent_payment_allocations')->insert([
                'rent_payment_id' => $payment->id,
                'rent_schedule_id' => $schedule->id,
                'amount' => Money::fromMinorUnits($amountCents),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $schedule->update(['status' => 'partially_paid']);
        }

        $tenancy = $tenancies[0];
        $schedule = $tenancy->rentSchedules()->where('category', 'rent')->orderBy('due_on')->firstOrFail();
        $pendingAmount = Money::fromMinorUnits(Money::percentageOf(Money::toMinorUnits($schedule->amount_due), '10.00'));
        $pendingPayment = RentPayment::create([
            'organization_id' => $organization->id,
            'tenancy_id' => $tenancy->id,
            'submitted_by_user_id' => $owner->id,
            'amount' => $pendingAmount,
            'payment_channel' => 'cash_deposit',
            'transaction_reference' => 'DEMO-RENT-PENDING-0001',
            'proof_document_path' => self::PROOF_PATH,
            'status' => 'pending',
        ]);
        DB::table('rent_payment_allocations')->insert([
            'rent_payment_id' => $pendingPayment->id,
            'rent_schedule_id' => $schedule->id,
            'amount' => $pendingAmount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  Collection<int, Client>  $clients
     * @param  array<int, array{project: Project, units: array<int, PropertyUnit>}>  $projects
     * @param  array<int, Tenancy>  $tenancies
     */
    private function createMaintenanceRecords(Organization $organization, User $owner, Collection $clients, array $projects, array $tenancies): void
    {
        $properties = collect($projects)
            ->filter(fn (array $item): bool => $item['project']->organization_id === $organization->id)
            ->flatMap(fn (array $item): array => $item['units'])
            ->values();
        $openTenancies = array_values(array_filter($tenancies, fn (Tenancy $tenancy): bool => $tenancy->status === 'active'));

        for ($index = 0; $index < 6; $index++) {
            $tenancy = $index < 3 ? $openTenancies[$index] : null;
            $unit = $tenancy === null
                ? $properties[$index + 8]
                : PropertyUnit::query()->findOrFail($tenancy->property_unit_id);
            $status = ['in_progress', 'in_progress', 'resolved', 'open', 'open', 'resolved'][$index];
            $request = MaintenanceRequest::create([
                'organization_id' => $organization->id,
                'property_unit_id' => $unit->id,
                'tenancy_id' => $tenancy?->id,
                'reported_by_client_id' => $tenancy?->tenant_client_id ?? $clients[$index]->id,
                'created_by_user_id' => $owner->id,
                'reference_number' => 'DEMO-MNT-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'category' => ['plumbing', 'electrical', 'appliance', 'structural', 'cleaning', 'security'][$index],
                'priority' => ['high', 'normal', 'urgent', 'low', 'normal', 'high'][$index],
                'status' => $status,
                'title' => [
                    'Kitchen sink leakage',
                    'Generator service request',
                    'Air conditioner inspection',
                    'Common-area lighting',
                    'Water pressure review',
                    'Gate access repair',
                ][$index],
                'description' => 'Synthetic maintenance request for local workflow testing.',
                'reported_at' => now()->subDays($index + 1),
                'resolved_at' => $status === 'resolved' ? now()->subDays(1) : null,
            ]);

            if ($index < 3) {
                $workOrderStatus = ['approved', 'pending_approval', 'completed'][$index];
                MaintenanceWorkOrder::create([
                    'organization_id' => $organization->id,
                    'maintenance_request_id' => $request->id,
                    'assigned_to_user_id' => $owner->id,
                    'approved_by_user_id' => $workOrderStatus === 'pending_approval' ? null : $owner->id,
                    'vendor_name' => ['Demo Plumbing Services', 'Demo Electrical Works', 'Demo HVAC Care'][$index],
                    'status' => $workOrderStatus,
                    'estimated_cost' => 45000 + ($index * 15000),
                    'actual_cost' => $workOrderStatus === 'completed' ? 52000 : null,
                    'approved_at' => $workOrderStatus === 'pending_approval' ? null : now()->subDays(1),
                    'scheduled_at' => $workOrderStatus === 'pending_approval' ? null : now()->addDays(2),
                    'completed_at' => $workOrderStatus === 'completed' ? now()->subHours(4) : null,
                    'completion_notes' => $workOrderStatus === 'completed' ? 'Demo work completed and inspected.' : null,
                ]);
            }
        }
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

    private function createProofFile(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0y8AAAAASUVORK5CYII=', true);
        if ($png === false || ! Storage::disk('local')->put(self::PROOF_PATH, $png)) {
            throw new RuntimeException('The local demo payment proof could not be created.');
        }
    }
}
