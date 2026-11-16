<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationManagementTest extends TestCase
{
    use RefreshDatabase;
    use WithoutOnboardingGate;

    private function membership(User $user, Organization $organization, string $role, ?Facility $facility = null, string $status = 'active'): void
    {
        Membership::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'facility_id' => $facility?->id,
            'role' => $role,
            'status' => $status,
        ]);
    }

    public function test_guests_cannot_use_management_endpoints(): void
    {
        $facility = Facility::factory()->create();
        $organization = $facility->organization;

        $this->getJson('/api/v1/organizations')->assertUnauthorized();
        $this->getJson("/api/v1/organizations/{$organization->id}")->assertUnauthorized();
        $this->patchJson("/api/v1/organizations/{$organization->id}", ['name' => 'Changed'])->assertUnauthorized();
        $this->getJson("/api/v1/organizations/{$organization->id}/facilities")->assertUnauthorized();
        $this->postJson("/api/v1/organizations/{$organization->id}/facilities", ['name' => 'New'])->assertUnauthorized();
        $this->getJson("/api/v1/organizations/{$organization->id}/facilities/{$facility->id}")->assertUnauthorized();
        $this->patchJson("/api/v1/organizations/{$organization->id}/facilities/{$facility->id}", ['name' => 'Changed'])->assertUnauthorized();
    }

    public function test_management_api_is_disabled_unless_explicitly_enabled_for_non_production(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($user, $organization, 'organization_admin');
        $this->actingAsMfa($user);

        config(['platform.synthetic_management_enabled' => false]);
        $this->getJson('/api/v1/organizations')->assertNotFound();
        $this->postJson("/api/v1/organizations/{$organization->id}/facilities", ['name' => 'Denied'])->assertNotFound();

        config(['platform.synthetic_management_enabled' => true]);
        $this->app['env'] = 'production';
        try {
            $this->getJson('/api/v1/organizations')->assertNotFound();
            $this->withSession(['_token' => 'test-csrf-token'])
                ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
                ->postJson("/api/v1/organizations/{$organization->id}/facilities", ['name' => 'Denied'])
                ->assertNotFound();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_management_writes_require_csrf_protection(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($user, $organization, 'organization_admin');
        $this->actingAsMfa($user);
        $this->app['env'] = 'local';
        try {
            $this->withSession(['_token' => 'test-csrf-token'])
                ->postJson("/api/v1/organizations/{$organization->id}/facilities", ['name' => 'Denied'])
                ->assertStatus(419);
            $this->withSession(['_token' => 'test-csrf-token'])
                ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
                ->postJson("/api/v1/organizations/{$organization->id}/facilities", ['name' => 'Allowed'])
                ->assertCreated();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_organization_admin_can_manage_only_their_own_organization_and_facilities(): void
    {
        $this->assertSame(true, config('platform.synthetic_management_enabled'));
        $this->assertTrue(app()->environment('testing'));
        $user = User::factory()->create();
        $organization = Organization::factory()->create(['legal_identity_reference' => 'private']);
        $other = Organization::factory()->create();
        $otherFacility = Facility::factory()->for($other)->create();
        $this->membership($user, $organization, 'organization_admin');
        $this->actingAsMfa($user);

        $this->getJson('/api/v1/organizations')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $organization->id)
            ->assertJsonMissingPath('data.0.legal_identity_reference');
        $this->getJson("/api/v1/organizations/{$organization->id}")
            ->assertOk()->assertJsonMissingPath('data.legal_identity_reference')->assertJsonPath('data.can_manage', true);
        $this->patchJson("/api/v1/organizations/{$organization->id}", ['name' => 'New organization name'])
            ->assertOk()->assertJsonPath('data.name', 'New organization name');
        $created = $this->postJson("/api/v1/organizations/{$organization->id}/facilities", [
            'name' => 'New facility', 'locale' => 'en', 'time_zone' => 'Africa/Lagos',
        ])->assertCreated()
            ->assertJsonPath('data.organization_id', $organization->id)
            ->assertJsonPath('data.status', 'inactive');
        $id = $created->json('data.id');
        $this->getJson("/api/v1/organizations/{$organization->id}/facilities")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/v1/organizations/{$organization->id}/facilities/{$id}")->assertOk();
        $this->patchJson("/api/v1/organizations/{$organization->id}/facilities/{$id}", ['name' => 'Updated facility'])
            ->assertOk()->assertJsonPath('data.name', 'Updated facility');
        $this->getJson("/api/v1/organizations/{$other->id}")->assertForbidden();
        $this->getJson('/api/v1/organizations/not-a-uuid')->assertNotFound();
        $this->getJson("/api/v1/organizations/{$organization->id}/facilities/not-a-uuid")->assertNotFound();
        $this->postJson("/api/v1/organizations/{$other->id}/facilities", ['name' => 'Denied'])->assertForbidden();
        $this->getJson("/api/v1/organizations/{$organization->id}/facilities/{$otherFacility->id}")->assertNotFound();
        $this->patchJson("/api/v1/organizations/{$organization->id}/facilities/{$otherFacility->id}", ['name' => 'Denied'])->assertNotFound();
        $this->deleteJson("/api/v1/organizations/{$organization->id}/facilities/{$id}")->assertMethodNotAllowed();
    }

    public function test_facility_admin_sees_only_assigned_facilities_and_cannot_manage_them(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $assigned = Facility::factory()->for($organization)->create();
        $second = Facility::factory()->for($organization)->create();
        $unassigned = Facility::factory()->for($organization)->create();
        $otherFacility = Facility::factory()->create();
        $this->membership($user, $organization, 'facility_admin', $assigned);
        $this->membership($user, $organization, 'facility_admin', $second);
        $this->actingAsMfa($user);

        $this->getJson('/api/v1/organizations')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/organizations/{$organization->id}")->assertOk()->assertJsonPath('data.can_manage', false);
        $this->getJson("/api/v1/organizations/{$organization->id}/facilities")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonMissing(['id' => $unassigned->id]);
        $this->getJson("/api/v1/organizations/{$organization->id}/facilities/{$assigned->id}")->assertOk();
        $this->getJson("/api/v1/organizations/{$organization->id}/facilities/{$unassigned->id}")->assertForbidden();
        $this->getJson("/api/v1/organizations/{$otherFacility->organization_id}/facilities/{$otherFacility->id}")->assertForbidden();
        $this->getJson("/api/v1/organizations/{$organization->id}/facilities/{$otherFacility->id}")->assertNotFound();
        $this->patchJson("/api/v1/organizations/{$organization->id}", ['name' => 'Denied'])->assertForbidden();
        $this->postJson("/api/v1/organizations/{$organization->id}/facilities", ['name' => 'Denied'])->assertForbidden();
        $this->patchJson("/api/v1/organizations/{$organization->id}/facilities/{$assigned->id}", ['name' => 'Denied'])->assertForbidden();
    }

    public function test_inactive_unknown_and_mis_scoped_roles_do_not_grant_access(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $facility = Facility::factory()->for($organization)->create();
        $this->membership($user, $organization, 'organization_admin', null, 'suspended');
        $this->membership($user, $organization, 'organization_admin', $facility);
        $this->membership($user, $organization, 'facility_admin');
        $this->membership($user, $organization, 'clinician', $facility);
        $this->actingAsMfa($user);

        $this->getJson('/api/v1/organizations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/organizations/{$organization->id}")->assertForbidden();
        $this->getJson("/api/v1/organizations/{$organization->id}/facilities")->assertForbidden();
        $this->postJson("/api/v1/organizations/{$organization->id}/facilities", ['name' => 'Denied'])->assertForbidden();
    }

    public function test_organization_admin_access_is_not_limited_by_another_membership(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $first = Facility::factory()->for($organization)->create();
        $second = Facility::factory()->for($organization)->create();
        $this->membership($user, $organization, 'facility_admin', $first);
        $this->membership($user, $organization, 'organization_admin');

        $this->actingAsMfa($user)->getJson("/api/v1/organizations/{$organization->id}/facilities/{$second->id}")->assertOk();
    }

    public function test_invalid_or_privileged_fields_are_rejected_and_names_are_unique_per_organization(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $facility = Facility::factory()->for($organization)->create(['name' => 'Existing']);
        Facility::factory()->for($other)->create(['name' => 'Other tenant']);
        $this->membership($user, $organization, 'organization_admin');
        $this->actingAsMfa($user);

        $this->patchJson("/api/v1/organizations/{$organization->id}", ['name' => 'Test', 'status' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/organizations/{$organization->id}/facilities", ['name' => 'Existing'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson("/api/v1/organizations/{$organization->id}/facilities", ['name' => 'Test', 'organization_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors('organization_id');
        $this->postJson("/api/v1/organizations/{$organization->id}/facilities", ['name' => 'Test', 'time_zone' => 'not-a-timezone'])
            ->assertUnprocessable()->assertJsonValidationErrors('time_zone');
        $this->patchJson("/api/v1/organizations/{$organization->id}/facilities/{$facility->id}", ['status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson("/api/v1/organizations/{$organization->id}/facilities/{$facility->id}", [])
            ->assertUnprocessable()->assertJsonValidationErrors('facility');
        $this->patchJson("/api/v1/organizations/{$organization->id}/facilities/{$facility->id}", ['locale' => null])
            ->assertOk()->assertJsonPath('data.locale', null);
        $this->patchJson("/api/v1/organizations/{$organization->id}/facilities/{$facility->id}", ['name' => 'Existing'])->assertOk();
        $this->postJson("/api/v1/organizations/{$organization->id}/facilities", ['name' => 'Other tenant'])->assertCreated();
    }

    public function test_facility_lists_are_paginated(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($user, $organization, 'organization_admin');
        Facility::factory()->count(26)->for($organization)->create();

        $this->actingAsMfa($user)->getJson("/api/v1/organizations/{$organization->id}/facilities")
            ->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('total', 26)->assertJsonPath('per_page', 25);
        $this->getJson("/api/v1/organizations/{$organization->id}/facilities?page=2")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_organization_lists_are_paginated_and_do_not_duplicate_memberships(): void
    {
        $user = User::factory()->create();
        $organizations = Organization::factory()->count(26)->create();
        foreach ($organizations as $organization) {
            $this->membership($user, $organization, 'organization_admin');
        }
        $this->membership($user, $organizations->first(), 'organization_admin');

        $this->actingAsMfa($user)->getJson('/api/v1/organizations')
            ->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('total', 26)->assertJsonPath('per_page', 25);
        $this->getJson('/api/v1/organizations?per_page=100')
            ->assertOk()->assertJsonCount(26, 'data')->assertJsonPath('total', 26)->assertJsonPath('per_page', 100);
        $this->getJson('/api/v1/organizations?page=2')->assertOk()->assertJsonCount(1, 'data');
    }
}
