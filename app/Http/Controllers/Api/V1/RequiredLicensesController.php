<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Support\OrganizationLicenseAccess;
use App\Support\OrganizationOnboardingStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RequiredLicensesController
{
    public function __invoke(Request $request, OrganizationLicenseAccess $access): JsonResponse
    {
        $pending = OrganizationOnboardingStatus::memberships($request->user())
            ->with('kernelBridgeAccountLink')->orderBy('name')->orderBy('id')->get()
            ->filter(fn (Organization $organization) => ! $access->verify($organization))
            ->map(fn (Organization $organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'can_link' => OrganizationAccess::can($request->user(), $organization, 'license.manage'),
            ])->values();

        return response()->json(['data' => $pending])->header('Cache-Control', 'no-store, private');
    }
}
