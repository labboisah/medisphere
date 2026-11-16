<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Support\SyntheticInvitationSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SyntheticPlatformController
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->synthetic_platform_admin, 403);

        return response()->json(Organization::query()->orderBy('name')->orderBy('id')->paginate(25, ['id', 'name', 'status']));
    }

    public function store(Request $request, SyntheticInvitationSender $sender): JsonResponse
    {
        abort_unless($request->user()->synthetic_platform_admin, 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);
        $organization = DB::transaction(function () use ($data, $sender, $request): Organization {
            $organization = Organization::create(['name' => $data['name'], 'status' => 'inactive']);
            $sender->send($organization, $request->user(), $data['email']);

            return $organization;
        });

        return response()->json(['data' => $organization->only(['id', 'name', 'status'])], 201);
    }
}
