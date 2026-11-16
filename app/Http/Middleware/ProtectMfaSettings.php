<?php

namespace App\Http\Middleware;

use App\Support\MfaSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ProtectMfaSettings
{
    private const PASSWORD_CONFIRMATION_TTL_SECONDS = 300;

    private const RECOVERY_CODE_ROTATION_LIMIT = 5;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route();
        $recoveryCodeRoute = $route?->named('two-factor.recovery-codes')
            || $route?->named('two-factor.regenerate-recovery-codes');
        $protected = $request->routeIs(
            'two-factor.enable', 'two-factor.confirm', 'two-factor.disable', 'two-factor.qr-code',
            'two-factor.secret-key', 'two-factor.recovery-codes', 'two-factor.regenerate-recovery-codes',
            'user-password.update',
        );

        if ($protected && $user && $user->two_factor_confirmed_at !== null && ! MfaSession::isVerified($request, $user)) {
            return response()->json(['message' => 'Multi-factor authentication is required.', 'code' => 'mfa_required'], 403);
        }

        if ($recoveryCodeRoute && $user && $user->two_factor_confirmed_at !== null) {
            $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
            if ($confirmedAt === 0 || now()->timestamp - $confirmedAt > self::PASSWORD_CONFIRMATION_TTL_SECONDS) {
                return response()->json([
                    'message' => 'Confirm your password again before accessing recovery codes.',
                    'code' => 'password_confirmation_required',
                ], 423);
            }
        }

        if ($route?->named('two-factor.regenerate-recovery-codes') && $user) {
            $key = 'mfa-recovery-code-rotation:'.$user->getAuthIdentifier().'|'.$request->ip();
            if (RateLimiter::tooManyAttempts($key, self::RECOVERY_CODE_ROTATION_LIMIT)) {
                return response()->json(['message' => 'Recovery codes were recently rotated. Try again later.'], 429);
            }
            RateLimiter::hit($key, 60);
        }

        if ($route?->named('two-factor.regenerate-recovery-codes') && $user) {
            return DB::transaction(function () use ($request, $next, $user): Response {
                $response = $next($request);
                if ($response->isSuccessful()) {
                    $correlationId = (string) Str::uuid();
                    DB::table('management_audit_events')->insert([
                        'id' => (string) Str::uuid(),
                        'actor_id' => $user->getAuthIdentifier(),
                        'organization_id' => null,
                        'facility_id' => null,
                        'action' => 'account.mfa.recovery_codes.regenerate',
                        'http_status' => $response->getStatusCode(),
                        'correlation_id' => $correlationId,
                        'created_at' => now(),
                    ]);
                    $response->headers->set('X-Correlation-ID', $correlationId);
                }
                $response->headers->set('Cache-Control', 'no-store, private');

                return $response;
            });
        }

        $response = $next($request);
        if ($protected) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
