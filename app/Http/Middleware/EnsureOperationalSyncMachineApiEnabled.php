<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOperationalSyncMachineApiEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment(['local', 'testing'])) {
            abort_unless(config('platform.synthetic_management_enabled') === true, 404);
        } else {
            abort_unless(
                app()->environment('production')
                    && config('operational_sync.production_machine_api_enabled') === true,
                404,
            );
            abort_unless($request->secure(), 403, 'HTTPS is required.');
        }

        return $next($request);
    }
}
