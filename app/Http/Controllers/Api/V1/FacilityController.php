<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FacilityController
{
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $user = $request->user();
        OrganizationAccess::requireMember($user, $organization);
        $perPage = max(1, min((int) $request->query('per_page', 25), 100));

        $query = $organization->facilities();
        if (! OrganizationAccess::can($user, $organization, 'facilities.view')
            && ! OrganizationAccess::can($user, $organization, 'facilities.manage')
            && ! OrganizationAccess::can($user, $organization, 'servers.manage')) {
            $query->whereIn('id', $user->memberships()
                ->where('organization_id', $organization->id)
                ->where('status', 'active')
                ->where('role', 'facility_admin')
                ->whereNotNull('facility_id')
                ->select('facility_id'));
        }

        return response()->json($query->orderBy('name')->orderBy('id')
            ->paginate($perPage, ['id', 'organization_id', 'name', 'status', 'locale', 'time_zone']));
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        OrganizationAccess::requirePermission($request->user(), $organization, 'facilities.manage');
        $validated = $this->validateFacility($request, $organization);
        $facility = $organization->facilities()->create($validated)->refresh();

        return response()->json(['data' => $facility->only(['id', 'organization_id', 'name', 'status', 'locale', 'time_zone'])], 201);
    }

    public function show(Request $request, Organization $organization, string $facility): JsonResponse
    {
        OrganizationAccess::requireMember($request->user(), $organization);
        $record = $organization->facilities()->findOrFail($facility);
        abort_unless(OrganizationAccess::canViewFacility($request->user(), $organization, $record->id), 403);

        return response()->json(['data' => $record->only(['id', 'organization_id', 'name', 'status', 'locale', 'time_zone'])]);
    }

    public function update(Request $request, Organization $organization, string $facility): JsonResponse
    {
        OrganizationAccess::requirePermission($request->user(), $organization, 'facilities.manage');
        $record = $organization->facilities()->findOrFail($facility);
        $record->update($this->validateFacility($request, $organization, $record->id));

        return response()->json(['data' => $record->only(['id', 'organization_id', 'name', 'status', 'locale', 'time_zone'])]);
    }

    private function validateFacility(Request $request, Organization $organization, ?string $facilityId = null): array
    {
        $name = Rule::unique('facilities', 'name')->where('organization_id', $organization->id);
        if ($facilityId !== null) {
            $name->ignore($facilityId);
        }

        $validated = $request->validate([
            'name' => [$facilityId === null ? 'required' : 'sometimes', 'required', 'string', 'max:255', $name],
            'locale' => ['sometimes', 'nullable', 'string', 'max:35'],
            'time_zone' => ['sometimes', 'nullable', 'timezone', 'max:64'],
            'id' => ['missing'],
            'organization_id' => ['missing'],
            'status' => ['missing'],
        ]);

        if ($facilityId !== null && $validated === []) {
            throw ValidationException::withMessages(['facility' => 'Provide at least one field to update.']);
        }

        return $validated;
    }
}
