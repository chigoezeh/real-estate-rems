<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClientManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_a_client_with_private_kyc_document(): void
    {
        Storage::fake('local');
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $identityNumber = '12345678901';

        $this->actingAs($owner)
            ->post(route('clients.store', absolute: false), [
                'organization_id' => $organization->id,
                'full_name' => 'Chinwe Okafor',
                'email' => 'chinwe@example.test',
                'phone' => '+2348012345678',
                'address' => 'Ikeja, Lagos',
                'next_of_kin_name' => 'Ada Okafor',
                'next_of_kin_phone' => '+2348098765432',
                'identity_type' => 'nin',
                'identity_number' => $identityNumber,
                'identity_document' => UploadedFile::fake()->create('nin.pdf', 20, 'application/pdf'),
            ])
            ->assertRedirect(route('clients.index', absolute: false));

        $client = Client::query()->firstOrFail();
        $this->assertSame($organization->id, $client->organization_id);
        $this->assertSame('pending', $client->kyc_status);
        $this->assertSame($identityNumber, $client->identity_number);
        $this->assertNotSame($identityNumber, DB::table('clients')->where('id', $client->id)->value('identity_number'));
        Storage::disk('local')->assertExists($client->identity_document_path);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'client.created',
            'subject_id' => $client->id,
        ]);
        $this->actingAs($owner)
            ->get(route('clients.identity-document', $client, absolute: false))
            ->assertOk()
            ->assertDownload('identity-document.pdf');

        $this->actingAs($owner)
            ->get(route('clients.index', absolute: false))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Clients/Index')
                ->where('clients.0.id', $client->id)
                ->where('clients.0.kycStatus', 'pending'));
    }

    public function test_kyc_review_requires_a_reason_when_rejecting_a_client(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $client = Client::factory()->forOrganization($organization)->create([
            'created_by_user_id' => $owner->id,
            'identity_document_path' => 'clients/test/identity/document.pdf',
        ]);

        $this->actingAs($owner)
            ->patch(route('clients.kyc.review', $client, absolute: false), ['status' => 'rejected'])
            ->assertSessionHasErrors('rejection_reason');

        $this->assertSame('pending', $client->fresh()->kyc_status);

        $this->actingAs($owner)
            ->patch(route('clients.kyc.review', $client, absolute: false), [
                'status' => 'rejected',
                'rejection_reason' => 'The identity document is not readable.',
            ])
            ->assertRedirect(route('clients.index', absolute: false));

        $this->assertSame('rejected', $client->fresh()->kyc_status);
        $this->assertSame('The identity document is not readable.', $client->fresh()->kyc_rejection_reason);
        $this->assertSame($owner->id, $client->fresh()->kyc_reviewed_by_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'client.kyc_rejected',
            'subject_id' => $client->id,
        ]);
    }

    public function test_client_access_is_scoped_to_the_assigned_organization_permissions(): void
    {
        Storage::fake('local');
        $owner = $this->installOwner();
        $root = $owner->organizations()->firstOrFail();
        $accessibleOrganization = Organization::create([
            'parent_id' => $root->id,
            'name' => 'Accessible Branch',
            'kind' => 'branch',
            'country' => 'NG',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'locale' => 'en',
        ]);
        $unrelatedOrganization = Organization::create([
            'parent_id' => $root->id,
            'name' => 'Unrelated Branch',
            'kind' => 'branch',
            'country' => 'NG',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'locale' => 'en',
        ]);
        $role = Role::query()->create([
            'organization_id' => $accessibleOrganization->id,
            'name' => 'KYC Reviewer',
            'key' => 'kyc-reviewer',
        ]);
        $role->permissions()->attach(Permission::query()
            ->whereIn('key', ['clients.view', 'clients.kyc.review'])
            ->pluck('id'));
        $user = User::factory()->create();
        $accessibleOrganization->members()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);
        $accessibleClient = Client::factory()->forOrganization($accessibleOrganization)->create([
            'identity_document_path' => 'clients/accessible/identity/document.pdf',
        ]);
        $unrelatedClient = Client::factory()->forOrganization($unrelatedOrganization)->create([
            'identity_document_path' => 'clients/unrelated/identity/document.pdf',
        ]);
        Storage::disk('local')->put($accessibleClient->identity_document_path, 'private document');
        Storage::disk('local')->put($unrelatedClient->identity_document_path, 'private document');

        $this->actingAs($user)
            ->get(route('clients.index', absolute: false))
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients', 1)
                ->where('clients.0.id', $accessibleClient->id));

        $this->actingAs($user)
            ->get(route('clients.identity-document', $unrelatedClient, absolute: false))
            ->assertNotFound();
        $this->actingAs($user)
            ->post(route('clients.store', absolute: false), [])
            ->assertForbidden();
    }

    private function installOwner(): User
    {
        $this->post('/setup', [
            'organization_name' => 'Northstar Properties',
            'country' => 'NG',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'administrator_name' => 'Ada Okafor',
            'administrator_email' => 'ada@example.test',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertRedirect(route('dashboard', absolute: false));

        return User::query()->where('email', 'ada@example.test')->firstOrFail();
    }
}
