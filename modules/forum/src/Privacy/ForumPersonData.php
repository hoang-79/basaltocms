<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Privacy;

use Basalto\Core\Content\RichText;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Counters;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Thread;
use Illuminate\Support\Facades\DB;

/**
 * Das Forum im Datenschutz des Kerns (N-05, CR-006 FO-23): Auskunft über Themen und Beiträge eines Kundenkontos.
 * Beim Löschen bleiben Beiträge stehen — sie gehören zur Diskussion anderer —, verlieren aber jede Verbindung zur
 * Person (`customer_id` leer, IP-Prüfsumme weg); das Profil anonymisiert der Kern. Seit TB 41 lässt sich je Site
 * wählen, dass die Beiträge stattdessen gelöscht werden (`forum.privacy_posts`).
 */
final class ForumPersonData
{
    /** @return array<string, mixed> */
    public function __invoke(string $email, bool $delete): array
    {
        $ids = Customer::byEmail($email)->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        $posts = Post::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->whereIn('customer_id', $ids);
        if (! $delete) {
            $list = (clone $posts)->orderBy('id')->get();

            return $list->isEmpty() ? [] : ['forum' => ['beitraege' => $list->map(static fn (Post $p): array => [
                'site' => $p->site_id, 'thema' => $p->thread_id, 'zeit' => $p->created_at?->toIso8601String(), 'text' => RichText::toText($p->body),
            ])->values()->all()]];
        }
        $count = (clone $posts)->count();
        // TB 41 (FO-23): je Site gewählt — Beiträge löschen statt anonymisieren
        foreach ((clone $posts)->pluck('site_id')->unique() as $siteId) {
            $site = Site::query()->whereKey($siteId)->first();
            if ($site === null || app(Settings::class)->get('forum.privacy_posts', 'anonymisieren', $site) !== 'loeschen') {
                continue;
            }
            $mine = Post::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->where('site_id', $site->id)->whereIn('customer_id', $ids)->get();
            $threads = array_values($mine->pluck('thread_id')->unique()->map(static fn (mixed $id): int => (int) $id)->all());
            DB::table('community_reactions')->where('subject_type', Forum::ATTACHMENTS)->whereIn('subject_id', $mine->pluck('id'))->delete();
            DB::table('forum_post_revisions')->whereIn('post_id', $mine->pluck('id'))->delete();
            Post::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->whereIn('id', $mine->pluck('id'))->forceDelete();
            // Themen ohne Beiträge anderer verschwinden ganz
            foreach ($threads as $threadId) {
                if (! Post::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->where('thread_id', $threadId)->exists()) {
                    Thread::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->whereKey($threadId)->forceDelete();
                }
            }
            app(Counters::class)->recount($site, $threads);
        }
        $posts->update(['customer_id' => null, 'ip_hash' => null]);
        Thread::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->whereIn('customer_id', $ids)->update(['customer_id' => null]);
        Thread::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->whereIn('last_customer_id', $ids)->update(['last_customer_id' => null]);

        return $count === 0 ? [] : ['forum_beitraege_anonymisiert' => $count];
    }
}
