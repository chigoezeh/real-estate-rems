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
        $manageableOrganizationIds = Organization::accessibleIdsFor($user, 'organizations.manage');
        $organizations = Organization::query()
            ->with('parent:id,name')
            ->withCount('members')
            ->whereIn('id', $organizationIds)
            ->orderBy('name')
            ->get([
                'id', 'parent_id', 'name', 'kind', 'registration_number', 'email', 'phone', 'address',
                'country', 'currency', 'timezone', 'locale', 'branding',
            ])
            ->map(fn (Organization $organization): array => [
                'id' => $organization->id,
                'parent_id' => $organization->parent_id,
                'name' => $organization->name,
                'kind' => $organization->kind,
                'registration_number' => $organization->registration_number,
                'email' => $organization->email,
                'phone' => $organization->phone,
                'address' => $organization->address,
                'country' => $organization->country,
                'currency' => $organization->currency,
                'timezone' => $organization->timezone,
                'locale' => $organization->locale,
                'branding' => $organization->branding,
                'parent' => $organization->parent,
                'members_count' => $organization->members_count,
                'can_edit' => in_array($organization->id, $manageableOrganizationIds, true),
            ]);

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

    public function update(Request $request, Organization $organization): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('organizations.manage'), 403);

        $manageableOrganizationIds = Organization::accessibleIdsFor($user, 'organizations.manage');
        abort_unless(in_array($organization->id, $manageableOrganizationIds, true), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:2000'],
            'country' => ['required', 'string', 'size:2', 'regex:/^[a-zA-Z]{2}$/'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[a-zA-Z]{3}$/'],
            'timezone' => ['required', 'timezone'],
            'locale' => ['required', 'string', 'max:10'],
            'branding.display_name' => ['nullable', 'string', 'max:255'],
            'branding.primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'branding.accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $branding = array_merge($organization->branding ?? [], $validated['branding']);
        $organization->update([
            'name' => $validated['name'],
            'registration_number' => $validated['registration_number'] ?? null,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'country' => strtoupper($validated['country']),
            'currency' => strtoupper($validated['currency']),
            'timezone' => $validated['timezone'],
            'locale' => $validated['locale'],
            'branding' => $branding,
        ]);

        AuditLog::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'action' => 'organization.updated',
            'subject_type' => Organization::class,
            'subject_id' => $organization->id,
            'metadata' => ['updated_fields' => array_keys($validated)],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return redirect()->route('organizations.index');
    }
}
