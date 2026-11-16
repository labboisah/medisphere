<?php

namespace App\Support;

use App\Models\KernelBridgeAccountLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

final class OperationalSyncProofVerifier
{
    /**
     * @param  array<string, mixed>  $proof
     */
    public function verify(array $proof, KernelBridgeAccountLink $link, string $installationUuid, string $challenge): void
    {
        $keys = config('operational_sync.kernelbridge_public_keys');
        if (! is_array($keys)
            || array_diff(array_keys($proof), ['payload_json', 'signature', 'algorithm', 'key_id']) !== []
            || count($proof) !== 4
            || ! is_string($proof['payload_json'] ?? null) || strlen($proof['payload_json']) > 4096
            || ! is_string($proof['key_id'] ?? null)
            || ($proof['algorithm'] ?? null) !== 'ed25519'
            || ! is_string($proof['signature'] ?? null)
            || ! is_string($keys[$proof['key_id']] ?? null)) {
            $this->reject();
        }
        $key = base64_decode($keys[$proof['key_id']], true);
        $signature = base64_decode($proof['signature'], true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signature, $proof['payload_json'], $key)) {
            $this->reject();
        }

        try {
            $claims = json_decode($proof['payload_json'], true, 16, JSON_THROW_ON_ERROR);
            $issued = is_array($claims) && is_string($claims['issued_at'] ?? null)
                ? Carbon::createFromFormat('!Y-m-d\TH:i:s\Z', $claims['issued_at'], 'UTC')
                : false;
        } catch (JsonException|\InvalidArgumentException) {
            $this->reject();
        }
        if (! is_array($claims) || ! $issued
            || $issued->format('Y-m-d\TH:i:s\Z') !== ($claims['issued_at'] ?? null)
            || $issued->lt(now()->subMinutes(5)) || $issued->gt(now()->addSeconds(30))
            || ($claims['purpose'] ?? null) !== 'mediflow.operational-sync.enrollment'
            || ($claims['version'] ?? null) !== 1
            || ! preg_match('/\A[a-f0-9]{64}\z/', $challenge)
            || ! is_string($claims['challenge'] ?? null) || ! hash_equals($challenge, $claims['challenge'])
            || ! Str::isUuid($installationUuid)
            || ($claims['installation_uuid'] ?? null) !== $installationUuid
            || ($claims['business_uuid'] ?? null) !== strtolower($link->organization_id)
            || ($claims['platform_account_link_uuid'] ?? null) !== $link->link_uuid
            || ($claims['license_uuid'] ?? null) !== $link->license_uuid
            || ($claims['product_code'] ?? null) !== strtoupper((string) config('kernelbridge.product_code'))
            || $link->status !== 'approved') {
            $this->reject();
        }
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['proof' => 'The installation enrollment proof is invalid or expired.']);
    }
}
