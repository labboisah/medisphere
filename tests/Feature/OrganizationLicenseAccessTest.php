<?php

namespace Tests\Feature;

use App\Models\KernelBridgeAccountLink;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationLicenseAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationLicenseAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['kernelbridge.api_url' => 'https://kernelbridge.test/api/v1', 'kernelbridge.product_code' => 'MEDIFLOW', 'kernelbridge.product_credential' => 'synthetic-credential']);
        Http::preventStrayRequests();
    }

    private function setupOrganization(User $user, bool $admin = true): Organization
    {
        $organization = Organization::factory()->create();
        $facility = $admin ? null : $organization->facilities()->create(['name' => 'Test', 'status' => 'inactive']);
        Membership::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $admin ? 'organization_admin' : 'facility_admin', 'facility_id' => $facility?->id, 'status' => 'active']);
        DB::table('organization_onboardings')->insert([
            'organization_id' => $organization->id, 'legal_name' => 'Test', 'organization_type' => 'Test',
            'ownership_type' => 'private', 'owner_name' => 'Test', 'registration_authority' => 'Test',
            'registration_number' => 'Test', 'address_line_1' => 'Test', 'city' => 'Test',
            'state_region' => 'Test', 'country' => 'Test', 'notice_version' => 'synthetic-2026-11-11',
            'authority_confirmed' => true, 'processing_consent' => true, 'accepted_by' => $user->id,
            'accepted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $organization;
    }

    private function link(Organization $organization, array $attributes = []): KernelBridgeAccountLink
    {
        return KernelBridgeAccountLink::create(array_merge([
            'organization_id' => $organization->id, 'link_uuid' => (string) Str::uuid(),
            'license_uuid' => (string) Str::uuid(), 'product_code' => 'MEDIFLOW',
            'status' => 'approved', 'license_status' => 'active', 'entitlements' => [],
            'verified_at' => now(),
        ], $attributes));
    }

    private function fakeStatus(KernelBridgeAccountLink $link, array $attributes = []): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => array_merge([
            'uuid' => $link->link_uuid, 'license_uuid' => $link->license_uuid,
            'business_uuid' => $link->organization_id, 'product_code' => 'MEDIFLOW',
            'status' => 'approved', 'license_status' => 'active', 'entitlements' => [],
        ], $attributes)])]);
    }

    public function test_unlicensed_admin_can_open_kernelbridge_screen_but_not_organization_routes(): void
    {
        $admin = User::factory()->create();
        $organization = $this->setupOrganization($admin);
        $this->actingAsMfa($admin)->getJson('/api/v1/session')->assertJsonPath('data.license_required', true);
        $this->getJson('/api/v1/licensing/required')->assertJsonPath('data.0.can_link', true);
        $this->getJson('/api/v1/organizations')->assertForbidden()->assertHeader('X-License-Required', '1');
        $this->getJson("/api/v1/organizations/{$organization->id}/facilities")->assertForbidden();
        $this->getJson("/api/v1/organizations/{$organization->id}/licensing")->assertForbidden();
        $this->get("/organization-license/{$organization->id}")->assertOk()
            ->assertSee('Link your business license')->assertSee('KernelBridge activation code');
    }

    public function test_linking_with_kernelbridge_screen_enables_access_and_does_not_store_code(): void
    {
        $admin = User::factory()->create();
        $organization = $this->setupOrganization($admin);
        $linkId = (string) Str::uuid();
        $licenseId = (string) Str::uuid();
        Http::fake(['*' => Http::response(['success' => true, 'data' => [
            'uuid' => $linkId, 'license_uuid' => $licenseId, 'business_uuid' => $organization->id,
            'product_code' => 'MEDIFLOW', 'status' => 'approved', 'license_status' => 'active', 'entitlements' => [],
        ]])]);
        $this->actingAsMfa($admin);
        $page = $this->get("/organization-license/{$organization->id}")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/wire:snapshot="([^"]+)"/', $page);
        preg_match('/wire:snapshot="([^"]+)"/', $page, $matches);
        $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
        $response = $this->withHeader('X-Livewire', 'true')->postJson(app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => ['activationCode' => 'TEST-SECRET'],
                'calls' => [['path' => '', 'method' => 'activate', 'params' => []]],
            ]],
        ])->assertOk();
        $response->assertJsonPath('components.0.effects.redirect', url('/'));
        $this->assertStringNotContainsString('TEST-SECRET', $response->getContent());
        $this->assertDatabaseHas('kernel_bridge_account_links', ['organization_id' => $organization->id, 'link_uuid' => $linkId]);
        $this->assertDatabaseHas('management_audit_events', [
            'actor_id' => $admin->id, 'organization_id' => $organization->id, 'action' => 'management.licensing.link',
        ]);
        $this->assertStringNotContainsString('TEST-SECRET', json_encode(DB::table('management_audit_events')->get(), JSON_THROW_ON_ERROR));
        $this->getJson('/api/v1/organizations')->assertOk();
    }

    public function test_grace_is_limited_to_24_hours_and_revocation_or_expiration_blocks_access(): void
    {
        $user = User::factory()->create();
        $organization = $this->setupOrganization($user);
        $link = $this->link($organization, ['verified_at' => now()->subHours(23)]);
        $this->actingAsMfa($user);
        $revoked = false;
        Http::fake(function () use (&$revoked, $link) {
            if (! $revoked) {
                throw new ConnectionException('Synthetic outage');
            }

            return Http::response(['success' => true, 'data' => [
                'uuid' => $link->link_uuid, 'license_uuid' => $link->license_uuid,
                'business_uuid' => $link->organization_id, 'product_code' => 'MEDIFLOW',
                'status' => 'revoked', 'license_status' => 'active', 'entitlements' => [],
            ]]);
        });
        $this->getJson('/api/v1/organizations')->assertOk();
        $link->update(['verified_at' => now()->subHours(24)]);
        $this->getJson('/api/v1/organizations')->assertForbidden();
        $link->update(['verified_at' => now(), 'expires_at' => now()->subSecond()]);
        $this->getJson('/api/v1/organizations')->assertForbidden();
        $link->update(['expires_at' => null]);
        $revoked = true;
        $this->getJson('/api/v1/organizations')->assertForbidden();
        $this->assertDatabaseHas('kernel_bridge_account_links', ['id' => $link->id, 'status' => 'revoked']);
    }

    public function test_facility_members_cannot_link_and_all_assigned_organizations_must_be_licensed(): void
    {
        $user = User::factory()->create();
        $organization = $this->setupOrganization($user, false);
        $this->actingAsMfa($user)->getJson('/api/v1/licensing/required')->assertJsonPath('data.0.can_link', false);
        $this->get("/organization-license/{$organization->id}")->assertForbidden();
        $this->postJson("/api/v1/organizations/{$organization->id}/licensing/link", ['activation_code' => 'TEST'])->assertForbidden();
        $link = $this->link($organization);
        $this->fakeStatus($link);
        $this->getJson('/api/v1/organizations')->assertOk();
        $this->setupOrganization($user, false);
        $this->getJson('/api/v1/organizations')->assertForbidden();
    }

    public function test_access_checks_status_dates_and_product_at_the_exact_boundaries(): void
    {
        $user = User::factory()->create();
        $organization = $this->setupOrganization($user);
        $link = $this->link($organization);
        $access = app(OrganizationLicenseAccess::class);
        $this->assertTrue($access->permits($link));
        foreach ([
            ['status' => 'revoked'], ['license_status' => 'suspended'],
            ['product_code' => 'OTHER'], ['verified_at' => null],
            ['verified_at' => now()->subHours(24)],
            ['starts_at' => now()->addHour()], ['expires_at' => now()->subSecond()],
            ['license_status' => 'grace', 'grace_expires_at' => now()->subSecond()],
            ['subscription_uuid' => (string) Str::uuid(), 'subscription_status' => 'expired'],
            ['ends_at' => now()->subSecond()],
        ] as $invalid) {
            $candidate = clone $link;
            $candidate->fill($invalid);
            $this->assertFalse($access->permits($candidate), json_encode($invalid));
        }
        $link->fill(['license_status' => 'grace', 'subscription_status' => 'grace', 'subscription_uuid' => (string) Str::uuid(), 'ends_at' => now()->subMinute(), 'grace_expires_at' => now()->addHour()]);
        $this->assertTrue($access->permits($link));
    }

    public function test_livewire_action_rechecks_membership_after_the_screen_was_opened(): void
    {
        $admin = User::factory()->create();
        $organization = $this->setupOrganization($admin);
        $this->actingAsMfa($admin);
        $page = $this->get("/organization-license/{$organization->id}")->assertOk()->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', $page, $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
        Membership::query()->where('organization_id', $organization->id)->update(['status' => 'suspended']);
        $this->withHeader('X-Livewire', 'true')->postJson(app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot, 'updates' => ['activationCode' => 'TEST'],
                'calls' => [['path' => '', 'method' => 'activate', 'params' => []]],
            ]],
        ])->assertForbidden();
        $this->assertDatabaseCount('kernel_bridge_account_links', 0);
    }

    public function test_rejected_product_credential_does_not_use_offline_grace(): void
    {
        $user = User::factory()->create();
        $organization = $this->setupOrganization($user);
        $this->link($organization);
        Http::fake(['*' => Http::response(['success' => false], 401)]);
        $this->actingAsMfa($user)->getJson('/api/v1/organizations')->assertForbidden();
    }

    public function test_approved_link_cannot_be_replaced_without_unlinking(): void
    {
        $user = User::factory()->create();
        $organization = $this->setupOrganization($user);
        $link = $this->link($organization);
        $this->actingAsMfa($user)
            ->postJson("/api/v1/organizations/{$organization->id}/licensing/link", ['activation_code' => 'TEST'])
            ->assertStatus(409)->assertJsonPath('code', 'account_link_already_approved');
        $this->assertDatabaseHas('kernel_bridge_account_links', ['id' => $link->id, 'link_uuid' => $link->link_uuid]);
        Http::assertNothingSent();
    }
}
