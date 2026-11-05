<?php

declare(strict_types=1);

namespace Basalto\Core\Analytics;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Extranet\Statistics\MetricSection;
use Basalto\Core\Models\Site;
use Basalto\Core\Payments\Payment;
use Basalto\Core\Payments\SitePayments;
use Basalto\Core\Seo\IndexNow;
use Basalto\Core\Seo\Sitemap;
use Basalto\Core\Support\Text;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Abschnitte des Kerns unter Verwaltung › Statistik (CR-019, TB 108) — für die gewählte Site: Suchmaschinen (Besuche
 * aus der Suche, Crawler, Sitemap, Meldungen, Adressen ohne Ziel), Formulare, Kundenkonten und Zahlungen.
 */
final class SiteStatistics
{
    /** Kurzfassung der Website für den Überblick (TB 55): Aufrufe und Besucher ohne Bots, mit dem Zeitraum davor. */
    public static function website(Period $period, Site $site): MetricSection
    {
        $views = static fn (Period $p): Builder => DB::table('analytics_page_views')->where('site_id', $site->id)->where('is_bot', false)->whereBetween('created_at', $p->between());
        $visitors = static fn (Period $p): int => (int) $views($p)->where('visitor_id', '<>', '')->distinct()->count('visitor_id');
        $previous = $period->previous();

        return (new MetricSection('website', Text::t('Website')))
            ->kpi(Text::t('Seitenaufrufe'), $views($period)->count(), $views($previous)->count(), headline: true)
            ->kpi(Text::t('Besucher'), $visitors($period), $visitors($previous), hint: Text::t('Nur mit Einwilligung gezählt'), headline: true);
    }

