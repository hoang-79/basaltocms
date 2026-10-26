<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Frontend;

use Basalto\Core\Content\RichText;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Thread;

/**
 * Die Reiter des Forums im Profil (CR-006 FO-18): die letzten Beiträge und Themen einer Person — nur aus Foren, die
 * die betrachtende Person lesen darf.
 */
final class ProfileLists
{
    /** @param  array<int|string>  $readable */
    public static function posts(Site $site, Customer $owner, array $readable, string $lang, string $base): string
    {
        $threads = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', false)->whereNull('moved_to_id')->whereIn('node_id', $readable);
        $posts = Post::query()->withoutGlobalScope(SiteScope::class)->where('customer_id', $owner->id)->where('is_disabled', false)->whereIn('thread_id', (clone $threads)->select('id'))
            ->orderByDesc('id')->limit(20)->get();
        $titles = $threads->whereIn('id', $posts->pluck('thread_id'))->pluck('title', 'id');
        $items = '';
        foreach ($posts as $post) {
            $items .= sprintf('<li class="forum-fall"><p class="forum-fall__kopf"><a href="%s">%s</a> · %s</p><blockquote>%s</blockquote></li>', Markup::e($base.'/beitrag/'.$post->id),
                Markup::e((string) ($titles[$post->thread_id] ?? '')), Markup::time($post->created_at, $lang), Markup::e(mb_substr(RichText::toText($post->body), 0, 200)));
        }

        return $items === '' ? sprintf('<p class="forum-empty">%s</p>', Markup::e(__('Keine Beiträge, die Sie lesen dürfen.', [], $lang))) : '<ol class="forum-faelle">'.$items.'</ol>';
    }

    /** @param  array<int|string>  $readable */
    public static function threads(Site $site, Customer $owner, array $readable, string $lang, string $base): string
    {
        $threads = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('customer_id', $owner->id)->where('is_disabled', false)->whereNull('moved_to_id')
            ->whereIn('node_id', $readable)->orderByDesc('id')->limit(20)->get();
        $items = '';
        foreach ($threads as $thread) {
            $items .= sprintf('<li class="forum-fall"><p class="forum-fall__kopf"><a href="%s">%s</a> · %s · %s</p></li>', Markup::e($base.'/thema/'.$thread->pathSegment()), Markup::e($thread->title),
                Markup::time($thread->created_at, $lang), Markup::e(trans_choice(':count Antwort|:count Antworten', $thread->replies, ['count' => $thread->replies], $lang)));
        }

        return $items === '' ? sprintf('<p class="forum-empty">%s</p>', Markup::e(__('Keine Themen, die Sie lesen dürfen.', [], $lang))) : '<ol class="forum-faelle">'.$items.'</ol>';
    }
}
