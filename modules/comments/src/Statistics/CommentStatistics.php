<?php

declare(strict_types=1);

namespace Basalto\Modules\Comments\Statistics;

use Basalto\Core\Analytics\Period;
use Basalto\Core\Extranet\Statistics\MetricSection;
use Basalto\Core\Models\Site;
use Basalto\Core\Support\Text;
use Basalto\Modules\Comments\CommentStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Abschnitt «Kommentare» unter Verwaltung › Statistik (CR-019, TB 109): neue und wartende Kommentare, abgewehrter Spam
 * und die Seiten, unter denen am meisten geschrieben wird.
 */
final class CommentStatistics
{
    public static function section(Period $period, Site $site): MetricSection
    {
        $previous = $period->previous();
        $written = static fn (Period $p): Builder => DB::table('comments')->where('comments.site_id', $site->id)->whereIn('comments.status', [CommentStatus::Approved->value, CommentStatus::Pending->value])
            ->whereBetween('comments.created_at', $p->between());
        $spam = static fn (Period $p): int => (int) DB::table('comment_spam_counts')->where('site_id', $site->id)->whereBetween('day', [$p->from->toDateString(), $p->to->toDateString()])->sum('count')
            + DB::table('comments')->where('site_id', $site->id)->where('status', CommentStatus::Spam->value)->whereBetween('created_at', $p->between())->count();
        $pages = $written($period)->join('entry_versions', static fn ($join) => $join->on('entry_versions.entry_id', '=', 'comments.entry_id')->on('entry_versions.locale', '=', 'comments.locale')
            ->where('entry_versions.status', 'published'))->selectRaw('entry_versions.title as title, COUNT(*) as n')->groupBy('entry_versions.title')->orderByDesc('n')->limit(10)->get();

        return (new MetricSection('kommentare', Text::t('Kommentare')))
            ->lead(Text::t('Kommentare unter Ihren Seiten und Beiträgen — geschrieben, wartend und als Spam abgewehrt.'))
            ->link(Text::t('Kommentare öffnen'), route('basalto.comments.index'))
            ->kpi(Text::t('Neue Kommentare'), $written($period)->count(), $written($previous)->count(), headline: true)
            ->kpi(Text::t('Warten auf Freigabe'), DB::table('comments')->where('site_id', $site->id)->where('status', CommentStatus::Pending->value)->count())
            ->kpi(Text::t('Abgewehrter Spam'), $spam($period), $spam($previous))
            ->series(Text::t('Neue Kommentare je Tag'), $period->daily($written($period), 'comments.created_at'))
            ->list(Text::t('Am meisten kommentiert'), $pages->map(static fn (object $row): array => ['label' => (string) $row->title, 'value' => (int) $row->n]),
                empty: Text::t('Im Zeitraum kam kein Kommentar.'));
    }
}
