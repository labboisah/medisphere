<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationRole;
use App\Models\User;
use App\Support\SyntheticInvitationSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class SyntheticInvitationController
{
    public function store(Request $request, Organization $organization, SyntheticInvitationSender $sender): JsonResponse
    {
        $organization = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
        OrganizationAccess::lockRoles();
        OrganizationAccess::requirePermission($request->user(), $organization, 'invitations.manage');
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role_id' => ['required', 'uuid'],
        ]);
        $role = OrganizationRole::query()->lockForUpdate()->findOrFail($validated['role_id']);
        abort_unless(OrganizationAccess::canAssign($request->user(), $organization, $role), 403);
        $sender->send($organization, $request->user(), $validated['email'], role: $role->membershipRole());

        return response()->json(['message' => 'Invitation sent.'], 201)->header('Cache-Control', 'no-store, private');
    }

    public function accept(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ]);
        DB::transaction(function () use ($validated): void {
            $hash = hash('sha256', $validated['token']);
            $candidate = DB::table('synthetic_invitations')->where('token_hash', $hash)->first();
            if ($candidate === null) {
                throw ValidationException::withMessages(['token' => 'This invitation is invalid, expired or already used.']);
            }
            $organization = Organization::query()->whereKey($candidate->organization_id)->lockForUpdate()->firstOrFail();
            OrganizationAccess::lockRoles();
            $claimed = DB::table('synthetic_invitations')
                ->where('token_hash', $hash)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())
                ->update(['accepted_at' => now(), 'updated_at' => now()]);
            if ($claimed !== 1) {
                throw ValidationException::withMessages(['token' => 'This invitation is invalid, expired or already used.']);
            }

            $invitation = DB::table('synthetic_invitations')->where('token_hash', $hash)->first();
            if ($invitation->role === 'organization_admin') {
                $issuer = User::find($invitation->invited_by);
                if (! $issuer?->synthetic_platform_admin || $organization->onboarding()->exists()) {
                    throw ValidationException::withMessages(['token' => 'This administrator invitation is no longer valid.']);
                }
            } else {
                $roleId = OrganizationAccess::roleId($invitation->role);
                $role = $roleId ? OrganizationRole::query()->find($roleId) : null;
                $issuer = User::find($invitation->invited_by);
                if (! $role || ! $issuer
                    || ! OrganizationAccess::can($issuer, $organization, 'invitations.manage')
                    || ! OrganizationAccess::canAssign($issuer, $organization, $role)) {
                    throw ValidationException::withMessages(['token' => 'This invitation is no longer valid.']);
                }
            }
            if (User::whereRaw('LOWER(email) = ?', [$invitation->email])->exists()) {
                throw ValidationException::withMessages(['token' => 'This invitation cannot be used for an existing account.']);
            }
            $user = User::create(['name' => $validated['name'], 'email' => $invitation->email, 'password' => $validated['password']]);
            $membership = new Membership(['role' => $invitation->role, 'status' => 'active']);
            $membership->organization()->associate($organization);
            $user->memberships()->save($membership);
        });

        return response()->json(['message' => 'Account created. Sign in and set up MFA.'], 201)->header('Cache-Control', 'no-store, private');
    }
}
