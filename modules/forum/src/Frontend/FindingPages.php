<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Frontend;

use Basalto\Core\Community\MountResult;
use Basalto\Core\Content\RichText;
use Basalto\Core\Search\SiteSearch;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\ForumSearch;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Thread;
use Basalto\Modules\Forum\NodeType;
use Basalto\Modules\Forum\Tags;
use Illuminate\Support\Facades\DB;

/**
 * Finden im Forum (CR-006 FO-21, FO-22), als Teil von {@see ForumMount}: Suche in den Themen (derselbe Index wie die
 * Site-Suche), Schlagwort-Seiten, RSS-Feeds für das ganze Forum und je Forum, strukturierte Daten
 * (`DiscussionForumPosting`) am Thema.
 */
trait FindingPages
{
    private string $extraHead = '';

    private function searchPage(): MountResult
    {
        $lang = $this->lang();
        $q = trim(mb_substr((string) $this->r->query('q'), 0, 100));
        $title = $this->t('Suche im Forum');
        $form = sprintf('<form class="forum-suche" role="search" method="get" action="%s"><label for="forum-suche-q" class="visually-hidden">%s</label><input id="forum-suche-q" type="search" name="q" value="%s" minlength="2" maxlength="100" placeholder="%2$s"><button type="submit" class="forum-button">%s %s</button></form>',
            $this->e($this->r->url('suche')), $this->e($this->t('Suchbegriff')), $this->e($q), Markup::mini('search'), $this->e($this->t('Suchen')));
        $html = $this->bar().$form;
        if (mb_strlen($q) >= 2) {
            $result = app(SiteSearch::class)->search($this->r->site, $lang, $q, $this->r->pageNumber(), ForumSearch::SOURCE);
            $items = '';
            foreach ($result['items'] as $item) {
                $items .= sprintf('<li class="forum-fall"><span class="forum-sechseck forum-sechseck--forum" aria-hidden="true">%s</span><p class="forum-fall__kopf"><a href="%s"><b>%s</b></a></p><p>%s</p></li>', Markup::icon('chat'), $this->e($item['path']), $this->e($item['title']), $item['excerpt']);
            }
            $html .= sprintf('<p class="forum-note" role="status">%s</p>', $this->e(trans_choice(':count Thema gefunden|:count Themen gefunden', $result['total'], ['count' => $result['total']], $lang)));
            if ($items !== '') {
                $html .= sprintf('<section class="forum-section"><ol class="forum-faelle">%s</ol></section>', $items)
                    .Markup::pagination($result['page'], $result['pages'], fn (int $p): string => $this->r->url('suche', ['q' => $q, 'seite' => $p > 1 ? $p : null]), $lang);
            }
        }
        $tags = Tags::popular($this->r->site);
        if ($tags !== []) {
            $html .= sprintf('<section class="forum-kasten"><h2>%s%s</h2><p class="forum-schlagwoerter">%s</p></section>', Markup::icon('hash'), $this->e($this->t('Häufige Schlagwörter')), $this->tagLinks($tags));
        }

        return $this->page($html, $title, [['label' => $title, 'href' => $this->r->url('suche')]], null, $q !== '', $q !== '');
    }

