<?php

declare(strict_types=1);

namespace Basalto\Core\Analytics;

use Basalto\Core\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * TB 54: Wertet die Seitenaufrufe einer Site aus (wie Fristo) — Kennzahlen, Verweildauer, Zeitreihe, Tagesstunden,
 * Kanäle, Verweise, Kampagnen, Seiten, Einstiegs- und Ausstiegsseiten, Technik, Sprachen, Länder, Antwortzeiten und
 * Bots. Besucher, Sitzungen und alles, was Sitzungen braucht, zählt nur Aufrufe mit Einwilligung; anonyme Aufrufe
 * zählen bei den Seitenaufrufen und Verteilungen mit. Tage und Stunden in der Zeitzone der Site (Abstand von heute).
 */
final class Aggregator
{
    /** @var array<string, int> */
    public const RANGES = Period::RANGES;

    /** Filter zum Eingrenzen: Abfrage → Spalte. */
    public const FILTERS = ['path' => 'path', 'referrer' => 'referrer_host', 'browser' => 'browser', 'os' => 'os', 'device' => 'device_type',
        'country' => 'country', 'source' => 'utm_source', 'locale' => 'locale'];

    /** Filterwert für «direkt / ohne Verweis». */
    public const DIRECT = '__direct__';

    /** Verweildauer je Sitzung höchstens so lange (Sekunden) — sonst verzerren vergessene Tabs. */
    private const SESSION_CAP = 1800;

    private int $offset = 0;

    /**
     * @param  array<string, string>  $filters
     * @return array<string, mixed>
     */
    public function build(Site $site, string $range, bool $bots, array $filters): array
    {
        $range = $this->range($range);
        $days = self::RANGES[$range];
        $zone = $site->timezone ?: (string) config('app.timezone');
        $now = CarbonImmutable::now($zone);
        $this->offset = $now->utcOffset();
        $from = $now->startOfDay()->subDays($days - 1);
        $to = $now->endOfDay();
        $base = fn (): Builder => DB::table('analytics_page_views')->where('site_id', $site->id)
            ->whereBetween('created_at', [$from->utc(), $to->utc()])
            ->when(! $bots, static fn (Builder $q) => $q->where('is_bot', false))
            ->tap(fn (Builder $q) => $this->applyFilters($q, $filters));
        $all = fn (): Builder => DB::table('analytics_page_views')->where('site_id', $site->id)
            ->whereBetween('created_at', [$from->utc(), $to->utc()])
            ->tap(fn (Builder $q) => $this->applyFilters($q, $filters));

        return [
            'range' => $range,
            'include_bots' => $bots,
            'filters' => $filters,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'kpis' => $this->kpis($base),
            'engagement' => $this->engagement($base),
            'new_vs_returning' => $this->newVsReturning($site, $base, $bots, $from),
            'timeseries' => $this->timeseries($base, $from, $to),
            'hourly' => $this->hourly($base),
            'channels' => $this->channels($base),
            'top_referrers' => $this->topReferrers($base),
            'campaigns' => $this->campaigns($base),
            'top_pages' => $this->topPages($base),
            'landing_pages' => $this->edgePages($base, 'MIN'),
            'exit_pages' => $this->edgePages($base, 'MAX'),
            'browsers' => $this->topGroup($base, 'browser', 8),
            'operating_systems' => $this->topGroup($base, 'os', 8),
            'devices' => $this->topGroup($base, 'device_type', 8),
            'languages' => $this->topGroup($base, 'locale', 12),
            'countries' => $this->topGroup($base, 'country', 12),
            'performance' => $this->performance($base),
            'bot_traffic' => $this->botTraffic($all),
            'recent' => $this->recent($base, $zone),
        ];
    }

