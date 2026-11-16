<?php

namespace App\Support;

use App\Models\OperationalSyncInstallation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

final class OperationalSyncIngestor
{
    private const MODULES = [
        'access_control', 'billing', 'branch_management', 'clinical_care', 'core', 'department_management',
        'doctor', 'finance', 'laboratory', 'maintenance', 'maternity', 'medical_director', 'nursing',
        'partner_network', 'patient_records', 'pharmacy', 'radiology', 'reports', 'specialist',
        'synchronization', 'wards_beds',
    ];

    /**
     * The caller must authenticate the installation before invoking this service.
     *
     * @param  array<string, mixed>  $event
     * @return array{event_id: string, status: string}
     */
    public function accept(OperationalSyncInstallation $installation, array $event): array
    {
        $allowed = ['event_id', 'sequence', 'occurred_at', 'payload_json', 'payload_sha256'];
        if (array_diff(array_keys($event), $allowed) !== []
            || count($event) !== count($allowed)
            || ! is_string($event['event_id'] ?? null) || ! Str::isUuid($event['event_id'])
            || ! is_int($event['sequence'] ?? null) || $event['sequence'] < 1
            || ! is_string($event['occurred_at'] ?? null)
            || ! is_string($event['payload_json'] ?? null) || strlen($event['payload_json']) > 4096
            || ! is_string($event['payload_sha256'] ?? null)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $event['payload_sha256'])
            || ! hash_equals(hash('sha256', $event['payload_json']), $event['payload_sha256'])) {
            $this->reject('Invalid operational event envelope.');
        }

        try {
            $occurredAt = Carbon::createFromFormat('!Y-m-d\TH:i:s\Z', $event['occurred_at'], 'UTC');
            $payload = json_decode($event['payload_json'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException|\InvalidArgumentException $exception) {
            $this->reject('Invalid operational event payload or timestamp.');
        }
        if (! $occurredAt || $occurredAt->format('Y-m-d\TH:i:s\Z') !== $event['occurred_at']
            || ! is_array($payload) || array_is_list($payload)) {
            $this->reject('Invalid operational event payload or timestamp.');
        }
        $this->validatePayload($payload);
        if (json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !== $event['payload_json']) {
            $this->reject('Operational payload must use the canonical JSON representation.');
        }
        $envelopeHash = hash('sha256', json_encode([
            'event_id' => $event['event_id'],
            'sequence' => $event['sequence'],
            'occurred_at' => $event['occurred_at'],
            'payload_sha256' => $event['payload_sha256'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($installation, $event, $occurredAt, $envelopeHash): array {
            $locked = OperationalSyncInstallation::query()->lockForUpdate()->findOrFail($installation->id);
            if ($locked->status !== 'active') {
                $this->reject('The operational sync installation is not active.');
            }
            $previous = DB::table('operational_sync_events')->where('id', $event['event_id'])->first();
            if ($previous) {
                if ($previous->sync_installation_id !== $locked->id || ! hash_equals($previous->envelope_hash, $envelopeHash)) {
                    $this->reject('Operational event identifier conflicts with an existing event.');
                }

                return ['event_id' => $event['event_id'], 'status' => 'duplicate'];
            }
            if ($event['sequence'] !== (int) $locked->last_sequence + 1) {
                $this->reject('Operational event sequence is out of order.');
            }
            DB::table('operational_sync_events')->insert([
                'id' => $event['event_id'],
                'sync_installation_id' => $locked->id,
                'sequence' => $event['sequence'],
                'envelope_hash' => $envelopeHash,
                'payload_json' => $event['payload_json'],
                'occurred_at' => $occurredAt,
                'accepted_at' => now(),
            ]);
            $locked->forceFill(['last_sequence' => $event['sequence']])->save();

            return ['event_id' => $event['event_id'], 'status' => 'accepted'];
        });
    }

    /** @param array<string, mixed> $payload */
    private function validatePayload(array $payload): void
    {
        $allowed = ['version', 'pending_count', 'failed_count', 'enabled_modules'];
        if (array_diff(array_keys($payload), $allowed) !== [] || count($payload) !== count($allowed)
            || ! is_string($payload['version'] ?? null)
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/', $payload['version'])
            || ! is_int($payload['pending_count'] ?? null) || $payload['pending_count'] < 0 || $payload['pending_count'] > 1000000
            || ! is_int($payload['failed_count'] ?? null) || $payload['failed_count'] < 0 || $payload['failed_count'] > 1000000
            || ! is_array($payload['enabled_modules'] ?? null) || ! array_is_list($payload['enabled_modules'])
            || count($payload['enabled_modules']) > count(self::MODULES)) {
            $this->reject('Invalid operational health payload.');
        }
        foreach ($payload['enabled_modules'] as $module) {
            if (! is_string($module) || ! in_array($module, self::MODULES, true)) {
                $this->reject('Unknown operational module.');
            }
        }
        if (count(array_unique($payload['enabled_modules'])) !== count($payload['enabled_modules'])) {
            $this->reject('Duplicate operational module.');
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['event' => $message]);
    }
}
