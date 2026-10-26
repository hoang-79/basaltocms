<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Console\HeartbeatCommand;
use Basalto\Core\Core;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Betriebszahlen für Übersicht und Einrichtung (K-16, K-17): Herzschlag, Warteschlange, Version, letzte Auslieferung. */
final class Operations
{
    /** @return array{heartbeat_age_seconds: int|null, queue_waiting: int, queue_failed: int, version: string, last_deploy: string|null} */
    public function summary(): array
    {
        $heartbeat = Cache::get(HeartbeatCommand::KEY);
        $deploy = config('basalto.deployed_at');

        return [
            'heartbeat_age_seconds' => is_string($heartbeat) ? (int) CarbonImmutable::parse($heartbeat)->diffInSeconds(CarbonImmutable::now()) : null,
            'queue_waiting' => Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0,
            'queue_failed' => Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0,
            'version' => Core::VERSION,
            'last_deploy' => is_string($deploy) && $deploy !== '' ? $deploy : null,
        ];
    }

    /**
     * Die zuletzt fehlgeschlagenen Jobs mit Namen und erster Zeile der Meldung (K-16).
     *
     * @return list<array{uuid: string, name: string, message: string, failed_at: string}>
     */
    public function failedJobs(int $limit = 3): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }
        $out = [];
        foreach (DB::table('failed_jobs')->orderByDesc('id')->limit($limit)->get(['uuid', 'payload', 'exception', 'failed_at']) as $row) {
            $payload = json_decode((string) $row->payload, true);
            $name = is_array($payload) && is_string($payload['displayName'] ?? null) ? class_basename($payload['displayName']) : 'Job';
            $message = trim(strtok((string) $row->exception, "\n") ?: '');
            $message = (string) preg_replace('/^[A-Za-z\\\\]+: /', '', $message);
            $out[] = ['uuid' => (string) $row->uuid, 'name' => $name, 'message' => mb_strimwidth($message, 0, 200, '…'), 'failed_at' => (string) $row->failed_at];
        }

        return $out;
    }
}