    public function range(mixed $range): string
    {
        return is_string($range) && array_key_exists($range, self::RANGES) ? $range : '7d';
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<string, string>
     */
    public function filters(array $input): array
    {
        $filters = [];
        foreach (array_keys(self::FILTERS) as $key) {
            $value = $input[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $filters[$key] = mb_substr($value, 0, 255);
            }
        }

        return $filters;
    }

    /** @param  array<string, string>  $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        foreach ($filters as $key => $value) {
            $column = self::FILTERS[$key];
            $key === 'referrer' && $value === self::DIRECT ? $query->whereNull($column) : $query->where($column, $value);
        }
    }

    /**
     * @param  callable(): Builder  $base
     * @return array{pageviews: int, visitors: int, sessions: int, accounts: int, anonymous: int, pages_per_session: float}
     */
    private function kpis(callable $base): array
    {
        $row = $base()->selectRaw('COUNT(*) as pageviews')
            ->selectRaw("COUNT(DISTINCT CASE WHEN visitor_id <> '' THEN visitor_id END) as visitors")
            ->selectRaw("COUNT(DISTINCT CASE WHEN session_id <> '' THEN session_id END) as sessions")
            ->selectRaw('COUNT(DISTINCT customer_id) as accounts')
            ->selectRaw("SUM(CASE WHEN visitor_id = '' THEN 1 ELSE 0 END) as anonymous")
            ->first();
        $pageviews = (int) ($row->pageviews ?? 0);
        $sessions = (int) ($row->sessions ?? 0);
        $identified = $pageviews - (int) ($row->anonymous ?? 0);

        return ['pageviews' => $pageviews, 'visitors' => (int) ($row->visitors ?? 0), 'sessions' => $sessions, 'accounts' => (int) ($row->accounts ?? 0),
            'anonymous' => (int) ($row->anonymous ?? 0), 'pages_per_session' => $sessions > 0 ? round($identified / $sessions, 1) : 0.0];
    }

    /**
     * Verweildauer aus den Zeitpunkten geschätzt (ohne Messung im Browser): Sitzung = letzter minus erster Aufruf
     * (gedeckelt), Zeit je Seite = Summe / (Aufrufe − Sitzungen), Absprung = Sitzungen mit einer Seite.
     *
     * @param  callable(): Builder  $base
     * @return array{avg_session_seconds: int, avg_time_on_page_seconds: int, bounce_rate: float}
     */
    private function engagement(callable $base): array
    {
        $sessions = $base()->where('session_id', '<>', '')->select('session_id')->selectRaw('COUNT(*) as views')
            ->selectRaw('MIN(created_at) as first_at')->selectRaw('MAX(created_at) as last_at')->groupBy('session_id')->get();
        $count = $sessions->count();
        if ($count === 0) {
            return ['avg_session_seconds' => 0, 'avg_time_on_page_seconds' => 0, 'bounce_rate' => 0.0];
        }
        $seconds = 0;
        $views = 0;
        $bounces = 0;
        foreach ($sessions as $session) {
            $views += (int) $session->views;
            if ((int) $session->views <= 1) {
                $bounces++;

                continue;
            }
            $seconds += min(self::SESSION_CAP, max(0, (int) CarbonImmutable::parse((string) $session->first_at)->diffInSeconds(CarbonImmutable::parse((string) $session->last_at))));
        }

        return ['avg_session_seconds' => (int) round($seconds / $count), 'avg_time_on_page_seconds' => (int) round($seconds / max(1, $views - $count)),
            'bounce_rate' => round($bounces / $count * 100, 1)];
    }

    /**
     * @param  callable(): Builder  $base
     * @return array{new: int, returning: int}
     */
    private function newVsReturning(Site $site, callable $base, bool $bots, CarbonImmutable $from): array
    {
        $total = (int) $base()->where('visitor_id', '<>', '')->distinct()->count('visitor_id');
        $first = DB::table('analytics_page_views')->where('site_id', $site->id)->where('visitor_id', '<>', '')
            ->when(! $bots, static fn (Builder $q) => $q->where('is_bot', false))
            ->select('visitor_id')->selectRaw('MIN(created_at) as first_seen')->groupBy('visitor_id');
        $new = (int) $base()->where('analytics_page_views.visitor_id', '<>', '')
            ->joinSub($first, 'fs', 'fs.visitor_id', '=', 'analytics_page_views.visitor_id')
            ->where('fs.first_seen', '>=', $from->utc())->distinct()->count('analytics_page_views.visitor_id');

        return ['new' => $new, 'returning' => max(0, $total - $new)];
    }

    /**
     * @param  callable(): Builder  $base
     * @return list<array{date: string, pageviews: int, visitors: int, sessions: int}>
     */
    private function timeseries(callable $base, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $base()->selectRaw($this->local('date').' as d', [$this->offset])->selectRaw('COUNT(*) as pageviews')
            ->selectRaw("COUNT(DISTINCT CASE WHEN visitor_id <> '' THEN visitor_id END) as visitors")
            ->selectRaw("COUNT(DISTINCT CASE WHEN session_id <> '' THEN session_id END) as sessions")
            ->groupBy('d')->get();
        // Ränder über eine Zeitumstellung zählen zum ersten bzw. letzten Tag (siehe Period::clamp)
        $first = $from->toDateString();
        $last = $to->toDateString();
        $days = [];
        foreach ($rows as $row) {
            $date = max($first, min($last, substr((string) $row->d, 0, 10)));
            $days[$date] = ['pageviews' => ($days[$date]['pageviews'] ?? 0) + (int) $row->pageviews, 'visitors' => ($days[$date]['visitors'] ?? 0) + (int) $row->visitors,
                'sessions' => ($days[$date]['sessions'] ?? 0) + (int) $row->sessions];
        }
        $series = [];
        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $row = $days[$day->toDateString()] ?? ['pageviews' => 0, 'visitors' => 0, 'sessions' => 0];
            $series[] = ['date' => $day->toDateString()] + $row;
        }

        return $series;
    }

