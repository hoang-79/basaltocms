<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Analytics\Aggregator;
use Basalto\Core\Analytics\Statistics;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TB 54: Verwaltung › Statistik — die Seitenaufrufe der gewählten Site (wie bei Fristo): Zeitraum 7, 30 oder 90 Tage,
 * Bots ein- oder ausblenden, Eingrenzen per Klick (Seite, Herkunft, Browser …). Die Auswertung macht {@see Aggregator}.
 */
final class StatisticsController
{
    public function __construct(private readonly CurrentSite $current, private readonly Aggregator $aggregator, private readonly Statistics $statistics) {}

    public function __invoke(Request $request): Response
    {
        $site = $this->current->require();
        $payload = $this->aggregator->build($site, (string) $request->query('range', '7d'), $request->boolean('bots'), $this->aggregator->filters($request->query()));

        return Inertia::render('core::Statistics', [
            'stats' => $payload,
            'enabled' => $this->statistics->enabled($site),
            'site' => ['name' => $site->name],
            'url' => route('basalto.statistics'),
            'settings_url' => route('basalto.settings.index', ['group' => 'statistics']),
        ]);
    }
}
