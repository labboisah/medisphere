<?php

namespace App\Support;

use App\Models\KernelBridgeAccountLink;
use App\Models\OperationalSyncInstallation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OperationalSyncAuthenticator
{
    public function __construct(
        private readonly OperationalSyncIngestor $ingestor,
        private readonly OrganizationLicenseAccess $licenseAccess,
        private readonly KernelBridgeAccountLinkManager $links,
        private readonly OperationalSyncEnrollment $enrollment,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     * @return array{event_id: string, status: string}
     */
    public function acceptAuthoritatively(string $credential, array $event): array
    {
        if (! preg_match('/\A[A-Za-z0-9_-]{43}\z/', $credential)) {
            $this->reject();
        }
        $installation = OperationalSyncInstallation::query()->where('credential_hash', hash('sha256', $credential))->first();
        $link = $installation ? KernelBridgeAccountLink::query()
            ->where('organization_id', $installation->organization_id)
            ->where('link_uuid', $installation->link_uuid)->first() : null;
        if (! $installation || $installation->status !== 'active' || ! $link) {
            $this->reject();
        }
        $link = $this->links->refresh($link);
        if (! $this->licenseAccess->permits($link)
            || ! $this->enrollment->installationIsActive($link, $installation->installation_uuid)) {
            $this->reject();
        }

        return $this->accept($credential, $event);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{event_id: string, status: string}
     */
    public function accept(string $credential, array $event): array
    {
        if (! preg_match('/\A[A-Za-z0-9_-]{43}\z/', $credential)) {
            $this->reject();
        }
        $digest = hash('sha256', $credential);
        $candidate = OperationalSyncInstallation::query()->where('credential_hash', $digest)->first();
        if (! $candidate) {
            $this->reject();
        }

        return DB::transaction(function () use ($candidate, $digest, $event): array {
            $link = KernelBridgeAccountLink::query()->where('organization_id', $candidate->organization_id)
                ->where('link_uuid', $candidate->link_uuid)->lockForUpdate()->first();
            $installation = OperationalSyncInstallation::query()->whereKey($candidate->id)->lockForUpdate()->first();
            if (! $link || ! $this->licenseAccess->permits($link)
                || ! $installation || $installation->status !== 'active'
                || $installation->organization_id !== $link->organization_id
                || $installation->link_uuid !== $link->link_uuid
                || ! is_string($installation->credential_hash) || ! hash_equals($installation->credential_hash, $digest)) {
                $this->reject();
            }

            return $this->ingestor->accept($installation, $event);
        });
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['credential' => 'The operational sync credential is invalid or inactive.']);
    }
}
