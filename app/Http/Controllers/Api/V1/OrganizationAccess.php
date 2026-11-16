<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Models\OrganizationRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class OrganizationAccess
{
    public const PERMISSIONS = [
        'organization.view', 'invitations.manage', 'users.manage',
        'license.manage', 'servers.manage', 'facilities.view', 'facilities.manage',
        'synthetic.exchange.manage',
    ];

    private static function memberships(User $user, Organization $organization): Builder
    {
        return $user->memberships()->getQuery()
            ->where('organization_id', $organization->id)
            ->where('status', 'active');
    }

    public static function isAdministrator(User $user, Organization $organization): bool
    {
        return self::memberships($user, $organization)
            ->where('role', 'organization_admin')
            ->whereNull('facility_id')
            ->exists();
    }

    public static function requireAdministrator(User $user, Organization $organization): void
    {
        abort_unless(self::isAdministrator($user, $organization), 403);
    }

    public static function permissions(User $user, Organization $organization): array
    {
        if (self::isAdministrator($user, $organization)) {
            return self::PERMISSIONS;
        }

        $roleIds = self::memberships($user, $organization)->whereNull('facility_id')
            ->where('role', 'like', 'custom:%')->pluck('role')
            ->map(fn (string $role) => self::roleId($role))->filter()->values()->all();

        return OrganizationRole::query()->whereIn('id', $roleIds)->get()
            ->flatMap(fn (OrganizationRole $role) => $role->permissions)
            ->intersect(self::PERMISSIONS)->unique()->values()->all();
    }

    public static function can(User $user, Organization $organization, string $permission): bool
    {
        return in_array($permission, self::permissions($user, $organization), true);
    }

    public static function requirePermission(User $user, Organization $organization, string $permission): void
    {
        abort_unless(self::can($user, $organization, $permission), 403);
    }

    public static function canAssign(User $user, Organization $organization, OrganizationRole $role): bool
    {
        return count(array_diff($role->permissions, self::permissions($user, $organization))) === 0;
    }

    public static function lockRoles(): void
    {
        OrganizationRole::query()->orderBy('id')->lockForUpdate()->get();
    }

    public static function roleId(string $value): ?string
    {
        $id = str_starts_with($value, 'custom:') ? substr($value, 7) : '';

        return Str::isUuid($id) ? $id : null;
    }

    public static function requireMember(User $user, Organization $organization): void
    {
        $allowed = self::memberships($user, $organization)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('role', 'organization_admin')->whereNull('facility_id'))
                ->orWhere(fn (Builder $query) => $query->where('role', 'facility_admin')->whereNotNull('facility_id'))
                ->orWhere(fn (Builder $query) => $query->whereNull('facility_id')->whereIn('role', OrganizationRole::all()->map->membershipRole())))
            ->exists();

        abort_unless($allowed, 403);
    }

    public static function canViewFacility(User $user, Organization $organization, string $facilityId): bool
    {
        return self::isAdministrator($user, $organization)
            || self::can($user, $organization, 'facilities.view')
            || self::can($user, $organization, 'facilities.manage')
            || self::memberships($user, $organization)
                ->where('role', 'facility_admin')
                ->where('facility_id', $facilityId)
                ->exists();
    }
}
