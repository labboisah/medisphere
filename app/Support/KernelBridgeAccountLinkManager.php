<?php

namespace App\Support;

use App\Models\KernelBridgeAccountLink;
use App\Models\Organization;
use App\Models\ServerEnrollmentCode;
use App\Models\ServerInstallation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KernelBridgeAccountLinkManager
{
    public function __construct(private readonly KernelBridgeLicensingClient $client) {}

    public function link(Organization $organization, string $activationCode): KernelBridgeAccountLink
    {
        if ($organization->kernelBridgeAccountLink()->where('status', 'approved')->exists()) {
            throw new KernelBridgeLicensingException('account_link_already_approved', 'Unlink the existing account before connecting a replacement license.', 409);
        }

        $data = $this->client->linkAccount($organization, $activationCode);
        $this->assertLinkIdentifiers($data);

        return DB::transaction(function () use ($organization, $data): KernelBridgeAccountLink {
            ServerEnrollmentCode::query()
                ->where('organization_id', $organization->id)
                ->where('link_uuid', '!=', $data['uuid'])
                ->delete();
            $link = KernelBridgeAccountLink::query()->updateOrCreate(
                ['organization_id' => $organization->id],
                [
                    'link_uuid' => $data['uuid'],
                    'license_uuid' => $data['license_uuid'],
                    ...$this->snapshot($data),
                ],
            );

            return $link->refresh();
        });
    }

    public function refresh(KernelBridgeAccountLink $link): KernelBridgeAccountLink
    {
        $data = $this->client->status($link);
        $this->assertLinkIdentifiers($data);
        DB::transaction(function () use ($link, $data): void {
            $link->fill($this->snapshot($data));
            $link->save();
            if ($link->status !== 'approved') {
                ServerEnrollmentCode::query()->where('organization_id', $link->organization_id)->delete();
            }
        });

        return $link->refresh();
    }

    public function unlink(KernelBridgeAccountLink $link): KernelBridgeAccountLink
    {
        $this->client->unlink($link);
        DB::transaction(function () use ($link): void {
            $link->forceFill(['status' => 'revoked', 'verified_at' => now()])->save();
            ServerEnrollmentCode::query()->where('organization_id', $link->organization_id)->delete();
            ServerInstallation::query()
                ->where('organization_id', $link->organization_id)
                ->where('status', '!=', 'revoked')
                ->update(['status' => 'revoked', 'updated_at' => now()]);
        });

        return $link->refresh();
    }

    public function revokeInstallation(KernelBridgeAccountLink $link, ServerInstallation $installation): ServerInstallation
    {
        $this->client->revokeInstallation($link, $installation);
        $installation->forceFill(['status' => 'revoked'])->save();

        return $installation->refresh();
    }

    /**
     * @return array{activation_code: string, expires_at: string}
     */
    public function issueActivationCode(KernelBridgeAccountLink $link, ServerInstallation $installation): array
    {
        if ($link->status !== 'approved') {
            throw new KernelBridgeLicensingException('account_link_inactive', 'Link an active KernelBridge account before issuing an installation code.', 409);
        }

        $result = $this->client->issueActivationCode($link, $installation);
        $installation->forceFill([
            'status' => 'activation_code_issued',
            'activation_code_expires_at' => $result['expires_at'],
        ])->save();

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function publicStatus(KernelBridgeAccountLink $link): array
    {
        return [
            'link_uuid' => $link->link_uuid,
            'product_code' => $link->product_code,
            'license_uuid' => $link->license_uuid,
            'subscription_uuid' => $link->subscription_uuid,
            'status' => $link->status,
            'license_status' => $link->license_status,
            'subscription_status' => $link->subscription_status,
            'plan_name' => $link->plan_name,
            'entitlement_revision' => $link->entitlement_revision,
            'entitlements' => $link->entitlements ?? [],
            'starts_at' => $link->starts_at?->toISOString(),
            'ends_at' => $link->ends_at?->toISOString(),
            'expires_at' => $link->expires_at?->toISOString(),
            'grace_expires_at' => $link->grace_expires_at?->toISOString(),
            'verified_at' => $link->verified_at?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function snapshot(array $data): array
    {
        if (! is_array($data['entitlements'] ?? null)) {
            throw new KernelBridgeLicensingException('invalid_licensing_response', 'KernelBridge returned an invalid entitlement snapshot.', 502);
        }

        return [
            'subscription_uuid' => $data['subscription_uuid'] ?? null,
            'product_code' => strtoupper((string) $data['product_code']),
            'status' => (string) $data['status'],
            'license_status' => $data['license_status'] ?? null,
            'subscription_status' => $data['subscription_status'] ?? null,
            'plan_name' => is_array($data['plan'] ?? null) ? ($data['plan']['name'] ?? null) : null,
            'entitlement_revision' => isset($data['entitlement_revision']) ? (int) $data['entitlement_revision'] : null,
            'entitlements' => $data['entitlements'],
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'grace_expires_at' => $data['grace_expires_at'] ?? null,
            'verified_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertLinkIdentifiers(array $data): void
    {
        if (! Str::isUuid($data['uuid'] ?? null)
            || ! Str::isUuid($data['license_uuid'] ?? null)
            || ! in_array($data['status'] ?? null, ['pending', 'approved', 'rejected', 'revoked'], true)
            || ! is_string($data['product_code'] ?? null)) {
            throw new KernelBridgeLicensingException('invalid_licensing_response', 'KernelBridge returned invalid account-link details.', 502);
        }
    }
}
