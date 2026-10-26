<?php

declare(strict_types=1);

namespace Basalto\Core\Analytics;

use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Illuminate\Http\Request;

/**
 * TB 54: Statistik im Frontend und die Einwilligung dazu. Der Cookie-Hinweis (wie bei Fristo) schreibt die Wahl in
 * `basalto_consent` (Liste der erlaubten Kategorien, `notwendig` für keine) — erst mit «Statistik» legt der Browser
 * die pseudonymen Kennungen `basalto_besucher` (ein Jahr) und `basalto_sitzung` (30 Minuten, gleitend) an. Ohne
 * Einwilligung, mit «Global Privacy Control» oder ohne Kennung zählt der Server den Aufruf anonym.
 */
final class Statistics
{
    public const CONSENT_COOKIE = 'basalto_consent';

    public const VISITOR_COOKIE = 'basalto_besucher';

    public const SESSION_COOKIE = 'basalto_sitzung';

    /** Kategorie der Statistik in der Einwilligung. */
    public const CATEGORY = 'statistik';

    public function __construct(private readonly Settings $settings) {}

    public function enabled(?Site $site): bool
    {
        return $site !== null && (bool) $this->settings->get('statistics.enabled', true, $site);
    }

    public function retentionDays(): int
    {
        return max(30, (int) $this->settings->get('statistics.retention_days', 400));
    }

    /** @return list<string> erlaubte Kategorien (leer: nur notwendige oder noch keine Wahl) */
    public static function consented(Request $request): array
    {
        $value = $request->cookies->get(self::CONSENT_COOKIE);
        if (! is_string($value) || $value === '' || $value === 'no' || $value === 'notwendig') {
            return [];
        }
        // Vor TB 54 kannte der Hinweis nur Ja oder Nein
        if ($value === 'yes') {
            return [self::CATEGORY, 'extern'];
        }

        return array_values(array_filter(explode(',', $value), static fn (string $c): bool => preg_match('/^[a-z]{2,20}$/', $c) === 1));
    }

    /** Darf dieser Aufruf Besucher und Sitzung tragen? Einwilligung «Statistik» und kein «Global Privacy Control». */
    public static function mayIdentify(Request $request): bool
    {
        return in_array(self::CATEGORY, self::consented($request), true) && $request->headers->get('Sec-GPC') !== '1';
    }

    /** Pseudonyme Kennung aus einem Cookie des Browsers — nur, wenn sie die erwartete Form hat. */
    public static function identifier(Request $request, string $cookie): string
    {
        $value = $request->cookies->get($cookie);

        return is_string($value) && preg_match('/^[A-Za-z0-9]{16,40}$/', $value) === 1 ? $value : '';
    }
}
