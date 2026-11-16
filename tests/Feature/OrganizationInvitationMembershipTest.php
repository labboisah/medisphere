<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationInvitationMembershipTest extends TestCase
{
    use RefreshDatabase;
    use WithoutOnboardingGate;

    private function administrator(Organization $organization): User
    {
        $user = User::factory()->create();
        Membership::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'role' => 'organization_admin',
            'facility_id' => null,
            'status' => 'active',
        ]);

        return $user;
    }

    public function test_organization_admin_can_list_resend_and_revoke_invitations(): void
    {
        config(['mail.default' => 'smtp', 'platform.synthetic_portal_url' => 'http://localhost:5187']);
        $organization = Organization::factory()->create();
        $admin = $this->administrator($organization);
        $pendingId = (string) Str::uuid();
        $expiredId = (string) Str::uuid();
        $acceptedId = (string) Str::uuid();
        $revokedId = (string) Str::uuid();
        DB::table('synthetic_invitations')->insert([
            ['id' => $pendingId, 'organization_id' => $organization->id, 'invited_by' => $admin->id, 'email' => 'pending@example.test', 'token_hash' => hash('sha256', '1'), 'expires_at' => now()->addHour(), 'accepted_at' => null, 'revoked_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $expiredId, 'organization_id' => $organization->id, 'invited_by' => $admin->id, 'email' => 'expired@example.test', 'token_hash' => hash('sha256', '2'), 'expires_at' => now()->subHour(), 'accepted_at' => null, 'revoked_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $acceptedId, 'organization_id' => $organization->id, 'invited_by' => $admin->id, 'email' => 'accepted@example.test', 'token_hash' => hash('sha256', '3'), 'expires_at' => now()->addHour(), 'accepted_at' => now(), 'revoked_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $revokedId, 'organization_id' => $organization->id, 'invited_by' => $admin->id, 'email' => 'revoked@example.test', 'token_hash' => hash('sha256', '4'), 'expires_at' => now()->addHour(), 'accepted_at' => null, 'revoked_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $role = OrganizationRole::create(['name' => 'Manager', 'permissions' => ['organization.view']]);
        DB::table('synthetic_invitations')->where('id', $expiredId)->update(['role' => $role->membershipRole()]);
        $this->actingAsMfa($admin);

        $statuses = collect(
            $this->getJson("/api/v1/organizations/{$organization->id}/invitations")
                ->assertOk()
                ->json('data')
        )->pluck('status')->all();
        $this->assertContains('pending', $statuses);
        $this->assertContains('expired', $statuses);
        $this->assertContains('accepted', $statuses);
        $this->assertContains('revoked', $statuses);

        Mail::shouldReceive('raw')->once();
        $this->postJson("/api/v1/organizations/{$organization->id}/invitations/{$expiredId}/resend")
            ->assertCreated();
        $this->assertNotNull(DB::table('synthetic_invitations')->where('id', $expiredId)->value('revoked_at'));
        $this->assertDatabaseHas('synthetic_invitations', ['organization_id' => $organization->id, 'email' => 'expired@example.test', 'accepted_at' => null]);
        $this->postJson("/api/v1/organizations/{$organization->id}/invitations/{$acceptedId}/resend")->assertUnprocessable();
        $this->deleteJson("/api/v1/organizations/{$organization->id}/invitations/{$pendingId}")
            ->assertOk();
        $this->postJson("/api/v1/organizations/{$organization->id}/invitations/{$pendingId}/resend")->assertUnprocessable();
    }

    public function test_membership_management_updates_roles_and_preserves_last_administrator(): void
    {
        $organization = Organization::factory()->create();
        $facilityA = Facility::factory()->for($organization)->create();
        $facilityB = Facility::factory()->for($organization)->create();
        $admin = $this->administrator($organization);
        $secondAdmin = User::factory()->create();
        $facilityUser = User::factory()->create();
        $suspendedUser = User::factory()->create();
        $adminMembership = Membership::query()->where('user_id', $admin->id)->where('organization_id', $organization->id)->firstOrFail();
        $secondAdminMembership = Membership::factory()->create(['user_id' => $secondAdmin->id, 'organization_id' => $organization->id, 'role' => 'organization_admin', 'facility_id' => null, 'status' => 'active']);
        $facilityMembership = Membership::factory()->create(['user_id' => $facilityUser->id, 'organization_id' => $organization->id, 'role' => 'facility_admin', 'facility_id' => $facilityA->id, 'status' => 'active']);
        $suspendedMembership = Membership::factory()->create(['user_id' => $suspendedUser->id, 'organization_id' => $organization->id, 'role' => 'organization_admin', 'facility_id' => null, 'status' => 'suspended']);
        $this->actingAsMfa($admin);

        $this->getJson("/api/v1/organizations/{$organization->id}/memberships")->assertOk()->assertJsonCount(4, 'data');
        $this->patchJson("/api/v1/organizations/{$organization->id}/memberships/{$facilityMembership->id}", [
            'role' => 'facility_admin',
            'status' => 'active',
            'facility_id' => $facilityB->id,
        ])->assertOk()->assertJsonPath('data.facility.id', $facilityB->id);
        $this->patchJson("/api/v1/organizations/{$organization->id}/memberships/{$suspendedMembership->id}", [
            'role' => 'organization_admin',
            'status' => 'active',
            'facility_id' => null,
        ])->assertOk()->assertJsonPath('data.role', 'organization_admin');
        $this->patchJson("/api/v1/organizations/{$organization->id}/memberships/{$secondAdminMembership->id}", [
            'role' => 'facility_admin',
            'status' => 'active',
            'facility_id' => $facilityA->id,
        ])->assertOk();
        $this->deleteJson("/api/v1/organizations/{$organization->id}/memberships/{$suspendedMembership->id}")->assertOk();
        $this->patchJson("/api/v1/organizations/{$organization->id}/memberships/{$secondAdminMembership->id}", ['role' => 'organization_admin', 'facility_id' => $facilityA->id])
            ->assertUnprocessable();
        $this->patchJson("/api/v1/organizations/{$organization->id}/memberships/{$secondAdminMembership->id}", ['role' => 'facility_admin', 'facility_id' => null])->assertUnprocessable();
        $this->deleteJson("/api/v1/organizations/{$organization->id}/memberships/{$adminMembership->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/organizations/{$organization->id}/memberships/{$secondAdminMembership->id}")->assertOk();
        $this->assertDatabaseHas('management_audit_events', ['organization_id' => $organization->id, 'action' => 'management.memberships.update']);
        $this->assertDatabaseHas('management_audit_events', ['organization_id' => $organization->id, 'action' => 'management.memberships.delete']);
    }

    public function test_membership_and_invitation_management_require_admin_scope(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->administrator($organization);
        $facilityUser = User::factory()->create();
        $facility = Facility::factory()->for($organization)->create();
        Membership::factory()->create(['user_id' => $facilityUser->id, 'organization_id' => $organization->id, 'role' => 'facility_admin', 'facility_id' => $facility->id, 'status' => 'active']);
        $other = User::factory()->create();
        $this->getJson("/api/v1/organizations/{$organization->id}/invitations")->assertUnauthorized();
        $this->actingAsMfa($facilityUser)->getJson("/api/v1/organizations/{$organization->id}/invitations")->assertForbidden();
        $this->actingAsMfa($facilityUser)->getJson("/api/v1/organizations/{$organization->id}/memberships")->assertForbidden();
        $this->actingAsMfa($other)->getJson("/api/v1/organizations/{$organization->id}/memberships")->assertForbidden();
        $this->actingAsMfa($admin);
        $this->app['env'] = 'local';
        try {
            $this->patchJson("/api/v1/organizations/{$organization->id}/memberships/{$organization->id}", ['status' => 'active'])->assertStatus(419);
            $this->deleteJson("/api/v1/organizations/{$organization->id}/invitations/{$organization->id}")->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_suspension_and_removal_revoke_organization_access_and_block_self_lockout(): void
    {
        $organization = Organization::factory()->create();
        $administrator = $this->administrator($organization);
        $otherAdministrator = User::factory()->create();
        Membership::factory()->create([
            'user_id' => $otherAdministrator->id,
            'organization_id' => $organization->id,
            'role' => 'organization_admin',
            'facility_id' => null,
            'status' => 'active',
        ]);
        $member = User::factory()->create();
        $membership = Membership::factory()->create([
            'user_id' => $member->id,
            'organization_id' => $organization->id,
            'role' => 'facility_admin',
            'facility_id' => Facility::factory()->for($organization)->create()->id,
            'status' => 'active',
        ]);

        $this->actingAsMfa($administrator);
        $this->patchJson("/api/v1/organizations/{$organization->id}/memberships/{$administrator->memberships()->firstOrFail()->id}", ['status' => 'suspended'])
            ->assertUnprocessable();
        $this->deleteJson("/api/v1/organizations/{$organization->id}/memberships/{$administrator->memberships()->firstOrFail()->id}")
            ->assertUnprocessable();
        $this->patchJson("/api/v1/organizations/{$organization->id}/memberships/{$membership->id}", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->actingAsMfa($member);
        $this->getJson("/api/v1/organizations/{$organization->id}/authorization")->assertForbidden();
        $this->getJson('/api/v1/organizations')->assertJsonMissing(['id' => $organization->id]);

        $this->actingAsMfa($administrator);
        $this->deleteJson("/api/v1/organizations/{$organization->id}/memberships/{$membership->id}")->assertOk();
        $this->actingAsMfa($member);
        $this->getJson("/api/v1/organizations/{$organization->id}/authorization")->assertForbidden();
    }
}
