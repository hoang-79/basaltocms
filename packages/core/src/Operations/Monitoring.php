<?php

declare(strict_types=1);

namespace Basalto\Core\Operations;

use Basalto\Core\Backend\Dates;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Pulse\Contracts\Storage;
use Laravel\Pulse\Pulse;
use Laravel\Pulse\Recorders\SlowRequests;
use Throwable;

/**
 * Kennzahlen aus Laravel Pulse für die Seite «Betrieb» (B-09): Ausnahmen, langsame Anfragen, langsame Abfragen und
 * Jobs, Warteschlange — über die letzte Stunde, aus den vorverdichteten Pulse-Tabellen. Pulse ist für den Kern
 * optional: ohne Paket oder ohne Tabellen meldet die Klasse «nicht installiert», statt zu scheitern.
 *
 * @phpstan-type Metrics array{
 *     installed: bool, enabled: bool, url: string|null, period: string, error: string|null,
 *     exceptions: array{total: int, top: list<array{class: string, location: string, count: int, last: string|null}>},
 *     slow_requests: array{total: int, threshold_ms: int|null, top: list<array{method: string, path: string, count: int, max_ms: int}>},
 *     slow_queries: int, slow_jobs: int,
 *     jobs: array{processed: int, failed: int, released: int}
 * }
 */
final class Monitoring
{
    public function __construct(private readonly Dates $dates) {}

    public function installed(): bool
    {
        return class_exists(Pulse::class) && Schema::hasTable('pulse_aggregates');
    }

    public function enabled(): bool
    {
        return $this->installed() && (bool) config('pulse.enabled');
    }

    /** Das Pulse-Dashboard, sofern seine Route registriert ist. */
    public function url(): ?string
    {
        return class_exists(Pulse::class) && Route::has('pulse') ? route('pulse') : null;
    }

    /** @return Metrics */
    public function metrics(): array
    {
        $empty = [
            'installed' => $this->installed(), 'enabled' => $this->enabled(), 'url' => $this->url(), 'period' => __('letzte Stunde'), 'error' => null,
            'exceptions' => ['total' => 0, 'top' => []],
            'slow_requests' => ['total' => 0, 'threshold_ms' => $this->slowRequestThreshold(), 'top' => []],
            'slow_queries' => 0, 'slow_jobs' => 0,
            'jobs' => ['processed' => 0, 'failed' => 0, 'released' => 0],
        ];
        if (! $empty['installed']) {
            return $empty;
        }
        try {
            $storage = app(Storage::class);
            $hour = CarbonInterval::hour();

            $exceptions = array_values($storage->aggregate('exception', ['max', 'count'], $hour, 'count', 'desc', 5)->map(function (object $row): array {
                [$class, $location] = self::decodeKey($row->key, 2);

                return ['class' => $class, 'location' => $location, 'count' => (int) ($row->count ?? 0), 'last' => isset($row->max) ? $this->dates->dateTime(CarbonImmutable::createFromTimestamp((int) $row->max)) : null];
            })->all());
            $slow = array_values($storage->aggregate('slow_request', ['max', 'count'], $hour, 'max', 'desc', 5)->map(static function (object $row): array {
                [$method, $path] = self::decodeKey($row->key, 2);

                return ['method' => $method, 'path' => $path, 'count' => (int) ($row->count ?? 0), 'max_ms' => (int) ($row->max ?? 0)];
            })->all());
            $jobs = $storage->aggregateTotal(['processed', 'failed', 'released'], 'count', $hour);
            $jobs = $jobs instanceof Collection ? $jobs : new Collection;

            return array_replace($empty, [
                'exceptions' => ['total' => self::total($storage->aggregateTotal('exception', 'count', $hour)), 'top' => $exceptions],
                'slow_requests' => ['total' => self::total($storage->aggregateTotal('slow_request', 'count', $hour)), 'threshold_ms' => $empty['slow_requests']['threshold_ms'], 'top' => $slow],
                'slow_queries' => self::total($storage->aggregateTotal('slow_query', 'count', $hour)),
                'slow_jobs' => self::total($storage->aggregateTotal('slow_job', 'count', $hour)),
                'jobs' => ['processed' => (int) $jobs->get('processed', 0), 'failed' => (int) $jobs->get('failed', 0), 'released' => (int) $jobs->get('released', 0)],
            ]);
        } catch (Throwable $e) {
            return array_replace($empty, ['error' => __('Pulse-Daten nicht lesbar: :error', ['error' => mb_strimwidth($e->getMessage(), 0, 160, '…')])]);
        }
    }

    /**
     * Die häufigsten Ausnahmen der letzten Stunde für den Alarm (Klasse und Zahl), leer ohne Pulse.
     *
     * @return list<array{class: string, count: int}>
     */
    public function topExceptions(int $limit = 3): array
    {
        if (! $this->enabled()) {
            return [];
        }
        try {
            return array_values(app(Storage::class)->aggregate('exception', 'count', CarbonInterval::hour(), 'count', 'desc', $limit)
                ->map(static fn (object $row): array => ['class' => self::decodeKey($row->key, 1)[0], 'count' => (int) ($row->count ?? 0)])->all());
        } catch (Throwable) {
            return [];
        }
    }

    private function slowRequestThreshold(): ?int
    {
        $threshold = config('pulse.recorders.'.SlowRequests::class.'.threshold');
        if (is_array($threshold)) {
            $threshold = $threshold['default'] ?? null;
        }

        return is_numeric($threshold) ? (int) $threshold : null;
    }

    private static function total(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Pulse speichert den Schlüssel als JSON-Liste (z. B. `["App\\Fehler","app/Datei.php:12"]`).
     *
     * @return list<string>
     */
    private static function decodeKey(mixed $key, int $parts): array
    {
        $decoded = is_string($key) ? json_decode($key, true) : null;
        $values = is_array($decoded) ? array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $decoded)) : [is_string($key) ? $key : ''];

        return array_pad(array_slice($values, 0, $parts), $parts, '');
    }
}
