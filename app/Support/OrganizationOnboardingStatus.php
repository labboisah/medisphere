<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\OrganizationRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class OrganizationOnboardingStatus
{
    public static function memberships(User $user): Builder
    {
        return Organization::query()->whereHas('memberships', fn (Builder $query) => $query
            ->where('user_id', $user->id)->where('status', 'active')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('role', 'organization_admin')->whereNull('facility_id'))
                ->orWhere(fn (Builder $query) => $query->where('role', 'facility_admin')->whereNotNull('facility_id'))
                ->orWhere(fn (Builder $query) => $query->whereNull('facility_id')->whereIn('role', OrganizationRole::all()->map->membershipRole()))));
    }

    public static function needsAttention(User $user): bool
    {
        return ! self::memberships($user)->exists()
            || self::memberships($user)->whereDoesntHave('onboarding')->exists();
    }
}
