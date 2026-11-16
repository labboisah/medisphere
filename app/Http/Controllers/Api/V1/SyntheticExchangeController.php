<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Models\SyntheticExchangeLink;
use App\Models\SyntheticSharingGrant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SyntheticExchangeController
{
    private const PERMISSION = 'synthetic.exchange.manage';

    private const DEMO_PURPOSE = 'synthetic-workflow-demonstration';

    private const DEMO_SCOPE = ['synthetic-demonstration-summary'];

    public function index(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize($request, $organization);
        $perPage = max(1, min((int) $request->query('per_page', 25), 100));
        $links = SyntheticExchangeLink::query()->where('organization_id', $organization->id)
            ->with(['grants' => fn ($query) => $query->orderByDesc('created_at')])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $links->setCollection($links->getCollection()->map(fn (SyntheticExchangeLink $link): array => $this->serializeLink($link)));

        return response()->json($links)->header('Cache-Control', 'no-store, private');
    }

    public function storeLink(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize($request, $organization);
        $this->rejectUnexpectedInput($request, ['facility_id']);
        $validated = $request->validate([
            'facility_id' => ['required', 'uuid', Rule::exists('facilities', 'id')->where('organization_id', $organization->id)],
            'organization_id' => ['missing'],
            'review_status' => ['missing'],
            'created_by' => ['missing'],
        ]);

        $link = SyntheticExchangeLink::query()->create($validated + [
            'organization_id' => $organization->id,
            'local_subject_reference' => (string) Str::uuid(),
            'recipient_subject_reference' => (string) Str::uuid(),
            'review_status' => 'pending',
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->serializeLink($link)], 201)
            ->header('Cache-Control', 'no-store, private');
    }

    public function reviewLink(Request $request, Organization $organization, string $link): JsonResponse
    {
        $this->authorize($request, $organization);
        $this->rejectUnexpectedInput($request, ['decision']);
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['confirmed', 'rejected'])],
            'id' => ['missing'],
            'organization_id' => ['missing'],
            'reviewed_by' => ['missing'],
        ]);

        $record = DB::transaction(function () use ($request, $organization, $link, $validated): SyntheticExchangeLink {
            $record = SyntheticExchangeLink::query()->where('organization_id', $organization->id)
                ->whereKey($link)->lockForUpdate()->firstOrFail();
            if ($record->review_status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'Only pending synthetic references can be reviewed.']);
            }
            $record->forceFill([
                'review_status' => $validated['decision'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();

            return $record->load('grants');
        });

        return response()->json(['data' => $this->serializeLink($record)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function storeGrant(Request $request, Organization $organization, string $link): JsonResponse
    {
        $this->authorize($request, $organization);
        $this->rejectUnexpectedInput($request, []);
        $record = DB::transaction(function () use ($request, $organization, $link): SyntheticSharingGrant {
            $exchangeLink = SyntheticExchangeLink::query()->where('organization_id', $organization->id)
                ->whereKey($link)->lockForUpdate()->firstOrFail();
            if ($exchangeLink->review_status !== 'confirmed') {
                throw ValidationException::withMessages(['link' => 'A demo grant requires confirmed synthetic references.']);
            }
            if ($exchangeLink->grants()->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['grant' => 'An active demo grant already exists for these references.']);
            }

            return $exchangeLink->grants()->create([
                'organization_id' => $organization->id,
                'facility_id' => $exchangeLink->facility_id,
                'purpose' => self::DEMO_PURPOSE,
                'scope' => self::DEMO_SCOPE,
                'created_by' => $request->user()->id,
                'expires_at' => now()->addDay(),
            ]);
        });

        return response()->json(['data' => $this->serializeGrant($record)], 201)
            ->header('Cache-Control', 'no-store, private');
    }

    public function revokeGrant(Request $request, Organization $organization, string $grant): JsonResponse
    {
        $this->authorize($request, $organization);
        $this->rejectUnexpectedInput($request, []);
        $record = DB::transaction(function () use ($request, $organization, $grant): SyntheticSharingGrant {
            $record = SyntheticSharingGrant::query()->where('organization_id', $organization->id)
                ->whereKey($grant)->lockForUpdate()->firstOrFail();
            if ($record->revoked_at !== null) {
                throw ValidationException::withMessages(['grant' => 'This demo grant has already been revoked.']);
            }
            $record->forceFill(['revoked_by' => $request->user()->id, 'revoked_at' => now()])->save();

            return $record;
        });

        return response()->json(['data' => $this->serializeGrant($record)])
            ->header('Cache-Control', 'no-store, private');
    }

    private function authorize(Request $request, Organization $organization): void
    {
        OrganizationAccess::requirePermission($request->user(), $organization, self::PERMISSION);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function rejectUnexpectedInput(Request $request, array $allowed): void
    {
        $unexpected = array_diff(array_keys($request->all()), $allowed);
        if ($unexpected !== []) {
            throw ValidationException::withMessages([
                'request' => 'This synthetic-only workflow does not accept clinical data or unrecognized fields.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function serializeLink(SyntheticExchangeLink $link): array
    {
        return [
            'id' => $link->id,
            'facility_id' => $link->facility_id,
            'local_subject_reference' => $link->local_subject_reference,
            'recipient_subject_reference' => $link->recipient_subject_reference,
            'review_status' => $link->review_status,
            'reviewed_at' => $link->reviewed_at?->toISOString(),
            'grants' => $link->relationLoaded('grants')
                ? $link->grants->map(fn (SyntheticSharingGrant $grant): array => $this->serializeGrant($grant))->values()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function serializeGrant(SyntheticSharingGrant $grant): array
    {
        return [
            'id' => $grant->id,
            'purpose' => $grant->purpose,
            'scope' => $grant->scope,
            'status' => $grant->status,
            'expires_at' => $grant->expires_at->toISOString(),
            'revoked_at' => $grant->revoked_at?->toISOString(),
        ];
    }
}
