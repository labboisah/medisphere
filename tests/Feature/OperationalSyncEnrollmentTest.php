<?php

namespace Tests\Feature;

use App\Models\KernelBridgeAccountLink;
use App\Models\OperationalSyncInstallation;
use App\Models\Organization;
use App\Support\KernelBridgeLicensingClient;
use App\Support\OperationalSyncEnrollment;
use App\Support\OperationalSyncAuthenticator;
use App\Support\KernelBridgeAccountLinkManager;
use App\Support\KernelBridgeLicensingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OperationalSyncEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_machine_endpoints_require_explicit_opt_in_https_and_endpoint_flags(): void
    {
        config([
            'platform.synthetic_management_enabled' => true,
            'operational_sync.enrollment_enabled' => true,
            'operational_sync.ingestion_enabled' => true,
            'operational_sync.production_machine_api_enabled' => false,
            'operational_sync.allow_insecure_ip_http' => true,
        ]);
        $originalEnvironment = $this->app['env'];
        try {
            $this->app['env'] = 'production';
            $this->postJson('https://localhost/api/v1/operational-sync/enroll', [])->assertNotFound();
            $this->postJson('https://localhost/api/v1/operational-sync/events', [])->assertNotFound();
            config(['operational_sync.production_machine_api_enabled' => true]);
            $this->postJson('http://127.0.0.1:8000/api/v1/operational-sync/enroll', [])->assertForbidden();
            $this->postJson('http://127.0.0.1:8000/api/v1/operational-sync/events', [])->assertForbidden();
            config([
                'operational_sync.enrollment_enabled' => false,
                'operational_sync.ingestion_enabled' => false,
            ]);
            $this->postJson('https://localhost/api/v1/operational-sync/enroll', [])->assertNotFound();
            $this->postJson('https://localhost/api/v1/operational-sync/events', [])->assertNotFound();
            config([
                'operational_sync.enrollment_enabled' => true,
                'operational_sync.ingestion_enabled' => true,
            ]);
            $this->postJson('https://localhost/api/v1/operational-sync/enroll', [])->assertUnprocessable();
            $this->postJson('https://localhost/api/v1/operational-sync/events', [])->assertUnprocessable();
        } finally {
            $this->app['env'] = $originalEnvironment;
        }
    }

    public function test_machine_endpoints_remain_restricted_to_synthetic_local_or_testing(): void
    {
        config([
            'platform.synthetic_management_enabled' => true,
            'operational_sync.enrollment_enabled' => true,
            'operational_sync.ingestion_enabled' => true,
            'operational_sync.production_machine_api_enabled' => true,
        ]);
        $originalEnvironment = $this->app['env'];
        try {
            $this->app['env'] = 'staging';
            $this->postJson('https://localhost/api/v1/operational-sync/enroll', [])->assertNotFound();
            $this->postJson('https://localhost/api/v1/operational-sync/events', [])->assertNotFound();
        } finally {
            $this->app['env'] = $originalEnvironment;
        }
        config(['platform.synthetic_management_enabled' => false]);
        $this->postJson('https://localhost/api/v1/operational-sync/enroll', [])->assertNotFound();
        $this->postJson('https://localhost/api/v1/operational-sync/events', [])->assertNotFound();
        config(['platform.synthetic_management_enabled' => true]);
        $this->postJson('http://localhost/api/v1/operational-sync/enroll', [])->assertForbidden();
        $this->postJson('http://localhost/api/v1/operational-sync/events', [])->assertForbidden();
    }

    public function test_http_ip_exception_requires_opt_in_and_synthetic_environment(): void
    {
        config([
            'platform.synthetic_management_enabled' => true,
            'operational_sync.enrollment_enabled' => true,
            'operational_sync.ingestion_enabled' => true,
            'operational_sync.allow_insecure_ip_http' => false,
        ]);
        foreach (['enroll', 'events'] as $path) {
            $this->postJson("http://127.0.0.1:8000/api/v1/operational-sync/{$path}", [])->assertForbidden();
        }
        config(['operational_sync.allow_insecure_ip_http' => true]);
        foreach (['enroll', 'events'] as $path) {
            $this->postJson("http://127.0.0.1:8000/api/v1/operational-sync/{$path}", [])->assertUnprocessable();
            $this->postJson("http://localhost:8000/api/v1/operational-sync/{$path}", [])->assertForbidden();
            $this->postJson("http://127.0.0.1/api/v1/operational-sync/{$path}", [])->assertForbidden();
        }
        config(['platform.synthetic_management_enabled' => false]);
        $this->postJson('http://127.0.0.1:8000/api/v1/operational-sync/enroll', [])->assertNotFound();
        config(['platform.synthetic_management_enabled' => true]);
        $environment = $this->app['env'];
        try {
            $this->app['env'] = 'production';
            $this->postJson('http://127.0.0.1:8000/api/v1/operational-sync/events', [])->assertNotFound();
        } finally {
            $this->app['env'] = $environment;
        }
    }

    public function test_code_is_single_use_bound_and_lost_response_can_be_retried_without_storing_plaintext(): void
    {
        $keys = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keys);
        config([
            'kernelbridge.product_code' => 'MEDIFLOW',
            'operational_sync.kernelbridge_public_keys' => ['test' => base64_encode(sodium_crypto_sign_publickey($keys))],
        ]);
        $organization = Organization::factory()->create();
        $link = KernelBridgeAccountLink::query()->create([
            'organization_id' => $organization->id, 'link_uuid' => (string) Str::uuid(),
            'license_uuid' => (string) Str::uuid(), 'product_code' => 'MEDIFLOW',
            'status' => 'approved', 'license_status' => 'active', 'entitlements' => [], 'verified_at' => now(),
        ]);
        $server = (string) Str::uuid();
        $this->mock(KernelBridgeLicensingClient::class)->shouldReceive('installations')->withArgs(fn ($item, $page) => $item->id === $link->id && $page === 1)
            ->twice()->andReturn(['data' => [[
                'installation_uuid' => $server, 'status' => 'active', 'expires_at' => null,
            ]], 'last_page' => 1]);
        $service = app(OperationalSyncEnrollment::class);
        $issued = $service->issue($link, $server);
        $code = $issued['code'];
        $row = DB::table('operational_sync_enrollment_codes')->first();
        $this->assertSame(hash('sha256', $code), $row->code_hash);
        $this->assertStringNotContainsString($code, json_encode($row, JSON_THROW_ON_ERROR));
        $claims = [
            'purpose' => 'mediflow.operational-sync.enrollment', 'version' => 1, 'challenge' => hash('sha256', $code),
            'issued_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'installation_uuid' => $server,
            'business_uuid' => $organization->id, 'platform_account_link_uuid' => $link->link_uuid,
            'license_uuid' => $link->license_uuid, 'product_code' => 'MEDIFLOW',
        ];
        $json = json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $proof = ['payload_json' => $json, 'signature' => base64_encode(sodium_crypto_sign_detached($json, $secret)), 'algorithm' => 'ed25519', 'key_id' => 'test'];
        $badProof = $proof;
        $badProof['signature'] = base64_encode(random_bytes(64));
        $this->assertRejected(fn () => $service->redeem($code, $badProof));
        $this->assertDatabaseCount('operational_sync_installations', 0);
        $this->assertNull(DB::table('operational_sync_enrollment_codes')->value('redeemed_at'));
        $credential = $service->redeem($code, $proof)['credential'];
        $this->assertNotSame($code, $credential);
        $this->assertSame($credential, $service->redeem($code, $proof)['credential']);
        $installation = OperationalSyncInstallation::query()->firstOrFail();
        $this->assertSame(hash('sha256', $credential), $installation->credential_hash);
        $this->assertStringNotContainsString($credential, json_encode(DB::table('operational_sync_enrollment_codes')->first(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($credential, $installation->toJson());
        $payload = json_encode(['version' => '1.0', 'pending_count' => 0, 'failed_count' => 0, 'enabled_modules' => ['core']], JSON_THROW_ON_ERROR);
        $event = [
            'event_id' => (string) Str::uuid(), 'sequence' => 1,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'payload_json' => $payload, 'payload_sha256' => hash('sha256', $payload),
        ];
        $authenticator = app(OperationalSyncAuthenticator::class);
        $this->assertSame('accepted', $authenticator->accept($credential, $event)['status']);
        $this->assertSame('duplicate', $authenticator->accept($credential, $event)['status']);
        $this->assertRejected(fn () => $authenticator->accept(str_repeat('x', 43), $event));
        $link->update(['status' => 'revoked']);
        $this->assertRejected(fn () => $authenticator->accept($credential, $event));
        $this->assertRejected(fn () => $service->redeem($code, $proof));
        $link->update(['status' => 'approved']);
        $replacement = $service->issue($link, $server)['code'];
        $this->assertRejected(fn () => $service->redeem($code, $proof));
        $newClaims = [...$claims, 'challenge' => hash('sha256', $replacement)];
        $newJson = json_encode($newClaims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $newProof = ['payload_json' => $newJson, 'signature' => base64_encode(sodium_crypto_sign_detached($newJson, $secret)), 'algorithm' => 'ed25519', 'key_id' => 'test'];
        $newCredential = $service->redeem($replacement, $newProof)['credential'];
        $this->assertNotSame($credential, $newCredential);
        $this->assertRejected(fn () => $authenticator->accept($credential, $event));
        $this->assertSame('duplicate', $authenticator->accept($newCredential, $event)['status']);
        $this->assertSame(1, (int) $installation->fresh()->last_sequence);
        $this->assertSame($newCredential, $service->redeem($replacement, $newProof)['credential']);
        $service->revoke($link, $server);
        $this->assertRejected(fn () => $authenticator->accept($newCredential, $event));
        $this->assertRejected(fn () => $service->redeem($replacement, $newProof));
        $changed = $proof;
        $changed['signature'] = base64_encode(random_bytes(64));
        $this->assertRejected(fn () => $service->redeem($code, $changed));
        $this->assertRejected(fn () => $service->redeem(str_repeat('x', 43), $proof));
        $installation->update(['status' => 'revoked']);
        $this->assertRejected(fn () => $service->redeem($code, $proof));
        $this->assertRejected(fn () => $authenticator->accept($credential, $event));
        $this->assertDatabaseCount('operational_sync_events', 1);
        $installation->update(['status' => 'active']);
        $this->travel(11)->minutes();
        $this->assertRejected(fn () => $service->redeem($code, $proof));
    }

    public function test_code_cannot_be_issued_for_an_unlinked_or_inactive_server(): void
    {
        $organization = Organization::factory()->create();
        $link = KernelBridgeAccountLink::query()->create([
            'organization_id' => $organization->id, 'link_uuid' => (string) Str::uuid(),
            'license_uuid' => (string) Str::uuid(), 'product_code' => 'MEDIFLOW',
            'status' => 'approved', 'license_status' => 'active', 'entitlements' => [], 'verified_at' => now(),
        ]);
        $server = (string) Str::uuid();
        $this->mock(KernelBridgeLicensingClient::class)->shouldReceive('installations')->once()
            ->andReturn(['data' => [['installation_uuid' => $server, 'status' => 'revoked', 'expires_at' => null]], 'last_page' => 1]);
        $this->assertRejected(fn () => app(OperationalSyncEnrollment::class)->issue($link, $server));
        $this->assertDatabaseCount('operational_sync_enrollment_codes', 0);
    }

    public static function machineApiUrls(): array
    {
        return [
            'https' => ['https://localhost/api/v1', false],
            'local IP http' => ['http://127.0.0.1:8000/api/v1', true],
        ];
    }

    #[DataProvider('machineApiUrls')]
    public function test_machine_endpoints_enroll_and_recheck_authoritative_server_status(string $base, bool $allowHttp): void
    {
        $keys = sodium_crypto_sign_keypair();
        config([
            'platform.synthetic_management_enabled' => true,
            'kernelbridge.product_code' => 'MEDIFLOW',
            'operational_sync.kernelbridge_public_keys' => ['test' => base64_encode(sodium_crypto_sign_publickey($keys))],
            'operational_sync.allow_insecure_ip_http' => $allowHttp,
        ]);
        $organization = Organization::factory()->create();
        $link = KernelBridgeAccountLink::query()->create([
            'organization_id' => $organization->id, 'link_uuid' => (string) Str::uuid(),
            'license_uuid' => (string) Str::uuid(), 'product_code' => 'MEDIFLOW',
            'status' => 'approved', 'license_status' => 'active', 'entitlements' => [], 'verified_at' => now(),
        ]);
        $server = (string) Str::uuid();
        $active = ['data' => [['installation_uuid' => $server, 'status' => 'active', 'expires_at' => null]], 'last_page' => 1];
        $revoked = ['data' => [['installation_uuid' => $server, 'status' => 'revoked', 'expires_at' => null]], 'last_page' => 1];
        $client = $this->mock(KernelBridgeLicensingClient::class);
        $client->shouldReceive('installations')->times(4)->andReturn($active, $active, $active, $revoked);
        $client->shouldReceive('status')->times(3)->andReturn([
            'uuid' => $link->link_uuid, 'license_uuid' => $link->license_uuid, 'product_code' => 'MEDIFLOW',
            'status' => 'approved', 'license_status' => 'active', 'entitlements' => [],
        ]);
        $service = app(OperationalSyncEnrollment::class);
        $code = $service->issue($link, $server)['code'];
        $claims = [
            'purpose' => 'mediflow.operational-sync.enrollment', 'version' => 1, 'challenge' => hash('sha256', $code),
            'issued_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'installation_uuid' => $server,
            'business_uuid' => $organization->id, 'platform_account_link_uuid' => $link->link_uuid,
            'license_uuid' => $link->license_uuid, 'product_code' => 'MEDIFLOW',
        ];
        $json = json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $proof = ['payload_json' => $json, 'signature' => base64_encode(sodium_crypto_sign_detached($json, sodium_crypto_sign_secretkey($keys))), 'algorithm' => 'ed25519', 'key_id' => 'test'];
        $enrollUrl = $base.'/operational-sync/enroll';
        $this->postJson($enrollUrl, ['code' => $code, 'proof' => $proof])->assertNotFound();
        config(['operational_sync.enrollment_enabled' => true, 'operational_sync.ingestion_enabled' => true]);
        $this->postJson('http://localhost/api/v1/operational-sync/enroll', ['code' => $code, 'proof' => $proof])->assertForbidden();
        $credential = $this->postJson($enrollUrl, ['code' => $code, 'proof' => $proof])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data.credential');
        $this->postJson($enrollUrl, ['code' => $code, 'proof' => $proof])->assertOk()->assertJsonPath('data.credential', $credential);
        $payload = json_encode(['version' => '1.0', 'pending_count' => 0, 'failed_count' => 0, 'enabled_modules' => ['core']], JSON_THROW_ON_ERROR);
        $event = [
            'event_id' => (string) Str::uuid(), 'sequence' => 1,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'payload_json' => $payload, 'payload_sha256' => hash('sha256', $payload),
        ];
        $url = $base.'/operational-sync/events';
        $this->withToken('invalid')->postJson($url, ['event' => $event])->assertUnprocessable()->assertJsonPath('code', 'credential_inactive');
        $this->withToken($credential)->postJson($url, ['event' => $event])->assertOk()->assertJsonPath('status', 'accepted');
        $this->withToken($credential)->postJson($url, ['event' => $event])->assertOk()->assertJsonPath('status', 'duplicate');
        $this->withToken($credential)->postJson($url, ['event' => $event])->assertUnprocessable()->assertJsonPath('code', 'credential_inactive');
        $this->assertDatabaseCount('operational_sync_events', 1);
    }

    public function test_machine_ingestion_fails_closed_when_authoritative_licensing_is_unavailable(): void
    {
        config(['platform.synthetic_management_enabled' => true, 'operational_sync.ingestion_enabled' => true]);
        $organization = Organization::factory()->create();
        $link = KernelBridgeAccountLink::query()->create([
            'organization_id' => $organization->id, 'link_uuid' => (string) Str::uuid(),
            'license_uuid' => (string) Str::uuid(), 'product_code' => 'MEDIFLOW',
            'status' => 'approved', 'license_status' => 'active', 'entitlements' => [], 'verified_at' => now(),
        ]);
        $credential = str_repeat('z', 43);
        OperationalSyncInstallation::query()->create([
            'organization_id' => $organization->id, 'installation_uuid' => (string) Str::uuid(),
            'link_uuid' => $link->link_uuid, 'status' => 'active', 'credential_hash' => hash('sha256', $credential),
        ]);
        $this->mock(KernelBridgeAccountLinkManager::class)->shouldReceive('refresh')->once()
            ->andThrow(new KernelBridgeLicensingException('licensing_service_unavailable', 'unavailable', 503));
        $payload = json_encode(['version' => '1.0', 'pending_count' => 0, 'failed_count' => 0, 'enabled_modules' => ['core']], JSON_THROW_ON_ERROR);
        $event = [
            'event_id' => (string) Str::uuid(), 'sequence' => 1,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'payload_json' => $payload, 'payload_sha256' => hash('sha256', $payload),
        ];
        $this->withToken($credential)->postJson('https://localhost/api/v1/operational-sync/events', ['event' => $event])
            ->assertStatus(503)->assertJsonPath('code', 'licensing_unavailable');
        $this->assertDatabaseCount('operational_sync_events', 0);
    }

    private function assertRejected(callable $action): void
    {
        try {
            $action();
            $this->fail('Invalid enrollment must be rejected.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }
}
