<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasPermission('organizations.view'), 403);

        $organizationIds = Organization::accessibleIdsFor($user, 'organizations.view');
        $organizations = Organization::query()
            ->with('parent:id,name')
            ->withCount('members')
            ->whereIn('id', $organizationIds)
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'kind', 'country', 'currency', 'timezone']);

        return Inertia::render('Organizations/Index', [
            'organizations' => $organizations,
            'canCreate' => $user->hasPermission('organizations.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('organizations.manage'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'string', 'in:company,subsidiary,branch'],
            'parent_id' => ['required', 'uuid', 'exists:organizations,id'],
        ]);

        $manageableOrganizationIds = Organization::accessibleIdsFor($user, 'organizations.manage');
        abort_unless(in_array($validated['parent_id'], $manageableOrganizationIds, true), 404);

        $parent = Organization::query()->findOrFail($validated['parent_id']);
        $organization = Organization::create([
            'parent_id' => $parent->id,
            'name' => $validated['name'],
            'kind' => $validated['kind'],
            'country' => $parent->country,
            'currency' => $parent->currency,
            'timezone' => $parent->timezone,
            'locale' => $parent->locale,
        ]);

        AuditLog::create([
            'organization_id' => $parent->id,
            'user_id' => $user->id,
            'action' => 'organization.created',
            'subject_type' => Organization::class,
            'subject_id' => $organization->id,
            'metadata' => ['name' => $organization->name, 'kind' => $organization->kind],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return redirect()->route('organizations.index');
    }
}
