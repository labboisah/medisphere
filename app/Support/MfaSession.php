<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

class MfaSession
{
    public static function markVerified(Request $request, User $user): void
    {
        $request->session()->put('mfa_verified', [
            'user_id' => $user->getKey(),
            'secret_hash' => hash('sha256', $user->two_factor_secret),
        ]);
    }

    public static function isVerified(Request $request, User $user): bool
    {
        $verified = $request->session()->get('mfa_verified');

        return is_array($verified)
            && ($verified['user_id'] ?? null) === $user->getKey()
            && is_string($user->two_factor_secret)
            && $user->two_factor_confirmed_at !== null
            && is_string($verified['secret_hash'] ?? null)
            && hash_equals($verified['secret_hash'], hash('sha256', $user->two_factor_secret));
    }
}
