<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\MfaSession;
use App\Support\OrganizationLicenseAccess;
use App\Support\OrganizationOnboardingStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentSessionController
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'synthetic_platform_admin' => app()->environment(['local', 'testing'])
                    && config('platform.synthetic_management_enabled') === true
                    && $request->user()->synthetic_platform_admin,
                'mfa' => MfaSession::isVerified($request, $request->user())
                    ? 'verified'
                    : ($request->user()->two_factor_confirmed_at === null ? 'setup_required' : 'sign_in_again'),
                'onboarding_required' => MfaSession::isVerified($request, $request->user())
                    && app()->environment(['local', 'testing'])
                    && config('platform.synthetic_management_enabled') === true
                    && OrganizationOnboardingStatus::needsAttention($request->user()),
                'license_required' => MfaSession::isVerified($request, $request->user())
                    && app()->environment(['local', 'testing'])
                    && config('platform.synthetic_management_enabled') === true
                    && ! OrganizationOnboardingStatus::needsAttention($request->user())
                    && ! app(OrganizationLicenseAccess::class)->userHasAccess($request->user()),
            ],
        ]);
    }
}
