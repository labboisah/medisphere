<?php

namespace App\Livewire;

use App\Http\Controllers\Api\V1\OrganizationAccess;
use App\Models\Organization;
use App\Support\KernelBridgeAccountLinkManager;
use App\Support\KernelBridgeLicensingException;
use App\Support\MfaSession;
use App\Support\OrganizationLicenseAccess;
use App\Support\OrganizationOnboardingStatus;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class OrganizationLicenseLink extends Component
{
    #[Locked]
    public string $organizationId;

    public string $activationCode = '';

    public function mount(string $organizationId): void
    {
        $this->organizationId = $organizationId;
        $this->authorizedOrganization();
    }

    private function authorizedOrganization(): Organization
    {
        abort_unless(app()->environment(['local', 'testing']) && config('platform.synthetic_management_enabled') === true, 404);
        $user = auth()->user();
        abort_unless($user && MfaSession::isVerified(request(), $user), 403);
        abort_if(OrganizationOnboardingStatus::needsAttention($user), 403);
        $organization = Organization::findOrFail($this->organizationId);
        OrganizationAccess::requirePermission($user, $organization, 'license.manage');

        return $organization;
    }

    private function throttle(): void
    {
        $key = 'organization-license-link:'.auth()->id().'|'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->addError('license', 'Too many attempts. Try again later.');
            abort(429);
        }
        RateLimiter::hit($key, 60);
    }

    private function audited(Organization $organization, string $action, Closure $operation): mixed
    {
        return DB::transaction(function () use ($organization, $action, $operation) {
            $result = $operation();
            DB::table('management_audit_events')->insert([
                'id' => (string) Str::uuid(),
                'actor_id' => auth()->id(),
                'organization_id' => $organization->id,
                'facility_id' => null,
                'action' => $action,
                'http_status' => 200,
                'correlation_id' => (string) Str::uuid(),
                'created_at' => now(),
            ]);

            return $result;
        });
    }

    public function activate(KernelBridgeAccountLinkManager $links, OrganizationLicenseAccess $access): mixed
    {
        $organization = $this->authorizedOrganization();
        $this->throttle();
        $code = trim($this->activationCode);
        $this->activationCode = '';
        $validator = Validator::make(['activationCode' => $code], ['activationCode' => ['required', 'string', 'max:255', 'regex:/\S/']]);
        if ($validator->fails()) {
            $this->addError('activationCode', 'Enter a valid activation code.');

            return null;
        }
        try {
            $link = $this->audited($organization, 'management.licensing.link', fn () => $links->link($organization, $code));
        } catch (KernelBridgeLicensingException $exception) {
            Log::warning('KernelBridge account linking failed.', ['organization_id' => $organization->id, 'code' => $exception->errorCode]);
            $this->addError('activationCode', $exception->getMessage());

            return null;
        }

        if ($access->permits($link)) {
            return redirect('/');
        }
        $this->addError('license', 'The account is linked, but its license is not currently active.');

        return null;
    }

    public function refreshAccountLinkStatus(KernelBridgeAccountLinkManager $links, OrganizationLicenseAccess $access): mixed
    {
        $organization = $this->authorizedOrganization();
        $this->throttle();
        $link = $organization->kernelBridgeAccountLink;
        abort_unless($link, 404);
        try {
            $link = $this->audited($organization, 'management.licensing.refresh', fn () => $links->refresh($link));
        } catch (KernelBridgeLicensingException $exception) {
            Log::warning('KernelBridge account-link refresh failed.', ['organization_id' => $organization->id, 'code' => $exception->errorCode]);
            $this->addError('license', $exception->getMessage());

            return null;
        }
        if ($access->permits($link)) {
            return redirect('/');
        }
        $this->addError('license', 'The linked license is not currently active.');

        return null;
    }

    public function requestAccountLinkAgain(): void
    {
        $this->authorizedOrganization();
        $this->addError('activationCode', 'Enter a new activation code to request a link.');
    }

    public function unlinkAccount(KernelBridgeAccountLinkManager $links): void
    {
        $organization = $this->authorizedOrganization();
        $this->throttle();
        $link = $organization->kernelBridgeAccountLink;
        abort_unless($link, 404);
        try {
            $this->audited($organization, 'management.licensing.unlink', fn () => $links->unlink($link));
            session()->flash('status', 'Account unlinked. Its installations and unused codes were revoked.');
        } catch (KernelBridgeLicensingException $exception) {
            Log::warning('KernelBridge account unlink failed.', ['organization_id' => $organization->id, 'code' => $exception->errorCode]);
            $this->addError('license', $exception->getMessage());
        }
    }

    public function render()
    {
        $organization = $this->authorizedOrganization();
        $link = $organization->kernelBridgeAccountLink;
        $license = $link ? (object) ['provider_metadata' => [
            'account_link_status' => $link->status,
            'account_link_uuid' => $link->link_uuid,
            'plan_name' => $link->plan_name,
        ]] : null;

        return view('kernelbridge-licensing::installation', [
            'productName' => 'MediFlow',
            'businessName' => $organization->name,
            'license' => $license,
            'licenseActive' => $link ? app(OrganizationLicenseAccess::class)->permits($link) : false,
        ]);
    }
}
