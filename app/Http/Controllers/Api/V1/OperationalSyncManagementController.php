<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Support\KernelBridgeLicensingException;
use App\Support\OperationalSyncEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OperationalSyncManagementController
{
    public function issue(Request $request, Organization $organization, OperationalSyncEnrollment $enrollment): JsonResponse
    {
        abort_unless(config('operational_sync.enrollment_enabled'), 404);
        OrganizationAccess::requirePermission($request->user(), $organization, 'servers.manage');
        $data = $request->validate(['installation_uuid' => ['required', 'uuid']]);
        $link = $organization->kernelBridgeAccountLink;
        abort_unless($link, 404);
        try {
            $issued = $enrollment->issue($link, strtolower($data['installation_uuid']));
        } catch (KernelBridgeLicensingException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode], $exception->httpStatus);
        }

        return response()->json(['data' => $issued], 201)->header('Cache-Control', 'no-store, private');
    }

    public function revoke(Request $request, Organization $organization, string $installation, OperationalSyncEnrollment $enrollment): JsonResponse
    {
        abort_unless(config('operational_sync.enrollment_enabled'), 404);
        OrganizationAccess::requirePermission($request->user(), $organization, 'servers.manage');
        $link = $organization->kernelBridgeAccountLink;
        abort_unless($link, 404);
        $enrollment->revoke($link, strtolower($installation));

        return response()->json(['data' => ['status' => 'revoked']])->header('Cache-Control', 'no-store, private');
    }
}
