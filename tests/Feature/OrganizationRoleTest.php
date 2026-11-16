<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrganizationRoleTest extends TestCase
{
    use RefreshDatabase;
    use WithoutOnboardingGate;

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        Membership::factory()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'role' => $role, 'status' => 'active', 'facility_id' => null]);

        return $user;
    }

    public function test_only_platform_admin_can_manage_valid_roles_and_changes_take_effect_immediately(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->member($organization, 'organization_admin');
        $platform = User::factory()->create();
        $platform->forceFill(['synthetic_platform_admin' => true])->save();
        $this->actingAsMfa($admin)->getJson('/api/v1/platform/roles')->assertForbidden();
        $this->postJson('/api/v1/platform/roles', ['name' => 'Manager', 'permissions' => []])->assertForbidden();
        $this->actingAsMfa($platform)->postJson('/api/v1/platform/roles', ['name' => 'Manager', 'permissions' => ['unknown']])->assertUnprocessable();
        $this->postJson('/api/v1/platform/roles', ['name' => 'Manager', 'permissions' => ['invalid-key' => 'users.manage']])->assertUnprocessable();
        $this->postJson('/api/v1/platform/roles', ['name' => 'Organization Administrator', 'permissions' => []])->assertUnprocessable();
        $id = $this->postJson('/api/v1/platform/roles', ['name' => 'Manager', 'permissions' => ['organization.view']])->assertCreated()->json('data.id');
        $role = OrganizationRole::findOrFail($id);
        $member = $this->member($organization, $role->membershipRole());
        $this->actingAsMfa($member)->getJson('/api/v1/organizations')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/organizations/{$organization->id}")->assertOk()->assertJsonPath('data.permissions.0', 'organization.view');
        $this->getJson("/api/v1/organizations/{$organization->id}/memberships")->assertForbidden();
        $this->actingAsMfa($platform)->patchJson("/api/v1/platform/roles/{$id}", ['name' => 'Manager', 'permissions' => ['users.manage']])->assertOk();
        $this->assertDatabaseCount('organization_role_changes', 2);
        $this->actingAsMfa($member)->getJson("/api/v1/organizations/{$organization->id}/memberships")->assertOk();
        $this->getJson("/api/v1/organizations/{$organization->id}")->assertJsonMissing(['organization.view']);
    }

    public function test_invites_are_tenant_scoped_and_cannot_exceed_the_inviter_permissions(): void
    {
        config(['mail.default' => 'smtp', 'platform.synthetic_portal_url' => 'http://localhost:8000']);
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $managerRole = OrganizationRole::create(['name' => 'Manager', 'permissions' => ['invitations.manage', 'organization.view']]);
        $lowRole = OrganizationRole::create(['name' => 'Reader', 'permissions' => ['organization.view']]);
        $highRole = OrganizationRole::create(['name' => 'License manager', 'permissions' => ['license.manage']]);
        $manager = $this->member($organization, $managerRole->membershipRole());
        $token = '';
        Mail::shouldReceive('raw')->once()->andReturnUsing(function ($text) use (&$token): void {
            preg_match('/#invite=([a-f0-9]{64})/', $text, $matches);
            $token = $matches[1];
        });
        $this->actingAsMfa($manager);
        $url = "/api/v1/organizations/{$organization->id}/invitations";
        $this->postJson($url, ['email' => 'bad@example.test'])->assertUnprocessable();
        $this->postJson($url, ['email' => 'bad@example.test', 'role_id' => $highRole->id])->assertForbidden();
        $this->getJson("/api/v1/organizations/{$organization->id}/roles")->assertOk()
            ->assertJsonMissing(['name' => $highRole->name])->assertJsonMissingPath('data.0.permissions');
        $this->postJson("/api/v1/organizations/{$other->id}/invitations", ['email' => 'bad@example.test', 'role_id' => $lowRole->id])->assertForbidden();
        $this->postJson($url, ['email' => 'reader@example.test', 'role_id' => $lowRole->id])->assertCreated();
        auth()->logout();
        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'Reader', 'password' => 'test-password', 'password_confirmation' => 'test-password'])->assertCreated();
        $user = User::where('email', 'reader@example.test')->firstOrFail();
        $this->assertDatabaseHas('memberships', ['user_id' => $user->id, 'organization_id' => $organization->id, 'role' => $lowRole->membershipRole()]);
        $this->assertDatabaseMissing('memberships', ['user_id' => $user->id, 'organization_id' => $other->id]);
    }

    public function test_pending_invitation_is_rejected_if_issuer_loses_permission(): void
    {
        config(['mail.default' => 'smtp', 'platform.synthetic_portal_url' => 'http://localhost:8000']);
        $organization = Organization::factory()->create();
        $role = OrganizationRole::create(['name' => 'Inviter', 'permissions' => ['invitations.manage']]);
        $reader = OrganizationRole::create(['name' => 'Reader', 'permissions' => []]);
        $issuer = $this->member($organization, $role->membershipRole());
        $token = '';
        Mail::shouldReceive('raw')->once()->andReturnUsing(function ($text) use (&$token): void {
            preg_match('/#invite=([a-f0-9]{64})/', $text, $matches);
            $token = $matches[1];
        });
        $this->actingAsMfa($issuer)->postJson("/api/v1/organizations/{$organization->id}/invitations", ['email' => 'reader@example.test', 'role_id' => $reader->id])->assertCreated();
        $role->update(['permissions' => []]);
        auth()->logout();
        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'Reader', 'password' => 'test-password', 'password_confirmation' => 'test-password'])->assertUnprocessable();
        $this->assertNull(DB::table('synthetic_invitations')->value('accepted_at'));
    }

    public function test_pending_invitation_is_rejected_if_its_role_gains_superior_permissions(): void
    {
        config(['mail.default' => 'smtp', 'platform.synthetic_portal_url' => 'http://localhost:8000']);
        $organization = Organization::factory()->create();
        $issuerRole = OrganizationRole::create(['name' => 'Inviter', 'permissions' => ['invitations.manage']]);
        $targetRole = OrganizationRole::create(['name' => 'Reader', 'permissions' => []]);
        $issuer = $this->member($organization, $issuerRole->membershipRole());
        $token = '';
        Mail::shouldReceive('raw')->once()->andReturnUsing(function ($text) use (&$token): void {
            preg_match('/#invite=([a-f0-9]{64})/', $text, $matches);
            $token = $matches[1];
        });
        $this->actingAsMfa($issuer)->postJson("/api/v1/organizations/{$organization->id}/invitations", ['email' => 'reader@example.test', 'role_id' => $targetRole->id])->assertCreated();
        $targetRole->update(['permissions' => ['license.manage']]);
        auth()->logout();
        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'Reader', 'password' => 'test-password', 'password_confirmation' => 'test-password'])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => 'reader@example.test']);
    }

    public function test_membership_manager_cannot_promote_or_modify_superior_roles(): void
    {
        $organization = Organization::factory()->create();
        $role = OrganizationRole::create(['name' => 'People manager', 'permissions' => ['users.manage']]);
        $high = OrganizationRole::create(['name' => 'Owner', 'permissions' => ['users.manage', 'license.manage']]);
        $manager = $this->member($organization, $role->membershipRole());
        $admin = $this->member($organization, 'organization_admin');
        $superior = $this->member($organization, $high->membershipRole());
        $target = $this->member($organization, $role->membershipRole());
        $this->actingAsMfa($manager);
        $adminMembership = $admin->memberships()->firstOrFail();
        $superiorMembership = $superior->memberships()->firstOrFail();
        $targetMembership = $target->memberships()->firstOrFail();
        $base = "/api/v1/organizations/{$organization->id}/memberships";
        $this->deleteJson("{$base}/{$adminMembership->id}")->assertForbidden();
        $this->deleteJson("{$base}/{$superiorMembership->id}")->assertForbidden();
        $this->patchJson("{$base}/{$targetMembership->id}", ['role' => $high->membershipRole()])->assertForbidden();
        $this->patchJson("{$base}/{$targetMembership->id}", ['role' => 'organization_admin'])->assertUnprocessable();
        $this->deleteJson("{$base}/{$targetMembership->id}")->assertOk();
    }

    public function test_server_permission_does_not_grant_license_management_or_other_organizations(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $role = OrganizationRole::create(['name' => 'Server manager', 'permissions' => ['servers.manage']]);
        $member = $this->member($organization, $role->membershipRole());
        $this->actingAsMfa($member)->getJson("/api/v1/organizations/{$organization->id}/licensing")->assertOk();
        $this->postJson("/api/v1/organizations/{$organization->id}/licensing/link", ['activation_code' => 'synthetic'])->assertForbidden();
        $this->getJson("/api/v1/organizations/{$other->id}/licensing")->assertForbidden();
        $this->getJson("/api/v1/organizations/{$organization->id}/memberships")->assertForbidden();
    }
}
