<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\SyntheticOrganizationAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class SyntheticOrganizationAdminSeederTest extends TestCase
{
    use RefreshDatabase;
    use WithoutOnboardingGate;

    public function test_seeded_administrator_can_sign_in_and_requires_mfa(): void
    {
        $this->assertSame(0, Artisan::call('db:seed', ['--class' => SyntheticOrganizationAdminSeeder::class]));
        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/One-time displayed password: ([A-Za-z0-9]{32})/', $output);
        preg_match('/One-time displayed password: ([A-Za-z0-9]{32})/', $output, $matches);

        $user = User::where('email', 'synthetic-admin@example.test')->firstOrFail();
        $this->assertTrue(Hash::check($matches[1], $user->password));
        $organization = Organization::where('name', 'Synthetic Healthcare Organization')->firstOrFail();
        $this->assertDatabaseHas('memberships', [
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'facility_id' => null,
            'role' => 'organization_admin',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('facilities', ['organization_id' => $organization->id, 'name' => 'Synthetic Clinic']);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $matches[1]])->assertOk();
        $this->getJson('/api/v1/session')->assertOk()->assertJsonPath('data.mfa', 'setup_required');
        $this->getJson('/api/v1/organizations')->assertForbidden();
        $this->actingAsMfa($user)->getJson("/api/v1/organizations/{$organization->id}")
            ->assertOk()->assertJsonPath('data.can_manage', true);
    }

    public function test_existing_account_is_not_modified_or_given_new_membership(): void
    {
        $user = User::factory()->create(['email' => 'synthetic-admin@example.test']);
        $password = $user->password;

        try {
            Artisan::call('db:seed', ['--class' => SyntheticOrganizationAdminSeeder::class]);
            $this->fail('The seeder must refuse an existing account.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already exists', $exception->getMessage());
        }

        $this->assertSame($password, $user->fresh()->password);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_seeder_rejects_disabled_gate_and_production(): void
    {
        config(['platform.synthetic_management_enabled' => false]);
        try {
            Artisan::call('db:seed', ['--class' => SyntheticOrganizationAdminSeeder::class]);
            $this->fail('The seeder must reject a disabled synthetic gate.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Synthetic management must be enabled', $exception->getMessage());
        }

        config(['platform.synthetic_management_enabled' => true]);
        $this->app['env'] = 'production';
        try {
            Artisan::call('db:seed', ['--class' => SyntheticOrganizationAdminSeeder::class, '--force' => true]);
            $this->fail('The seeder must reject production.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Synthetic management must be enabled', $exception->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertDatabaseCount('users', 0);
    }
}
