<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Notifications\ErrorAlarmNotification;
use Basalto\Core\Operations\ErrorCounter;
use Basalto\Core\Operations\Monitoring;
use Basalto\Core\Operations\Operators;
use Basalto\Core\Settings\Catalog;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Support\BackendLocale;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Alarm bei Fehlerhäufung (B-09): Der Scheduler prüft jede Minute, ob in den letzten `operations.error_alarm_minutes`
 * Minuten mindestens `operations.error_alarm_threshold` Ausnahmen gemeldet wurden, und schreibt dann den Betreibern
 * der Installation eine Mail — höchstens eine je `operations.error_alarm_pause_minutes` (gedrosselt). Ohne Fremddienst;
 * Schwelle 0 schaltet den Alarm aus.
 */
final class ErrorAlarmCommand extends Command
{
    /** Letzter Alarm: Zeitpunkt, Zahl, Empfänger — für die Drosselung und die Seite «Betrieb». */
    public const LAST_KEY = 'basalto.errors.alarm';

    protected $signature = 'basalto:error-alarm';

    protected $description = 'Benachrichtigt die Betreiber, wenn sich gemeldete Ausnahmen häufen (gedrosselt)';

    public function handle(Settings $settings, Monitoring $monitoring): int
    {
        $config = self::config($settings);
        if ($config['threshold'] === 0) {
            $this->line('Fehleralarm ist aus (Schwelle 0).');

            return self::SUCCESS;
        }
        $count = ErrorCounter::count($config['minutes']);
        if ($count < $config['threshold']) {
            $this->line(sprintf('%d Ausnahmen in %d Minuten — unter der Schwelle %d.', $count, $config['minutes'], $config['threshold']));

            return self::SUCCESS;
        }
        $last = self::last();
        if ($last !== null && now()->subMinutes($config['pause'])->lt($last['at'])) {
            $this->line(sprintf('%d Ausnahmen in %d Minuten — Alarm gedrosselt, letzter um %s.', $count, $config['minutes'], $last['at']));

            return self::SUCCESS;
        }
        $recipients = Operators::emails();
        if ($recipients === []) {
            $this->warn('Fehler häufen sich, aber die Installation hat keinen Betreiber mit E-Mail.');

            return self::SUCCESS;
        }
        BackendLocale::notify($recipients, new ErrorAlarmNotification(
            $count, $config['minutes'], $config['threshold'], (string) config('app.name', 'Basalto'), route('basalto.operations'), $monitoring->url(), $monitoring->topExceptions(), $config['pause'],
        ));
        Cache::forever(self::LAST_KEY, ['at' => now()->toIso8601String(), 'count' => $count, 'recipients' => count($recipients)]);
        $this->info(sprintf('Alarm an %d Betreiber: %d Ausnahmen in %d Minuten.', count($recipients), $count, $config['minutes']));

        return self::SUCCESS;
    }

    /** @return array{threshold: int, minutes: int, pause: int} */
    public static function config(Settings $settings): array
    {
        $value = static fn (string $key): int => (int) $settings->get($key, Catalog::find($key)['default'] ?? 0);

        return [
            'threshold' => max(0, $value('operations.error_alarm_threshold')),
            'minutes' => min(60, max(1, $value('operations.error_alarm_minutes'))),
            'pause' => max(1, $value('operations.error_alarm_pause_minutes')),
        ];
    }

    /** @return array{at: string, count: int, recipients: int}|null */
    public static function last(): ?array
    {
        $last = Cache::get(self::LAST_KEY);

        return is_array($last) && is_string($last['at'] ?? null) ? ['at' => $last['at'], 'count' => (int) ($last['count'] ?? 0), 'recipients' => (int) ($last['recipients'] ?? 0)] : null;
    }
}
