<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceWorkOrder;
use App\Models\Organization;
use App\Models\PropertyUnit;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasPermission('maintenance.view'), 403);
        $organizationIds = Organization::accessibleIdsFor($user, 'maintenance.view');
        $manageableOrganizationIds = Organization::accessibleIdsFor($user, 'maintenance.manage');

        return Inertia::render('Maintenance/Index', [
            'requests' => MaintenanceRequest::query()
                ->with([
                    'propertyUnit:id,project_id,phase,block,code',
                    'propertyUnit.project:id,name',
                    'tenancy.tenant:id,full_name',
                    'workOrders',
                ])
                ->whereIn('organization_id', $organizationIds)
                ->latest('reported_at')
                ->limit(200)
                ->get()
                ->map(fn (MaintenanceRequest $maintenanceRequest): array => [
                    'id' => $maintenanceRequest->id,
                    'reference' => $maintenanceRequest->reference_number,
                    'title' => $maintenanceRequest->title,
                    'description' => $maintenanceRequest->description,
                    'category' => $maintenanceRequest->category,
                    'priority' => $maintenanceRequest->priority,
                    'status' => $maintenanceRequest->status,
                    'canManage' => in_array($maintenanceRequest->organization_id, $manageableOrganizationIds, true),
                    'reportedAt' => $maintenanceRequest->reported_at?->toDateTimeString(),
                    'property' => $maintenanceRequest->propertyUnit->project->name.' · '.trim($maintenanceRequest->propertyUnit->phase.' '.$maintenanceRequest->propertyUnit->block.' '.$maintenanceRequest->propertyUnit->code),
                    'tenantName' => $maintenanceRequest->tenancy?->tenant?->full_name,
                    'workOrders' => $maintenanceRequest->workOrders->map(fn (MaintenanceWorkOrder $workOrder): array => [
                        'id' => $workOrder->id,
                        'vendorName' => $workOrder->vendor_name,
                        'status' => $workOrder->status,
                        'estimatedCost' => $workOrder->estimated_cost,
                        'actualCost' => $workOrder->actual_cost,
                        'scheduledAt' => $workOrder->scheduled_at?->toDateTimeString(),
                        'completedAt' => $workOrder->completed_at?->toDateTimeString(),
                        'completionNotes' => $workOrder->completion_notes,
                    ])->values(),
                ])
                ->values(),
            'properties' => PropertyUnit::query()
                ->with('project:id,organization_id,name')
                ->whereHas('project', fn ($query) => $query->whereIn('organization_id', $manageableOrganizationIds))
                ->orderBy('code')
                ->get()
                ->map(fn (PropertyUnit $property): array => [
                    'id' => $property->id,
                    'projectName' => $property->project->name,
                    'label' => trim($property->phase.' '.$property->block.' '.$property->code),
                ])
                ->values(),
            'canManage' => $user->hasPermission('maintenance.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('maintenance.manage'), 403);
        $validated = $request->validate([
            'property_unit_id' => ['required', 'uuid', 'exists:property_units,id'],
            'category' => ['required', Rule::in(['plumbing', 'electrical', 'structural', 'appliance', 'security', 'cleaning', 'other'])],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:10000'],
        ]);

        $property = PropertyUnit::query()->with('project')->findOrFail($validated['property_unit_id']);
        $organizationId = $property->project->organization_id;
        abort_unless(in_array($organizationId, Organization::accessibleIdsFor($user, 'maintenance.manage'), true), 404);

        DB::transaction(function () use ($request, $user, $validated, $property, $organizationId): void {
            $lockedProperty = PropertyUnit::query()->lockForUpdate()->findOrFail($property->id);
            $activeTenancy = Tenancy::query()
                ->where('property_unit_id', $lockedProperty->id)
                ->where('status', 'active')
                ->whereDate('starts_on', '<=', today())
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()))
                ->latest('starts_on')
                ->first();

            $maintenanceRequest = MaintenanceRequest::create([
                'organization_id' => $organizationId,
                'property_unit_id' => $lockedProperty->id,
                'tenancy_id' => $activeTenancy?->id,
                'created_by_user_id' => $user->id,
                'reference_number' => 'MNT-'.now()->format('Y').'-'.Str::upper(Str::random(8)),
                'category' => $validated['category'],
                'priority' => $validated['priority'],
                'status' => 'open',
                'title' => $validated['title'],
                'description' => $validated['description'],
                'reported_at' => now(),
            ]);

            $this->audit($request, $user, $organizationId, $maintenanceRequest, 'maintenance.requested', [
                'priority' => $maintenanceRequest->priority,
                'category' => $maintenanceRequest->category,
            ]);
        });

        return redirect()->route('maintenance.index')->with('success', 'Maintenance request recorded.');
    }

    public function storeWorkOrder(Request $request, MaintenanceRequest $maintenanceRequest): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('maintenance.manage'), 403);
        abort_unless(in_array($maintenanceRequest->organization_id, Organization::accessibleIdsFor($user, 'maintenance.manage'), true), 404);
        $validated = $request->validate([
            'vendor_name' => ['nullable', 'string', 'max:180'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($request, $user, $maintenanceRequest, $validated): void {
            $lockedRequest = MaintenanceRequest::query()->lockForUpdate()->findOrFail($maintenanceRequest->id);
            abort_unless(in_array($lockedRequest->status, ['open', 'in_progress'], true), 409, 'A work order cannot be added to a closed maintenance request.');

            $workOrder = MaintenanceWorkOrder::create([
                'organization_id' => $lockedRequest->organization_id,
                'maintenance_request_id' => $lockedRequest->id,
                'vendor_name' => $validated['vendor_name'] ?? null,
                'status' => 'pending_approval',
                'estimated_cost' => $validated['estimated_cost'] ?? null,
                'scheduled_at' => $validated['scheduled_at'] ?? null,
            ]);

            if ($lockedRequest->status === 'open') {
                $lockedRequest->update(['status' => 'in_progress']);
            }

            $this->audit($request, $user, $lockedRequest->organization_id, $workOrder, 'maintenance.work_order.created', [
                'estimated_cost' => $workOrder->estimated_cost,
                'vendor_name' => $workOrder->vendor_name,
            ]);
        });

        return redirect()->route('maintenance.index')->with('success', 'Work order submitted for approval.');
    }

    public function approveWorkOrder(Request $request, MaintenanceWorkOrder $maintenanceWorkOrder): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('maintenance.manage'), 403);
        abort_unless(in_array($maintenanceWorkOrder->organization_id, Organization::accessibleIdsFor($user, 'maintenance.manage'), true), 404);

        DB::transaction(function () use ($request, $user, $maintenanceWorkOrder): void {
            $workOrder = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($maintenanceWorkOrder->id);
            abort_unless($workOrder->status === 'pending_approval', 409, 'Only pending work orders can be approved.');
            $workOrder->update([
                'status' => 'approved',
                'approved_by_user_id' => $user->id,
                'approved_at' => now(),
            ]);

            $this->audit($request, $user, $workOrder->organization_id, $workOrder, 'maintenance.work_order.approved', []);
        });

        return redirect()->route('maintenance.index')->with('success', 'Work order approved.');
    }

    public function completeWorkOrder(Request $request, MaintenanceWorkOrder $maintenanceWorkOrder): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('maintenance.manage'), 403);
        abort_unless(in_array($maintenanceWorkOrder->organization_id, Organization::accessibleIdsFor($user, 'maintenance.manage'), true), 404);
        $validated = $request->validate([
            'actual_cost' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'completion_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($request, $user, $maintenanceWorkOrder, $validated): void {
            $workOrder = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($maintenanceWorkOrder->id);
            abort_unless($workOrder->status === 'approved', 409, 'Only approved work orders can be completed.');
            $workOrder->update([
                'status' => 'completed',
                'actual_cost' => $validated['actual_cost'] ?? null,
                'completion_notes' => $validated['completion_notes'] ?? null,
                'completed_at' => now(),
            ]);

            $maintenanceRequest = MaintenanceRequest::query()->lockForUpdate()->findOrFail($workOrder->maintenance_request_id);
            if (! $maintenanceRequest->workOrders()->where('status', '!=', 'completed')->exists()) {
                $maintenanceRequest->update(['status' => 'resolved', 'resolved_at' => now()]);
            }

            $this->audit($request, $user, $workOrder->organization_id, $workOrder, 'maintenance.work_order.completed', [
                'actual_cost' => $workOrder->actual_cost,
            ]);
        });

        return redirect()->route('maintenance.index')->with('success', 'Work order marked complete.');
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Request $request, User $user, string $organizationId, object $subject, string $action, array $metadata): void
    {
        AuditLog::create([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->id,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
