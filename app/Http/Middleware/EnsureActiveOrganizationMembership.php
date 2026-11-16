<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveOrganizationMembership
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $organization = $request->route('organization');

        if (! $organization instanceof Organization) {
            abort(404);
        }

        $membership = $request->user()
            ->memberships()
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->first();

        if ($membership === null) {
            abort(403);
        }

        $request->attributes->set('organizationMembership', $membership);

        return $next($request);
    }
}
