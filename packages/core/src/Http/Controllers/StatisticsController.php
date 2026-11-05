<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Analytics\Aggregator;
use Basalto\Core\Analytics\Period;
use Basalto\Core\Analytics\SiteStatistics;
use Basalto\Core\Analytics\Statistics;
use Basalto\Core\Backend\Backend;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Support\Text;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Verwaltung › Statistik (TB 54, CR-019 TB 108) — aufgebaut wie im Extranet: «Überblick» mit den wichtigsten Zahlen
 * jedes Abschnitts und dem Vergleich zum Zeitraum davor, «Website» mit den Seitenaufrufen der Site (Zeitraum, Bots,
 * Eingrenzen per Klick; ausgewertet von {@see Aggregator}) und je ein Reiter der Abschnitte aus Kern, Modulen und Plugins
 * ({@see Backend::statistics()}) — nur, was auf der Site an ist und die Person sehen darf.
 */
final class StatisticsController
{
    public function __construct(private readonly CurrentSite $current, private readonly Aggregator $aggregator, private readonly Statistics $statistics, private readonly Backend $backend) {}

    public function __invoke(Request $request): Response
    {
        $site = $this->current->require();
        $user = $request->user();
        abort_if($user === null, 403);
        $period = Period::make($request->query('range'), $site->timezone ?: (string) config('app.timezone'));
        $sections = $this->backend->statisticsFor($user, $site);
        $tabs = [['key' => 'ueberblick', 'label' => Text::t('Überblick')], ['key' => 'website', 'label' => Text::t('Website')]];
        foreach ($sections as $section) {
            $tabs[] = ['key' => $section['key'], 'label' => Text::t($section['label'])];
        }
        $tab = (string) $request->query('tab', 'ueberblick');
        if (! in_array($tab, array_column($tabs, 'key'), true)) {
            $tab = 'ueberblick';
        }

        $overview = null;
        $current = null;
        if ($tab === 'ueberblick') {
            $overview = [['key' => 'website', 'label' => Text::t('Website'), 'kpis' => SiteStatistics::website($period, $site)->headlines()]];
            foreach ($sections as $section) {
                $built = ($section['build'])($period, $site);
                $overview[] = ['key' => $section['key'], 'label' => Text::t($section['label']), 'kpis' => $built->headlines()];
            }
        } elseif ($tab !== 'website') {
            foreach ($sections as $section) {
                if ($section['key'] === $tab) {
                    $current = ($section['build'])($period, $site)->toArray();
                }
            }
        }

        return Inertia::render('core::Statistics', [
            'tab' => $tab,
            'tabs' => $tabs,
            'range' => $period->range,
            'period' => ['from' => $period->from->toDateString(), 'to' => $period->to->toDateString(), 'days' => $period->days],
            'overview' => $overview,
            'section' => $current,
            'website' => $tab === 'website'
                ? $this->aggregator->build($site, (string) $request->query('range', '7d'), $request->boolean('bots'), $this->aggregator->filters($request->query()))
                : null,
            'enabled' => $this->statistics->enabled($site),
            'site' => ['name' => $site->name],
            'urls' => ['index' => route('basalto.statistics'), 'website' => route('basalto.statistics', ['tab' => 'website'])],
            'settings_url' => route('basalto.settings.index', ['group' => 'statistics']),
        ]);
    }
}
