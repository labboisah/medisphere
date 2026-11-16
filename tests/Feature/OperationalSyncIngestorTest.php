<?php

namespace Tests\Feature;

use App\Models\OperationalSyncInstallation;
use App\Models\Organization;
use App\Support\OperationalSyncIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OperationalSyncIngestorTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_acknowledgement_and_sequence_are_durable_and_bound_to_installation(): void
    {
        $server = $this->installation();
        $event = $this->event();
        $service = app(OperationalSyncIngestor::class);
        $this->assertSame('accepted', $service->accept($server, $event)['status']);
        $this->assertSame('duplicate', $service->accept($server->fresh(), $event)['status']);
        $this->assertDatabaseCount('operational_sync_events', 1);
        $this->assertSame(1, (int) $server->fresh()->last_sequence);
        $this->assertRejected($this->installation(), $event);
        $changed = $event;
        $changed['occurred_at'] = '2026-01-02T00:00:00Z';
        $this->assertRejected($server, $changed);
    }

    public function test_gap_rejected_without_advancing_or_losing_event(): void
    {
        $server = $this->installation();
        $second = $this->event(2);
        $this->assertRejected($server, $second);
        $this->assertDatabaseCount('operational_sync_events', 0);
        $this->assertSame(0, (int) $server->fresh()->last_sequence);
        app(OperationalSyncIngestor::class)->accept($server, $this->event());
        $this->assertSame('accepted', app(OperationalSyncIngestor::class)->accept($server, $second)['status']);
    }

    public function test_rejects_sensitive_unknown_or_tampered_data_and_inactive_installation(): void
    {
        $server = $this->installation();
        $payload = ['version' => '1.0', 'pending_count' => 0, 'failed_count' => 0, 'enabled_modules' => ['pharmacy'], 'patient_name' => 'synthetic'];
        $this->assertRejected($server, $this->event(1, $payload));
        $payload['enabled_modules'] = ['unapproved_module'];
        unset($payload['patient_name']);
        $this->assertRejected($server, $this->event(1, $payload));
        $event = $this->event();
        $event['payload_sha256'] = str_repeat('0', 64);
        $this->assertRejected($server, $event);
        $event = $this->event();
        $event['payload_json'] = '{"version":"1.0","pending_count":0,"failed_count":0,"patient_name":"hidden","patient_name":null,"enabled_modules":[]}';
        $event['payload_sha256'] = hash('sha256', $event['payload_json']);
        $this->assertRejected($server, $event);
        $event['payload_json'] = '{"version":"1.0","pending_count":999,"pending_count":0,"failed_count":0,"enabled_modules":[]}';
        $event['payload_sha256'] = hash('sha256', $event['payload_json']);
        $this->assertRejected($server, $event);
        $server->update(['status' => 'revoked']);
        $this->assertRejected($server, $this->event());
        $this->assertSame(0, DB::table('operational_sync_events')->count());
    }

    private function installation(): OperationalSyncInstallation
    {
        return OperationalSyncInstallation::query()->create([
            'organization_id' => Organization::factory()->create()->id,
            'installation_uuid' => (string) Str::uuid(),
            'status' => 'active',
        ]);
    }

    private function event(int $sequence = 1, ?array $payload = null): array
    {
        $json = json_encode($payload ?? [
            'version' => '1.0.0', 'pending_count' => 0, 'failed_count' => 0,
            'enabled_modules' => ['pharmacy', 'laboratory'],
        ], JSON_THROW_ON_ERROR);

        return ['event_id' => (string) Str::uuid(), 'sequence' => $sequence,
            'occurred_at' => '2026-01-01T00:00:00Z', 'payload_json' => $json, 'payload_sha256' => hash('sha256', $json)];
    }

    private function assertRejected(OperationalSyncInstallation $installation, array $event): void
    {
        try {
            app(OperationalSyncIngestor::class)->accept($installation, $event);
            $this->fail('Invalid event must be rejected.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }
}
