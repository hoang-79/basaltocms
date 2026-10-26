<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum;

use Basalto\Core\Content\RichText;
use Basalto\Core\Models\Site;
use Basalto\Core\Search\SearchSources;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Thread;
use Illuminate\Support\Facades\DB;

/**
 * Das Forum in der Suche und der Sitemap (CR-006 FO-21, FO-22): je Thema ein Suchdokument (Titel, Schlagwörter,
 * Startbeitrag und Antworten) in der Sprache des Themas — nur Themen, die Gäste lesen dürfen, damit kein Treffer mehr
 * verrät als die Seite selbst. Nach jedem Schreiben und jeder Moderation aktuell; `basalto:search-index` baut neu.
 */
final class ForumSearch
{
    public const SOURCE = 'forum';

    public const TYPE = 'forum_thread';

    public function __construct(private readonly Forum $forum, private readonly SearchSources $sources) {}

    public function thread(int $id): void
    {
        $thread = Thread::query()->withoutGlobalScope(SiteScope::class)->find($id);
        $site = $thread === null ? null : Site::query()->find($thread->site_id);
        $node = $thread === null || $site === null ? null : $this->forum->nodes($site)->get($thread->node_id);
        if ($thread === null || $site === null || $node === null || ! $this->listed($site, $thread, $node)) {
            $this->sources->remove(self::SOURCE, $id);

            return;
        }
        $locale = $this->locale($site, $thread);
        $base = $this->forum->basePath($site, $locale);
        if ($base === null) {
            $this->sources->remove(self::SOURCE, $id);

            return;
        }
        $texts = Post::query()->withoutGlobalScope(SiteScope::class)->where('thread_id', $thread->id)->where('is_disabled', false)->orderBy('id')->limit(50)->get()
            ->map(static fn (Post $p): string => RichText::toText($p->body))->implode("\n");
        $tags = implode(' ', array_column(Tags::of([$thread->id])[$thread->id] ?? [], 'name'));
        $this->sources->put($site, self::SOURCE, $thread->id, $locale, self::TYPE, $thread->title, trim($tags."\n".$texts), $base.'/thema/'.$thread->pathSegment(), $thread->last_posted_at);
    }

    public function rebuild(Site $site): void
    {
        $this->forum->forget();
        DB::table('search_documents')->where('site_id', $site->id)->where('source', self::SOURCE)->delete();
        Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereNull('moved_to_id')->where('is_disabled', false)
            ->orderBy('id')->select('id')->chunk(500, function ($threads): void {
                foreach ($threads as $thread) {
                    $this->thread($thread->id);
                }
            });
    }

    /**
     * Themen für die Sitemap einer Sprache — dieselben, die auch in der Suche stehen.
     *
     * @return list<array{loc: string, lastmod: \DateTimeInterface|null, alternates: array<string, string>}>
     */
    public function sitemap(Site $site, string $locale, string $base): array
    {
        $prefix = $this->forum->basePath($site, $locale);
        if ($prefix === null) {
            return [];
        }
        $nodes = $this->forum->nodes($site)->filter(fn (Node $n): bool => $this->forum->can($site, null, Forum::READ, $n))->keys()->all();
        $out = [];
        Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereIn('node_id', $nodes)->where('is_disabled', false)->whereNull('moved_to_id')
            ->orderByDesc('last_posted_at')->limit(10000)->get(['id', 'slug', 'locale', 'last_posted_at'])
            ->each(function (Thread $thread) use (&$out, $site, $locale, $base, $prefix): void {
                if ($this->locale($site, $thread) === $locale) {
                    $out[] = ['loc' => rtrim($base, '/').$prefix.'/thema/'.$thread->pathSegment(), 'lastmod' => $thread->last_posted_at, 'alternates' => []];
                }
            });

        return $out;
    }

    private function listed(Site $site, Thread $thread, Node $node): bool
    {
        return ! $thread->is_disabled && ! $thread->trashed() && $thread->moved_to_id === null && $this->forum->can($site, null, Forum::READ, $node);
    }

    private function locale(Site $site, Thread $thread): string
    {
        return in_array($thread->locale, $site->localeCodes(), true) ? $thread->locale : $site->default_locale;
    }
}
