<?php

namespace App\Http\Middleware;

use App\Support\OrganizationLicenseAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationLicensed
{
    public function __construct(private readonly OrganizationLicenseAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->access->userHasAccess($request->user())) {
            return response()->json(['message' => 'An active KernelBridge account link is required.', 'code' => 'license_required'], 403)
                ->header('X-License-Required', '1');
        }

        return $next($request);
    }
}
