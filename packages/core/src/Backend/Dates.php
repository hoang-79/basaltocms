<?php

declare(strict_types=1);

namespace Basalto\Core\Backend;

use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Support\Preferences;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Zeitangaben für das Backend: in der Zeitzone und im Datums- und Zeitformat aus dem Profil — ohne Angabe in der
 * Zeitzone der aktuellen Site und im Schreibstil der Backend-Sprache.
 * Die Seiten bekommen fertige Zeichenketten (Vertrag in types.ts), nie ISO-Werte zum Selberformatieren.
 */
final class Dates
{
    public function __construct(private readonly CurrentSite $current) {}

    /** Datum mit Uhrzeit, z. B. «15.10.2026, 18:17» (Deutsch) oder «2026-10-15 18:17» (Englisch). */
    public function dateTime(mixed $value): ?string
    {
        $own = $this->ownFormats();

        return $this->format($value, $own !== null ? $own['date_format'].', '.$own['time_format'] : (app()->getLocale() === 'en' ? 'Y-m-d H:i' : 'd.m.Y, H:i'));
    }

    /** Nur das Datum, z. B. «15.10.2026» oder «2026-10-15». */
    public function date(mixed $value): ?string
    {
        $own = $this->ownFormats();

        return $this->format($value, $own !== null ? $own['date_format'] : (app()->getLocale() === 'en' ? 'Y-m-d' : 'd.m.Y'));
    }

    /** Relativ für Listen: «heute 09:40», «gestern 16:12», «12.10.» im selben Jahr, sonst das volle Datum. */
    public function relative(mixed $value): ?string
    {
        return ($this->relativeFormatter())($value);
    }

    /**
     * {@see relative()} für lange Listen: Zeitzone, «jetzt», Sprache und Profilformate einmal ermitteln, dann je Zeile
     * nur noch umrechnen und formatieren (N-03).
     *
     * @return \Closure(mixed): ?string
     */
    public function relativeFormatter(): \Closure
    {
        $timezone = $this->timezone();
        $now = CarbonImmutable::now($timezone);
        $today = $now->format('Y-m-d');
        $yesterday = $now->subDay()->format('Y-m-d');
        $en = app()->getLocale() === 'en';
        // In der Sprache der Oberfläche (N-01), einmal je Liste
        [$todayWord, $yesterdayWord] = [(string) __('heute :time'), (string) __('gestern :time')];
        $own = $this->ownFormats();
        $time = $own !== null ? $own['time_format'] : 'H:i';
        $appTimezone = (string) config('app.timezone', 'UTC');

        return static function (mixed $value) use ($timezone, $now, $today, $yesterday, $en, $own, $time, $appTimezone, $todayWord, $yesterdayWord): ?string {
            if ($value instanceof DateTimeInterface) {
                $moment = CarbonImmutable::instance($value)->setTimezone($timezone);
            } elseif (is_string($value) && $value !== '') {
                $moment = CarbonImmutable::parse($value, $appTimezone)->setTimezone($timezone);
            } else {
                return null;
            }
            $day = $moment->format('Y-m-d');
            if ($day === $today) {
                return str_replace(':time', $moment->format($time), $todayWord);
            }
            if ($day === $yesterday) {
                return str_replace(':time', $moment->format($time), $yesterdayWord);
            }
            if ($own !== null) {
                return $moment->format($own['date_format']);
            }
            if ($moment->year === $now->year) {
                return $moment->format($en ? 'm/d' : 'd.m.');
            }

            return $moment->format($en ? 'Y-m-d' : 'd.m.Y');
        };
    }

    /** Zeitzone aus dem Profil, sonst die der aktuellen Site, sonst die der Anwendung. */
    public function timezone(): string
    {
        $own = auth()->user()?->getAttribute('timezone');
        // Die eine Zone prüfen statt je Aufruf die Liste aller 420 aufzubauen (N-03: jede Zeitangabe einer Liste)
        if (is_string($own) && $own !== '' && @timezone_open($own) !== false) {
            return $own;
        }
        $site = $this->current->get();

        return $site !== null ? $site->timezone : (string) config('app.timezone', 'UTC');
    }

    /**
     * Die Formate aus dem Profil — null, solange der Benutzer keine eigenen gewählt hat (dann gilt die Sprache).
     *
     * @return array{date_format: string, time_format: string}|null
     */
    private function ownFormats(): ?array
    {
        $user = auth()->user();
        $stored = $user instanceof Model ? $user->getAttribute('preferences') : null;
        if ($stored === null || $stored === '' || $stored === []) {
            return null;
        }
        $prefs = Preferences::of($user);

        return ['date_format' => $prefs['date_format'], 'time_format' => $prefs['time_format']];
    }

    private function format(mixed $value, string $format): ?string
    {
        return $this->moment($value)?->format($format);
    }

    /** Der Zeitpunkt in der Zeitzone der Site — aus Carbon, DateTime oder Datenbank-Zeichenkette. */
    private function moment(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            $moment = CarbonImmutable::instance($value);
        } elseif (is_string($value) && $value !== '') {
            $moment = CarbonImmutable::parse($value, (string) config('app.timezone', 'UTC'));
        } else {
            return null;
        }

        return $moment->setTimezone($this->timezone());
    }
}
