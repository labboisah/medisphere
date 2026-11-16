<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;
    use WithoutOnboardingGate;

    public function test_csrf_token_endpoint_starts_a_server_managed_session(): void
    {
        $this->getJson('/api/v1/csrf-token')
            ->assertOk()
            ->assertJsonStructure(['data' => ['token']])
            ->assertSessionHas('_token');
    }

    public function test_authenticated_user_can_read_the_current_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/session')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'synthetic_platform_admin' => false,
                    'mfa' => 'setup_required',
                    'onboarding_required' => false,
                    'license_required' => false,
                ],
            ]);
    }

    public function test_unauthenticated_user_cannot_read_the_current_session(): void
    {
        $this->getJson('/api/v1/session')
            ->assertUnauthorized();
    }

    public function test_login_uses_the_server_managed_session_guard(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_confirmed_two_factor_authentication_requires_a_challenge_before_login(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk()
            ->assertExactJson(['two_factor' => true]);

        $this->assertGuest();
    }

    public function test_public_registration_route_is_not_available(): void
    {
        $this->postJson('/api/v1/auth/register')
            ->assertNotFound();
    }

    public function test_active_organization_membership_is_required_for_authorization_check(): void
    {
        $organization = Organization::factory()->create();
        $member = User::factory()->create();
        Membership::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $member->id,
            'status' => 'active',
        ]);

        $this->actingAsMfa($member)
            ->getJson("/api/v1/organizations/{$organization->id}/authorization")
            ->assertOk()
            ->assertJsonPath('data.organization_id', $organization->id);
    }

    public function test_membership_in_another_organization_is_denied(): void
    {
        $member = User::factory()->create();
        Membership::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'user_id' => $member->id,
            'status' => 'active',
        ]);
        $otherOrganization = Organization::factory()->create();

        $this->actingAsMfa($member)
            ->getJson("/api/v1/organizations/{$otherOrganization->id}/authorization")
            ->assertForbidden();
    }
}
