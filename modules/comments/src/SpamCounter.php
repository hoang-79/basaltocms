<?php

declare(strict_types=1);

namespace Basalto\Modules\Comments;

use Basalto\Core\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Der Spam-Zähler je Site (M-27): erkannter Spam wird nie gespeichert, nur je Tag gezählt — die Moderation zeigt die
 * letzten 30 Tage und die Summe. Als Spam markierte Kommentare zählen beim Markieren mit.
 */
final class SpamCounter
{
    public const WINDOW_DAYS = 30;

    public function count(int $siteId): void
    {
        $day = now()->toDateString();
        $row = ['site_id' => $siteId, 'day' => $day];
        if (DB::table('comment_spam_counts')->where($row)->increment('count') === 0) {
            DB::table('comment_spam_counts')->insertOrIgnore($row + ['count' => 0]);
            DB::table('comment_spam_counts')->where($row)->increment('count');
        }
    }

    /** @return array{recent: int, total: int} */
    public function totals(Site $site): array
    {
        $query = DB::table('comment_spam_counts')->where('site_id', $site->id);

        return ['recent' => (int) (clone $query)->where('day', '>=', now()->subDays(self::WINDOW_DAYS - 1)->toDateString())->sum('count'), 'total' => (int) $query->sum('count')];
    }
}
