<?php

namespace App\Support;

use App\Models\KernelBridgeAccountLink;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class OrganizationLicenseAccess
{
    public function __construct(private readonly KernelBridgeAccountLinkManager $links) {}

    public function permits(KernelBridgeAccountLink $link): bool
    {
        $now = now();

        if ($link->status !== 'approved'
            || strtoupper($link->product_code) !== strtoupper((string) config('kernelbridge.product_code'))
            || ! in_array($link->license_status, ['active', 'trial', 'grace'], true)
            || ($link->subscription_uuid !== null && ! in_array($link->subscription_status, ['active', 'trial', 'grace'], true))
            || $link->verified_at === null || $link->verified_at->isFuture()
            || $link->verified_at->lte($now->copy()->subHours(24))
            || ($link->starts_at !== null && $link->starts_at->isFuture())
            || ($link->expires_at !== null && $link->expires_at->lte($now))
            || ($link->license_status === 'grace' && ($link->grace_expires_at === null || $link->grace_expires_at->lte($now)))
            || ($link->subscription_status === 'grace' && ($link->grace_expires_at === null || $link->grace_expires_at->lte($now)))
            || ($link->ends_at !== null && $link->ends_at->lte($now)
                && ! ($link->subscription_status === 'grace' && $link->grace_expires_at?->gt($now)))) {
            return false;
        }

        return true;
    }

    public function verify(Organization $organization): bool
    {
        $link = $organization->kernelBridgeAccountLink;
        if (! $link) {
            return false;
        }

        try {
            $link = $this->links->refresh($link);
        } catch (KernelBridgeLicensingException $exception) {
            Log::warning('KernelBridge account-link verification failed.', [
                'organization_id' => $organization->id,
                'code' => $exception->errorCode,
            ]);
            if ($exception->errorCode !== 'licensing_service_unavailable') {
                return false;
            }
        }

        return $this->permits($link);
    }

    public function userHasAccess(User $user): bool
    {
        $organizations = OrganizationOnboardingStatus::memberships($user)->with('kernelBridgeAccountLink')->get();
        if ($organizations->isEmpty()) {
            return false;
        }
        foreach ($organizations as $organization) {
            if (! $this->verify($organization)) {
                return false;
            }
        }

        return true;
    }
}
