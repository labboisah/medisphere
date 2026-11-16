<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Tests\TestCase;

class MfaEnforcementTest extends TestCase
{
    use RefreshDatabase;
    use WithoutOnboardingGate;

    public function test_unenrolled_and_unverified_sessions_cannot_access_organization_data(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        Membership::factory()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'role' => 'organization_admin', 'status' => 'active']);
        $this->actingAs($user);

        $this->getJson('/api/v1/session')->assertJsonPath('data.mfa', 'setup_required');
        $this->getJson('/api/v1/organizations')->assertForbidden()->assertJsonPath('code', 'mfa_required');
        $this->getJson("/api/v1/organizations/{$organization->id}/authorization")->assertForbidden();
        $user->forceFill(['two_factor_secret' => encrypt('synthetic'), 'two_factor_confirmed_at' => now()])->save();
        $this->getJson('/api/v1/session')->assertJsonPath('data.mfa', 'sign_in_again');
        $this->getJson('/api/v1/organizations')->assertForbidden();
    }

    public function test_enrollment_requires_password_confirmation_and_marks_session_only_after_valid_code(): void
    {
        $user = User::factory()->create(['password' => Hash::make('test-password')]);
        $provider = $this->mock(TwoFactorAuthenticationProvider::class);
        $provider->shouldReceive('generateSecretKey')->andReturn('synthetic-secret');
        $provider->shouldReceive('verify')->with('synthetic-secret', '000000')->once()->andReturn(false);
        $provider->shouldReceive('verify')->with('synthetic-secret', '123456')->once()->andReturn(true);
        $this->actingAs($user);

        $this->postJson('/api/v1/auth/user/two-factor-authentication')->assertStatus(423);
        $this->postJson('/api/v1/auth/user/confirm-password', ['password' => 'test-password'])->assertSuccessful();
        $this->postJson('/api/v1/auth/user/two-factor-authentication')->assertSuccessful();
        $this->getJson('/api/v1/auth/user/two-factor-secret-key')->assertOk()->assertJsonPath('secretKey', 'synthetic-secret')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/organizations')->assertForbidden();
        $this->postJson('/api/v1/auth/user/confirmed-two-factor-authentication', ['code' => '000000'])->assertUnprocessable();
        $this->getJson('/api/v1/session')->assertJsonPath('data.mfa', 'setup_required');
        $this->postJson('/api/v1/auth/user/confirmed-two-factor-authentication', ['code' => '123456'])->assertSuccessful();
        $this->getJson('/api/v1/session')->assertJsonPath('data.mfa', 'verified');
        $this->getJson('/api/v1/organizations')->assertOk();
        $this->getJson('/api/v1/auth/user/two-factor-recovery-codes')->assertOk()->assertJsonCount(8)->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_verified_recovery_code_authenticates_the_session_and_secret_changes_revoke_it(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('test-password'),
            'two_factor_secret' => encrypt('synthetic-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['synthetic-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'test-password'])
            ->assertOk()->assertJsonPath('two_factor', true);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['recovery_code' => 'synthetic-recovery-code'])->assertSuccessful();
        $this->getJson('/api/v1/session')->assertJsonPath('data.mfa', 'verified');
        $this->getJson('/api/v1/organizations')->assertOk();
        $user->forceFill(['two_factor_secret' => encrypt('new-secret')])->save();
        auth()->forgetGuards();
        $this->getJson('/api/v1/organizations')->assertForbidden();
    }

    public function test_password_only_session_cannot_read_or_change_existing_mfa_settings(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('test-password'),
            'two_factor_secret' => encrypt('synthetic-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['synthetic-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);

        $this->getJson('/api/v1/auth/user/two-factor-secret-key')->assertForbidden();
        $this->getJson('/api/v1/auth/user/two-factor-qr-code')->assertForbidden();
        $this->getJson('/api/v1/auth/user/two-factor-recovery-codes')->assertForbidden();
        $this->postJson('/api/v1/auth/user/two-factor-authentication', ['force' => true])->assertForbidden();
        $this->postJson('/api/v1/auth/user/confirmed-two-factor-authentication', ['code' => '123456'])->assertForbidden();
        $this->deleteJson('/api/v1/auth/user/two-factor-authentication')->assertForbidden();
        $this->putJson('/api/v1/auth/user/password', ['current_password' => 'test-password', 'password' => 'new-test-password', 'password_confirmation' => 'new-test-password'])->assertForbidden();
    }

    public function test_qr_code_requires_authentication_and_password_confirmation_and_is_not_cached(): void
    {
        $this->getJson('/api/v1/auth/user/two-factor-qr-code')->assertUnauthorized();
        $user = User::factory()->create(['password' => Hash::make('test-password')]);
        $this->actingAs($user);
        $this->getJson('/api/v1/auth/user/two-factor-qr-code')->assertStatus(423);
        $this->postJson('/api/v1/auth/user/confirm-password', ['password' => 'test-password'])->assertSuccessful();
        $this->postJson('/api/v1/auth/user/two-factor-authentication')->assertSuccessful();
        $response = $this->getJson('/api/v1/auth/user/two-factor-qr-code')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonStructure(['svg', 'url']);
        $this->assertStringContainsString('<svg', $response->json('svg'));
        $this->assertStringStartsWith('otpauth://totp/', $response->json('url'));
    }

    public function test_recovery_code_access_requires_recent_password_and_rotation_requires_verified_mfa(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('test-password'),
            'two_factor_secret' => encrypt('synthetic-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['old-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);

        $this->getJson('/api/v1/auth/user/two-factor-recovery-codes')->assertForbidden();
        $this->postJson('/api/v1/auth/user/two-factor-recovery-codes')->assertForbidden();

        $this->actingAsMfa($user)->withSession(['auth.password_confirmed_at' => now()->subMinutes(6)->timestamp]);
        $this->getJson('/api/v1/auth/user/two-factor-recovery-codes')->assertStatus(423);
        $this->postJson('/api/v1/auth/user/two-factor-recovery-codes')->assertStatus(423);

        $this->postJson('/api/v1/auth/user/confirm-password', ['password' => 'test-password'])->assertSuccessful();
        $this->getJson('/api/v1/auth/user/two-factor-recovery-codes')
            ->assertOk()
            ->assertJsonFragment(['old-recovery-code'])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/v1/auth/user/two-factor-recovery-codes')
            ->assertSuccessful()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Correlation-ID');

        $codes = $this->getJson('/api/v1/auth/user/two-factor-recovery-codes')
            ->assertOk()
            ->assertJsonCount(8)
            ->json();
        $this->assertNotContains('old-recovery-code', $codes);
        $this->assertDatabaseHas('management_audit_events', [
            'actor_id' => $user->id,
            'action' => 'account.mfa.recovery_codes.regenerate',
            'http_status' => 200,
        ]);
    }
}
