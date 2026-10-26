<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum;

use Basalto\Core\Content\RichText;
use Basalto\Core\Models\Site;
use Basalto\Core\Search\SearchSources;
use Basalto\Core\Search\SearchText;
use Basalto\Core\Search\SiteSearch;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Frontend\Markup;
use Basalto\Modules\Forum\Frontend\People;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Thread;
use Illuminate\Support\Carbon;
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

    /** So viele Beiträge je Thema stehen im Suchdokument — und werden für den passenden Beitrag gelesen. */
    public const POSTS = 50;

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
        $texts = Post::query()->withoutGlobalScope(SiteScope::class)->where('thread_id', $thread->id)->where('is_disabled', false)->orderBy('id')->limit(self::POSTS)->get()
            ->map(static fn (Post $p): string => RichText::toText($p->body))->implode("\n");
        $tags = implode(' ', array_column(Tags::of([$thread->id])[$thread->id] ?? [], 'name'));
        $this->sources->put($site, self::SOURCE, $thread->id, $locale, self::TYPE, $thread->title, trim($tags."\n".$texts), $base.'/thema/'.$thread->pathSegment(), $thread->last_posted_at);
    }

    /**
     * TB 71: Für jedes gefundene Thema der Beitrag, in dem die Suche steht — mit der ganzen Wortfolge zuerst, sonst der
     * mit den meisten Wörtern, bei Gleichstand der frühere. Der Treffer führt direkt zum Beitrag und nennt, wer ihn wann
     * geschrieben hat. Steht die Suche nur im Titel oder in den Schlagwörtern, bleibt der Treffer beim Thema.
     *
     * @param  list<int>  $threadIds
     * @return array<int, array{path?: string, excerpt: string, meta: string}>
     */
    public function hits(Site $site, array $threadIds, string $query, string $locale): array
    {
        $words = SearchText::words($query);
        $base = $this->forum->basePath($site, $locale);
        if ($words === [] || $base === null) {
            return [];
        }
        $phrase = implode(' ', $words);
        $best = [];
        foreach ($threadIds as $threadId) {
            $posts = Post::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('thread_id', $threadId)->where('is_disabled', false)
                ->orderBy('id')->limit(self::POSTS)->get(['id', 'customer_id', 'body', 'created_at']);
            foreach ($posts->values() as $index => $post) {
                $text = RichText::toText($post->body);
                $folded = SearchText::fold($text);
                $score = str_contains($folded, $phrase) ? count($words) + 1 : count(array_filter($words, static fn (string $w): bool => str_contains($folded, $w)));
                if ($score > ($best[$threadId]['score'] ?? 0)) {
                    $best[$threadId] = ['score' => $score, 'post' => $post, 'number' => $index + 1, 'text' => $text];
                }
            }
        }
        $people = new People($site, array_values(array_map(static fn (array $b): ?int => $b['post']->customer_id, $best)));
        $hits = [];
        foreach ($best as $threadId => $b) {
            $post = $b['post'];
            $when = '';
            if ($post->created_at !== null) {
                $day = Carbon::instance($post->created_at)->copy();
                $day->locale($locale);
                $when = ' · '.$day->isoFormat(Markup::dayFormat($locale, false));
            }
            $hits[$threadId] = ['excerpt' => SiteSearch::excerpt($b['text'], $query),
                'meta' => $people->name($post->customer_id, $locale).' · '.__('Beitrag :number', ['number' => $b['number']], $locale).$when]
                + ($b['number'] > 1 ? ['path' => $base.'/beitrag/'.$post->id] : []);
        }

        return $hits;
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