    /**
     * @param  callable(): Builder  $base
     * @return list<array{hour: int, pageviews: int, visitors: int}>
     */
    private function hourly(callable $base): array
    {
        $rows = $base()->selectRaw($this->local('hour').' as h', [$this->offset])->selectRaw('COUNT(*) as pageviews')
            ->selectRaw("COUNT(DISTINCT CASE WHEN visitor_id <> '' THEN visitor_id END) as visitors")
            ->groupBy('h')->get()->keyBy(static fn (object $row): int => (int) $row->h);
        $out = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $row = $rows[$hour] ?? null;
            $out[] = ['hour' => $hour, 'pageviews' => (int) ($row->pageviews ?? 0), 'visitors' => (int) ($row->visitors ?? 0)];
        }

        return $out;
    }

    /**
     * Datum oder Stunde in der Zeitzone der Site ({@see Period}).
     *
     * @return literal-string
     */
    private function local(string $part): string
    {
        return $part === 'date' ? Period::localDate() : Period::localHour();
    }

    /** Kanal eines Aufrufs: Kampagne, direkt, Suchmaschine, soziales Netzwerk oder Verweis. */
    private const CHANNEL = "CASE WHEN utm_source IS NOT NULL AND utm_source <> '' THEN 'campaign' WHEN referrer_host IS NULL OR referrer_host = '' THEN 'direct' "
        ."WHEN referrer_host LIKE '%google.%' OR referrer_host LIKE '%bing.%' OR referrer_host LIKE '%duckduckgo.%' OR referrer_host LIKE '%yahoo.%' "
        ."OR referrer_host LIKE '%ecosia.%' OR referrer_host LIKE '%search.%' OR referrer_host LIKE '%qwant.%' OR referrer_host LIKE '%startpage.%' THEN 'organic' "
        ."WHEN referrer_host LIKE '%facebook.%' OR referrer_host LIKE '%twitter.%' OR referrer_host LIKE '%x.com%' OR referrer_host LIKE '%t.co%' "
        ."OR referrer_host LIKE '%linkedin.%' OR referrer_host LIKE '%instagram.%' OR referrer_host LIKE '%reddit.%' OR referrer_host LIKE '%youtube.%' "
        ."OR referrer_host LIKE '%mastodon%' OR referrer_host LIKE '%bsky.%' OR referrer_host LIKE '%threads.%' THEN 'social' ELSE 'referral' END";

    /**
     * @param  callable(): Builder  $base
     * @return list<array{label: string, value: string, count: int}>
     */
    private function channels(callable $base): array
    {
        return array_values($base()->selectRaw(self::CHANNEL.' as channel')->selectRaw('COUNT(*) as count')->groupBy('channel')->orderByDesc('count')->get()
            ->map(static fn (object $r): array => ['label' => (string) $r->channel, 'value' => (string) $r->channel, 'count' => (int) $r->count])->values()->all());
    }

    /**
     * @param  callable(): Builder  $base
     * @return list<array{source: string, medium: ?string, campaign: ?string, views: int, visitors: int, sessions: int}>
     */
    private function campaigns(callable $base): array
    {
        return array_values($base()->whereNotNull('utm_source')->where('utm_source', '<>', '')->select('utm_source', 'utm_medium', 'utm_campaign')
            ->selectRaw('COUNT(*) as views')->selectRaw("COUNT(DISTINCT CASE WHEN visitor_id <> '' THEN visitor_id END) as visitors")
            ->selectRaw("COUNT(DISTINCT CASE WHEN session_id <> '' THEN session_id END) as sessions")
            ->groupBy('utm_source', 'utm_medium', 'utm_campaign')->orderByDesc('views')->limit(20)->get()
            ->map(static fn (object $r): array => ['source' => (string) $r->utm_source, 'medium' => $r->utm_medium, 'campaign' => $r->utm_campaign,
                'views' => (int) $r->views, 'visitors' => (int) $r->visitors, 'sessions' => (int) $r->sessions])->values()->all());
    }

    /**
     * @param  callable(): Builder  $base
     * @return list<array{label: string, value: string, count: int, visitors: int, avg_ms: int}>
     */
    private function topPages(callable $base): array
    {
        return array_values($base()->select('path')->selectRaw('COUNT(*) as count')->selectRaw("COUNT(DISTINCT CASE WHEN visitor_id <> '' THEN visitor_id END) as visitors")
            ->selectRaw('AVG(response_time_ms) as avg_ms')->groupBy('path')->orderByDesc('count')->limit(15)->get()
            ->map(static fn (object $r): array => ['label' => (string) $r->path, 'value' => (string) $r->path, 'count' => (int) $r->count, 'visitors' => (int) $r->visitors,
                'avg_ms' => (int) round((float) ($r->avg_ms ?? 0))])->values()->all());
    }

    /**
     * Einstiegs- (MIN) oder Ausstiegsseiten (MAX) — erste bzw. letzte Seite jeder Sitzung.
     *
     * @param  callable(): Builder  $base
     * @param  'MIN'|'MAX'  $edge
     * @return list<array{label: string, value: string, count: int, visitors: int}>
     */
    private function edgePages(callable $base, string $edge): array
    {
        $edges = $base()->where('session_id', '<>', '')->select('session_id')->selectRaw($edge.'(created_at) as t')->groupBy('session_id');

        return array_values($base()->joinSub($edges, 'e', static function ($join): void {
            $join->on('analytics_page_views.session_id', '=', 'e.session_id')->on('analytics_page_views.created_at', '=', 'e.t');
        })->select('analytics_page_views.path')->selectRaw('COUNT(DISTINCT analytics_page_views.session_id) as count')
            ->selectRaw('COUNT(DISTINCT analytics_page_views.visitor_id) as visitors')->groupBy('analytics_page_views.path')->orderByDesc('count')->limit(10)->get()
            ->map(static fn (object $r): array => ['label' => (string) $r->path, 'value' => (string) $r->path, 'count' => (int) $r->count, 'visitors' => (int) $r->visitors])->values()->all());
    }

    /**
     * @param  callable(): Builder  $base
     * @return array{measured: int, avg_ms: int, p50: int, p90: int, p99: int, slowest: list<array{label: string, avg_ms: int, max_ms: int, count: int}>}
     */
    private function performance(callable $base): array
    {
        $measured = (int) $base()->whereNotNull('response_time_ms')->count();
        $slowest = array_values($base()->whereNotNull('response_time_ms')->select('path')->selectRaw('AVG(response_time_ms) as avg_ms')->selectRaw('MAX(response_time_ms) as max_ms')
            ->selectRaw('COUNT(*) as count')->groupBy('path')->orderByDesc('avg_ms')->limit(12)->get()
            ->map(static fn (object $r): array => ['label' => (string) $r->path, 'avg_ms' => (int) round((float) $r->avg_ms), 'max_ms' => (int) $r->max_ms, 'count' => (int) $r->count])->values()->all());

        return ['measured' => $measured, 'avg_ms' => (int) round((float) ($base()->whereNotNull('response_time_ms')->avg('response_time_ms') ?? 0)),
            'p50' => $this->percentile($base, 0.50, $measured), 'p90' => $this->percentile($base, 0.90, $measured), 'p99' => $this->percentile($base, 0.99, $measured),
            'slowest' => $slowest];
    }

    /** @param  callable(): Builder  $base */
    private function percentile(callable $base, float $p, int $count): int
    {
        return $count === 0 ? 0 : (int) ($base()->whereNotNull('response_time_ms')->orderBy('response_time_ms')->offset((int) min($count - 1, floor($count * $p)))->limit(1)->value('response_time_ms') ?? 0);
    }

    /**
     * @param  callable(): Builder  $all
     * @return array{total: int, humans: int, bots: int, bot_share: float, top_bots: list<array{label: string, value: string, count: int}>}
     */
    private function botTraffic(callable $all): array
    {
        $total = (int) $all()->count();
        $bots = (int) $all()->where('is_bot', true)->count();

        return ['total' => $total, 'humans' => max(0, $total - $bots), 'bots' => $bots, 'bot_share' => $total > 0 ? round($bots / $total * 100, 1) : 0.0,
            'top_bots' => $this->topGroup(static fn (): Builder => $all()->where('is_bot', true), 'browser', 10)];
    }

    /**
     * @param  callable(): Builder  $base
     * @return list<array{label: string, value: string, count: int}>
     */
    private function topGroup(callable $base, string $column, int $limit): array
    {
        return array_values($base()->select($column)->selectRaw('COUNT(*) as count')->whereNotNull($column)->where($column, '<>', '')->groupBy($column)
            ->orderByDesc('count')->limit($limit)->get()
            ->map(static fn (object $r): array => ['label' => (string) $r->{$column}, 'value' => (string) $r->{$column}, 'count' => (int) $r->count])->values()->all());
    }

    /**
     * @param  callable(): Builder  $base
     * @return list<array{label: string, value: string, count: int}>
     */
    private function topReferrers(callable $base): array
    {
        $rows = $this->topGroup($base, 'referrer_host', 14);
        $direct = (int) $base()->whereNull('referrer_host')->count();
        if ($direct > 0) {
            array_unshift($rows, ['label' => self::DIRECT, 'value' => self::DIRECT, 'count' => $direct]);
        }

        return $rows;
    }

    /**
     * @param  callable(): Builder  $base
     * @return list<array{path: string, referrer_host: ?string, browser: ?string, os: ?string, device_type: ?string, country: ?string, created_at: string}>
     */
    private function recent(callable $base, string $zone): array
    {
        return array_values($base()->orderByDesc('created_at')->orderByDesc('id')->limit(25)->get(['path', 'referrer_host', 'browser', 'os', 'device_type', 'country', 'created_at'])
            ->map(static fn (object $r): array => ['path' => (string) $r->path, 'referrer_host' => $r->referrer_host, 'browser' => $r->browser, 'os' => $r->os,
                'device_type' => $r->device_type, 'country' => $r->country, 'created_at' => CarbonImmutable::parse((string) $r->created_at, 'UTC')->setTimezone($zone)->toIso8601String()])
            ->values()->all());
    }
}
