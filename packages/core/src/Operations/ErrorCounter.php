<?php

declare(strict_types=1);

namespace Basalto\Core\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Zählt gemeldete Ausnahmen je Minute im Cache (B-09, «Alarm bei Fehlerhäufung») — unabhängig von Pulse, damit der
 * Alarm auch mit `PULSE_ENABLED=false` und auf einfachem Hosting greift. Gezählt wird, was der Exception-Handler
 * meldet (404, Validierung und andere nicht gemeldete Ausnahmen nicht). Ein Fehler beim Zählen selbst (Datenbank-Cache
 * weg) wird verschluckt: Der Zähler darf die Fehlerbehandlung nie stören.
 */
final class ErrorCounter
{
    public const PREFIX = 'basalto.errors.';

    /** So lange bleibt ein Minutenzähler liegen — länger als das grösste Fenster der Einstellung (60 Minuten). */
    private const TTL_MINUTES = 120;

    public static function record(?CarbonImmutable $at = null): void
    {
        try {
            $key = self::key($at ?? CarbonImmutable::now());
            Cache::add($key, 0, now()->addMinutes(self::TTL_MINUTES));
            Cache::increment($key);
        } catch (Throwable) {
            // bewusst still: ohne Cache gibt es keinen Alarm, aber die eigentliche Ausnahme wird weiter gemeldet
        }
    }

    /** Ausnahmen der letzten `$minutes` Minuten, die laufende Minute eingeschlossen. */
    public static function count(int $minutes): int
    {
        $now = CarbonImmutable::now();
        $total = 0;
        for ($i = 0; $i < max(1, $minutes); $i++) {
            $total += (int) Cache::get(self::key($now->subMinutes($i)), 0);
        }

        return $total;
    }

    private static function key(CarbonImmutable $at): string
    {
        return self::PREFIX.$at->utc()->format('YmdHi');
    }
}
