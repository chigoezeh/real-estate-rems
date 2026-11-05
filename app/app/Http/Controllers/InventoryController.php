<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\PropertyUnit;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    private const DEFAULT_STATUS_LABELS = [
        'available' => 'Available',
        'reserved' => 'Reserved',
        'under_installment' => 'Under installment',
        'allocated' => 'Allocated',
        'sold' => 'Sold',
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasPermission('inventory.view'), 403);

        $organizationIds = Organization::accessibleIdsFor($user, 'inventory.view');
        $manageableOrganizationIds = Organization::accessibleIdsFor($user, 'inventory.manage');
        $reservableOrganizationIds = Organization::accessibleIdsFor($user, 'inventory.reserve');
        $projects = Project::query()
            ->with('organization:id,name,currency')
            ->withCount('units')
            ->whereIn('organization_id', $organizationIds)
            ->orderBy('name')
            ->get();
        $selectedProjectId = $request->query('project_id');

        if ($selectedProjectId !== null) {
            abort_unless($projects->contains('id', $selectedProjectId), 404);
        } else {
            $selectedProjectId = $projects->first()?->id;
        }

        $selectedProject = $projects->firstWhere('id', $selectedProjectId);
        $units = $selectedProject
            ? PropertyUnit::query()
                ->where('project_id', $selectedProject->id)
                ->with(['reservations' => fn ($query) => $query
                    ->where('status', 'active')
                    ->with('reservedBy:id,name')])
                ->orderBy('phase')
                ->orderBy('block')
                ->orderBy('code')
                ->get()
                ->map(fn (PropertyUnit $unit): array => $this->unitSummary($unit))
                ->values()
            : collect();

        return Inertia::render('Inventory/Index', [
            'projects' => $projects->map(fn (Project $project): array => [
                'id' => $project->id,
                'organizationId' => $project->organization_id,
                'organizationName' => $project->organization->name,
                'organizationCurrency' => $project->organization->currency,
                'name' => $project->name,
                'code' => $project->code,
                'location' => $project->location,
                'description' => $project->description,
                'titleTypes' => $project->settings['title_types'] ?? [],
                'statusLabels' => $this->statusLabels($project->settings['status_labels'] ?? []),
                'reservationHours' => $project->settings['reservation_hours'] ?? 24,
                'unitsCount' => $project->units_count,
                'canManage' => in_array($project->organization_id, $manageableOrganizationIds, true),
                'canReserve' => in_array($project->organization_id, $reservableOrganizationIds, true),
            ])->values(),
            'selectedProjectId' => $selectedProjectId,
            'units' => $units,
            'organizations' => Organization::query()
                ->whereIn('id', Organization::accessibleIdsFor($user, 'inventory.manage'))
                ->orderBy('name')
                ->get(['id', 'name']),
            'canManage' => $user->hasPermission('inventory.manage'),
            'canReserve' => $user->hasPermission('inventory.reserve'),
        ]);
    }

    public function storeProject(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('inventory.manage'), 403);
        $request->merge(['code' => strtoupper(trim((string) $request->input('code', '')))]);

        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/',
                Rule::unique('projects', 'code')->where('organization_id', $request->input('organization_id')),
            ],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'title_types' => ['required', 'array', 'min:1', 'max:20'],
            'title_types.*' => ['required', 'string', 'distinct', 'max:80'],
            'status_labels' => ['sometimes', 'array:available,reserved,under_installment,allocated,sold'],
            'status_labels.available' => ['sometimes', 'required', 'string', 'max:40'],
            'status_labels.reserved' => ['sometimes', 'required', 'string', 'max:40'],
            'status_labels.under_installment' => ['sometimes', 'required', 'string', 'max:40'],
            'status_labels.allocated' => ['sometimes', 'required', 'string', 'max:40'],
            'status_labels.sold' => ['sometimes', 'required', 'string', 'max:40'],
            'reservation_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
        ]);

        $organizationIds = Organization::accessibleIdsFor($user, 'inventory.manage');
        abort_unless(in_array($validated['organization_id'], $organizationIds, true), 404);

        $project = Project::create([
            'organization_id' => $validated['organization_id'],
            'name' => $validated['name'],
            'code' => strtoupper($validated['code']),
            'location' => $validated['location'] ?? null,
            'description' => $validated['description'] ?? null,
            'settings' => [
                'title_types' => array_values($validated['title_types']),
                'status_labels' => $this->statusLabels($validated['status_labels'] ?? []),
                'reservation_hours' => (int) ($validated['reservation_hours'] ?? 24),
            ],
        ]);

        $this->audit($request, $user, $project->organization_id, Project::class, $project->id, 'project.created', [
            'name' => $project->name,
            'code' => $project->code,
        ]);

        return redirect()->route('inventory.index', ['project_id' => $project->id]);
    }

    public function updateProject(Request $request, Project $project): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('inventory.manage'), 403);
        $this->ensureProjectAccess($user, $project, 'inventory.manage');
        $request->merge(['code' => strtoupper(trim((string) $request->input('code', '')))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/',
                Rule::unique('projects', 'code')->where('organization_id', $project->organization_id)->ignore($project->id),
            ],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'title_types' => ['required', 'array', 'min:1', 'max:20'],
            'title_types.*' => ['required', 'string', 'distinct', 'max:80'],
            'status_labels' => ['required', 'array:available,reserved,under_installment,allocated,sold'],
            'status_labels.available' => ['required', 'string', 'max:40'],
            'status_labels.reserved' => ['required', 'string', 'max:40'],
            'status_labels.under_installment' => ['required', 'string', 'max:40'],
            'status_labels.allocated' => ['required', 'string', 'max:40'],
            'status_labels.sold' => ['required', 'string', 'max:40'],
            'reservation_hours' => ['required', 'integer', 'min:1', 'max:168'],
        ]);

        $project->update([
            'name' => $validated['name'],
            'code' => $validated['code'],
            'location' => $validated['location'] ?? null,
            'description' => $validated['description'] ?? null,
            'settings' => array_merge($project->settings ?? [], [
                'title_types' => array_values($validated['title_types']),
                'status_labels' => $this->statusLabels($validated['status_labels']),
                'reservation_hours' => (int) $validated['reservation_hours'],
            ]),
        ]);

        $this->audit($request, $user, $project->organization_id, Project::class, $project->id, 'project.updated', [
            'name' => $project->name,
            'code' => $project->code,
        ]);

        return redirect()->route('inventory.index', ['project_id' => $project->id]);
    }

    public function storeUnit(Request $request, Project $project): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('inventory.manage'), 403);
        $this->ensureProjectAccess($user, $project, 'inventory.manage');
        $request->merge([
            'phase' => trim((string) $request->input('phase', '')),
            'block' => trim((string) $request->input('block', '')),
            'code' => strtoupper(trim((string) $request->input('code', ''))),
        ]);

        $titleTypes = $project->settings['title_types'] ?? [];
        $validated = $request->validate([
            'phase' => ['nullable', 'string', 'max:80'],
            'block' => ['nullable', 'string', 'max:80'],
            'code' => [
                'required',
                'string',
                'max:80',
                Rule::unique('property_units', 'code')
                    ->where('project_id', $project->id)
                    ->where('phase', $request->input('phase', ''))
                    ->where('block', $request->input('block', '')),
            ],
            'unit_type' => ['required', 'string', 'max:40'],
            'usage' => ['nullable', 'string', 'max:40'],
            'area_sqm' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'base_price' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'title_type' => ['nullable', 'string', 'max:80', Rule::in($titleTypes)],
            'title_status' => ['nullable', 'string', 'max:80'],
            'rental_status' => ['sometimes', Rule::in(['not_listed', 'available'])],
            'is_corner' => ['required', 'boolean'],
            'corner_premium_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'topography_premium_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
        ]);

        $unit = $project->units()->create([
            ...$validated,
            'rental_status' => $validated['rental_status'] ?? 'not_listed',
            'phase' => trim($validated['phase'] ?? ''),
            'block' => trim($validated['block'] ?? ''),
            'status' => 'available',
        ]);

        $this->audit($request, $user, $project->organization_id, PropertyUnit::class, $unit->id, 'inventory.unit_created', [
            'project_id' => $project->id,
            'code' => $unit->code,
            'unit_type' => $unit->unit_type,
        ]);

        return redirect()->route('inventory.index', ['project_id' => $project->id]);
    }

    public function reserve(Request $request, PropertyUnit $propertyUnit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('inventory.reserve'), 403);

        DB::transaction(function () use ($request, $user, $propertyUnit): void {
            $unit = PropertyUnit::query()->lockForUpdate()->findOrFail($propertyUnit->id);
            $project = Project::query()->findOrFail($unit->project_id);
            $this->ensureProjectAccess($user, $project, 'inventory.reserve');

            $activeReservation = Reservation::query()
                ->where('property_unit_id', $unit->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->latest('created_at')
                ->first();

            if ($activeReservation !== null && $activeReservation->reserved_until?->isFuture()) {
                abort(409, 'This unit is already reserved.');
            }

            if ($activeReservation !== null) {
                abort_if(
                    $activeReservation->reserved_until === null || $activeReservation->reserved_until->isFuture(),
                    409,
                    'This unit is already reserved.',
                );
                abort_unless($unit->status === 'reserved', 409, 'This unit is not available.');
                $activeReservation->update(['status' => 'expired', 'released_at' => now()]);
            } else {
                abort_unless($unit->status === 'available', 409, 'This unit is not available.');
            }

            $unit->update(['status' => 'reserved']);
            $reservation = Reservation::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'property_unit_id' => $unit->id,
                'reserved_by_user_id' => $user->id,
                'status' => 'active',
                'reserved_until' => now()->addHours((int) ($project->settings['reservation_hours'] ?? 24)),
            ]);

            $this->audit($request, $user, $project->organization_id, Reservation::class, $reservation->id, 'inventory.unit_reserved', [
                'project_id' => $project->id,
                'unit_code' => $unit->code,
                'reserved_until' => $reservation->reserved_until?->toIso8601String(),
            ]);
        });

        return redirect()->route('inventory.index', ['project_id' => $propertyUnit->project_id]);
    }

    public function release(Request $request, PropertyUnit $propertyUnit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('inventory.manage'), 403);

        DB::transaction(function () use ($request, $user, $propertyUnit): void {
            $unit = PropertyUnit::query()->lockForUpdate()->findOrFail($propertyUnit->id);
            $project = Project::query()->findOrFail($unit->project_id);
            $this->ensureProjectAccess($user, $project, 'inventory.manage');

            $reservation = Reservation::query()
                ->where('property_unit_id', $unit->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->latest('created_at')
                ->first();

            abort_unless($reservation !== null && $unit->status === 'reserved', 409, 'This unit has no active reservation.');

            $reservation->update(['status' => 'released', 'released_at' => now()]);
            $unit->update(['status' => 'available']);

            $this->audit($request, $user, $project->organization_id, Reservation::class, $reservation->id, 'inventory.unit_released', [
                'project_id' => $project->id,
                'unit_code' => $unit->code,
            ]);
        });

        return redirect()->route('inventory.index', ['project_id' => $propertyUnit->project_id]);
    }

    private function ensureProjectAccess(User $user, Project $project, string $permission): void
    {
        $organizationIds = Organization::accessibleIdsFor($user, $permission);

        abort_unless(in_array($project->organization_id, $organizationIds, true), 404);
    }

    /** @param array<string, string> $labels
     * @return array<string, string>
     */
    private function statusLabels(array $labels): array
    {
        return array_merge(self::DEFAULT_STATUS_LABELS, $labels);
    }

    /** @return array<string, mixed> */
    private function unitSummary(PropertyUnit $unit): array
    {
        $reservation = $unit->reservations->first();

        return [
            'id' => $unit->id,
            'phase' => $unit->phase,
            'block' => $unit->block,
            'code' => $unit->code,
            'unitType' => $unit->unit_type,
            'usage' => $unit->usage,
            'areaSqm' => $unit->area_sqm,
            'basePrice' => $unit->base_price,
            'titleType' => $unit->title_type,
            'titleStatus' => $unit->title_status,
            'rentalStatus' => $unit->rental_status,
            'status' => $unit->status,
            'isCorner' => $unit->is_corner,
            'cornerPremiumPercent' => $unit->corner_premium_percent,
            'topographyPremiumAmount' => $unit->topography_premium_amount,
            'reservedUntil' => $reservation?->reserved_until?->toIso8601String(),
            'reservedBy' => $reservation?->reservedBy?->name,
            'reservationExpired' => $reservation?->reserved_until?->isPast() ?? false,
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Request $request, User $user, string $organizationId, string $subjectType, string $subjectId, string $action, array $metadata): void
    {
        AuditLog::create([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
