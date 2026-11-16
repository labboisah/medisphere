<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSyntheticManagementEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            app()->environment(['local', 'testing']) && config('platform.synthetic_management_enabled') === true,
            404,
        );

        return $next($request);
    }
}
