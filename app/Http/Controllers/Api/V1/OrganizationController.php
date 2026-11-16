<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Support\OrganizationOnboardingStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $perPage = (int) ($validated['per_page'] ?? 25);
        $organizations = OrganizationOnboardingStatus::memberships($request->user())
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage, ['id', 'name', 'status']);

        return response()->json($organizations);
    }

    public function show(Request $request, Organization $organization): JsonResponse
    {
        OrganizationAccess::requireMember($request->user(), $organization);
        $canManage = OrganizationAccess::isAdministrator($request->user(), $organization);
        $permissions = OrganizationAccess::permissions($request->user(), $organization);
        $onboarding = in_array('organization.view', $permissions, true) ? $organization->onboarding : null;
        $facilityMember = $request->user()->memberships()->where('organization_id', $organization->id)
            ->where('status', 'active')->where('role', 'facility_admin')->whereNotNull('facility_id')->exists();

        return response()->json(['data' => $organization->only(['id', 'name', 'status']) + [
            'can_manage' => $canManage,
            'can_view_facilities' => $facilityMember || in_array('facilities.view', $permissions, true) || in_array('facilities.manage', $permissions, true),
            'permissions' => $permissions,
            'onboarding' => $onboarding ? $onboarding->only([
                'legal_name', 'organization_type', 'ownership_type', 'owner_name',
                'registration_authority', 'registration_number', 'address_line_1', 'address_line_2',
                'city', 'state_region', 'postal_code', 'country', 'notice_version',
                'authority_confirmed', 'processing_consent', 'accepted_at',
            ]) + ['accepted_by_name' => $onboarding->acceptedBy?->name] : null,
        ]])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        OrganizationAccess::requireAdministrator($request->user(), $organization);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'id' => ['missing'],
            'status' => ['missing'],
            'legal_identity_reference' => ['missing'],
        ]);
        $organization->update($validated);

        return response()->json(['data' => $organization->only(['id', 'name', 'status'])]);
    }
}
