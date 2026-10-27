<?php

declare(strict_types=1);

namespace Basalto\Core\Activity;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Autosave-Anfragen (CR-013): Das Backend, das Extranet und das Kundenkonto schicken beim selbständigen Speichern die
 * Kopfzeile `X-Basalto-Autosave: 1`. Protokoll und Verlauf einer Einstellung fassen eine Folge davon zusammen —
 * dieselbe Person, dieselbe Aktion, derselbe Gegenstand innert zehn Minuten ergeben eine Zeile (alt vom ersten, neu
 * vom letzten Speichern). Ohne die Kopfzeile bleibt jede Aktion eine eigene Zeile.
 */
final class AutosaveRequest
{
    public const HEADER = 'X-Basalto-Autosave';

    public const WINDOW_MINUTES = 10;

    public static function current(): bool
    {
        $request = app()->bound('request') ? app('request') : null;

        return $request instanceof Request && $request->headers->get(self::HEADER) === '1';
    }

    /** Ab wann eine frühere Zeile noch zur selben Folge gehört. */
    public static function since(): CarbonImmutable
    {
        return CarbonImmutable::now()->subMinutes(self::WINDOW_MINUTES);
    }
}
