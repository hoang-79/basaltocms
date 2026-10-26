<?php

declare(strict_types=1);

namespace Basalto\Core\Operations;

use Basalto\Core\Console\HeartbeatCommand;
use Basalto\Core\Core;
use Basalto\Core\Models\Media;
use Basalto\Core\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Betrieb sichtbar (K-17, Mockup 19): Readiness für `/up` (Datenbank, Herzschlag des Schedulers) und Liveness für
 * `/up/live` (nur der Prozess, ohne Datenbank), dazu die Zahlen der Seite «Betrieb» — Warteschlange, Datenbank,
 * Cache, Speicher, Backups, Scheduler-Aufgaben mit letztem Lauf. Gemessen wird bei jedem Aufruf, nichts geschätzt.
 *
 * Herzschlag: Fehlt er länger als die Schwelle (Einstellung `operations.heartbeat_minutes`), ist `/up` gestört. Eine
 * frische Installation ohne ersten Herzschlag gilt erst nach dieser Schwelle als gestört — sonst schlüge die erste
 * Readiness-Probe nach einem Deploy fehl, bevor der Scheduler je laufen konnte.
 *
 * @phpstan-type Check array{ok: bool, detail: string}
 */
final class Health
{
    public const FIRST_SEEN = 'basalto.up.first_seen';

    public const TASK_PREFIX = 'basalto.schedule.';

    public function __construct(private readonly Settings $settings) {}

    /** @return array{status: string, version: string} */
    public function liveness(): array
    {
        return ['status' => 'lebt', 'version' => Core::VERSION];
    }

    /**
     * @return array{ok: bool, status: string, version: string, checks: array{database: Check, scheduler: Check}}
     */
    public function readiness(): array
    {
        $database = $this->database();
        $scheduler = $this->scheduler();
        $ok = $database['ok'] && $scheduler['ok'];

        return [
            'ok' => $ok,
            'status' => $ok ? 'bereit' : 'gestört',
            'version' => Core::VERSION,
            'checks' => [
                'database' => ['ok' => $database['ok'], 'detail' => $database['detail']],
                'scheduler' => ['ok' => $scheduler['ok'], 'detail' => $scheduler['detail']],
            ],
        ];
    }

    /** Schwelle aus den Einstellungen — ohne Datenbank die Vorgabe, damit `/up` dann 503 statt 500 meldet. */
    public function thresholdMinutes(): int
    {
        try {
            return max(1, (int) $this->settings->get('operations.heartbeat_minutes', 5));
        } catch (Throwable) {
            return 5;
        }
    }

    /** @return array{ok: bool, detail: string, driver: string, version: string|null, ms: float|null, size: int|null, pending: int|null} */
    public function database(): array
    {
        $driver = (string) DB::connection()->getDriverName();
        try {
            $start = hrtime(true);
            DB::select('select 1');
            $ms = round((hrtime(true) - $start) / 1e6, 1);
        } catch (Throwable) {
            return ['ok' => false, 'detail' => __('Datenbank nicht erreichbar'), 'driver' => $driver, 'version' => null, 'ms' => null, 'size' => null, 'pending' => null];
        }

        return ['ok' => true, 'detail' => sprintf('%s · %s ms', $driver, $ms), 'driver' => $driver, 'version' => $this->databaseVersion($driver), 'ms' => $ms,
            'size' => $this->databaseSize($driver), 'pending' => $this->pendingMigrations()];
    }

    /** @return array{ok: bool, detail: string, age_seconds: int|null, last: string|null} */
    public function scheduler(): array
    {
        $threshold = $this->thresholdMinutes() * 60;
        $heartbeat = Cache::get(HeartbeatCommand::KEY);
        if (is_string($heartbeat)) {
            $age = (int) CarbonImmutable::parse($heartbeat)->diffInSeconds(CarbonImmutable::now());

            return ['ok' => $age <= $threshold, 'detail' => $age <= $threshold ? __('Herzschlag vor :seconds s', ['seconds' => $age]) : __('Herzschlag vor :seconds s — älter als :minutes Minuten', ['seconds' => $age, 'minutes' => $this->thresholdMinutes()]), 'age_seconds' => $age, 'last' => $heartbeat];
        }
        $first = Cache::get(self::FIRST_SEEN);
        if (! is_string($first)) {
            $first = CarbonImmutable::now()->toIso8601String();
            Cache::forever(self::FIRST_SEEN, $first);
        }
        $waiting = (int) CarbonImmutable::parse($first)->diffInSeconds(CarbonImmutable::now());

        return ['ok' => $waiting <= $threshold, 'detail' => $waiting <= $threshold ? __('noch kein Herzschlag — Scheduler startet') : __('kein Herzschlag — läuft der Scheduler (Cron)?'), 'age_seconds' => null, 'last' => null];
    }

