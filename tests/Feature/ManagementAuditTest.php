<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ManagementAuditTest extends TestCase
{
    use RefreshDatabase;
    use WithoutOnboardingGate;

    public function test_management_activity_records_actor_scope_and_outcome_without_request_contents(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $facility = Facility::factory()->for($organization)->create();
        Membership::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'role' => 'facility_admin',
            'facility_id' => $facility->id,
            'status' => 'active',
        ]);
        $this->actingAsMfa($user);

        $response = $this->getJson("/api/v1/organizations/{$organization->id}/facilities/{$facility->id}")->assertOk();
        $this->postJson("/api/v1/organizations/{$organization->id}/facilities", ['name' => 'Sensitive synthetic name'])->assertForbidden();

        $events = DB::table('management_audit_events')->orderBy('rowid')->get();
        $this->assertCount(2, $events);
        $this->assertSame($user->id, $events[0]->actor_id);
        $this->assertSame($organization->id, $events[0]->organization_id);
        $this->assertSame($facility->id, $events[0]->facility_id);
        $this->assertSame('management.facilities.show', $events[0]->action);
        $this->assertSame(200, $events[0]->http_status);
        $this->assertSame($response->headers->get('X-Correlation-ID'), $events[0]->correlation_id);
        $this->assertSame('management.facilities.create', $events[1]->action);
        $this->assertSame(403, $events[1]->http_status);
        $this->assertStringNotContainsString('Sensitive synthetic name', json_encode($events, JSON_THROW_ON_ERROR));
    }

    public function test_created_facility_is_referenced_in_audit(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        Membership::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'role' => 'organization_admin',
            'facility_id' => null,
            'status' => 'active',
        ]);

        $response = $this->actingAsMfa($user)->postJson("/api/v1/organizations/{$organization->id}/facilities", ['name' => 'Synthetic facility'])->assertCreated();
        $this->assertDatabaseHas('management_audit_events', [
            'organization_id' => $organization->id,
            'facility_id' => $response->json('data.id'),
            'action' => 'management.facilities.create',
            'http_status' => 201,
        ]);
    }

    public function test_audit_failure_rolls_back_management_mutation(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        Membership::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'role' => 'organization_admin',
            'facility_id' => null,
            'status' => 'active',
        ]);
        Schema::drop('management_audit_events');

        try {
            $this->withoutExceptionHandling()->actingAsMfa($user)
                ->postJson("/api/v1/organizations/{$organization->id}/facilities", ['name' => 'Must roll back']);
            $this->fail('Audit storage failure should surface an exception.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('facilities', ['organization_id' => $organization->id, 'name' => 'Must roll back']);
        }
    }
}
