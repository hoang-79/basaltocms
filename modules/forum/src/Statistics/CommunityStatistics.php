<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Statistics;

use Basalto\Core\Analytics\Period;
use Basalto\Core\Extranet\Statistics\MetricSection;
use Basalto\Core\Models\Site;
use Basalto\Core\Support\Text;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Abschnitt «Community» der Statistik im Extranet (TB 55): Mitglieder, Themen und Beiträge im Forum der Site des
 * Herstellers — ohne Papierkorb und ohne ausgeblendete Beiträge.
 */
final class CommunityStatistics
{
    public static function section(Period $period, Site $site): MetricSection
    {
        $previous = $period->previous();
        $members = static fn (Period $p): Builder => DB::table('community_profiles')->where('site_id', $site->id)->whereBetween('created_at', $p->between());
        $threads = static fn (Period $p): Builder => DB::table('forum_threads')->where('site_id', $site->id)->whereNull('deleted_at')->where('is_disabled', false)->whereBetween('created_at', $p->between());
        $posts = static fn (Period $p): Builder => DB::table('forum_posts')->where('forum_posts.site_id', $site->id)->whereNull('forum_posts.deleted_at')
            ->where('forum_posts.is_disabled', false)->whereBetween('forum_posts.created_at', $p->between());
        $writers = static fn (Period $p): int => (int) $posts($p)->whereNotNull('customer_id')->distinct()->count('customer_id');
        $locale = app()->getLocale();

        return (new MetricSection('community', Text::t('Community')))
            ->lead(Text::t('Das Forum der Website — Mitglieder mit Profil, neue Themen und Beiträge.'))
            ->link(Text::t('Community öffnen'), route('basalto.forum.index'))
            ->kpi(Text::t('Neue Mitglieder'), $members($period)->count(), $members($previous)->count(), headline: true)
            ->kpi(Text::t('Mitglieder insgesamt'), DB::table('community_profiles')->where('site_id', $site->id)->count())
            ->kpi(Text::t('Neue Themen'), $threads($period)->count(), $threads($previous)->count())
            ->kpi(Text::t('Neue Beiträge'), $posts($period)->count(), $posts($previous)->count(), headline: true)
            ->kpi(Text::t('Aktive Schreibende'), $writers($period), $writers($previous), hint: Text::t('Mitglieder mit mindestens einem Beitrag'))
            ->series(Text::t('Beiträge je Tag'), $period->daily($posts($period)))
            ->series(Text::t('Neue Mitglieder je Tag'), $period->daily($members($period)))
            ->list(Text::t('Foren mit den meisten Beiträgen'), self::topNodes($posts($period), $locale), empty: Text::t('Keine Beiträge im Zeitraum.'));
    }

    /**
     * Foren nach Beiträgen im Zeitraum — gezählt je Forum (nach dessen Kennung, denn nach dem JSON-Titel lässt sich in
     * PostgreSQL nicht gruppieren), die Titel danach in der Sprache der Oberfläche.
     *
     * @return list<array{label: string, value: int}>
     */
    private static function topNodes(Builder $posts, string $locale): array
    {
        $counts = $posts->join('forum_threads', 'forum_threads.id', '=', 'forum_posts.thread_id')->selectRaw('forum_threads.node_id as node, COUNT(*) as n')
            ->groupBy('forum_threads.node_id')->orderByDesc('n')->limit(10)->get();
        $titles = DB::table('forum_nodes')->whereIn('id', $counts->pluck('node')->all())->pluck('title', 'id');

        return array_values($counts->map(static fn (object $row): array => ['label' => self::title((string) ($titles[$row->node] ?? ''), $locale), 'value' => (int) $row->n])->all());
    }

    /** Titel eines Forums aus dem JSON je Sprache — sonst Deutsch, sonst der erste. */
    private static function title(string $json, string $locale): string
    {
        $titles = json_decode($json, true);
        if (! is_array($titles) || $titles === []) {
            return $json;
        }
        $title = $titles[$locale] ?? $titles['de'] ?? reset($titles);

        return is_string($title) ? $title : '';
    }
}
