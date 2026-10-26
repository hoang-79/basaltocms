<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum;

use Basalto\Core\Models\Site;
use Basalto\Modules\Forum\Models\Thread;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Schlagwörter (CR-006 FO-21): beim Eröffnen und Bearbeiten mit Komma getrennt, höchstens fünf je Thema, je zwei bis
 * dreissig Zeichen; gleiche Wörter teilen sich ein Schlagwort der Site (`/community/tag/<slug>`).
 */
final class Tags
{
    public const MAX = 5;

    /** @return list<string> die Wörter aus der Eingabe, bereinigt */
    public static function parse(mixed $input): array
    {
        $words = [];
        foreach (explode(',', is_string($input) ? $input : '') as $word) {
            $word = trim((string) preg_replace('/\s+/u', ' ', $word));
            if (mb_strlen($word) >= 2 && mb_strlen($word) <= 30 && ! in_array(mb_strtolower($word), array_map('mb_strtolower', $words), true)) {
                $words[] = $word;
            }
        }

        return $words;
    }

    /** @param  list<string>  $words */
    public static function sync(Site $site, Thread $thread, array $words): void
    {
        $ids = [];
        foreach (array_slice($words, 0, self::MAX) as $word) {
            $slug = Str::slug($word, '-', 'de');
            if ($slug === '') {
                continue;
            }
            $existing = DB::table('forum_tags')->where('site_id', $site->id)->where('slug', $slug)->value('id');
            $ids[] = $existing !== null ? (int) $existing : (int) DB::table('forum_tags')->insertGetId(['site_id' => $site->id, 'name' => $word, 'slug' => $slug, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('forum_thread_tag')->where('thread_id', $thread->id)->delete();
        foreach (array_unique($ids) as $id) {
            DB::table('forum_thread_tag')->insert(['thread_id' => $thread->id, 'tag_id' => $id]);
        }
    }

    /**
     * @param  list<int>  $threadIds
     * @return array<int, list<array{name: string, slug: string}>>
     */
    public static function of(array $threadIds): array
    {
        if ($threadIds === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('forum_thread_tag')->join('forum_tags', 'forum_tags.id', '=', 'forum_thread_tag.tag_id')->whereIn('thread_id', $threadIds)
            ->orderBy('forum_tags.name')->get(['thread_id', 'name', 'slug']) as $row) {
            $out[(int) $row->thread_id][] = ['name' => (string) $row->name, 'slug' => (string) $row->slug];
        }

        return $out;
    }

    /** @return list<array{name: string, slug: string, count: int}> die häufigsten Schlagwörter der Site */
    public static function popular(Site $site, int $limit = 20): array
    {
        return array_values(DB::table('forum_tags')->join('forum_thread_tag', 'forum_thread_tag.tag_id', '=', 'forum_tags.id')
            ->join('forum_threads', 'forum_threads.id', '=', 'forum_thread_tag.thread_id')->where('forum_tags.site_id', $site->id)
            ->where('forum_threads.is_disabled', false)->whereNull('forum_threads.deleted_at')
            ->groupBy('forum_tags.id', 'forum_tags.name', 'forum_tags.slug')->orderByRaw('count(*) desc')->orderBy('forum_tags.name')->limit($limit)
            ->get(['forum_tags.name', 'forum_tags.slug', DB::raw('count(*) as n')])->map(static fn (object $r): array => ['name' => (string) $r->name, 'slug' => (string) $r->slug, 'count' => (int) $r->n])->all());
    }
}
