<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicensed;
use App\Http\Middleware\EnsureOrganizationOnboarded;
use App\Models\Facility;
use App\Models\KernelBridgeAccountLink;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OperationalSyncInstallation;
use App\Models\ServerEnrollmentCode;
use App\Models\ServerInstallation;
use App\Models\User;
use App\Support\OperationalSyncIngestor;
use App\Support\KernelBridgeLicensingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KernelBridgeLicensingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureOrganizationOnboarded::class);
        $this->withoutMiddleware(EnsureOrganizationLicensed::class);
        config([
            'kernelbridge.api_url' => 'https://kernelbridge.test/api/v1',
            'kernelbridge.product_code' => 'MEDIFLOW',
            'kernelbridge.product_credential' => 'test-product-credential',
            'kernelbridge.timeout_seconds' => 10,
        ]);
    }

    public function test_admin_links_account_and_issues_installation_bound_code_without_persisting_code_or_credential(): void
    {
        $organization = Organization::factory()->create();
        $administrator = $this->administrator($organization);
        $facility = Facility::factory()->for($organization)->create();
        $linkUuid = (string) Str::uuid();
        $licenseUuid = (string) Str::uuid();
        $expiresAt = now()->addDay()->toISOString();
        Http::fake(function (ClientRequest $request) use ($organization, $linkUuid, $licenseUuid, $expiresAt) {
            if ($request->url() === 'https://kernelbridge.test/api/v1/platform/account-links/requests') {
                return Http::response(['success' => true, 'data' => [
                    'uuid' => $linkUuid,
                    'license_uuid' => $licenseUuid,
                    'subscription_uuid' => (string) Str::uuid(),
                    'product_code' => 'MEDIFLOW',
                    'business_uuid' => strtolower($organization->id),
                    'status' => 'approved',
                    'license_status' => 'active',
                    'subscription_status' => 'active',
                    'plan' => ['name' => 'Professional'],
                    'entitlement_revision' => 8,
                    'entitlements' => [['key' => 'clinical.records', 'enabled' => true]],
                    'expires_at' => $expiresAt,
                ]]);
            }
            if (str_ends_with($request->url(), '/activation-codes')) {
                return Http::response(['success' => true, 'data' => [
                    'link_uuid' => $linkUuid,
                    'installation_uuid' => $request->data()['installation_uuid'],
                    'activation_code' => 'ONE-TIME-SECRET',
                    'expires_at' => $expiresAt,
                ]], 201);
            }

            return Http::response(['success' => false], 500);
        });
        $this->actingAsMfa($administrator);

        $this->postJson("/api/v1/organizations/{$organization->id}/licensing/link", ['activation_code' => ' OWNER-ACTIVATION-CODE '])
            ->assertCreated()
            ->assertJsonPath('data.link_uuid', $linkUuid)
            ->assertJsonPath('data.plan_name', 'Professional')
            ->assertJsonPath('data.entitlement_revision', 8)
            ->assertJsonMissingPath('data.activation_code');

        $link = KernelBridgeAccountLink::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame($licenseUuid, $link->license_uuid);
        $this->assertDatabaseMissing('kernel_bridge_account_links', ['activation_code' => 'OWNER-ACTIVATION-CODE']);
        $this->getJson("/api/v1/organizations/{$organization->id}/licensing")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.link.plan_name', 'Professional')
            ->assertJsonPath('data.link.entitlements.0.key', 'clinical.records');

        $registration = $this->postJson("/api/v1/organizations/{$organization->id}/installations", [
            'facility_id' => $facility->id,
            'name' => 'Main clinic server',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'registered')
            ->json('data');
        $this->assertTrue(Str::isUuid($registration['installation_uuid']));

        $code = $this->postJson("/api/v1/organizations/{$organization->id}/installations/{$registration['id']}/activation-code")
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.installation_uuid', $registration['installation_uuid'])
            ->assertJsonPath('data.activation_code', 'ONE-TIME-SECRET')
            ->assertJsonPath('data.expires_at', $expiresAt);
        $this->assertSame('ONE-TIME-SECRET', $code->json('data.activation_code'));
        $activationRequest = Http::recorded(fn (ClientRequest $request) => str_ends_with($request->url(), '/activation-codes'))->last()[0];
        $activationRequestPayload = $activationRequest->data();
        $this->assertSame('https://kernelbridge.test/api/v1/platform/account-links/'.$linkUuid.'/activation-codes', $activationRequest->url());
        $this->assertSame($registration['installation_uuid'], $activationRequestPayload['installation_uuid']);
        $this->assertSame('Main clinic server', $activationRequestPayload['device_name']);
        $this->assertSame('activation_code_issued', ServerInstallation::query()->findOrFail($registration['id'])->status);
        $this->assertDatabaseMissing('server_installations', ['activation_code' => 'ONE-TIME-SECRET']);
        $this->assertDatabaseMissing('server_installations', ['credential' => 'test-product-credential']);
        Http::assertSent(fn (ClientRequest $request) => $request->hasHeader('Authorization', 'Bearer test-product-credential'));
    }

    public function test_refresh_unlink_and_individual_revocation_update_local_state_after_kernelbridge_confirms(): void
    {
        $organization = Organization::factory()->create();
        $administrator = $this->administrator($organization);
        $facility = Facility::factory()->for($organization)->create();
        $link = KernelBridgeAccountLink::query()->create([
            'organization_id' => $organization->id,
            'link_uuid' => (string) Str::uuid(),
            'license_uuid' => (string) Str::uuid(),
            'product_code' => 'MEDIFLOW',
            'status' => 'approved',
            'license_status' => 'active',
            'entitlements' => [],
            'verified_at' => now()->subDay(),
        ]);
        $installation = ServerInstallation::query()->create([
            'organization_id' => $organization->id,
            'facility_id' => $facility->id,
            'installation_uuid' => (string) Str::uuid(),
            'name' => 'Test server',
            'status' => 'activation_code_issued',
            'registered_by' => $administrator->id,
        ]);
        Http::fake(function (ClientRequest $request) use ($link, $installation, $organization) {
            if ($request->method() === 'GET') {
                return Http::response(['success' => true, 'data' => [
                    'uuid' => $link->link_uuid,
                    'license_uuid' => $link->license_uuid,
                    'business_uuid' => strtolower($organization->id),
                    'product_code' => 'MEDIFLOW',
                    'status' => 'approved',
                    'license_status' => 'active',
                    'subscription_status' => 'active',
                    'plan' => ['name' => 'Renewed'],
                    'entitlements' => [],
                ]]);
            }

            if (str_ends_with($request->url(), '/revoke')) {
                return Http::response(['success' => true, 'data' => [
                    'link_uuid' => $link->link_uuid,
                    'installation_uuid' => $installation->installation_uuid,
                    'status' => 'revoked',
                ]]);
            }

            return Http::response(['success' => true, 'data' => [
                'uuid' => $link->link_uuid,
                'license_uuid' => $link->license_uuid,
                'business_uuid' => strtolower($organization->id),
                'status' => 'revoked',
            ]]);
        });
        $this->actingAsMfa($administrator);

        $this->postJson("/api/v1/organizations/{$organization->id}/licensing/refresh")
            ->assertOk()
            ->assertJsonPath('data.plan_name', 'Renewed');
        $this->assertSame('Renewed', $link->fresh()->plan_name);

        $this->postJson("/api/v1/organizations/{$organization->id}/installations/{$installation->id}/revoke")
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');
        $this->assertSame('revoked', $installation->fresh()->status);

        $this->deleteJson("/api/v1/organizations/{$organization->id}/licensing/link")
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');
        $this->assertSame('revoked', $link->fresh()->status);
    }

    public function test_missing_credentials_and_product_mismatch_fail_without_success_fallback(): void
    {
        $organization = Organization::factory()->create();
        $administrator = $this->administrator($organization);
        $this->actingAsMfa($administrator);
        config(['kernelbridge.product_credential' => null]);

        $this->postJson("/api/v1/organizations/{$organization->id}/licensing/link", ['activation_code' => 'synthetic-code'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'licensing_not_configured');
        $this->assertDatabaseCount('kernel_bridge_account_links', 0);

        config(['kernelbridge.product_credential' => 'configured-test-credential']);
        Http::fake([
            '*' => Http::response(['success' => true, 'data' => [
                'uuid' => (string) Str::uuid(),
                'license_uuid' => (string) Str::uuid(),
                'business_uuid' => strtolower((string) Str::uuid()),
                'product_code' => 'OTHER',
                'status' => 'approved',
                'entitlements' => [],
            ]]),
        ]);
        $this->postJson("/api/v1/organizations/{$organization->id}/licensing/link", ['activation_code' => 'synthetic-code'])
            ->assertStatus(502)
            ->assertJsonPath('code', 'invalid_link_response');
        $this->assertDatabaseCount('kernel_bridge_account_links', 0);
    }

    public function test_licensing_management_requires_an_active_organization_administrator(): void
    {
        $organization = Organization::factory()->create();
        $facility = Facility::factory()->for($organization)->create();
        $user = User::factory()->create();
        Membership::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'facility_id' => $facility->id,
            'role' => 'facility_admin',
            'status' => 'active',
        ]);

        $this->actingAsMfa($user)
            ->getJson("/api/v1/organizations/{$organization->id}/licensing")
            ->assertForbidden();
    }

    public function test_admin_generates_account_bound_server_code_without_registering_a_server(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->administrator($organization);
        $link = KernelBridgeAccountLink::query()->create([
            'organization_id' => $organization->id, 'link_uuid' => (string) Str::uuid(),
            'license_uuid' => (string) Str::uuid(), 'product_code' => 'MEDIFLOW',
            'status' => 'approved', 'license_status' => 'active', 'entitlements' => [], 'verified_at' => now(),
        ]);
        $expiry = now()->addDay()->toISOString();
        Http::fake(['*' => Http::response(['success' => true, 'data' => [
            'link_uuid' => $link->link_uuid, 'activation_code_uuid' => (string) Str::uuid(),
            'activation_code' => 'SYNTHETIC-SERVER-CODE', 'expires_at' => $expiry,
        ]], 201)]);
        $url = "/api/v1/organizations/{$organization->id}/server-enrollment-codes";
        $this->actingAsMfa(User::factory()->create())->postJson($url)->assertForbidden();
        $result = $this->actingAsMfa($admin)->postJson($url)->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.activation_code', 'SYNTHETIC-SERVER-CODE')
            ->assertJsonPath('data.expires_at', $expiry)
            ->assertJsonMissingPath('data.installation_uuid');
        $codeId = $result->json('data.id');
        $this->assertNotSame('SYNTHETIC-SERVER-CODE', DB::table('server_enrollment_codes')->where('id', $codeId)->value('encrypted_code'));
        $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.0.id', $codeId)->assertDontSee('SYNTHETIC-SERVER-CODE');
        $this->postJson("$url/$codeId/reveal")->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.activation_code', 'SYNTHETIC-SERVER-CODE');
        $this->assertDatabaseHas('management_audit_events', ['action' => 'management.installations.enrollment-code-reveal', 'organization_id' => $organization->id]);
        $this->assertDatabaseCount('server_installations', 0);
        $this->assertDatabaseHas('management_audit_events', ['action' => 'management.installations.enrollment-code', 'organization_id' => $organization->id]);
        $this->assertStringNotContainsString('SYNTHETIC-SERVER-CODE', json_encode(\Illuminate\Support\Facades\DB::table('management_audit_events')->get(), JSON_THROW_ON_ERROR));
        Http::assertSent(fn (ClientRequest $request) => $request->url() === "https://kernelbridge.test/api/v1/platform/account-links/{$link->link_uuid}/server-enrollment-codes");
    }

    public function test_saved_enrollment_codes_are_scoped_and_expired_codes_cannot_be_revealed(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $admin = $this->administrator($organization);
        $otherAdmin = $this->administrator($other);
        $link = KernelBridgeAccountLink::query()->create([
            'organization_id' => $organization->id, 'link_uuid' => (string) Str::uuid(),
            'license_uuid' => (string) Str::uuid(), 'product_code' => 'MEDIFLOW',
            'status' => 'approved', 'license_status' => 'active', 'entitlements' => [],
        ]);
        KernelBridgeAccountLink::query()->create([
            'organization_id' => $other->id, 'link_uuid' => (string) Str::uuid(),
            'license_uuid' => (string) Str::uuid(), 'product_code' => 'MEDIFLOW',
            'status' => 'approved', 'license_status' => 'active', 'entitlements' => [],
        ]);
        $code = ServerEnrollmentCode::query()->create([
            'organization_id' => $organization->id, 'link_uuid' => $link->link_uuid,
            'encrypted_code' => 'PRIVATE-CODE', 'expires_at' => now()->addHour(),
        ]);
        $this->actingAsMfa($otherAdmin)->postJson("/api/v1/organizations/{$other->id}/server-enrollment-codes/{$code->id}/reveal")->assertNotFound();
        $this->actingAsMfa($otherAdmin)->postJson("/api/v1/organizations/{$organization->id}/server-enrollment-codes/{$code->id}/reveal")->assertForbidden();
        $this->actingAsMfa($admin);
        $code->update(['expires_at' => now()->subSecond()]);
        $this->getJson("/api/v1/organizations/{$organization->id}/server-enrollment-codes")->assertExactJson(['data' => []]);
        $this->postJson("/api/v1/organizations/{$organization->id}/server-enrollment-codes/{$code->id}/reveal")->assertNotFound();
        $this->artisan('schedule:run')->assertSuccessful();
        $this->assertDatabaseMissing('server_enrollment_codes', ['id' => $code->id]);
    }

    public function test_unlink_deletes_saved_enrollment_codes(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->administrator($organization);
        $link = KernelBridgeAccountLink::query()->create([
            'organization_id' => $organization->id, 'link_uuid' => (string) Str::uuid(),
            'license_uuid' => (string) Str::uuid(), 'product_code' => 'MEDIFLOW',
            'status' => 'approved', 'license_status' => 'active', 'entitlements' => [],
        ]);
        $code = ServerEnrollmentCode::query()->create([
            'organization_id' => $organization->id, 'link_uuid' => $link->link_uuid,
            'encrypted_code' => 'PRIVATE-CODE', 'expires_at' => now()->addHour(),
        ]);
        Http::fake(['*' => Http::response(['success' => true, 'data' => [
            'uuid' => $link->link_uuid, 'license_uuid' => $link->license_uuid,
            'business_uuid' => strtolower($organization->id), 'status' => 'revoked',
        ]])]);
        $this->actingAsMfa($admin)->deleteJson("/api/v1/organizations/{$organization->id}/licensing/link")->assertOk();
        $this->assertDatabaseMissing('server_enrollment_codes', ['id' => $code->id]);
    }

    public function test_invalid_enrollment_response_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->administrator($organization);
        $link = KernelBridgeAccountLink::query()->create([
            'organization_id' => $organization->id, 'link_uuid' => (string) Str::uuid(),
            'license_uuid' => (string) Str::uuid(), 'product_code' => 'MEDIFLOW',
            'status' => 'approved', 'license_status' => 'active', 'entitlements' => [], 'verified_at' => now(),
        ]);
        Http::fake(['*' => Http::response(['success' => true, 'data' => [
            'link_uuid' => $link->link_uuid, 'activation_code_uuid' => (string) Str::uuid(),
            'activation_code' => 'SYNTHETIC-SERVER-CODE', 'expires_at' => now()->subMinute()->toISOString(),
        ]], 201)]);
        $this->actingAsMfa($admin)->postJson("/api/v1/organizations/{$organization->id}/server-enrollment-codes")
            ->assertStatus(502)->assertJsonPath('code', 'invalid_activation_code_response');
    }

    public function test_activated_server_inventory_is_authorized_scoped_and_rejects_mismatched_response(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->administrator($organization);
        $other = User::factory()->create();
        $link = KernelBridgeAccountLink::query()->create([
            'organization_id' => $organization->id, 'link_uuid' => (string) Str::uuid(),
            'license_uuid' => (string) Str::uuid(), 'product_code' => 'MEDIFLOW',
            'status' => 'approved', 'license_status' => 'active', 'entitlements' => [],
        ]);
        $serverUuid = (string) Str::uuid();
        $payload = [
            'link_uuid' => $link->link_uuid, 'business_uuid' => strtolower($organization->id),
            'installations' => [[
                'installation_uuid' => $serverUuid, 'device_name' => 'Clinic server', 'runtime_type' => 'server',
                'status' => 'active', 'activated_at' => now()->toISOString(), 'last_verified_at' => null,
                'expires_at' => null, 'credential_hash' => 'must-not-be-returned',
            ]],
            'current_page' => 1, 'last_page' => 1, 'total' => 1,
        ];
        Http::fake(['*' => Http::sequence()
            ->push(['success' => true, 'data' => $payload])
            ->push(['success' => true, 'data' => [...$payload, 'business_uuid' => (string) Str::uuid()]])
            ->push(['success' => false], 500)]);
        $url = "/api/v1/organizations/{$organization->id}/activated-servers";
        $this->actingAsMfa($other)->getJson($url)->assertForbidden();
        $this->actingAsMfa($admin)->getJson($url)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.0.installation_uuid', $serverUuid)
            ->assertJsonPath('data.0.device_name', 'Clinic server')
            ->assertJsonMissingPath('data.0.credential_hash')
            ->assertJsonPath('total', 1);
        $this->getJson($url)->assertStatus(502)->assertJsonPath('code', 'invalid_installations_response');
        $this->getJson($url)->assertStatus(503)->assertJsonPath('code', 'licensing_service_unavailable');
    }

    public function test_operational_health_is_tenant_and_account_link_scoped_and_disabled_by_default(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = $this->administrator($organization);
        $link = KernelBridgeAccountLink::query()->create([
            'organization_id' => $organization->id, 'link_uuid' => (string) Str::uuid(),
            'license_uuid' => (string) Str::uuid(), 'product_code' => 'MEDIFLOW',
            'status' => 'approved', 'license_status' => 'active', 'entitlements' => [],
        ]);
        $serverUuid = (string) Str::uuid();
        $payload = [
            'link_uuid' => $link->link_uuid, 'business_uuid' => strtolower($organization->id),
            'installations' => [[
                'installation_uuid' => $serverUuid, 'device_name' => 'Clinic server', 'runtime_type' => 'server',
                'status' => 'active', 'activated_at' => null, 'last_verified_at' => null, 'expires_at' => null,
            ]],
            'current_page' => 1, 'last_page' => 1, 'total' => 1,
        ];
        Http::fake(['*' => Http::response(['success' => true, 'data' => $payload])]);
        $url = "/api/v1/organizations/{$organization->id}/activated-servers";
        $sync = OperationalSyncInstallation::query()->create([
            'organization_id' => $otherOrganization->id, 'installation_uuid' => $serverUuid,
            'link_uuid' => $link->link_uuid, 'status' => 'active',
        ]);
        $json = json_encode(['version' => '1.2', 'pending_count' => 2, 'failed_count' => 1, 'enabled_modules' => ['pharmacy']], JSON_THROW_ON_ERROR);
        $event = [
            'event_id' => (string) Str::uuid(), 'sequence' => 1,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'payload_json' => $json, 'payload_sha256' => hash('sha256', $json),
        ];
        app(OperationalSyncIngestor::class)->accept($sync, $event);
        config(['operational_sync.enrollment_enabled' => true]);
        $this->actingAsMfa($admin)->getJson($url)->assertOk()->assertJsonMissingPath('data.0.operational_health')
            ->assertJsonPath('operational_sync_enrollment_enabled', true);
        config(['operational_sync.enrollment_enabled' => false]);
        config(['operational_sync.monitoring_enabled' => true]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.operational_health.status', 'unknown');

        $sync = OperationalSyncInstallation::query()->create([
            'organization_id' => $organization->id, 'installation_uuid' => $serverUuid,
            'link_uuid' => (string) Str::uuid(), 'status' => 'active',
        ]);
        $event['event_id'] = (string) Str::uuid();
        app(OperationalSyncIngestor::class)->accept($sync, $event);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.operational_health.status', 'unknown');
        $sync->update(['link_uuid' => $link->link_uuid]);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.0.operational_health.status', 'online')
            ->assertJsonPath('data.0.operational_health.failed_count', 1)
            ->assertJsonPath('data.0.operational_health.enabled_modules.0', 'pharmacy');
        DB::table('operational_sync_events')->where('id', $event['event_id'])->update([
            'occurred_at' => now()->subMinutes(20),
        ]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.operational_health.status', 'offline');
        DB::table('operational_sync_events')->where('id', $event['event_id'])->update([
            'occurred_at' => now()->addMinutes(10),
        ]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.operational_health.status', 'unknown');
        DB::table('operational_sync_events')->where('id', $event['event_id'])->update([
            'occurred_at' => now(),
        ]);
        $this->travel(6)->minutes();
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.operational_health.status', 'delayed');
        $this->travel(10)->minutes();
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.operational_health.status', 'offline');
        $sync->update(['status' => 'revoked']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.operational_health.status', 'unknown');
    }

    public function test_operational_sync_summary_is_scoped_gated_and_uses_latest_usable_reports(): void
    {
        $this->travelTo(now()->startOfSecond());
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = $this->administrator($organization);
        $otherAdmin = $this->administrator($otherOrganization);
        $link = KernelBridgeAccountLink::query()->create([
            'organization_id' => $organization->id, 'link_uuid' => (string) Str::uuid(),
            'license_uuid' => (string) Str::uuid(), 'product_code' => 'MEDIFLOW',
            'status' => 'approved', 'license_status' => 'active', 'entitlements' => [],
        ]);
        $url = "/api/v1/organizations/{$organization->id}/operational-sync-summary";
        $this->getJson($url)->assertUnauthorized();
        $this->actingAsMfa($otherAdmin)->getJson($url)->assertForbidden();
        $this->actingAsMfa($admin)->getJson($url)->assertOk()
            ->assertJsonPath('data.status', 'disabled')->assertJsonMissingPath('data.enrolled');
        config(['operational_sync.monitoring_enabled' => true]);
        $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.status', 'available')->assertJsonPath('data.enrolled', 0)
            ->assertJsonPath('data.latest_received_at', null);

        $payload = json_encode(['version' => '1.0', 'pending_count' => 2, 'failed_count' => 1, 'enabled_modules' => ['pharmacy']], JSON_THROW_ON_ERROR);
        foreach ([
            [$organization->id, $link->link_uuid, 0],
            [$organization->id, $link->link_uuid, -6],
            [$organization->id, $link->link_uuid, -20],
            [$organization->id, $link->link_uuid, 10],
            [$organization->id, (string) Str::uuid(), 0],
            [$otherOrganization->id, $link->link_uuid, 0],
        ] as [$organizationId, $linkUuid, $minutes]) {
            $sync = OperationalSyncInstallation::query()->create([
                'organization_id' => $organizationId, 'installation_uuid' => (string) Str::uuid(),
                'link_uuid' => $linkUuid, 'status' => 'active',
            ]);
            app(OperationalSyncIngestor::class)->accept($sync, [
                'event_id' => (string) Str::uuid(), 'sequence' => 1,
                'occurred_at' => now()->addMinutes($minutes)->utc()->format('Y-m-d\TH:i:s\Z'),
                'payload_json' => $payload, 'payload_sha256' => hash('sha256', $payload),
            ]);
            if ($organizationId === $organization->id && $linkUuid === $link->link_uuid && $minutes === 0) {
                $latest = json_encode(['version' => '1.1', 'pending_count' => 5, 'failed_count' => 2, 'enabled_modules' => ['core']], JSON_THROW_ON_ERROR);
                app(OperationalSyncIngestor::class)->accept($sync, [
                    'event_id' => (string) Str::uuid(), 'sequence' => 2,
                    'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                    'payload_json' => $latest, 'payload_sha256' => hash('sha256', $latest),
                ]);
            }
        }
        OperationalSyncInstallation::query()->create([
            'organization_id' => $organization->id, 'installation_uuid' => (string) Str::uuid(),
            'link_uuid' => $link->link_uuid, 'status' => 'active',
        ]);
        OperationalSyncInstallation::query()->create([
            'organization_id' => $organization->id, 'installation_uuid' => (string) Str::uuid(),
            'link_uuid' => $link->link_uuid, 'status' => 'revoked',
        ]);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.enrolled', 5)
            ->assertJsonPath('data.online', 1)->assertJsonPath('data.delayed', 1)
            ->assertJsonPath('data.offline', 1)->assertJsonPath('data.unknown', 2)
            ->assertJsonPath('data.pending_count', 9)->assertJsonPath('data.failed_count', 4)
            ->assertJsonPath('data.latest_received_at', now()->utc()->toISOString());
        $link->update(['status' => 'revoked']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.status', 'unlinked')->assertJsonMissingPath('data.enrolled');
    }

    public function test_operational_enrollment_management_is_gated_authorized_and_audited(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->administrator($organization);
        $other = User::factory()->create();
        $link = KernelBridgeAccountLink::query()->create([
            'organization_id' => $organization->id, 'link_uuid' => (string) Str::uuid(),
            'license_uuid' => (string) Str::uuid(), 'product_code' => 'MEDIFLOW',
            'status' => 'approved', 'license_status' => 'active', 'entitlements' => [], 'verified_at' => now(),
        ]);
        $server = (string) Str::uuid();
        $url = "/api/v1/organizations/{$organization->id}/operational-sync-enrollment";
        $this->actingAsMfa($admin)->postJson($url, ['installation_uuid' => $server])->assertNotFound();
        config(['operational_sync.enrollment_enabled' => true]);
        $this->actingAsMfa($other)->postJson($url, ['installation_uuid' => $server])->assertForbidden();
        $this->mock(KernelBridgeLicensingClient::class)->shouldReceive('installations')->once()
            ->andReturn(['data' => [['installation_uuid' => $server, 'status' => 'active', 'expires_at' => null]], 'last_page' => 1]);
        $response = $this->actingAsMfa($admin)->postJson($url, ['installation_uuid' => $server])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $code = $response->json('data.code');
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $code);
        $this->assertDatabaseHas('management_audit_events', [
            'organization_id' => $organization->id, 'actor_id' => $admin->id,
            'action' => 'management.operational-sync.issue', 'http_status' => 201,
        ]);
        $this->assertStringNotContainsString($code, json_encode(DB::table('management_audit_events')->get(), JSON_THROW_ON_ERROR));
        $installation = OperationalSyncInstallation::query()->create([
            'organization_id' => $organization->id, 'installation_uuid' => $server,
            'link_uuid' => $link->link_uuid, 'status' => 'active', 'credential_hash' => hash('sha256', 'credential'),
        ]);
        $revokeUrl = "/api/v1/organizations/{$organization->id}/operational-sync/{$server}";
        $this->actingAsMfa($other)->deleteJson($revokeUrl)->assertForbidden();
        $this->actingAsMfa($admin)->deleteJson($revokeUrl)->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertNull($installation->fresh()->credential_hash);
        $this->assertDatabaseCount('operational_sync_enrollment_codes', 0);
    }

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
}
