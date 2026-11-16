<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\KernelBridgeLicensingException;
use App\Support\OperationalSyncAuthenticator;
use App\Support\OperationalSyncEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class OperationalSyncMachineController
{
    public function enroll(Request $request, OperationalSyncEnrollment $enrollment): JsonResponse
    {
        $this->assertRequest($request, 'enrollment_enabled');
        $input = $request->json()->all();
        if (array_diff(array_keys($input), ['code', 'proof']) !== [] || count($input) !== 2
            || ! is_string($input['code'] ?? null) || ! is_array($input['proof'] ?? null)) {
            return $this->error('invalid_request', 422);
        }
        try {
            $result = $enrollment->redeem($input['code'], $input['proof']);
        } catch (ValidationException) {
            return $this->error('invalid_enrollment', 422);
        }

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store, private');
    }

    public function ingest(Request $request, OperationalSyncAuthenticator $authenticator): JsonResponse
    {
        $this->assertRequest($request, 'ingestion_enabled');
        $input = $request->json()->all();
        if (array_keys($input) !== ['event'] || ! is_array($input['event'])) {
            return $this->error('invalid_request', 422);
        }
        try {
            $result = $authenticator->acceptAuthoritatively((string) $request->bearerToken(), $input['event']);
        } catch (ValidationException $exception) {
            return $this->error(isset($exception->errors()['credential']) ? 'credential_inactive' : 'event_rejected', 422);
        } catch (KernelBridgeLicensingException $exception) {
            if (in_array($exception->httpStatus, [403, 404], true)) {
                return $this->error('credential_inactive', 403);
            }

            return $this->error('licensing_unavailable', 503);
        }

        return response()->json($result)->header('Cache-Control', 'no-store, private');
    }

    private function assertRequest(Request $request, string $flag): void
    {
        abort_unless(config('operational_sync.'.$flag), 404);
        $allowHttp = ! $request->secure()
            && app()->environment(['local', 'testing'])
            && config('platform.synthetic_management_enabled') === true
            && config('operational_sync.allow_insecure_ip_http') === true
            && preg_match('/\A(?:\[[0-9a-f:.]+\]|[0-9.]+):[0-9]{1,5}\z/i', (string) $request->headers->get('host')) === 1
            && filter_var(trim($request->getHost(), '[]'), FILTER_VALIDATE_IP) !== false;
        abort_unless($request->secure() || $allowHttp, 403, 'HTTPS is required unless IP-based HTTP is explicitly enabled for local testing.');
        abort_unless($request->isJson(), 415);
        abort_if(strlen($request->getContent()) > 8192, 413);
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => 'The operational sync request could not be accepted.'], $status)
            ->header('Cache-Control', 'no-store, private');
    }
}
