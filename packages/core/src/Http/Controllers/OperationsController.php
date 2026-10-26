<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Backend\Dates;
use Basalto\Core\Console\ErrorAlarmCommand;
use Basalto\Core\Live\Live;
use Basalto\Core\Live\LivePush;
use Basalto\Core\Operations\ErrorCounter;
use Basalto\Core\Operations\Health;
use Basalto\Core\Operations\Monitoring;
use Basalto\Core\Operations\Operators;
use Basalto\Core\Saas\CloudDomainLimit;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Fehlgeschlagene Jobs aus dem Cockpit wiederholen oder verwerfen (K-16) — nur, wer die Site verwalten darf; beides im Protokoll. */
final class OperationsController
{
    /** Stand der letzten Sicherung und der letzten Wiederherstellungsprüfung (schreibt `basalto:backup` bzw. `basalto:backup-verify`). */
    public const BACKUP_KEY = 'basalto.backup.last';

    public const BACKUP_VERIFIED_KEY = 'basalto.backup.verified';

    public function __construct(private readonly CurrentSite $current, private readonly Recorder $recorder) {}

    /**
     * Verwaltung › Betrieb (K-17, Mockup 19): was `/up` prüft und was dahinter liegt — Herzschlag, Warteschlange,
     * Datenbank, Cache, Speicher, Backups und die Aufgaben des Schedulers mit letztem Lauf. Betreiber der Installation
     * sehen dazu die Kennzahlen aus Pulse und den Stand des Fehleralarms (B-09) — sie umfassen alle Sites.
     */
    public function index(Request $request, Health $health, Operations $operations, Dates $dates, Monitoring $monitoring, Settings $settings): Response
    {
        $isOperator = Operators::allows($request->user());
        $this->current->require();
        $readiness = $health->readiness();
        $backup = Cache::get(self::BACKUP_KEY);
        $verified = Cache::get(self::BACKUP_VERIFIED_KEY);

        return Inertia::render('core::Operations', [
            'up' => $readiness,
            'scheduler' => $health->scheduler() + ['threshold_minutes' => $health->thresholdMinutes()],
            'queue' => $health->queue(),
            // CR-008 (LV-13): Live-Aktualisierung — Takt, Grenze, Push eingerichtet und erreichbar
            'live' => ['interval' => app(Live::class)->interval(), 'rate' => max(60, (int) config('basalto.live.rate', 600)), 'push' => app(LivePush::class)->enabled(),
                'host' => app(LivePush::class)->clientConfig()['host'] ?? null, 'reachable' => app(LivePush::class)->enabled() ? app(LivePush::class)->reachable() : null],
            // TB 29: fehlgeschlagene Jobs auch hier mit «Noch einmal versuchen» und «Verwerfen» (bisher nur auf der Übersicht)
            'failed' => array_map(static fn (array $job): array => $job + ['urls' => ['retry' => route('basalto.operations.jobs.retry', $job['uuid']),
                'forget' => route('basalto.operations.jobs.forget', $job['uuid'])]], $operations->failedJobs(5)),
            'database' => $health->database(),
            'cache' => $health->cache(),
            'storage' => $health->storage(),
            'backup' => [
                'last' => is_array($backup) && is_string($backup['at'] ?? null) ? $dates->dateTime($backup['at']) : null,
                'bytes' => is_array($backup) && is_numeric($backup['bytes'] ?? null) ? (int) $backup['bytes'] : null,
                'verified' => is_array($verified) && is_string($verified['at'] ?? null) ? $dates->dateTime($verified['at']) : null,
                'available' => array_key_exists('basalto:backup', Artisan::all()),
            ],
            'tasks' => array_map(static fn (array $task): array => $task + ['last_label' => $task['last'] === null ? null : $dates->dateTime($task['last'])], $health->tasks()),
            'deployed_at' => $operations->summary()['last_deploy'],
            'can_edit' => Gate::allows('update', $this->current->require()),
            'urls' => ['backup' => route('basalto.operations.backup'), 'up' => url('/up'), 'live' => url('/up/live')],
            // TB 10 Scheibe 6 (B-09): nur für Betreiber der Installation
            'monitoring' => $isOperator ? $monitoring->metrics() : null,
            'alarm' => $isOperator ? $this->alarm($settings, $dates) : null,
            // TB 12 Scheibe 3 (G-11): Kundendomains gegen das Limit der Cloud-Organisation — Betreiber, nur im SaaS-Modus
            'cloud_domains' => $isOperator && Saas::enabled() ? CloudDomainLimit::status() : null,
        ]);
    }

    /**
     * Stand des Fehleralarms (B-09): Einstellung, Zahl im laufenden Fenster, letzter Alarm.
     *
     * @return array{threshold: int, minutes: int, pause: int, current: int, last: string|null, last_count: int|null, recipients: int, settings_url: string}
     */
    private function alarm(Settings $settings, Dates $dates): array
    {
        $config = ErrorAlarmCommand::config($settings);
        $last = ErrorAlarmCommand::last();

        return $config + [
            'current' => ErrorCounter::count($config['minutes']),
            'last' => $last === null ? null : $dates->dateTime($last['at']),
            'last_count' => $last['count'] ?? null,
            'recipients' => count(Operators::emails()),
            'settings_url' => route('basalto.settings.index', ['group' => 'operations']),
        ];
    }

    /** «Backup jetzt»: in die Warteschlange — auf einfachem Hosting arbeitet sie der Scheduler in der nächsten Minute ab. */
    public function backup(Request $request): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        abort_unless(array_key_exists('basalto:backup', Artisan::all()), 404);
        Artisan::queue('basalto:backup');
        $this->recorder->record('system.backup_requested', null, null, null, $site, $request->user() instanceof Authenticatable ? $request->user() : null);

        return back()->with('status', __('Backup ist angestossen — es läuft im Hintergrund.'));
    }

    public function retry(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize($uuid);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $this->record($request, 'system.job_retried', $uuid);

        return back()->with('status', __('Job ist wieder in der Warteschlange.'));
    }

    public function forget(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize($uuid);
        Artisan::call('queue:forget', ['id' => $uuid]);
        $this->record($request, 'system.job_forgotten', $uuid);

        return back()->with('status', __('Job verworfen.'));
    }

    private function authorize(string $uuid): void
    {
        Gate::authorize('update', $this->current->require());
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
    }

    private function record(Request $request, string $action, string $uuid): void
    {
        $user = $request->user();
        $this->recorder->record($action, null, null, ['uuid' => $uuid], $this->current->get(), $user instanceof Authenticatable ? $user : null);
    }
}
