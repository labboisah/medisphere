<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Membership;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationAuthorizationController
{
    public function __invoke(Request $request, Organization $organization): JsonResponse
    {
        /** @var Membership $membership */
        $membership = $request->attributes->get('organizationMembership');

        return response()->json([
            'data' => [
                'organization_id' => $organization->id,
                'facility_id' => $membership->facility_id,
                'role' => $membership->role,
            ],
        ]);
    }
}
