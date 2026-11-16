<?php

namespace App\Http\Middleware;

use App\Support\MfaSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMfaVerified
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! MfaSession::isVerified($request, $request->user())) {
            return response()->json(['message' => 'Multi-factor authentication is required.', 'code' => 'mfa_required'], 403);
        }

        return $next($request);
    }
}
