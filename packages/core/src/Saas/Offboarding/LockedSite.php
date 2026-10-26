<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Offboarding;

use Basalto\Core\Models\Site;
use Basalto\Core\Saas\Saas;
use Illuminate\Http\Response;

/**
 * Das Frontend einer gesperrten Site (TB 12 Scheibe 4, Mockup 24 `_gesperrt`): eine neutrale Seite ohne Inhalte, ohne
 * Theme und ohne Datenbankabfragen der Site, in ihrer Sprache — Status **410 Gone**.
 *
 * Warum 410 und nicht 503: 503 sagt «vorübergehend, bitte wiederkommen» — das stimmt nach einer Kündigung nicht mehr.
 * Zurücknehmen geht nur bis zum Ende der Laufzeit; ist die Site gesperrt, wird sie gelöscht. 410 lässt Suchmaschinen die
 * Seiten rasch vergessen (auch Personendaten in Inhalten), Browser und Proxys dürfen die Antwort nicht als Inhalt halten
 * (`no-store`), und `noindex` verhindert, dass die Hinweisseite selbst gelistet wird.
 */
final class LockedSite
{
    public static function response(?Site $site): ?Response
    {
        // Ohne SaaS keine Abfrage — die Auslieferung aus dem Seiten-Cache liest nur Site, Einstellung und Theme (M-16)
        if ($site === null || ! Saas::enabled() || ! Lifecycle::is($site->account, Lifecycle::LOCKED)) {
            return null;
        }
        $locale = app()->getLocale();
        $html = '<!doctype html><html lang="'.e($locale).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="robots" content="noindex"><title>'.e(__('Nicht mehr verfügbar')).'</title></head>'
            .'<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f5f1ea;color:#1f1d1c;font:16px/1.5 system-ui,sans-serif;text-align:center">'
            .'<main style="padding:48px 24px;max-width:560px"><h1 style="font-size:24px;margin:0 0 8px">'.e(__('Diese Site ist nicht mehr verfügbar.')).'</h1>'
            .'<p style="margin:0;color:#5a5551">'.e(request()->getHost()).'</p></main></body></html>';

        return new Response($html, 410, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }
}
