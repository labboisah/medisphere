<?php

namespace Tests\Feature;

use App\Models\KernelBridgeAccountLink;
use App\Models\Organization;
use App\Support\OperationalSyncProofVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class OperationalSyncProofVerifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_proof_is_signed_fresh_and_bound_to_code_installation_account_license_and_product(): void
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
            'status' => 'approved', 'license_status' => 'active', 'entitlements' => [],
        ]);
        $installation = (string) Str::uuid();
        $challenge = hash('sha256', 'synthetic-code');
        $claims = [
            'purpose' => 'mediflow.operational-sync.enrollment', 'version' => 1,
            'challenge' => $challenge, 'issued_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'installation_uuid' => $installation, 'business_uuid' => $organization->id,
            'platform_account_link_uuid' => $link->link_uuid, 'license_uuid' => $link->license_uuid,
            'product_code' => 'MEDIFLOW',
        ];
        $sign = function (array $claims) use ($secret): array {
            $json = json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            return ['payload_json' => $json, 'signature' => base64_encode(sodium_crypto_sign_detached($json, $secret)), 'algorithm' => 'ed25519', 'key_id' => 'test'];
        };
        $verifier = app(OperationalSyncProofVerifier::class);
        $verifier->verify($sign($claims), $link, $installation, $challenge);
        $this->assertTrue(true);
        foreach ([
            ['challenge', hash('sha256', 'other')],
            ['purpose', 'other'],
            ['installation_uuid', (string) Str::uuid()],
            ['business_uuid', (string) Str::uuid()],
            ['platform_account_link_uuid', (string) Str::uuid()],
            ['license_uuid', (string) Str::uuid()],
            ['product_code', 'OTHER'],
            ['issued_at', now()->subMinutes(6)->utc()->format('Y-m-d\TH:i:s\Z')],
            ['issued_at', now()->addMinute()->utc()->format('Y-m-d\TH:i:s\Z')],
        ] as [$field, $value]) {
            $this->assertRejected($verifier, $sign([...$claims, $field => $value]), $link, $installation, $challenge);
        }
        $proof = $sign($claims);
        $proof['payload_json'] .= ' ';
        $this->assertRejected($verifier, $proof, $link, $installation, $challenge);
        $proof = $sign($claims);
        $proof['key_id'] = 'untrusted';
        $this->assertRejected($verifier, $proof, $link, $installation, $challenge);
        $link->status = 'revoked';
        $this->assertRejected($verifier, $sign($claims), $link, $installation, $challenge);
    }

    private function assertRejected(OperationalSyncProofVerifier $verifier, array $proof, KernelBridgeAccountLink $link, string $installation, string $challenge): void
    {
        try {
            $verifier->verify($proof, $link, $installation, $challenge);
            $this->fail('Invalid proof must be rejected.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }
}