    private function tagPage(string $slug): MountResult
    {
        $site = $this->r->site;
        $tag = DB::table('forum_tags')->where('site_id', $site->id)->where('slug', $slug)->first();
        if ($tag === null) {
            return MountResult::notFound();
        }
        $lang = $this->lang();
        $readable = $this->forum->nodes($site)->filter(fn (Node $n): bool => $this->forum->can($site, $this->customer, Forum::READ, $n))->keys()->all();
        $query = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', false)->whereNull('moved_to_id')->whereIn('node_id', $readable)
            ->whereIn('id', DB::table('forum_thread_tag')->where('tag_id', $tag->id)->select('thread_id'));
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / self::THREADS_PER_PAGE));
        $page = min($this->r->pageNumber(), $pages);
        $threads = $query->orderByDesc('last_posted_at')->forPage($page, self::THREADS_PER_PAGE)->get();
        $title = $this->t('Schlagwort «:name»', ['name' => (string) $tag->name]);
        $list = $threads->isEmpty() ? sprintf('<section class="forum-section"><p class="forum-empty">%s</p></section>', $this->e($this->t('Keine Themen mit diesem Schlagwort.')))
            : sprintf('<section class="forum-section"><ol class="forum-list">%s</ol></section>', $this->threadRows(array_values($threads->all()), true))
                .Markup::pagination($page, $pages, fn (int $p): string => $this->r->url('tag/'.$slug, ['seite' => $p > 1 ? $p : null]), $lang);

        return $this->page($this->bar().$list, $title, [['label' => $title, 'href' => $this->r->url('tag/'.$slug)]]);
    }

    /** @param  list<array{name: string, slug: string}>  $tags */
    private function tagLinks(array $tags): string
    {
        return implode(' ', array_map(fn (array $t): string => sprintf('<a class="forum-schlagwort" href="%s">#%s</a>', $this->e($this->r->url('tag/'.$t['slug'])), $this->e($t['name'])), $tags));
    }

    /** RSS 2.0 — das ganze Forum oder ein Forum; nur, was Gäste lesen dürfen. */
    private function feed(?string $slug = null): MountResult
    {
        $site = $this->r->site;
        $node = $slug === null ? null : $this->forum->bySlug($site, $slug);
        if ($slug !== null && ($node === null || $node->type !== NodeType::Board || ! $this->forum->can($site, null, Forum::READ, $node))) {
            return MountResult::notFound();
        }
        $nodes = $node !== null ? [$node->id] : $this->forum->nodes($site)->filter(fn (Node $n): bool => $this->forum->can($site, null, Forum::READ, $n))->keys()->all();
        $threads = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereIn('node_id', $nodes)->where('is_disabled', false)->whereNull('moved_to_id')
            ->orderByDesc('created_at')->limit(30)->get();
        $firsts = Post::query()->withoutGlobalScope(SiteScope::class)->whereIn('id', $threads->pluck('first_post_id')->filter())->get()->keyBy('id');
        $host = $this->r->request->getSchemeAndHttpHost();
        $people = new People($site, $threads->pluck('customer_id')->all());
        $lang = $this->lang();
        $x = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $items = '';
        foreach ($threads as $thread) {
            $link = $host.$this->r->url('thema/'.$thread->pathSegment());
            $first = $firsts->get((int) $thread->first_post_id);
            $items .= sprintf('<item><title>%s</title><link>%s</link><guid isPermaLink="true">%2$s</guid><pubDate>%s</pubDate><dc:creator>%s</dc:creator><description>%s</description></item>',
                $x($thread->title), $x($link), $thread->created_at?->toRfc2822String() ?? '', $x($people->name($thread->customer_id, $lang)),
                $x(mb_substr(RichText::toText($first === null ? [] : $first->body), 0, 400)));
        }
        $title = $node !== null ? $node->titleIn($lang).' — '.$site->name : $this->t('Forum').' — '.$site->name;
        $self = $host.$this->r->url($slug === null ? 'feed' : $slug.'/feed');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".sprintf('<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><title>%s</title><link>%s</link><description>%s</description><language>%s</language><atom:link href="%s" rel="self" type="application/rss+xml"/>%s</channel></rss>',
            $x($title), $x($host.$this->r->url($slug ?? '')), $x($this->t('Neue Themen')), $x($lang), $x($self), $items);

        return MountResult::raw($xml, 'application/rss+xml; charset=UTF-8');
    }

    /** `<link rel="alternate">` für den Feed einer Übersicht oder eines Forums. */
    private function feedLink(?Node $node = null): void
    {
        $url = $this->r->url($node === null ? 'feed' : $node->slug.'/feed');
        $this->extraHead .= sprintf('<link rel="alternate" type="application/rss+xml" title="%s" href="%s">', $this->e($node?->titleIn($this->lang()) ?? $this->t('Forum')), $this->e($url));
    }

    /**
     * Strukturierte Daten am Thema (FO-22): `DiscussionForumPosting` mit Autor, Datum, Antworten und den Beiträgen der
     * Seite als `Comment` — wie Google sie für Foren liest.
     *
     * @param  list<Post>  $posts
     */
    private function structuredData(Thread $thread, array $posts, People $people): void
    {
        $host = $this->r->request->getSchemeAndHttpHost();
        $lang = $this->lang();
        $person = function (?int $id) use ($people, $host, $lang): array {
            $profile = $people->profile($id);

            return ['@type' => 'Person', 'name' => $people->name($id, $lang)] + ($profile === null ? [] : ['url' => $host.$this->r->url('mitglied/'.$profile->pathSegment())]);
        };
        $first = null;
        $comments = [];
        foreach ($posts as $post) {
            if ($post->id === $thread->first_post_id) {
                $first = $post;

                continue;
            }
            if ($post->is_disabled) {
                continue;
            }
            $comments[] = ['@type' => 'Comment', 'text' => mb_substr(RichText::toText($post->body), 0, 1000), 'datePublished' => $post->created_at?->toIso8601String(), 'author' => $person($post->customer_id),
                'url' => $host.$this->r->url('thema/'.$thread->pathSegment()).'#beitrag-'.$post->id];
        }
        $data = ['@context' => 'https://schema.org', '@type' => 'DiscussionForumPosting', 'headline' => $thread->title, 'url' => $host.$this->r->url('thema/'.$thread->pathSegment()),
            'datePublished' => $thread->created_at?->toIso8601String(), 'dateModified' => $thread->last_posted_at?->toIso8601String(), 'author' => $person($thread->customer_id),
            'interactionStatistic' => ['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/CommentAction', 'userInteractionCount' => $thread->replies]];
        if ($first !== null) {
            $data['text'] = mb_substr(RichText::toText($first->body), 0, 2000);
        }
        if ($comments !== []) {
            $data['comment'] = $comments;
        }
        $this->extraHead .= '<script type="application/ld+json">'.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).'</script>';
    }
}
