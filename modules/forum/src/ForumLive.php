<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum;

use Basalto\Core\Live\Live;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Thread;

/**
 * Live-Aktualisierung im Forum (CR-008): Schlüssel für das offene Thema, die Themenliste eines Forums (mit seinen
 * Vorfahren, deren Übersicht den letzten Beitrag zeigt) und die Übersicht. Gemeldet wird nur, was alle sehen dürfen,
 * die das Thema lesen — wartende Beiträge erst beim Freischalten.
 */
final class ForumLive
{
    public const OVERVIEW = 'forum.uebersicht';

    public function __construct(private readonly Live $live, private readonly Forum $forum) {}

    public static function thread(int $id): string
    {
        return 'forum.thema.'.$id;
    }

    public static function node(int $id): string
    {
        return 'forum.forum.'.$id;
    }

    /** TB 49: Ort für «Wer schreibt gerade» in einem Thema (der Live-Schlüssel ist `Typing::key()` davon). */
    public static function typing(int $threadId): string
    {
        return 'forum.'.$threadId;
    }

    /** Ein sichtbarer Beitrag ist dazugekommen (Antwort, neues Thema, Freischaltung). */
    public function posted(Site $site, Thread $thread): void
    {
        $this->live->signal($site->id, self::thread($thread->id));
        $node = Node::query()->withoutGlobalScope(SiteScope::class)->find($thread->node_id);
        foreach ($node === null ? [] : $this->forum->chain($site, $node) as [, $id]) {
            $this->live->signal($site->id, self::node((int) $id));
        }
        $this->live->signal($site->id, self::OVERVIEW);
    }
}
