<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizationMembershipController
{
    public function index(Request $request, Organization $organization): JsonResponse
    {
        OrganizationAccess::requirePermission($request->user(), $organization, 'users.manage');
        $perPage = max(1, min((int) $request->query('per_page', 25), 100));
        $paginator = $organization->memberships()
            ->with(['user:id,name,email', 'facility:id,organization_id,name'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($perPage, ['id', 'user_id', 'organization_id', 'facility_id', 'role', 'status', 'created_at', 'updated_at']);

        $paginator->setCollection($paginator->getCollection()->map(fn (Membership $membership): array => [
            'id' => $membership->id,
            'user_id' => $membership->user_id,
            'role' => $membership->role,
            'status' => $membership->status,
            'facility_id' => $membership->facility_id,
            'facility' => $membership->facility ? ['id' => $membership->facility->id, 'name' => $membership->facility->name] : null,
            'user' => ['id' => $membership->user->id, 'name' => $membership->user->name, 'email' => $membership->user->email],
            'created_at' => $membership->created_at?->toISOString(),
            'updated_at' => $membership->updated_at?->toISOString(),
        ]));

        return response()->json($paginator);
    }

    public function update(Request $request, Organization $organization, string $membership): JsonResponse
    {
        OrganizationAccess::requirePermission($request->user(), $organization, 'users.manage');
        $validated = $request->validate([
            'role' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'facility_id' => ['sometimes', 'nullable', 'uuid'],
            'id' => ['missing'],
            'organization_id' => ['missing'],
            'user_id' => ['missing'],
        ]);
        if ($validated === []) {
            throw ValidationException::withMessages(['membership' => 'Provide at least one field to update.']);
        }

        $record = DB::transaction(function () use ($request, $organization, $membership, $validated): Membership {
            $lockedOrganization = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            OrganizationAccess::lockRoles();
            OrganizationAccess::requirePermission($request->user(), $lockedOrganization, 'users.manage');
            $record = $lockedOrganization->memberships()->whereKey($membership)->lockForUpdate()->firstOrFail();
            $this->authorizeTarget($request, $lockedOrganization, $record);

            $role = $validated['role'] ?? $record->role;
            $status = $validated['status'] ?? $record->status;
            $facilityId = array_key_exists('facility_id', $validated) ? $validated['facility_id'] : $record->facility_id;
            if ($role === 'organization_admin' && $record->role !== 'organization_admin') {
                throw ValidationException::withMessages(['role' => 'Organization administrators are invited during provisioning only.']);
            }
            if (str_starts_with($role, 'custom:')) {
                $roleId = OrganizationAccess::roleId($role);
                $selectedRole = $roleId ? OrganizationRole::query()->find($roleId) : null;
                abort_unless($selectedRole && OrganizationAccess::canAssign($request->user(), $lockedOrganization, $selectedRole), 403);
            } elseif (! in_array($role, ['organization_admin', 'facility_admin'], true)
                || ($role === 'facility_admin' && ! OrganizationAccess::isAdministrator($request->user(), $lockedOrganization))) {
                abort(403);
            }
            $this->validateRoleShape($lockedOrganization, $role, $facilityId);
            if ($record->user_id === $request->user()->id && $status !== 'active') {
                throw ValidationException::withMessages(['membership' => 'Ask another organization administrator to suspend your membership.']);
            }
            $this->guardLastAdministrator($lockedOrganization, $record, $status === 'active' && $role === 'organization_admin' && $facilityId === null);

            $record->role = $role;
            $record->status = $status;
            if ($facilityId === null) {
                $record->facility()->dissociate();
            } else {
                $record->facility()->associate($lockedOrganization->facilities()->findOrFail($facilityId));
            }
            $record->save();

            return $record;
        });
        $record->refresh()->load(['user:id,name,email', 'facility:id,organization_id,name']);

        return response()->json(['data' => [
            'id' => $record->id,
            'user_id' => $record->user_id,
            'role' => $record->role,
            'status' => $record->status,
            'facility_id' => $record->facility_id,
            'facility' => $record->facility ? ['id' => $record->facility->id, 'name' => $record->facility->name] : null,
            'user' => ['id' => $record->user->id, 'name' => $record->user->name, 'email' => $record->user->email],
        ]]);
    }

    public function destroy(Request $request, Organization $organization, string $membership): JsonResponse
    {
        OrganizationAccess::requirePermission($request->user(), $organization, 'users.manage');
        DB::transaction(function () use ($request, $organization, $membership): void {
            $lockedOrganization = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            OrganizationAccess::lockRoles();
            OrganizationAccess::requirePermission($request->user(), $lockedOrganization, 'users.manage');
            $record = $lockedOrganization->memberships()->whereKey($membership)->lockForUpdate()->firstOrFail();
            $this->authorizeTarget($request, $lockedOrganization, $record);
            if ($record->user_id === $request->user()->id) {
                throw ValidationException::withMessages(['membership' => 'Ask another organization administrator to remove your membership.']);
            }
            $this->guardLastAdministrator($lockedOrganization, $record, false);
            $record->delete();
        });

        return response()->json(['message' => 'Membership removed.']);
    }

    private function validateRoleShape(Organization $organization, string $role, ?string $facilityId): void
    {
        if ($role !== 'facility_admin' && $facilityId !== null) {
            throw ValidationException::withMessages(['facility_id' => 'Organization-wide roles must not be scoped to a facility.']);
        }

        if ($role === 'facility_admin' && $facilityId === null) {
            throw ValidationException::withMessages(['facility_id' => 'Facility administrators must be assigned to a facility.']);
        }
        if ($facilityId !== null && ! $organization->facilities()->whereKey($facilityId)->exists()) {
            throw ValidationException::withMessages(['facility_id' => 'The selected facility is not in this organization.']);
        }
    }

    private function authorizeTarget(Request $request, Organization $organization, Membership $record): void
    {
        if (OrganizationAccess::isAdministrator($request->user(), $organization)) {
            return;
        }
        $roleId = OrganizationAccess::roleId($record->role);
        if (! $roleId) {
            abort(403);
        }
        $role = OrganizationRole::query()->find($roleId);
        abort_unless($role && OrganizationAccess::canAssign($request->user(), $organization, $role), 403);
    }

    private function guardLastAdministrator(Organization $organization, Membership $record, bool $willRemainOrganizationAdmin): void
    {
        $currentlyProtected = $record->status === 'active' && $record->role === 'organization_admin' && $record->facility_id === null;
        if (! $currentlyProtected || $willRemainOrganizationAdmin) {
            return;
        }

        $remaining = $organization->memberships()
            ->where('status', 'active')
            ->where('role', 'organization_admin')
            ->whereNull('facility_id')
            ->where('id', '!=', $record->id)
            ->exists();
        if (! $remaining) {
            throw ValidationException::withMessages(['membership' => 'An organization must keep at least one active organization administrator.']);
        }
    }
}
