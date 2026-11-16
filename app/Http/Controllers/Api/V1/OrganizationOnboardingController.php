<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Support\OrganizationOnboardingStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizationOnboardingController
{
    public const NOTICE_VERSION = 'synthetic-2026-11-11';

    public function index(Request $request): JsonResponse
    {
        $organizations = OrganizationOnboardingStatus::memberships($request->user())
            ->whereDoesntHave('onboarding')->orderBy('name')->orderBy('id')
            ->paginate(25, ['id', 'name']);
        $organizations->getCollection()->transform(fn (Organization $organization) => [
            'id' => $organization->id,
            'name' => $organization->name,
            'can_complete' => OrganizationAccess::isAdministrator($request->user(), $organization),
        ]);

        return response()->json($organizations)->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        OrganizationAccess::requireAdministrator($request->user(), $organization);
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:255'],
            'organization_type' => ['required', 'string', 'max:255'],
            'ownership_type' => ['required', Rule::in(['private', 'public', 'nonprofit', 'other'])],
            'owner_name' => ['required', 'string', 'max:255'],
            'registration_authority' => ['required', 'string', 'max:255'],
            'registration_number' => ['required', 'string', 'max:255'],
            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state_region' => ['required', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'country' => ['required', 'string', 'max:255'],
            'authority_confirmed' => ['accepted'],
            'processing_consent' => ['accepted'],
            'notice_version' => ['required', Rule::in([self::NOTICE_VERSION])],
        ]);

        DB::transaction(function () use ($request, $organization, $data): void {
            $locked = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            OrganizationAccess::requireAdministrator($request->user(), $locked);
            if ($locked->onboarding()->exists()) {
                throw ValidationException::withMessages(['organization' => 'This organization has already completed onboarding.']);
            }
            unset($data['authority_confirmed'], $data['processing_consent'], $data['notice_version']);
            $onboarding = $locked->onboarding()->make($data);
            $onboarding->forceFill([
                'notice_version' => self::NOTICE_VERSION,
                'authority_confirmed' => true,
                'processing_consent' => true,
                'accepted_by' => $request->user()->id,
                'accepted_at' => now(),
            ]);
            $onboarding->save();
        });

        return response()->json(['message' => 'Organization onboarding completed.'], 201)->header('Cache-Control', 'no-store, private');
    }
}
