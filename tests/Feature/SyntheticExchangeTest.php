<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SyntheticExchangeTest extends TestCase
{
    use RefreshDatabase;
    use WithoutOnboardingGate;

    public function test_synthetic_reference_review_and_demo_grant_lifecycle_never_accepts_clinical_payloads(): void
    {
        config(['platform.synthetic_management_enabled' => true]);
        $organization = Organization::factory()->create();
        $facility = Facility::factory()->for($organization)->create();
        $user = User::factory()->create();
        Membership::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'role' => 'organization_admin',
            'status' => 'active',
        ]);
        $this->actingAsMfa($user);

        $base = "/api/v1/organizations/{$organization->id}/synthetic-exchange";
        $created = $this->postJson("{$base}/links", [
            'facility_id' => $facility->id,
            'patient_name' => 'Must never be accepted',
            'local_subject_reference' => (string) Str::uuid(),
            'recipient_subject_reference' => (string) Str::uuid(),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('synthetic_exchange_links', 0);

        $created = $this->postJson("{$base}/links", [
            'facility_id' => $facility->id,
        ])->assertCreated()
            ->assertJsonPath('data.review_status', 'pending')
            ->assertJsonPath('data.facility_id', $facility->id);
        $localReference = $created->json('data.local_subject_reference');
        $this->assertTrue(Str::isUuid($localReference));
        $this->assertTrue(Str::isUuid($created->json('data.recipient_subject_reference')));
        $link = $created->json('data.id');

        $this->postJson("{$base}/links/{$link}/grants", [])->assertUnprocessable();
        $this->postJson("{$base}/links/{$link}/review", ['decision' => 'confirmed'])
            ->assertOk()->assertJsonPath('data.review_status', 'confirmed');
        $this->postJson("{$base}/links/{$link}/grants", ['clinical_payload' => ['example' => 'must be rejected']])
            ->assertUnprocessable();
        $this->assertDatabaseCount('synthetic_sharing_grants', 0);
        $grant = $this->postJson("{$base}/links/{$link}/grants", [])->assertCreated()
            ->assertJsonPath('data.purpose', 'synthetic-workflow-demonstration')
            ->assertJsonPath('data.scope.0', 'synthetic-demonstration-summary')
            ->assertJsonPath('data.status', 'active');
        $grantId = $grant->json('data.id');
        $expiresAt = \Illuminate\Support\Carbon::parse($grant->json('data.expires_at'));
        $this->assertTrue($expiresAt->greaterThan(now()));
        $this->assertTrue($expiresAt->lessThanOrEqualTo(now()->addDay()->addSecond()));
        $this->postJson("{$base}/links/{$link}/grants", [])->assertUnprocessable();
        $this->deleteJson("{$base}/grants/{$grantId}")->assertOk()
            ->assertJsonPath('data.status', 'revoked');
        $this->deleteJson("{$base}/grants/{$grantId}")->assertUnprocessable();
        $expiredGrantId = $this->postJson("{$base}/links/{$link}/grants", [])->assertCreated()->json('data.id');
        DB::table('synthetic_sharing_grants')->where('id', $expiredGrantId)
            ->update(['expires_at' => now()->subSecond()]);

        $list = $this->getJson("{$base}/links")->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.0.review_status', 'confirmed');
        $grantStatuses = array_column($list->json('data.0.grants'), 'status');
        sort($grantStatuses);
        $this->assertSame(['expired', 'revoked'], $grantStatuses);
        $this->assertStringNotContainsString('Must never be accepted', $list->getContent());
        $this->assertDatabaseHas('management_audit_events', [
            'organization_id' => $organization->id,
            'facility_id' => $facility->id,
            'action' => 'management.synthetic-exchange.links.create',
            'actor_id' => $user->id,
        ]);
        $this->assertStringNotContainsString($localReference, json_encode(DB::table('management_audit_events')->get(), JSON_THROW_ON_ERROR));
    }

    public function test_synthetic_exchange_requires_permission_and_organization_scoping(): void
    {
        config(['platform.synthetic_management_enabled' => true]);
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $facility = Facility::factory()->for($organization)->create();
        $otherFacility = Facility::factory()->for($otherOrganization)->create();
        $user = User::factory()->create();
        Membership::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'role' => 'facility_admin',
            'facility_id' => $facility->id,
            'status' => 'active',
        ]);
        $this->actingAsMfa($user);

        $this->getJson("/api/v1/organizations/{$organization->id}/synthetic-exchange/links")->assertForbidden();
        $this->getJson("/api/v1/organizations/{$otherOrganization->id}/synthetic-exchange/links")->assertForbidden();

        $administrator = User::factory()->create();
        Membership::factory()->create([
            'user_id' => $administrator->id,
            'organization_id' => $organization->id,
            'role' => 'organization_admin',
            'status' => 'active',
        ]);
        $this->actingAsMfa($administrator)->postJson("/api/v1/organizations/{$organization->id}/synthetic-exchange/links", [
            'facility_id' => $otherFacility->id,
        ])->assertUnprocessable();
    }

    public function test_synthetic_exchange_routes_remain_unavailable_in_production(): void
    {
        config(['platform.synthetic_management_enabled' => true]);
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        Membership::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'role' => 'organization_admin',
            'status' => 'active',
        ]);
        $this->actingAsMfa($user);
        $originalEnvironment = $this->app['env'];
        try {
            foreach (['production', 'staging'] as $environment) {
                $this->app['env'] = $environment;
                $this->getJson("/api/v1/organizations/{$organization->id}/synthetic-exchange/links")->assertNotFound();
            }
        } finally {
            $this->app['env'] = $originalEnvironment;
        }
    }
}