    /** @return array{driver: string, waiting: int, failed: int, via_scheduler: bool} */
    public function queue(): array
    {
        $driver = (string) config('queue.default');

        return [
            'driver' => $driver,
            'waiting' => $driver === 'database' && Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0,
            'failed' => Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0,
            'via_scheduler' => self::queueViaScheduler(),
        ];
    }

    /** @return array{driver: string, ms: float|null} */
    public function cache(): array
    {
        $driver = (string) config('cache.default');
        try {
            $start = hrtime(true);
            Cache::put('basalto.up.ping', 1, 10);
            Cache::get('basalto.up.ping');

            return ['driver' => $driver, 'ms' => round((hrtime(true) - $start) / 1e6, 1)];
        } catch (Throwable) {
            return ['driver' => $driver, 'ms' => null];
        }
    }

    /** @return array{disk: string, driver: string|null, media: int, bytes: int} */
    public function storage(): array
    {
        $disk = (string) config('filesystems.default');

        return ['disk' => $disk, 'driver' => is_string(config('filesystems.disks.'.$disk.'.driver')) ? config('filesystems.disks.'.$disk.'.driver') : null,
            'media' => (int) Media::query()->withoutGlobalScopes()->count(), 'bytes' => (int) Media::query()->withoutGlobalScopes()->sum('size')];
    }

    /**
     * Die Aufgaben des Schedulers mit letztem Lauf (aufgezeichnet von {@see self::recordTask()}).
     *
     * @return list<array{command: string, expression: string, last: string|null, status: string|null, runtime: float|null}>
     */
    public function tasks(): array
    {
        $out = [];
        foreach (app(Schedule::class)->events() as $event) {
            $command = self::commandOf($event);
            $last = Cache::get(self::TASK_PREFIX.md5($command));
            $out[] = ['command' => $command, 'expression' => $event->expression, 'last' => is_array($last) ? ($last['at'] ?? null) : null,
                'status' => is_array($last) ? ($last['status'] ?? null) : null, 'runtime' => is_array($last) && is_numeric($last['runtime'] ?? null) ? (float) $last['runtime'] : null];
        }

        return $out;
    }

    /** Nach jedem Lauf einer Aufgabe: Zeitpunkt, Ergebnis, Dauer — für die Liste der Seite «Betrieb». */
    public static function recordTask(Event $event, string $status, ?float $runtime): void
    {
        Cache::forever(self::TASK_PREFIX.md5(self::commandOf($event)), ['at' => CarbonImmutable::now()->toIso8601String(), 'status' => $status, 'runtime' => $runtime === null ? null : round($runtime, 2)]);
    }

    /** Einfaches Hosting (B-05): ohne Worker arbeitet der Scheduler die Datenbank-Warteschlange ab, höchstens 50 s je Minute. */
    public static function scheduleQueue(Schedule $schedule): void
    {
        if (self::queueViaScheduler()) {
            $schedule->command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping()->onOneServer();
        }
    }

    /** Einfaches Hosting (B-05): Datenbank-Warteschlange ohne Worker — der Scheduler arbeitet sie jede Minute ab. */
    public static function queueViaScheduler(): bool
    {
        return config('queue.default') === 'database' && (bool) config('basalto.operations.queue_via_scheduler', true);
    }

    private static function commandOf(Event $event): string
    {
        $command = (string) ($event->command ?? $event->description ?? '');
        $command = (string) preg_replace('/^.*?artisan[\'"]?\s+/', '', $command);

        return trim($command) !== '' ? trim($command) : (string) ($event->description ?? __('Aufgabe'));
    }

    private function databaseVersion(string $driver): ?string
    {
        try {
            $row = match ($driver) {
                'sqlite' => DB::selectOne('select sqlite_version() as v'),
                'pgsql' => DB::selectOne('show server_version'),
                default => DB::selectOne('select version() as v'),
            };
            $values = $row === null ? [] : array_values((array) $row);

            return isset($values[0]) ? (string) $values[0] : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function databaseSize(string $driver): ?int
    {
        try {
            return match ($driver) {
                'sqlite' => is_file((string) config('database.connections.sqlite.database')) ? (int) filesize((string) config('database.connections.sqlite.database')) : null,
                'pgsql' => (int) (DB::selectOne('select pg_database_size(current_database()) as s')->s ?? 0),
                'mysql', 'mariadb' => (int) (DB::selectOne('select sum(data_length + index_length) as s from information_schema.tables where table_schema = database()')->s ?? 0),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    private function pendingMigrations(): ?int
    {
        try {
            /** @var Migrator $migrator */
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
            $ran = $migrator->getRepository()->getRan();

            return count(array_diff(array_keys($files), $ran));
        } catch (Throwable) {
            return null;
        }
    }
}
