<?php

declare(strict_types=1);

namespace Basalto\Modules\Members\Statistics;

use Basalto\Core\Analytics\Period;
use Basalto\Core\Extranet\Statistics\MetricSection;
use Basalto\Core\Models\Site;
use Basalto\Core\Support\Text;
use Basalto\Modules\Members\MemberStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Abschnitt «Mitglieder» unter Verwaltung › Statistik (CR-019, TB 109): neue Mitglieder, aktive, wartende Anfragen.
 */
final class MemberStatistics
{
    public static function section(Period $period, Site $site): MetricSection
    {
        $previous = $period->previous();
        $joined = static fn (Period $p): Builder => DB::table('members')->where('site_id', $site->id)->where('status', MemberStatus::Active->value)->whereBetween('approved_at', $p->between());
        $count = static fn (MemberStatus $status): int => DB::table('members')->where('site_id', $site->id)->where('status', $status->value)->count();

        return (new MetricSection('mitglieder', Text::t('Mitglieder')))
            ->lead(Text::t('Mitglieder Ihres geschützten Bereichs — aufgenommen, aktiv und wartend.'))
            ->link(Text::t('Mitglieder öffnen'), route('basalto.members.index'))
            ->kpi(Text::t('Neue Mitglieder'), $joined($period)->count(), $joined($previous)->count(), headline: true)
            ->kpi(Text::t('Aktive Mitglieder'), $count(MemberStatus::Active), headline: true)
            ->kpi(Text::t('Anfragen offen'), $count(MemberStatus::Pending))
            ->series(Text::t('Neue Mitglieder je Tag'), $period->daily($joined($period), 'approved_at'));
    }
}
