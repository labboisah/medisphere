<?php

namespace App\Support;

use App\Models\KernelBridgeAccountLink;
use App\Models\OperationalSyncInstallation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OperationalSyncEnrollment
{
    public function __construct(
        private readonly KernelBridgeLicensingClient $licensing,
        private readonly OperationalSyncProofVerifier $proofs,
        private readonly OrganizationLicenseAccess $licenseAccess,
    ) {}

    /** @return array{code: string, expires_at: string} */
    public function issue(KernelBridgeAccountLink $link, string $installationUuid): array
    {
        if (! $this->licenseAccess->permits($link) || ! Str::isUuid($installationUuid)) {
            $this->reject();
        }
        if (! $this->installationIsActive($link, $installationUuid)) {
            $this->reject();
        }
        $code = $this->secret();
        $expires = now()->addMinutes(10);
        DB::transaction(function () use ($link, $installationUuid, $code, $expires): void {
            $current = KernelBridgeAccountLink::query()->lockForUpdate()->find($link->id);
            if (! $current || ! $this->licenseAccess->permits($current) || $current->link_uuid !== $link->link_uuid) {
                $this->reject();
            }
            DB::table('operational_sync_enrollment_codes')
                ->where('organization_id', $current->organization_id)
                ->where('installation_uuid', $installationUuid)
                ->delete();
            DB::table('operational_sync_enrollment_codes')->insert([
                'id' => (string) Str::uuid(), 'organization_id' => $current->organization_id,
                'link_uuid' => $current->link_uuid, 'installation_uuid' => $installationUuid,
                'code_hash' => hash('sha256', $code), 'expires_at' => $expires,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return ['code' => $code, 'expires_at' => $expires->toISOString()];
    }

    public function installationIsActive(KernelBridgeAccountLink $link, string $installationUuid): bool
    {
        for ($page = 1; $page <= 100; $page++) {
            $inventory = $this->licensing->installations($link, $page);
            foreach ($inventory['data'] as $installation) {
                if ($installation['installation_uuid'] === $installationUuid && $installation['status'] === 'active'
                    && ($installation['expires_at'] === null || Carbon::parse($installation['expires_at'])->isFuture())) {
                    return true;
                }
            }
            if ($page >= $inventory['last_page']) {
                return false;
            }
            if ($page === 100) {
                throw new KernelBridgeLicensingException('installation_inventory_limit', 'The installation inventory is too large to verify this enrollment request.', 503);
            }
        }
        return false;
    }

    /**
     * @param  array<string, mixed>  $proof
     * @return array{credential: string, installation_uuid: string}
     */
    public function redeem(string $code, array $proof): array
    {
        if (! preg_match('/\A[A-Za-z0-9_-]{43}\z/', $code)
            || ! is_string($proof['payload_json'] ?? null)
            || ! is_string($proof['signature'] ?? null)
            || ! is_string($proof['algorithm'] ?? null)
            || ! is_string($proof['key_id'] ?? null)
            || strlen($proof['payload_json']) > 4096 || strlen($proof['signature']) > 128
            || strlen($proof['key_id']) > 128 || strlen($proof['algorithm']) > 32) {
            $this->reject();
        }
        $proofHash = hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $codeHash = hash('sha256', $code);
        $candidate = DB::table('operational_sync_enrollment_codes')->where('code_hash', $codeHash)->first();
        if (! $candidate) {
            $this->reject();
        }

        return DB::transaction(function () use ($candidate, $codeHash, $proof, $proofHash): array {
            $link = KernelBridgeAccountLink::query()->where('organization_id', $candidate->organization_id)
                ->where('link_uuid', $candidate->link_uuid)->lockForUpdate()->first();
            $record = DB::table('operational_sync_enrollment_codes')->where('id', $candidate->id)
                ->where('code_hash', $codeHash)->lockForUpdate()->first();
            if (! $record || Carbon::parse($record->expires_at)->isPast()) {
                $this->reject();
            }
            if (! $link || ! $this->licenseAccess->permits($link)) {
                $this->reject();
            }
            $this->proofs->verify($proof, $link, $record->installation_uuid, $codeHash);
            if ($record->redeemed_at !== null) {
                $installation = OperationalSyncInstallation::query()->where('organization_id', $record->organization_id)
                    ->where('installation_uuid', $record->installation_uuid)->lockForUpdate()->first();
                if (! $record->encrypted_credential || ! $record->proof_hash
                    || ! hash_equals($record->proof_hash, $proofHash) || ! $installation
                    || $installation->status !== 'active' || $installation->link_uuid !== $record->link_uuid) {
                    $this->reject();
                }
                $credential = Crypt::decryptString($record->encrypted_credential);
                if (! is_string($installation->credential_hash) || ! hash_equals($installation->credential_hash, hash('sha256', $credential))) {
                    $this->reject();
                }

                return ['credential' => $credential, 'installation_uuid' => $record->installation_uuid];
            }
            $installation = OperationalSyncInstallation::query()->where('organization_id', $record->organization_id)
                ->where('installation_uuid', $record->installation_uuid)->lockForUpdate()->first();
            if ($installation && $installation->link_uuid !== $record->link_uuid && (int) $installation->last_sequence > 0) {
                $this->reject();
            }
            $credential = $this->secret();
            if (! $installation) {
                $installation = new OperationalSyncInstallation;
                $installation->organization_id = $record->organization_id;
                $installation->installation_uuid = $record->installation_uuid;
            }
            $installation->forceFill([
                'link_uuid' => $record->link_uuid, 'status' => 'active', 'credential_hash' => hash('sha256', $credential),
            ])->save();
            DB::table('operational_sync_enrollment_codes')->where('id', $record->id)->update([
                'redeemed_at' => now(), 'proof_hash' => $proofHash,
                'encrypted_credential' => Crypt::encryptString($credential), 'updated_at' => now(),
            ]);

            return ['credential' => $credential, 'installation_uuid' => $record->installation_uuid];
        });
    }

    public function revoke(KernelBridgeAccountLink $link, string $installationUuid): void
    {
        if (! Str::isUuid($installationUuid)) {
            $this->reject();
        }
        DB::transaction(function () use ($link, $installationUuid): void {
            $current = KernelBridgeAccountLink::query()->lockForUpdate()->find($link->id);
            if (! $current || $current->status !== 'approved' || $current->link_uuid !== $link->link_uuid) {
                $this->reject();
            }
            $installation = OperationalSyncInstallation::query()->where('organization_id', $current->organization_id)
                ->where('link_uuid', $current->link_uuid)->where('installation_uuid', $installationUuid)
                ->lockForUpdate()->first();
            if (! $installation) {
                $this->reject();
            }
            $installation->forceFill(['status' => 'revoked', 'credential_hash' => null])->save();
            DB::table('operational_sync_enrollment_codes')->where('organization_id', $current->organization_id)
                ->where('installation_uuid', $installationUuid)->delete();
        });
    }

    private function secret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['enrollment' => 'The sync enrollment request is invalid or expired.']);
    }
}
