<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Models\OrganizationRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrganizationRoleController
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->synthetic_platform_admin, 403);

        return response()->json(['data' => OrganizationRole::query()->orderBy('name')->get(), 'permissions' => OrganizationAccess::PERMISSIONS]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->synthetic_platform_admin, 403);
        $role = OrganizationRole::create($this->validateRole($request));
        $this->recordChange($request, $role, null);

        return response()->json(['data' => $role], 201);
    }

    public function update(Request $request, OrganizationRole $role): JsonResponse
    {
        abort_unless($request->user()->synthetic_platform_admin, 403);
        $data = $this->validateRole($request, $role);
        OrganizationAccess::lockRoles();
        $role = OrganizationRole::query()->lockForUpdate()->findOrFail($role->id);
        $before = $role->only(['name', 'permissions']);
        $role->update($data);
        $this->recordChange($request, $role, $before);

        return response()->json(['data' => $role]);
    }

    public function available(Request $request, Organization $organization): JsonResponse
    {
        abort_unless(OrganizationAccess::can($request->user(), $organization, 'invitations.manage')
            || OrganizationAccess::can($request->user(), $organization, 'users.manage'), 403);

        return response()->json(['data' => OrganizationRole::query()->orderBy('name')->get()
            ->filter(fn (OrganizationRole $role) => OrganizationAccess::canAssign($request->user(), $organization, $role))
            ->map(fn (OrganizationRole $role) => $role->only(['id', 'name']))
            ->values()]);
    }

    private function validateRole(Request $request, ?OrganizationRole $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('organization_roles', 'name')->ignore($role?->id), function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && in_array(strtolower(trim($value)), ['organization administrator', 'facility administrator', 'organization_admin', 'facility_admin'], true)) {
                    $fail('This name is reserved for a built-in role.');
                }
            }],
            'permissions' => ['present', 'array', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_array($value) && ! array_is_list($value)) {
                    $fail('Permissions must be a list.');
                }
            }],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(OrganizationAccess::PERMISSIONS)],
        ]);
    }

    private function recordChange(Request $request, OrganizationRole $role, ?array $before): void
    {
        DB::table('organization_role_changes')->insert([
            'organization_role_id' => $role->id,
            'actor_id' => $request->user()->id,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => json_encode($role->only(['name', 'permissions']), JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
