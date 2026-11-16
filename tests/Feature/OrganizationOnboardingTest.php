<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Http\Middleware\EnsureOrganizationLicensed;
use App\Models\Organization;
use App\Models\OrganizationRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrganizationOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(Organization $organization): User
    {
        $user = User::factory()->create();
        Membership::factory()->create([
            'user_id' => $user->id, 'organization_id' => $organization->id,
            'role' => 'organization_admin', 'status' => 'active', 'facility_id' => null,
        ]);

        return $user;
    }

    private function payload(): array
    {
        return [
            'legal_name' => 'Example Health Ltd', 'organization_type' => 'Clinic',
            'ownership_type' => 'private', 'owner_name' => 'Example Owner',
            'registration_authority' => 'Example Registry', 'registration_number' => 'TEST-123',
            'address_line_1' => '1 Example Road', 'city' => 'Example City',
            'state_region' => 'Example State', 'country' => 'Example Country',
            'authority_confirmed' => true, 'processing_consent' => true,
            'notice_version' => 'synthetic-2026-11-11',
        ];
    }

    public function test_admin_must_complete_onboarding_before_accessing_organization_features(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->administrator($organization);
        $this->actingAs($admin)->getJson('/api/v1/onboarding')->assertForbidden();
        $this->actingAsMfa($admin)->getJson('/api/v1/session')->assertJsonPath('data.onboarding_required', true);
        $this->getJson('/api/v1/onboarding')->assertOk()->assertJsonPath('data.0.can_complete', true);
        $this->getJson('/api/v1/organizations')->assertForbidden()
            ->assertJsonPath('code', 'onboarding_required')->assertHeader('X-Onboarding-Required', '1');
        $this->getJson("/api/v1/organizations/{$organization->id}")->assertForbidden();
        $this->patchJson("/api/v1/organizations/{$organization->id}", ['name' => 'Changed'])->assertForbidden();
        $this->getJson("/api/v1/organizations/{$organization->id}/facilities")->assertForbidden();
        $this->postJson("/api/v1/organizations/{$organization->id}/facilities", ['name' => 'Blocked'])->assertForbidden();
        $this->getJson("/api/v1/organizations/{$organization->id}/invitations")->assertForbidden();
        $this->postJson("/api/v1/organizations/{$organization->id}/invitations", ['email' => 'test@example.test'])->assertForbidden();
        $this->getJson("/api/v1/organizations/{$organization->id}/memberships")->assertForbidden();
        $this->getJson("/api/v1/organizations/{$organization->id}/licensing")->assertForbidden();
        $this->postJson("/api/v1/organizations/{$organization->id}/installations", [])->assertForbidden();
        $this->getJson("/api/v1/organizations/{$organization->id}/authorization")->assertForbidden();
        $this->postJson("/api/v1/organizations/{$organization->id}/onboarding", $this->payload())->assertCreated();
        $this->assertDatabaseHas('organization_onboardings', [
            'organization_id' => $organization->id, 'accepted_by' => $admin->id,
            'notice_version' => 'synthetic-2026-11-11', 'registration_number' => 'TEST-123',
            'authority_confirmed' => true, 'processing_consent' => true,
        ]);
        $this->assertDatabaseHas('management_audit_events', [
            'actor_id' => $admin->id, 'organization_id' => $organization->id,
            'action' => 'management.onboarding.complete', 'http_status' => 201,
        ]);
        $this->getJson('/api/v1/session')->assertJsonPath('data.onboarding_required', false);
        $this->getJson('/api/v1/organizations')->assertForbidden()->assertJsonPath('code', 'license_required');
        $this->postJson("/api/v1/organizations/{$organization->id}/onboarding", $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('organization_onboardings', 1);
    }

    public function test_consent_authority_and_notice_version_are_required_without_partial_write(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsMfa($this->administrator($organization));
        $this->postJson("/api/v1/organizations/{$organization->id}/onboarding", array_merge($this->payload(), [
            'processing_consent' => false, 'authority_confirmed' => false, 'notice_version' => 'old',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['processing_consent', 'authority_confirmed', 'notice_version']);
        $this->assertDatabaseCount('organization_onboardings', 0);
    }

    public function test_organization_details_are_visible_only_to_authorized_members(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->administrator($organization);
        $this->actingAsMfa($admin)->postJson("/api/v1/organizations/{$organization->id}/onboarding", $this->payload())->assertCreated();
        $this->withoutMiddleware(EnsureOrganizationLicensed::class);
        $this->getJson("/api/v1/organizations/{$organization->id}")->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.onboarding.registration_number', 'TEST-123')
            ->assertJsonPath('data.onboarding.accepted_by_name', $admin->name)
            ->assertJsonPath('data.onboarding.processing_consent', true);
        $facility = $organization->facilities()->create(['name' => 'Test Clinic', 'status' => 'inactive']);
        $member = User::factory()->create();
        Membership::factory()->create(['user_id' => $member->id, 'organization_id' => $organization->id, 'role' => 'facility_admin', 'status' => 'active', 'facility_id' => $facility->id]);
        $this->actingAsMfa($member)->getJson("/api/v1/organizations/{$organization->id}")->assertOk()->assertJsonPath('data.onboarding', null);
        $this->actingAsMfa(User::factory()->create())->getJson("/api/v1/organizations/{$organization->id}")->assertForbidden();
    }

    public function test_other_users_and_tenants_cannot_complete_onboarding(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $admin = $this->administrator($organization);
        $this->actingAsMfa($admin)->postJson("/api/v1/organizations/{$other->id}/onboarding", $this->payload())->assertForbidden();
        $facility = $organization->facilities()->create(['name' => 'Test Clinic', 'status' => 'inactive']);
        $user = User::factory()->create();
        Membership::factory()->create([
            'user_id' => $user->id, 'organization_id' => $organization->id,
            'role' => 'facility_admin', 'status' => 'active', 'facility_id' => $facility->id,
        ]);
        $this->actingAsMfa($user)->getJson('/api/v1/onboarding')->assertOk()->assertJsonPath('data.0.can_complete', false);
        $this->postJson("/api/v1/organizations/{$organization->id}/onboarding", $this->payload())->assertForbidden();
        $this->getJson('/api/v1/organizations')->assertForbidden();
        $this->assertDatabaseCount('organization_onboardings', 0);
    }

    public function test_custom_role_members_are_subject_to_the_onboarding_gate(): void
    {
        $organization = Organization::factory()->create();
        $role = OrganizationRole::create(['name' => 'Reader', 'permissions' => ['organization.view']]);
        $user = User::factory()->create();
        Membership::factory()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'role' => $role->membershipRole(), 'status' => 'active', 'facility_id' => null]);
        $this->actingAsMfa($user)->getJson('/api/v1/session')->assertJsonPath('data.onboarding_required', true);
        $this->getJson('/api/v1/onboarding')->assertJsonPath('data.0.can_complete', false);
        $this->getJson('/api/v1/organizations')->assertForbidden()->assertJsonPath('code', 'onboarding_required');
    }

    public function test_onboarding_requires_csrf_and_the_synthetic_gate(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->administrator($organization);
        $this->app['env'] = 'local';
        try {
            $this->actingAsMfa($admin)->withSession(['_token' => 'test-token'])
                ->postJson("/api/v1/organizations/{$organization->id}/onboarding", $this->payload())->assertStatus(419);
            config(['platform.synthetic_management_enabled' => false]);
            $this->withHeader('X-CSRF-TOKEN', 'test-token')->getJson('/api/v1/onboarding')->assertNotFound();
            $this->postJson("/api/v1/organizations/{$organization->id}/onboarding", $this->payload())->assertNotFound();
            $this->assertDatabaseCount('organization_onboardings', 0);
        } finally {
            $this->app['env'] = 'testing';
            config(['platform.synthetic_management_enabled' => true]);
        }
    }

    public function test_every_assigned_organization_must_be_completed_and_platform_provisioning_remains_available(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $admin = $this->administrator($first);
        Membership::factory()->create([
            'user_id' => $admin->id, 'organization_id' => $second->id,
            'role' => 'organization_admin', 'status' => 'active', 'facility_id' => null,
        ]);
        $admin->forceFill(['synthetic_platform_admin' => true])->save();
        $this->actingAsMfa($admin)->getJson('/api/v1/platform/organizations')->assertOk();
        $this->postJson("/api/v1/organizations/{$first->id}/onboarding", $this->payload())->assertCreated();
        $this->getJson('/api/v1/organizations')->assertForbidden();
        $this->postJson("/api/v1/organizations/{$second->id}/onboarding", $this->payload())->assertCreated();
        $this->getJson('/api/v1/organizations')->assertForbidden()->assertJsonPath('code', 'license_required');
    }

    public function test_invited_admin_cannot_reach_workspace_until_mfa_and_onboarding_are_completed(): void
    {
        config(['mail.default' => 'smtp', 'platform.synthetic_portal_url' => 'http://localhost:8000']);
        $platformAdmin = User::factory()->create();
        $platformAdmin->forceFill(['synthetic_platform_admin' => true])->save();
        $token = '';
        Mail::shouldReceive('raw')->once()->andReturnUsing(function ($text) use (&$token): void {
            preg_match('/#invite=([a-f0-9]{64})/', $text, $matches);
            $token = $matches[1];
        });
        $organizationId = $this->actingAsMfa($platformAdmin)
            ->postJson('/api/v1/platform/organizations', ['name' => 'Synthetic Organization', 'email' => 'invited@example.test'])
            ->assertCreated()->json('data.id');
        auth()->logout();
        $this->postJson('/api/v1/invitations/accept', [
            'token' => $token, 'name' => 'Invited Admin', 'password' => 'test-password',
            'password_confirmation' => 'test-password',
        ])->assertCreated();
        $this->postJson('/api/v1/auth/login', ['email' => 'invited@example.test', 'password' => 'test-password'])->assertOk();
        $this->getJson('/api/v1/session')->assertJsonPath('data.mfa', 'setup_required');
        $this->getJson('/api/v1/onboarding')->assertForbidden();
        $admin = User::where('email', 'invited@example.test')->firstOrFail();
        $this->actingAsMfa($admin)->getJson('/api/v1/session')->assertJsonPath('data.onboarding_required', true);
        $this->getJson("/api/v1/organizations/{$organizationId}")->assertForbidden();
        $this->postJson("/api/v1/organizations/{$organizationId}/onboarding", $this->payload())->assertCreated();
        $this->getJson("/api/v1/organizations/{$organizationId}")->assertForbidden()->assertJsonPath('code', 'license_required');
    }
}
