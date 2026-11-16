<?php

namespace App\Support;

use App\Models\KernelBridgeAccountLink;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class OperationalSyncHealth
{
    /**
     * @param  list<string>  $installationUuids
     * @return array<string, array<string, mixed>>
     */
    public function forInstallations(KernelBridgeAccountLink $link, array $installationUuids): array
    {
        if ($installationUuids === []) {
            return [];
        }

        $rows = $this->query($link)
            ->whereIn('installation.installation_uuid', $installationUuids)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->installation_uuid] = $this->project($row);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function summary(KernelBridgeAccountLink $link): array
    {
        $summary = [
            'enrolled' => 0, 'online' => 0, 'delayed' => 0, 'offline' => 0, 'unknown' => 0,
            'pending_count' => 0, 'failed_count' => 0, 'latest_received_at' => null,
        ];
        foreach ($this->query($link)->where('installation.status', 'active')->cursor() as $row) {
            $health = $this->project($row);
            $summary['enrolled']++;
            $summary[$health['status']]++;
            if ($health['status'] === 'unknown') {
                continue;
            }
            $summary['pending_count'] += $health['pending_count'];
            $summary['failed_count'] += $health['failed_count'];
            if ($summary['latest_received_at'] === null || $health['received_at'] > $summary['latest_received_at']) {
                $summary['latest_received_at'] = $health['received_at'];
            }
        }

        return $summary;
    }

    private function query(KernelBridgeAccountLink $link): Builder
    {
        return DB::table('operational_sync_installations as installation')
            ->leftJoin('operational_sync_events as event', function ($join): void {
                $join->on('event.sync_installation_id', '=', 'installation.id')
                    ->on('event.sequence', '=', 'installation.last_sequence');
            })
            ->where('installation.organization_id', $link->organization_id)
            ->where('installation.link_uuid', $link->link_uuid)
            ->select('installation.installation_uuid', 'installation.status', 'event.payload_json', 'event.occurred_at', 'event.accepted_at');
    }

    /** @return array<string, mixed> */
    private function project(object $row): array
    {
        if ($row->status !== 'active' || $row->accepted_at === null) {
            return ['status' => 'unknown'];
        }

        $accepted = Carbon::parse($row->accepted_at, 'UTC');
        $observed = Carbon::parse($row->occurred_at, 'UTC');
        if ($observed->gt(now()->addMinutes(5))) {
            return ['status' => 'unknown'];
        }
        $freshness = $observed->lt($accepted) ? $observed : $accepted;
        $payload = json_decode($row->payload_json, true, 16, JSON_THROW_ON_ERROR);

        return [
            'status' => $freshness->lt(now()->subMinutes(15)) ? 'offline' : ($freshness->lt(now()->subMinutes(5)) ? 'delayed' : 'online'),
            'received_at' => $accepted->toISOString(),
            'observed_at' => $observed->toISOString(),
            'version' => $payload['version'],
            'pending_count' => $payload['pending_count'],
            'failed_count' => $payload['failed_count'],
            'enabled_modules' => $payload['enabled_modules'],
        ];
    }
}
