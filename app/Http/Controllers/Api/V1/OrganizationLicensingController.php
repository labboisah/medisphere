<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Models\ServerEnrollmentCode;
use App\Models\ServerInstallation;
use App\Support\KernelBridgeAccountLinkManager;
use App\Support\KernelBridgeLicensingException;
use App\Support\KernelBridgeLicensingClient;
use App\Support\OperationalSyncHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrganizationLicensingController
{
    public function show(Request $request, Organization $organization, KernelBridgeAccountLinkManager $links): JsonResponse
    {
        $license = OrganizationAccess::can($request->user(), $organization, 'license.manage');
        $servers = OrganizationAccess::can($request->user(), $organization, 'servers.manage');
        abort_unless($license || $servers, 403);
        $link = $organization->kernelBridgeAccountLink;

        return response()->json([
            'data' => [
                'link' => $link ? ($license ? $links->publicStatus($link) : [
                    'status' => $link->status,
                    'license_status' => $link->license_status,
                ]) : null,
                'installations' => $servers ? $this->installationList($organization) : [],
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function link(Request $request, Organization $organization, KernelBridgeAccountLinkManager $links): JsonResponse
    {
        OrganizationAccess::requirePermission($request->user(), $organization, 'license.manage');
        $data = $request->validate([
            'activation_code' => ['required', 'string', 'max:255'],
        ]);
        if (! preg_match('/\S/', $data['activation_code'])) {
            return response()->json(['message' => 'An activation code is required.'], 422);
        }

        try {
            $link = $links->link($organization, trim($data['activation_code']));
        } catch (KernelBridgeLicensingException $exception) {
            return $this->error($exception);
        }

        return response()->json(['data' => $links->publicStatus($link)], 201);
    }

    public function activatedServers(Request $request, Organization $organization, KernelBridgeLicensingClient $client, OperationalSyncHealth $health): JsonResponse
    {
        OrganizationAccess::requirePermission($request->user(), $organization, 'servers.manage');
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
        $link = $organization->kernelBridgeAccountLink;
        abort_unless($link && $link->status === 'approved', 404);
        try {
            $result = $client->installations($link, (int) ($data['page'] ?? 1));
        } catch (KernelBridgeLicensingException $exception) {
            return $this->error($exception);
        }

        if (config('operational_sync.monitoring_enabled')) {
            $healthByInstallation = $health->forInstallations($link, array_column($result['data'], 'installation_uuid'));
            foreach ($result['data'] as &$installation) {
                $installation['operational_health'] = $installation['status'] === 'active'
                    ? ($healthByInstallation[$installation['installation_uuid']] ?? ['status' => 'unknown'])
                    : ['status' => 'unknown'];
            }
            unset($installation);
        }
        if (config('operational_sync.enrollment_enabled')) {
            $result['operational_sync_enrollment_enabled'] = true;
        }

        return response()->json($result)->header('Cache-Control', 'no-store, private');
    }

    public function syncSummary(Request $request, Organization $organization, OperationalSyncHealth $health): JsonResponse
    {
        OrganizationAccess::requirePermission($request->user(), $organization, 'servers.manage');
        $link = $organization->kernelBridgeAccountLink;
        if (! config('operational_sync.monitoring_enabled')) {
            $data = ['status' => 'disabled'];
        } elseif (! $link || $link->status !== 'approved') {
            $data = ['status' => 'unlinked'];
        } else {
            $data = ['status' => 'available', ...$health->summary($link)];
        }

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store, private');
    }

    public function refresh(Request $request, Organization $organization, KernelBridgeAccountLinkManager $links): JsonResponse
    {
        OrganizationAccess::requirePermission($request->user(), $organization, 'license.manage');
        $link = $organization->kernelBridgeAccountLink;
        abort_unless($link, 404);

        try {
            $link = $links->refresh($link);
        } catch (KernelBridgeLicensingException $exception) {
            return $this->error($exception);
        }

        return response()->json(['data' => $links->publicStatus($link)]);
    }

    public function unlink(Request $request, Organization $organization, KernelBridgeAccountLinkManager $links): JsonResponse
    {
        OrganizationAccess::requirePermission($request->user(), $organization, 'license.manage');
        $link = $organization->kernelBridgeAccountLink;
        abort_unless($link, 404);

        try {
            $link = $links->unlink($link);
        } catch (KernelBridgeLicensingException $exception) {
            return $this->error($exception);
        }

        return response()->json(['data' => $links->publicStatus($link)]);
    }

    public function issueEnrollmentCode(Request $request, Organization $organization, KernelBridgeLicensingClient $client): JsonResponse
    {
        OrganizationAccess::requirePermission($request->user(), $organization, 'servers.manage');
        $link = $organization->kernelBridgeAccountLink;
        abort_unless($link, 404);
        if ($link->status !== 'approved') {
            return response()->json(['message' => 'An approved account link is required.'], 409);
        }

        try {
            $issued = $client->issueEnrollmentCode($link);
        } catch (KernelBridgeLicensingException $exception) {
            return $this->error($exception);
        }

        $record = ServerEnrollmentCode::query()->create([
            'organization_id' => $organization->id,
            'link_uuid' => $link->link_uuid,
            'encrypted_code' => $issued['activation_code'],
            'expires_at' => $issued['expires_at'],
        ]);

        return response()->json(['data' => ['id' => $record->id, ...$issued]], 201)->header('Cache-Control', 'no-store, private');
    }

    public function enrollmentCodes(Request $request, Organization $organization): JsonResponse
    {
        OrganizationAccess::requirePermission($request->user(), $organization, 'servers.manage');
        $link = $organization->kernelBridgeAccountLink;
        $codes = $link && $link->status === 'approved'
            ? ServerEnrollmentCode::query()
                ->where('organization_id', $organization->id)
                ->where('link_uuid', $link->link_uuid)
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->get(['id', 'created_at', 'expires_at'])
                ->map(fn (ServerEnrollmentCode $code): array => [
                    'id' => $code->id,
                    'created_at' => $code->created_at->toISOString(),
                    'expires_at' => $code->expires_at->toISOString(),
                ])->all()
            : [];

        return response()->json(['data' => $codes])->header('Cache-Control', 'no-store, private');
    }

    public function revealEnrollmentCode(Request $request, Organization $organization, string $code): JsonResponse
    {
        OrganizationAccess::requirePermission($request->user(), $organization, 'servers.manage');
        $link = $organization->kernelBridgeAccountLink;
        abort_unless($link && $link->status === 'approved', 404);
        $record = ServerEnrollmentCode::query()
            ->where('organization_id', $organization->id)
            ->where('link_uuid', $link->link_uuid)
            ->where('expires_at', '>', now())
            ->findOrFail($code);

        return response()->json(['data' => [
            'id' => $record->id,
            'activation_code' => $record->encrypted_code,
            'expires_at' => $record->expires_at->toISOString(),
        ]])->header('Cache-Control', 'no-store, private');
    }

    public function registerInstallation(Request $request, Organization $organization): JsonResponse
    {
        OrganizationAccess::requirePermission($request->user(), $organization, 'servers.manage');
        $data = $request->validate([
            'facility_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $facility = $organization->facilities()->findOrFail($data['facility_id']);
        $installation = ServerInstallation::query()->create([
            'organization_id' => $organization->id,
            'facility_id' => $facility->id,
            'installation_uuid' => (string) Str::uuid(),
            'name' => trim($data['name']),
            'status' => 'registered',
            'registered_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->serializeInstallation($installation->load('facility:id,name'))], 201);
    }

    public function issueActivationCode(
        Request $request,
        Organization $organization,
        string $installation,
        KernelBridgeAccountLinkManager $links,
    ): JsonResponse {
        OrganizationAccess::requirePermission($request->user(), $organization, 'servers.manage');
        $accountLink = $organization->kernelBridgeAccountLink;
        abort_unless($accountLink, 404);
        $record = $organization->serverInstallations()->findOrFail($installation);
        if ($record->status === 'revoked') {
            return response()->json(['message' => 'Register a replacement installation; revoked installations cannot be reused.'], 409);
        }

        try {
            $issued = $links->issueActivationCode($accountLink, $record);
        } catch (KernelBridgeLicensingException $exception) {
            return $this->error($exception);
        }

        return response()->json([
            'data' => [
                'installation_uuid' => $record->installation_uuid,
                'activation_code' => $issued['activation_code'],
                'expires_at' => $issued['expires_at'],
            ],
        ], 201)->header('Cache-Control', 'no-store, private');
    }

    public function revokeInstallation(
        Request $request,
        Organization $organization,
        string $installation,
        KernelBridgeAccountLinkManager $links,
    ): JsonResponse {
        OrganizationAccess::requirePermission($request->user(), $organization, 'servers.manage');
        $accountLink = $organization->kernelBridgeAccountLink;
        abort_unless($accountLink, 404);
        $record = $organization->serverInstallations()->findOrFail($installation);

        try {
            $record = $links->revokeInstallation($accountLink, $record);
        } catch (KernelBridgeLicensingException $exception) {
            return $this->error($exception);
        }

        return response()->json(['data' => $this->serializeInstallation($record->load('facility:id,name'))]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function installationList(Organization $organization): array
    {
        return $organization->serverInstallations()
            ->with('facility:id,name')
            ->orderBy('created_at')
            ->get()
            ->map(fn (ServerInstallation $installation): array => $this->serializeInstallation($installation))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeInstallation(ServerInstallation $installation): array
    {
        return [
            'id' => $installation->id,
            'facility_id' => $installation->facility_id,
            'facility_name' => $installation->facility?->name,
            'installation_uuid' => $installation->installation_uuid,
            'name' => $installation->name,
            'status' => $installation->status,
            'activation_code_expires_at' => $installation->activation_code_expires_at?->toISOString(),
            'created_at' => $installation->created_at?->toISOString(),
        ];
    }

    private function error(KernelBridgeLicensingException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->errorCode,
        ], $exception->httpStatus);
    }
}
