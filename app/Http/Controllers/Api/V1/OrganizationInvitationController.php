<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Models\OrganizationRole;
use App\Support\SyntheticInvitationSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationInvitationController
{
    public function index(Request $request, Organization $organization): JsonResponse
    {
        OrganizationAccess::requirePermission($request->user(), $organization, 'invitations.manage');
        $perPage = max(1, min((int) $request->query('per_page', 25), 100));
        $now = now();
        $paginator = DB::table('synthetic_invitations')
            ->where('organization_id', $organization->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['id', 'organization_id', 'invited_by', 'email', 'role', 'created_at', 'expires_at', 'accepted_at', 'revoked_at']);

        $paginator->setCollection($paginator->getCollection()->map(function (object $row) use ($now): array {
            $expiresAt = Carbon::parse($row->expires_at);
            $status = $row->accepted_at !== null
                ? 'accepted'
                : ($row->revoked_at !== null ? 'revoked' : ($expiresAt->lte($now) ? 'expired' : 'pending'));

            return [
                'id' => $row->id,
                'email' => $row->email,
                'role' => $row->role,
                'invited_by' => $row->invited_by,
                'created_at' => $row->created_at,
                'expires_at' => $row->expires_at,
                'accepted_at' => $row->accepted_at,
                'revoked_at' => $row->revoked_at,
                'status' => $status,
                'can_resend' => in_array($status, ['pending', 'expired'], true) && str_starts_with($row->role, 'custom:'),
                'can_revoke' => in_array($status, ['pending', 'expired'], true),
            ];
        }));

        return response()->json($paginator);
    }

    public function resend(Request $request, Organization $organization, string $invitation, SyntheticInvitationSender $sender): JsonResponse
    {
        $organization = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
        OrganizationAccess::lockRoles();
        OrganizationAccess::requirePermission($request->user(), $organization, 'invitations.manage');
        $record = DB::table('synthetic_invitations')
            ->where('id', $invitation)
            ->where('organization_id', $organization->id)
            ->first();
        if ($record === null) {
            abort(404);
        }
        if ($record->accepted_at !== null) {
            throw ValidationException::withMessages(['invitation' => 'Accepted invitations cannot be resent.']);
        }
        $roleId = OrganizationAccess::roleId($record->role);
        $role = $roleId ? OrganizationRole::query()->find($roleId) : null;
        if (! $role || ! OrganizationAccess::canAssign($request->user(), $organization, $role)) {
            throw ValidationException::withMessages(['invitation' => 'This invitation cannot be resent with its current role.']);
        }

        $created = $sender->send($organization, $request->user(), $record->email, $invitation);

        return response()->json(['data' => $created], 201)->header('Cache-Control', 'no-store, private');
    }

    public function revoke(Request $request, Organization $organization, string $invitation): JsonResponse
    {
        $organization = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
        OrganizationAccess::lockRoles();
        OrganizationAccess::requirePermission($request->user(), $organization, 'invitations.manage');
        $record = DB::table('synthetic_invitations')
            ->where('id', $invitation)
            ->where('organization_id', $organization->id)
            ->first();
        if ($record === null) {
            abort(404);
        }
        if ($record->accepted_at !== null) {
            throw ValidationException::withMessages(['invitation' => 'Accepted invitations cannot be revoked.']);
        }
        if ($record->revoked_at !== null) {
            throw ValidationException::withMessages(['invitation' => 'This invitation has already been revoked.']);
        }

        DB::table('synthetic_invitations')
            ->where('id', $invitation)
            ->where('organization_id', $organization->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Invitation revoked.']);
    }
}