    public static function searchEngines(Period $period, Site $site): MetricSection
    {
        $previous = $period->previous();
        $views = static fn (Period $p): Builder => DB::table('analytics_page_views')->where('site_id', $site->id)->whereBetween('created_at', $p->between());
        // Grob über LIKE vorfiltern, genau in PHP zuordnen (Hosts gibt es wenige)
        $fromSearch = static fn (Period $p): Builder => $views($p)->where('is_bot', false)->where(static function (Builder $q): void {
            foreach (SearchEngines::likePatterns() as $pattern) {
                $q->orWhereLike('referrer_host', $pattern);
            }
        });
        $byEngine = static function (Period $p) use ($fromSearch): array {
            $out = [];
            foreach ($fromSearch($p)->selectRaw('referrer_host, COUNT(*) as n')->groupBy('referrer_host')->get() as $row) {
                $name = SearchEngines::engine((string) $row->referrer_host);
                if ($name !== null) {
                    $out[$name] = ($out[$name] ?? 0) + (int) $row->n;
                }
            }
            arsort($out);

            return $out;
        };
        $engines = $byEngine($period);
        $landing = [];
        foreach ($fromSearch($period)->selectRaw('referrer_host, path, COUNT(*) as n')->groupBy('referrer_host', 'path')->get() as $row) {
            if (SearchEngines::engine((string) $row->referrer_host) !== null) {
                $landing[(string) $row->path] = ($landing[(string) $row->path] ?? 0) + (int) $row->n;
            }
        }
        arsort($landing);
        $crawlers = $views($period)->where('is_bot', true)->selectRaw('browser, COUNT(*) as n')->groupBy('browser')->orderByDesc('n')->limit(10)->get();
        $crawled = static fn (Period $p): int => $views($p)->where('is_bot', true)->count();
        $google = static fn (Period $p): int => $views($p)->where('is_bot', true)->where('browser', 'Googlebot')->count();
        $sent = static fn (Period $p): int => DB::table('indexnow_urls')->where('site_id', $site->id)->where('status', IndexNow::SENT)->whereBetween('sent_at', $p->between())->count();
        $missing = DB::table('not_found_hits')->where('site_id', $site->id)->whereBetween('last_seen_at', $period->between());
        $sitemap = app(Sitemap::class)->count($site);

        return (new MetricSection('suchmaschinen', Text::t('Suchmaschinen')))
            ->lead(Text::t('Wie Suchmaschinen Ihre Website finden und lesen: Besuche aus der Suche, Besuche der Crawler, Adressen in der Sitemap und Meldungen über IndexNow.'))
            ->link(Text::t('Suchmaschinen öffnen'), route('basalto.search_engines'))
            ->kpi(Text::t('Besuche aus Suchmaschinen'), array_sum($engines), array_sum($byEngine($previous)), hint: Text::t('Seitenaufrufe mit einer Suchmaschine als Herkunft'), headline: true)
            ->kpi(Text::t('Besuche von Crawlern'), $crawled($period), $crawled($previous), hint: Text::t('Googlebot, Bingbot und andere Programme'), headline: true)
            ->kpi(Text::t('Davon Googlebot'), $google($period), $google($previous))
            ->kpi(Text::t('Adressen in der Sitemap'), $sitemap)
            ->kpi(Text::t('Gemeldet über IndexNow'), $sent($period), $sent($previous))
            ->kpi(Text::t('Adressen ohne Ziel (404)'), (clone $missing)->count(), DB::table('not_found_hits')->where('site_id', $site->id)->whereBetween('last_seen_at', $previous->between())->count())
            ->series(Text::t('Besuche aus Suchmaschinen je Tag'), $period->daily($fromSearch($period)))
            ->series(Text::t('Besuche von Crawlern je Tag'), $period->daily($views($period)->where('is_bot', true)))
            ->list(Text::t('Suchmaschinen'), array_map(static fn (string $name, int $n): array => ['label' => $name, 'value' => $n], array_keys($engines), array_values($engines)),
                empty: Text::t('Im Zeitraum kam niemand über eine Suchmaschine.'))
            ->list(Text::t('Einstiegsseiten aus der Suche'), array_map(static fn (string $path, int $n): array => ['label' => $path, 'value' => $n],
                array_keys(array_slice($landing, 0, 10, true)), array_values(array_slice($landing, 0, 10, true))), empty: Text::t('Noch keine.'))
            ->list(Text::t('Crawler'), $crawlers->map(static fn (object $row): array => ['label' => (string) ($row->browser ?? Text::t('Anderer Bot')), 'value' => (int) $row->n]),
                empty: Text::t('Im Zeitraum war kein Crawler da.'))
            ->list(Text::t('Adressen ohne Ziel (404)'), (clone $missing)->orderByDesc('hits')->limit(10)->get(['path', 'hits'])
                ->map(static fn (object $row): array => ['label' => (string) $row->path, 'value' => (int) $row->hits]), empty: Text::t('Keine — jede aufgerufene Adresse hatte ein Ziel.'));
    }

    public static function forms(Period $period, Site $site): MetricSection
    {
        $previous = $period->previous();
        $submissions = static fn (Period $p): Builder => DB::table('form_submissions')->where('form_submissions.site_id', $site->id)->whereBetween('form_submissions.created_at', $p->between());
        $byForm = $submissions($period)->join('forms', 'forms.id', '=', 'form_submissions.form_id')->selectRaw('forms.handle as handle, COUNT(*) as n')
            ->groupBy('forms.handle')->orderByDesc('n')->limit(10)->get();
        $open = DB::table('form_submissions')->where('site_id', $site->id)->where('status', 'new')->count();

        return (new MetricSection('formulare', Text::t('Formulare')))
            ->lead(Text::t('Eingänge aus den Formularen der Website — Kontakt, Bewerbungen, Anmeldungen.'))
            ->link(Text::t('Formulare öffnen'), route('basalto.forms.index'))
            ->kpi(Text::t('Eingänge'), $submissions($period)->count(), $submissions($previous)->count(), headline: true)
            ->kpi(Text::t('Noch nicht gelesen'), $open)
            ->kpi(Text::t('Abgewehrter Spam'), (int) DB::table('forms')->where('site_id', $site->id)->whereBetween('spam_last_at', $period->between())->sum('spam_count'),
                hint: Text::t('Formulare mit Spam im Zeitraum, gezählt seit Beginn'))
            ->series(Text::t('Eingänge je Tag'), $period->daily($submissions($period)))
            ->list(Text::t('Eingänge je Formular'), $byForm->map(static fn (object $row): array => ['label' => (string) $row->handle, 'value' => (int) $row->n]),
                empty: Text::t('Im Zeitraum kam kein Eingang.'));
    }

