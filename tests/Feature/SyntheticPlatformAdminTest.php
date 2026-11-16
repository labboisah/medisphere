<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\SyntheticPlatformAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class SyntheticPlatformAdminTest extends TestCase
{
    use RefreshDatabase;
    use WithoutOnboardingGate;

    private function platformAdmin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['synthetic_platform_admin' => true])->save();

        return $user;
    }

    public function test_seeder_creates_separate_user_with_password_and_no_membership_and_refuses_repeat(): void
    {
        $this->assertSame(0, Artisan::call('db:seed', ['--class' => SyntheticPlatformAdminSeeder::class]));
        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/One-time displayed password: ([A-Za-z0-9]{32})/', $output);
        preg_match('/One-time displayed password: ([A-Za-z0-9]{32})/', $output, $matches);
        $user = User::where('email', 'synthetic-platform-admin@example.test')->firstOrFail();
        $this->assertTrue($user->synthetic_platform_admin);
        $this->assertTrue(Hash::check($matches[1], $user->password));
        $this->assertDatabaseCount('memberships', 0);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $matches[1]])->assertOk();
        $this->getJson('/api/v1/session')->assertJsonPath('data.mfa', 'setup_required')->assertJsonPath('data.synthetic_platform_admin', true);
        try {
            Artisan::call('db:seed', ['--class' => SyntheticPlatformAdminSeeder::class]);
            $this->fail('Existing accounts must not be overwritten.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already exists', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 1);
    }

    public function test_platform_permissions_require_mfa_and_do_not_bypass_tenant_access(): void
    {
        $admin = $this->platformAdmin();
        $organization = Organization::factory()->create();
        $this->getJson('/api/v1/platform/organizations')->assertUnauthorized();
        $this->actingAs($admin)->getJson('/api/v1/platform/organizations')->assertForbidden();
        $this->actingAsMfa($admin)->getJson('/api/v1/platform/organizations')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/organizations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/organizations/{$organization->id}")->assertForbidden();
        $this->getJson("/api/v1/organizations/{$organization->id}/facilities")->assertForbidden();
        $this->postJson("/api/v1/organizations/{$organization->id}/facilities", ['name' => 'Denied'])->assertForbidden();
        $other = User::factory()->create();
        Membership::factory()->create(['user_id' => $other->id, 'organization_id' => $organization->id, 'role' => 'organization_admin', 'facility_id' => null, 'status' => 'active']);
        $this->actingAsMfa($other)->getJson('/api/v1/platform/organizations')->assertForbidden();
        $this->postJson('/api/v1/platform/organizations', ['name' => 'Denied', 'email' => 'test@example.test'])->assertForbidden();
        config(['platform.synthetic_management_enabled' => false]);
        $this->actingAsMfa($admin)->getJson('/api/v1/platform/organizations')->assertNotFound();
        config(['platform.synthetic_management_enabled' => true]);
        $this->app['env'] = 'production';
        try {
            $this->getJson('/api/v1/platform/organizations')->assertNotFound();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_platform_provisions_and_invites_without_granting_membership(): void
    {
        config(['mail.default' => 'smtp', 'platform.synthetic_portal_url' => 'http://localhost:5187']);
        $admin = $this->platformAdmin();
        $token = '';
        Mail::shouldReceive('raw')->once()->andReturnUsing(function ($text) use (&$token): void {
            preg_match('/#invite=([a-f0-9]{64})/', $text, $matches);
            $token = $matches[1];
        });
        $response = $this->actingAsMfa($admin)->postJson('/api/v1/platform/organizations', ['name' => 'Synthetic clinic group', 'email' => 'new@example.test'])->assertCreated();
        $id = $response->json('data.id');
        $this->assertDatabaseHas('organizations', ['id' => $id, 'status' => 'inactive']);
        $this->assertDatabaseHas('synthetic_invitations', ['organization_id' => $id, 'invited_by' => $admin->id, 'email' => 'new@example.test']);
        $this->assertDatabaseHas('management_audit_events', ['action' => 'management.platform.organizations.create', 'actor_id' => $admin->id, 'organization_id' => $id]);
        $this->assertDatabaseCount('memberships', 0);
        $this->getJson("/api/v1/organizations/{$id}")->assertForbidden();
        auth()->logout();
        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'New Administrator', 'password' => 'test-password', 'password_confirmation' => 'test-password'])->assertCreated();
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertDatabaseHas('memberships', ['organization_id' => $id, 'user_id' => $user->id, 'role' => 'organization_admin', 'status' => 'active']);
        $this->actingAs($user)->getJson('/api/v1/organizations')->assertForbidden();
        $this->actingAsMfa($user)->getJson("/api/v1/organizations/{$id}")->assertOk()->assertJsonPath('data.can_manage', true);
    }

    public function test_role_cannot_be_granted_by_mass_assignment_and_seeder_is_disabled_outside_synthetic_mode(): void
    {
        $user = User::create(['name' => 'Test', 'email' => 'test@example.test', 'password' => 'test-password', 'synthetic_platform_admin' => true]);
        $this->assertFalse($user->fresh()->synthetic_platform_admin);
        config(['platform.synthetic_management_enabled' => false]);
        try {
            Artisan::call('db:seed', ['--class' => SyntheticPlatformAdminSeeder::class]);
            $this->fail('Disabled synthetic mode must reject the seeder.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Synthetic management must be enabled', $exception->getMessage());
        }
        config(['platform.synthetic_management_enabled' => true]);
        $this->app['env'] = 'production';
        try {
            Artisan::call('db:seed', ['--class' => SyntheticPlatformAdminSeeder::class, '--force' => true]);
            $this->fail('Production must reject the seeder.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Synthetic management must be enabled', $exception->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_existing_account_and_delivery_failure_roll_back_organization(): void
    {
        config(['mail.default' => 'smtp', 'platform.synthetic_portal_url' => 'http://localhost:5187']);
        $admin = $this->platformAdmin();
        User::factory()->create(['email' => 'existing@example.test']);
        $this->actingAsMfa($admin)->postJson('/api/v1/platform/organizations', ['name' => 'Existing account', 'email' => 'existing@example.test'])->assertUnprocessable();
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('Synthetic transport failure'));
        $this->postJson('/api/v1/platform/organizations', ['name' => 'Failed email', 'email' => 'new@example.test'])->assertServerError();
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('synthetic_invitations', 0);
    }

    public function test_platform_provisioning_enforces_csrf_in_local(): void
    {
        $admin = $this->platformAdmin();
        $this->actingAsMfa($admin);
        $this->app['env'] = 'local';
        try {
            $this->withSession(['_token' => 'expected'])->postJson('/api/v1/platform/organizations', ['name' => 'Test', 'email' => 'test@example.test'])->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
