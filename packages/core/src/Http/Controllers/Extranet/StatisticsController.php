<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Extranet;

use Basalto\Core\Analytics\Aggregator;
use Basalto\Core\Analytics\Period;
use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Extranet\ExtranetRegistry;
use Basalto\Core\Extranet\Statistics\MetricSection;
use Basalto\Core\Models\Site;
use Basalto\Core\Support\Text;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Extranet › Statistik (TB 55): Reiter «Überblick» (die wichtigsten Zahlen aller Abschnitte), «Website» (die
 * Seitenaufrufe von basaltocms.com wie unter Verwaltung › Statistik) und je ein Reiter der Abschnitte aus Kern und
 * Modulen — Produkt (Downloads, Installationen), Lizenzen, Marktplatz, Community, Kundenkonten, SaaS.
 *
 * @phpstan-import-type Section from MetricSection
 * @phpstan-import-type Kpi from MetricSection
 */
final class StatisticsController
{
    public function __construct(private readonly ExtranetRegistry $registry, private readonly Aggregator $aggregator) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);
        $site = Extranet::vendorSite();
        abort_if($site === null, 404);
        $period = Period::make($request->query('range'), $site->timezone ?: (string) config('app.timezone'));
        $sections = $this->registry->statisticsFor($user);
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
            $overview = [['key' => 'website', 'label' => Text::t('Website'), 'kpis' => $this->website($period, $site)]];
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

        return Inertia::render('core::Extranet/Statistics', [
            'tab' => $tab,
            'tabs' => $tabs,
            'range' => $period->range,
            'period' => ['from' => $period->from->toDateString(), 'to' => $period->to->toDateString(), 'days' => $period->days],
            'site' => ['name' => $site->name],
            'overview' => $overview,
            'section' => $current,
            'website' => $tab === 'website'
                ? $this->aggregator->build($site, $period->range, $request->boolean('bots'), $this->aggregator->filters($request->query()))
                : null,
            'urls' => ['index' => route('extranet.statistics.index'), 'website' => route('extranet.statistics.index', ['tab' => 'website'])],
        ]);
    }

    /**
     * Kurzfassung der Website für den Überblick: Aufrufe und Besucher ohne Bots.
     *
     * @return list<Kpi>
     */
    private function website(Period $period, Site $site): array
    {
        $views = static fn (Period $p): Builder => DB::table('analytics_page_views')->where('site_id', $site->id)->where('is_bot', false)->whereBetween('created_at', $p->between());
        $visitors = static fn (Period $p): int => (int) $views($p)->where('visitor_id', '<>', '')->distinct()->count('visitor_id');
        $previous = $period->previous();

        return (new MetricSection('website', Text::t('Website')))
            ->kpi(Text::t('Seitenaufrufe'), $views($period)->count(), $views($previous)->count(), headline: true)
            ->kpi(Text::t('Besucher'), $visitors($period), $visitors($previous), hint: Text::t('Nur mit Einwilligung gezählt'), headline: true)
            ->headlines();
    }
}
