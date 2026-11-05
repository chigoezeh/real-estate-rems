<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Inspection;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InspectionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasPermission('inspections.view'), 403);
        $organizationIds = Organization::accessibleIdsFor($user, 'inspections.view');
        $manageableOrganizationIds = Organization::accessibleIdsFor($user, 'inspections.manage');

        return Inertia::render('Inspections/Index', [
            'inspections' => Inspection::query()
                ->with(['client:id,full_name,phone', 'project:id,name', 'assignedTo:id,name', 'createdBy:id,name'])
                ->whereIn('organization_id', $organizationIds)
                ->orderByDesc('scheduled_at')
                ->limit(300)
                ->get()
                ->map(fn (Inspection $inspection): array => [
                    'id' => $inspection->id,
                    'organizationId' => $inspection->organization_id,
                    'clientName' => $inspection->client->full_name,
                    'clientPhone' => $inspection->client->phone,
                    'projectName' => $inspection->project->name,
                    'assignedToUserId' => $inspection->assigned_to_user_id,
                    'assignedToName' => $inspection->assignedTo?->name,
                    'scheduledAt' => $inspection->scheduled_at->toIso8601String(),
                    'status' => $inspection->status,
                    'notes' => $inspection->notes,
                    'createdByName' => $inspection->createdBy?->name,
                ])
                ->values(),
            'clients' => Client::query()
                ->whereIn('organization_id', $manageableOrganizationIds)
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
                ->whereIn('organization_id', $manageableOrganizationIds)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'organization_id', 'name'])
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'organizationId' => $project->organization_id,
                    'name' => $project->name,
                ])
                ->values(),
            'assignees' => DB::table('organization_user')
                ->join('users', 'organization_user.user_id', '=', 'users.id')
                ->whereIn('organization_user.organization_id', $manageableOrganizationIds)
                ->where('organization_user.status', 'active')
                ->orderBy('users.name')
                ->get(['users.id', 'users.name', 'organization_user.organization_id as organization_id'])
                ->map(fn (object $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'organizationId' => $member->organization_id,
                ])
                ->values(),
            'canManage' => $user->hasPermission('inspections.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('inspections.manage'), 403);
        $validated = $request->validate([
            'client_id' => ['required', 'uuid', 'exists:clients,id'],
            'project_id' => ['required', 'uuid', 'exists:projects,id'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'assigned_to_user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $client = Client::query()->findOrFail($validated['client_id']);
        $project = Project::query()->findOrFail($validated['project_id']);
        $organizationIds = Organization::accessibleIdsFor($user, 'inspections.manage');
        abort_unless($client->organization_id === $project->organization_id, 404);
        abort_unless(in_array($project->organization_id, $organizationIds, true), 404);
        $this->assertAssigneeBelongsToOrganization($validated['assigned_to_user_id'] ?? null, $project->organization_id);

        DB::transaction(function () use ($request, $user, $validated, $project): void {
            $inspection = Inspection::create([
                'organization_id' => $project->organization_id,
                'client_id' => $validated['client_id'],
                'project_id' => $project->id,
                'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null,
                'created_by_user_id' => $user->id,
                'scheduled_at' => $validated['scheduled_at'],
                'status' => 'scheduled',
                'notes' => $validated['notes'] ?? null,
            ]);

            $this->audit($request, $user, $inspection, 'inspection.scheduled', [
                'client_id' => $inspection->client_id,
                'project_id' => $inspection->project_id,
                'assigned_to_user_id' => $inspection->assigned_to_user_id,
                'scheduled_at' => $inspection->scheduled_at->toIso8601String(),
            ]);
        });

        return redirect()->route('inspections.index');
    }

    public function update(Request $request, Inspection $inspection): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('inspections.manage'), 403);
        abort_unless(in_array($inspection->organization_id, Organization::accessibleIdsFor($user, 'inspections.manage'), true), 404);
        $validated = $request->validate([
            'assigned_to_user_id' => ['sometimes', 'nullable', 'uuid', 'exists:users,id'],
            'status' => ['sometimes', Rule::in(['scheduled', 'confirmed', 'completed', 'cancelled'])],
        ]);
        abort_unless(isset($validated['assigned_to_user_id']) || array_key_exists('assigned_to_user_id', $validated) || isset($validated['status']), 422);
        if (array_key_exists('assigned_to_user_id', $validated)) {
            $this->assertAssigneeBelongsToOrganization($validated['assigned_to_user_id'], $inspection->organization_id);
        }

        DB::transaction(function () use ($request, $user, $inspection, $validated): void {
            $lockedInspection = Inspection::query()->lockForUpdate()->findOrFail($inspection->id);
            $changes = [];
            foreach (['assigned_to_user_id', 'status'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $changes[$field] = $validated[$field];
                }
            }
            $lockedInspection->update($changes);
            $this->audit($request, $user, $lockedInspection, 'inspection.updated', $changes);
        });

        return redirect()->route('inspections.index');
    }

    private function assertAssigneeBelongsToOrganization(?string $assigneeId, string $organizationId): void
    {
        if ($assigneeId === null) {
            return;
        }

        $isActiveMember = DB::table('organization_user')
            ->where('organization_id', $organizationId)
            ->where('user_id', $assigneeId)
            ->where('status', 'active')
            ->exists();
        abort_unless($isActiveMember, 404);
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Request $request, User $user, Inspection $inspection, string $action, array $metadata): void
    {
        AuditLog::create([
            'organization_id' => $inspection->organization_id,
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => Inspection::class,
            'subject_id' => $inspection->id,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
