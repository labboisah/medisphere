<?php

namespace App\Support;

use App\Models\KernelBridgeAccountLink;
use App\Models\Organization;
use App\Models\ServerInstallation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class KernelBridgeLicensingClient
{
    /**
     * @return array<string, mixed>
     */
    public function linkAccount(Organization $organization, string $activationCode): array
    {
        $data = $this->request('POST', '/platform/account-links/requests', [
            'activation_code' => $activationCode,
            'business_uuid' => strtolower($organization->id),
        ]);
        if (($data['business_uuid'] ?? null) !== strtolower($organization->id)
            || strtoupper((string) ($data['product_code'] ?? '')) !== strtoupper((string) config('kernelbridge.product_code'))
            || ($data['status'] ?? null) !== 'approved') {
            throw new KernelBridgeLicensingException('invalid_link_response', 'KernelBridge returned an unexpected account-link response.', 502);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(KernelBridgeAccountLink $link): array
    {
        $data = $this->request('GET', '/platform/account-links/'.rawurlencode($link->link_uuid));
        if (($data['uuid'] ?? null) !== $link->link_uuid
            || ($data['license_uuid'] ?? null) !== $link->license_uuid
            || ($data['business_uuid'] ?? null) !== strtolower($link->organization_id)
            || strtoupper((string) ($data['product_code'] ?? '')) !== strtoupper((string) config('kernelbridge.product_code'))) {
            throw new KernelBridgeLicensingException('account_link_mismatch', 'KernelBridge account-link details do not match this organization.', 502);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function installations(KernelBridgeAccountLink $link, int $page): array
    {
        $data = $this->request('GET', '/platform/account-links/'.rawurlencode($link->link_uuid).'/installations?page='.$page);
        if (($data['link_uuid'] ?? null) !== $link->link_uuid
            || ($data['business_uuid'] ?? null) !== strtolower($link->organization_id)
            || ! is_array($data['installations'] ?? null)
            || ! array_is_list($data['installations'])
            || ! is_int($data['current_page'] ?? null)
            || $data['current_page'] !== $page
            || ! is_int($data['last_page'] ?? null)
            || $data['last_page'] < 1
            || ! is_int($data['total'] ?? null)
            || $data['total'] < 0) {
            throw new KernelBridgeLicensingException('invalid_installations_response', 'KernelBridge returned an invalid server list.', 502);
        }

        $installations = [];
        foreach ($data['installations'] as $item) {
            if (! is_array($item)
                || ! Str::isUuid($item['installation_uuid'] ?? null)
                || ! is_string($item['status'] ?? null)
                || ! in_array($item['status'], ['active', 'revoked', 'deactivated'], true)) {
                throw new KernelBridgeLicensingException('invalid_installations_response', 'KernelBridge returned an invalid server record.', 502);
            }
            foreach (['device_name', 'runtime_type', 'activated_at', 'last_verified_at', 'expires_at'] as $field) {
                if (! array_key_exists($field, $item) || (! is_string($item[$field]) && $item[$field] !== null)) {
                    throw new KernelBridgeLicensingException('invalid_installations_response', 'KernelBridge returned an invalid server record.', 502);
                }
            }
            foreach (['activated_at', 'last_verified_at', 'expires_at'] as $field) {
                if ($item[$field] !== null && strtotime($item[$field]) === false) {
                    throw new KernelBridgeLicensingException('invalid_installations_response', 'KernelBridge returned an invalid server timestamp.', 502);
                }
            }
            $installations[] = array_intersect_key($item, array_flip(['installation_uuid', 'device_name', 'runtime_type', 'status', 'activated_at', 'last_verified_at', 'expires_at']));
        }

        return ['data' => $installations, 'current_page' => $page, 'last_page' => $data['last_page'], 'total' => $data['total']];
    }

    /**
     * @return array<string, mixed>
     */
    public function unlink(KernelBridgeAccountLink $link): array
    {
        $data = $this->request('DELETE', '/platform/account-links/'.rawurlencode($link->link_uuid));
        if (($data['uuid'] ?? null) !== $link->link_uuid
            || ($data['license_uuid'] ?? null) !== $link->license_uuid
            || ($data['business_uuid'] ?? null) !== strtolower($link->organization_id)
            || ($data['status'] ?? null) !== 'revoked') {
            throw new KernelBridgeLicensingException('invalid_unlink_response', 'KernelBridge did not confirm account-link revocation.', 502);
        }

        return $data;
    }

    /**
     * @return array{activation_code: string, expires_at: string}
     */
    public function issueActivationCode(KernelBridgeAccountLink $link, ServerInstallation $installation): array
    {
        $data = $this->request('POST', '/platform/account-links/'.rawurlencode($link->link_uuid).'/activation-codes', [
            'installation_uuid' => $installation->installation_uuid,
            'device_name' => $installation->name,
        ]);
        if (($data['link_uuid'] ?? null) !== $link->link_uuid
            || ($data['installation_uuid'] ?? null) !== $installation->installation_uuid
            || ! is_string($data['activation_code'] ?? null)
            || ! is_string($data['expires_at'] ?? null)) {
            throw new KernelBridgeLicensingException('invalid_activation_code_response', 'KernelBridge returned an unexpected installation-code response.', 502);
        }

        return [
            'activation_code' => $data['activation_code'],
            'expires_at' => $data['expires_at'],
        ];
    }

    /** @return array{activation_code: string, expires_at: string} */
    public function issueEnrollmentCode(KernelBridgeAccountLink $link): array
    {
        $data = $this->request('POST', '/platform/account-links/'.rawurlencode($link->link_uuid).'/server-enrollment-codes');
        if (($data['link_uuid'] ?? null) !== $link->link_uuid
            || ! Str::isUuid($data['activation_code_uuid'] ?? null)
            || ! is_string($data['activation_code'] ?? null)
            || trim($data['activation_code']) === ''
            || ! is_string($data['expires_at'] ?? null)
            || strtotime($data['expires_at']) === false
            || strtotime($data['expires_at']) <= now()->getTimestamp()) {
            throw new KernelBridgeLicensingException('invalid_activation_code_response', 'KernelBridge returned an unexpected enrollment-code response.', 502);
        }

        return ['activation_code' => $data['activation_code'], 'expires_at' => $data['expires_at']];
    }

    /**
     * @return array<string, mixed>
     */
    public function revokeInstallation(KernelBridgeAccountLink $link, ServerInstallation $installation): array
    {
        $data = $this->request(
            'POST',
            '/platform/account-links/'.rawurlencode($link->link_uuid).'/installations/'.rawurlencode($installation->installation_uuid).'/revoke',
        );
        if (($data['link_uuid'] ?? null) !== $link->link_uuid
            || ($data['installation_uuid'] ?? null) !== $installation->installation_uuid
            || ($data['status'] ?? null) !== 'revoked') {
            throw new KernelBridgeLicensingException('invalid_revocation_response', 'KernelBridge did not confirm installation revocation.', 502);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $url = trim((string) config('kernelbridge.api_url'));
        $credential = trim((string) config('kernelbridge.product_credential'));
        if ($url === '' || $credential === '') {
            throw new KernelBridgeLicensingException('licensing_not_configured', 'KernelBridge licensing is not configured for this environment.', 503);
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (($parts['scheme'] ?? null) !== 'https' && ! ($isLoopback && ($parts['scheme'] ?? null) === 'http'))) {
            throw new KernelBridgeLicensingException('licensing_not_configured', 'KernelBridge API URL must use HTTPS except for local loopback development.', 503);
        }

        try {
            $request = Http::baseUrl(rtrim($url, '/'))
                ->acceptJson()
                ->asJson()
                ->withToken($credential)
                ->withoutRedirecting()
                ->connectTimeout(max(1, min(5, (int) config('kernelbridge.timeout_seconds', 10))))
                ->timeout(max(1, (int) config('kernelbridge.timeout_seconds', 10)));
            $response = $method === 'GET'
                ? $request->get($path)
                : ($method === 'DELETE' ? $request->delete($path) : $request->post($path, $payload ?? []));
        } catch (ConnectionException) {
            throw new KernelBridgeLicensingException('licensing_service_unavailable', 'KernelBridge licensing is temporarily unavailable. Try again later.', 503);
        }

        return $this->readResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function readResponse(Response $response): array
    {
        $body = $response->json();
        if ($response->successful() && is_array($body) && ($body['success'] ?? false) === true && is_array($body['data'] ?? null)) {
            return $body['data'];
        }

        $status = $response->status();
        if ($status === 401) {
            throw new KernelBridgeLicensingException('licensing_credential_rejected', 'KernelBridge rejected the configured product credential.', 503);
        }
        if ($status === 403) {
            throw new KernelBridgeLicensingException('licensing_request_denied', 'KernelBridge denied this licensing request. Check product scope and licensing policy.', 403);
        }
        if ($status === 404) {
            throw new KernelBridgeLicensingException('licensing_record_not_found', 'The KernelBridge licensing record was not found.', 404);
        }
        if ($status === 409 || $status === 422) {
            throw new KernelBridgeLicensingException('licensing_request_conflict', 'KernelBridge rejected this licensing request. Check the activation code, account link, and installation binding.', 422);
        }
        if ($status === 429) {
            throw new KernelBridgeLicensingException('licensing_rate_limited', 'KernelBridge is rate limiting licensing requests. Try again later.', 429);
        }

        throw new KernelBridgeLicensingException(
            'licensing_service_unavailable',
            'KernelBridge licensing could not complete the request. Try again later.',
            $status >= 500 ? 503 : 502,
        );
    }
}
