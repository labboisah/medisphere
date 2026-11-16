<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuditSyntheticManagement
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return DB::transaction(function () use ($request, $next): Response {
            $response = $next($request);
            if ($response->getStatusCode() >= 500) {
                throw new HttpResponseException($response);
            }
            $organization = $request->route('organization');
            $facility = $request->route('facility');
            if ($request->route()->getName() === 'management.facilities.create' && $response instanceof JsonResponse && $response->getStatusCode() === 201) {
                $facility = $response->getData(true)['data']['id'];
            }
            if ($request->route()->getName() === 'management.synthetic-exchange.links.create' && $response instanceof JsonResponse && $response->getStatusCode() === 201) {
                $facility = $response->getData(true)['data']['facility_id'];
            }
            if ($request->route()->getName() === 'management.platform.organizations.create' && $response instanceof JsonResponse && $response->getStatusCode() === 201) {
                $organization = Organization::findOrFail($response->getData(true)['data']['id']);
            }
            $correlationId = (string) Str::uuid();

            DB::table('management_audit_events')->insert([
                'id' => (string) Str::uuid(),
                'actor_id' => $request->user()->getAuthIdentifier(),
                'organization_id' => $organization instanceof Organization ? $organization->id : null,
                'facility_id' => is_string($facility) ? $facility : null,
                'action' => $request->route()->getName(),
                'http_status' => $response->getStatusCode(),
                'correlation_id' => $correlationId,
                'created_at' => now(),
            ]);

            $response->headers->set('X-Correlation-ID', $correlationId);

            return $response;
        });
    }
}