    public static function accounts(Period $period, Site $site): MetricSection
    {
        $previous = $period->previous();
        $created = static fn (Period $p): Builder => DB::table('customers')->where('site_id', $site->id)->whereBetween('created_at', $p->between());
        $active = static fn (Period $p): int => DB::table('customers')->where('site_id', $site->id)->whereBetween('last_login_at', $p->between())->count();

        return (new MetricSection('kundenkonten', Text::t('Kundenkonten')))
            ->lead(Text::t('Konten von Besucherinnen und Besuchern Ihrer Website — für Community, Mitgliederbereich und Käufe.'))
            ->kpi(Text::t('Neue Kundenkonten'), $created($period)->count(), $created($previous)->count(), headline: true)
            ->kpi(Text::t('Kundenkonten insgesamt'), DB::table('customers')->where('site_id', $site->id)->count())
            ->kpi(Text::t('Angemeldet im Zeitraum'), $active($period), $active($previous))
            ->series(Text::t('Neue Kundenkonten je Tag'), $period->daily($created($period)));
    }

    public static function payments(Period $period, Site $site): MetricSection
    {
        $previous = $period->previous();
        $paid = static fn (Period $p): Builder => DB::table('payments')->where('site_id', $site->id)->where('status', Payment::PAID)->whereBetween('paid_at', $p->between());
        $currency = app(SitePayments::class)->setting($site)->currency ?? 'CHF';
        $modules = [];
        foreach (app(Backend::class)->modules() as $module) {
            $modules[(string) $module['handle']] = Text::t((string) $module['label']);
        }
        $byModule = $paid($period)->selectRaw('module, COUNT(*) as n, SUM(amount) as total')->groupBy('module')->orderByDesc('total')->get();

        return (new MetricSection('zahlungen', Text::t('Zahlungen')))
            ->lead(Text::t('Zahlungen über Ihr Stripe-Konto — Anzahlungen, Gutscheine und was Plugins sonst verkaufen.'))
            ->link(Text::t('Zahlungen öffnen'), route('basalto.payments'))
            ->kpi(Text::t('Umsatz'), (int) $paid($period)->sum('amount'), (int) $paid($previous)->sum('amount'), 'money', currency: $currency, headline: true)
            ->kpi(Text::t('Bezahlte Zahlungen'), $paid($period)->count(), $paid($previous)->count(), headline: true)
            ->series(Text::t('Bezahlte Zahlungen je Tag'), $period->daily($paid($period), 'paid_at'))
            ->list(Text::t('Umsatz je Herkunft'), $byModule->map(static fn (object $row): array => ['label' => $modules[(string) $row->module] ?? (string) $row->module,
                'value' => (int) $row->total, 'hint' => (string) trans_choice(':count Zahlung|:count Zahlungen', (int) $row->n, ['count' => (int) $row->n])]), 'money', Text::t('Im Zeitraum wurde nichts bezahlt.'), $currency);
    }

    /** Ob die Site Zahlungen hat oder ein Stripe-Konto eingerichtet ist — sonst bleibt der Abschnitt weg. */
    public static function hasPayments(Site $site): bool
    {
        return app(SitePayments::class)->configured($site) || DB::table('payments')->where('site_id', $site->id)->exists();
    }
}
