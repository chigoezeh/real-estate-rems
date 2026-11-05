<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasPermission('clients.view'), 403);
        $organizationIds = Organization::accessibleIdsFor($user, 'clients.view');

        return Inertia::render('Clients/Index', [
            'clients' => Client::query()
                ->with('organization:id,name')
                ->whereIn('organization_id', $organizationIds)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Client $client): array => [
                    'id' => $client->id,
                    'organizationId' => $client->organization_id,
                    'organizationName' => $client->organization->name,
                    'fullName' => $client->full_name,
                    'email' => $client->email,
                    'phone' => $client->phone,
                    'identityType' => $client->identity_type,
                    'kycStatus' => $client->kyc_status,
                    'hasIdentityDocument' => $client->identity_document_path !== null,
                    'createdAt' => $client->created_at?->toIso8601String(),
                ])
                ->values(),
            'organizations' => Organization::query()
                ->whereIn('id', Organization::accessibleIdsFor($user, 'clients.manage'))
                ->orderBy('name')
                ->get(['id', 'name']),
            'canManage' => $user->hasPermission('clients.manage'),
            'canReviewKyc' => $user->hasPermission('clients.kyc.review'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('clients.manage'), 403);

        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:2000'],
            'next_of_kin_name' => ['nullable', 'string', 'max:255'],
            'next_of_kin_phone' => ['nullable', 'string', 'max:40'],
            'identity_type' => ['required', 'string', 'in:nin,drivers_license,international_passport'],
            'identity_number' => ['required', 'string', 'max:100'],
            'identity_document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $organizationIds = Organization::accessibleIdsFor($user, 'clients.manage');
        abort_unless(in_array($validated['organization_id'], $organizationIds, true), 404);

        $clientId = (string) Str::uuid();
        $documentPath = $validated['identity_document']->store("clients/{$clientId}/identity", 'local');
        abort_unless($documentPath !== false, 500);

        try {
            DB::transaction(function () use ($request, $user, $validated, $clientId, $documentPath): void {
                $client = Client::create([
                    'id' => $clientId,
                    'organization_id' => $validated['organization_id'],
                    'created_by_user_id' => $user->id,
                    'full_name' => $validated['full_name'],
                    'email' => $validated['email'] ?? null,
                    'phone' => $validated['phone'],
                    'address' => $validated['address'] ?? null,
                    'next_of_kin_name' => $validated['next_of_kin_name'] ?? null,
                    'next_of_kin_phone' => $validated['next_of_kin_phone'] ?? null,
                    'identity_type' => $validated['identity_type'],
                    'identity_number' => $validated['identity_number'],
                    'identity_document_path' => $documentPath,
                    'kyc_status' => 'pending',
                ]);

                AuditLog::create([
                    'organization_id' => $client->organization_id,
                    'user_id' => $user->id,
                    'action' => 'client.created',
                    'subject_type' => Client::class,
                    'subject_id' => $client->id,
                    'metadata' => ['full_name' => $client->full_name, 'kyc_status' => $client->kyc_status],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($documentPath);
            throw $exception;
        }

        return redirect()->route('clients.index');
    }

    public function downloadIdentityDocument(Request $request, Client $client): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('clients.kyc.review'), 403);
        abort_unless(in_array($client->organization_id, Organization::accessibleIdsFor($user, 'clients.kyc.review'), true), 404);
        abort_if($client->identity_document_path === null, 404);

        $extension = pathinfo($client->identity_document_path, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($client->identity_document_path, "identity-document.{$extension}");
    }

    public function reviewKyc(Request $request, Client $client): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('clients.kyc.review'), 403);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['verified', 'rejected'])],
            'rejection_reason' => ['nullable', 'required_if:status,rejected', 'string', 'max:2000'],
        ]);
        $organizationIds = Organization::accessibleIdsFor($user, 'clients.kyc.review');
        abort_unless(in_array($client->organization_id, $organizationIds, true), 404);

        DB::transaction(function () use ($request, $user, $client, $validated): void {
            $lockedClient = Client::query()->lockForUpdate()->findOrFail($client->id);
            $status = $validated['status'];

            $lockedClient->update([
                'kyc_status' => $status,
                'kyc_reviewed_by_user_id' => $user->id,
                'kyc_reviewed_at' => now(),
                'kyc_rejection_reason' => $status === 'rejected' ? $validated['rejection_reason'] : null,
            ]);

            AuditLog::create([
                'organization_id' => $lockedClient->organization_id,
                'user_id' => $user->id,
                'action' => "client.kyc_{$status}",
                'subject_type' => Client::class,
                'subject_id' => $lockedClient->id,
                'metadata' => ['status' => $status],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        return redirect()->route('clients.index');
    }
}
