<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SyntheticInvitationTest extends TestCase
{
    use RefreshDatabase;
    use WithoutOnboardingGate;

    private function administrator(Organization $organization): User
    {
        $user = User::factory()->create();
        Membership::factory()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'role' => 'organization_admin', 'facility_id' => null, 'status' => 'active']);

        return $user;
    }

    public function test_invitation_is_hashed_delivered_and_accepted_once_with_mfa_required(): void
    {
        config(['mail.default' => 'smtp', 'platform.synthetic_portal_url' => 'http://localhost:5187']);
        $organization = Organization::factory()->create();
        $administrator = $this->administrator($organization);
        $role = OrganizationRole::create(['name' => 'Manager', 'permissions' => ['organization.view']]);
        $link = '';
        Mail::shouldReceive('raw')->once()->andReturnUsing(function ($text) use (&$link): void {
            $this->assertStringContainsString('MediSphere test organization', $text);
            preg_match('/#invite=([a-f0-9]{64})/', $text, $matches);
            $link = $matches[1];
        });
        $this->actingAsMfa($administrator)->postJson("/api/v1/organizations/{$organization->id}/invitations", ['email' => 'NEW@example.test', 'role_id' => $role->id])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->assertJsonMissingPath('token');
        $invitation = DB::table('synthetic_invitations')->first();
        $this->assertSame(hash('sha256', $link), $invitation->token_hash);
        $this->assertSame('new@example.test', $invitation->email);
        $this->assertEqualsWithDelta(86400, Carbon::parse($invitation->expires_at)->diffInSeconds(Carbon::parse($invitation->created_at), true), 1);
        $this->assertDatabaseHas('management_audit_events', ['action' => 'management.invitations.create', 'organization_id' => $organization->id]);
        $this->assertStringNotContainsString($link, json_encode(DB::table('management_audit_events')->get(), JSON_THROW_ON_ERROR));
        auth()->logout();
        $payload = ['token' => $link, 'name' => 'Synthetic User', 'password' => 'test-password', 'password_confirmation' => 'test-password'];
        $this->postJson('/api/v1/invitations/accept', $payload)->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('test-password', $user->password));
        $this->assertDatabaseHas('memberships', ['user_id' => $user->id, 'organization_id' => $organization->id, 'role' => $role->membershipRole(), 'status' => 'active']);
        $this->postJson('/api/v1/invitations/accept', $payload)->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'test-password'])->assertOk();
        $this->getJson('/api/v1/session')->assertJsonPath('data.mfa', 'setup_required');
        $this->getJson('/api/v1/organizations')->assertForbidden();
    }

    public function test_expired_invalid_and_existing_account_invitations_do_not_create_memberships(): void
    {
        $organization = Organization::factory()->create();
        $administrator = $this->administrator($organization);
        $existing = User::factory()->create(['email' => 'EXISTING@example.test']);
        $token = str_repeat('a', 64);
        DB::table('synthetic_invitations')->insert(['id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'invited_by' => $administrator->id, 'email' => 'existing@example.test', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $payload = ['token' => $token, 'name' => 'Synthetic', 'password' => 'test-password', 'password_confirmation' => 'test-password'];
        $this->postJson('/api/v1/invitations/accept', $payload)->assertUnprocessable();
        $this->assertNull(DB::table('synthetic_invitations')->value('accepted_at'));
        DB::table('synthetic_invitations')->update(['email' => 'new@example.test', 'expires_at' => now()->subSecond()]);
        $this->postJson('/api/v1/invitations/accept', $payload)->assertUnprocessable();
        $this->postJson('/api/v1/invitations/accept', array_merge($payload, ['token' => str_repeat('b', 64)]))->assertUnprocessable();
        $this->assertDatabaseMissing('memberships', ['user_id' => $existing->id]);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    public function test_invitations_require_admin_mfa_and_synthetic_gate_and_smtp(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->administrator($organization);
        $role = OrganizationRole::create(['name' => 'Manager', 'permissions' => ['organization.view']]);
        $other = User::factory()->create();
        $url = "/api/v1/organizations/{$organization->id}/invitations";
        $this->postJson($url, ['email' => 'new@example.test'])->assertUnauthorized();
        $this->actingAs($admin)->postJson($url, ['email' => 'new@example.test'])->assertForbidden();
        $this->actingAsMfa($other)->postJson($url, ['email' => 'new@example.test'])->assertForbidden();
        config(['platform.synthetic_management_enabled' => false]);
        $this->actingAsMfa($admin)->postJson($url, ['email' => 'new@example.test'])->assertNotFound();
        $this->postJson('/api/v1/invitations/accept', [])->assertNotFound();
        config(['platform.synthetic_management_enabled' => true, 'mail.default' => 'log', 'platform.synthetic_portal_url' => 'http://localhost:5187']);
        $this->actingAsMfa($admin)->postJson($url, ['email' => 'new@example.test', 'role_id' => $role->id])->assertServerError();
        $this->assertDatabaseCount('synthetic_invitations', 0);
    }

    public function test_mail_delivery_failure_rolls_back_invitation(): void
    {
        config(['mail.default' => 'smtp', 'platform.synthetic_portal_url' => 'http://localhost:5187']);
        $organization = Organization::factory()->create();
        $admin = $this->administrator($organization);
        $role = OrganizationRole::create(['name' => 'Manager', 'permissions' => ['organization.view']]);
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('Synthetic transport failure'));
        $this->actingAsMfa($admin)->postJson("/api/v1/organizations/{$organization->id}/invitations", ['email' => 'new@example.test', 'role_id' => $role->id])->assertServerError();
        $this->assertDatabaseCount('synthetic_invitations', 0);
    }

    public function test_acceptance_requires_csrf_in_local_and_is_unavailable_in_production(): void
    {
        $this->app['env'] = 'local';
        try {
            $this->withSession(['_token' => 'expected'])->postJson('/api/v1/invitations/accept', [])->assertStatus(419);
            $this->app['env'] = 'production';
            $this->withHeader('X-CSRF-TOKEN', 'expected')->postJson('/api/v1/invitations/accept', [])->assertNotFound();
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
