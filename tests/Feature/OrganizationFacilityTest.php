<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationFacilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_facility_belongs_to_its_organization(): void
    {
        $organization = Organization::factory()->create();
        $facility = Facility::factory()->for($organization)->create();

        $this->assertTrue($facility->organization->is($organization));
        $this->assertTrue($organization->facilities->contains($facility));
    }

    public function test_a_facility_cannot_reference_an_unknown_organization(): void
    {
        $this->expectException(QueryException::class);

        Facility::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => (string) Str::uuid(),
            'name' => 'Synthetic test facility',
            'status' => 'inactive',
        ]);
    }

    public function test_a_membership_cannot_reference_a_facility_from_another_organization(): void
    {
        $facility = Facility::factory()->create();
        $membership = Membership::factory()->make([
            'facility_id' => $facility->id,
            'organization_id' => Organization::factory()->create()->id,
            'user_id' => User::factory()->create()->id,
        ]);

        $this->expectException(QueryException::class);

        $membership->save();
    }
}
