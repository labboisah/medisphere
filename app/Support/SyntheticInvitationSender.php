<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SyntheticInvitationSender
{
    /**
     * @return array{id:string,email:string,expires_at:string}
     */
    public function send(Organization $organization, User $actor, string $address, ?string $replacedInvitationId = null, string $role = 'organization_admin'): array
    {
        $email = Str::lower(trim($address));
        if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => 'Invitations are only available for new accounts.']);
        }
        $url = config('platform.synthetic_portal_url');
        $parts = is_string($url) ? parse_url($url) : false;
        if (config('mail.default') !== 'smtp' || ! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (($parts['scheme'] ?? '') === 'http' && ! in_array($parts['host'], ['localhost', '127.0.0.1', '::1'], true))) {
            throw new RuntimeException('Configure the SMTP mailer and a valid synthetic portal URL before sending invitations.');
        }

        if ($replacedInvitationId !== null) {
            $candidate = DB::table('synthetic_invitations')
                ->where('id', $replacedInvitationId)
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->first();
            if ($candidate === null) {
                throw ValidationException::withMessages(['invitation' => 'The invitation no longer exists.']);
            }
            if ($candidate->accepted_at !== null) {
                throw ValidationException::withMessages(['invitation' => 'Accepted invitations cannot be resent.']);
            }
            if ($candidate->revoked_at !== null) {
                throw ValidationException::withMessages(['invitation' => 'Revoked invitations cannot be resent.']);
            }
            DB::table('synthetic_invitations')
                ->where('id', $candidate->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);
            $email = Str::lower($candidate->email);
            $role = $candidate->role;
        }

        $token = bin2hex(random_bytes(32));
        $id = (string) Str::uuid();
        $expiresAt = now()->addHours(24);
        DB::table('synthetic_invitations')->insert([
            'id' => $id,
            'organization_id' => $organization->id,
            'invited_by' => $actor->id,
            'email' => $email,
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
            'accepted_at' => null,
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $link = rtrim($url, '/').'/#invite='.$token;
        Mail::raw("You have been invited to join a synthetic MediSphere test organization.\n\nOpen this private link within 24 hours:\n{$link}\n\nDo not forward it. Use synthetic data only.", function ($message) use ($email): void {
            $message->to($email)->subject('MediSphere synthetic test invitation');
        });

        return ['id' => $id, 'email' => $email, 'expires_at' => $expiresAt->toISOString()];
    }
}
