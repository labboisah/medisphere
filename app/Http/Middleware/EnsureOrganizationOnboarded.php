<?php

namespace App\Http\Middleware;

use App\Support\OrganizationOnboardingStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        if (OrganizationOnboardingStatus::needsAttention($request->user())) {
            return response()->json(['message' => 'Organization onboarding is required.', 'code' => 'onboarding_required'], 403)
                ->header('X-Onboarding-Required', '1');
        }

        return $next($request);
    }
}
