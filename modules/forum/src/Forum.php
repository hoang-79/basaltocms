<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum;

use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\Mounts;
use Basalto\Core\Community\VisitorAccess;
use Basalto\Core\Content\Pages;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Thread;
use Illuminate\Support\Collection;

/**
 * Das Forum einer Site: Baum, Rechte je Knoten, Adressen. Die Knoten einer Site lädt es einmal je Anfrage (scoped).
 */
final class Forum
{
    public const MOUNT = 'forum';

    /** Rechte des Forums (Besucherrechte des Kerns, je Forum überschreibbar). */
    public const VIEW = 'forum.view';

    public const READ = 'forum.read';

    public const START = 'forum.start';

    public const REPLY = 'forum.reply';

    public const MODERATE = 'forum.moderate';

    public const EDIT_OWN = 'forum.edit_own';

    /** TB 39 (FO-17): ohne Freischaltung schreiben — wer es nicht hat, schreibt in die Warteschlange der Moderation. */
    public const NO_APPROVAL = 'forum.no_approval';

    public const SUBJECT = 'forum_node';

    /** Anhänge gehören zu Beiträgen. */
    public const ATTACHMENTS = 'forum_post';

    /** @var array<int, Collection<int, Node>> */
    private array $nodes = [];

    public function __construct(private readonly VisitorAccess $access) {}

    /** @return Collection<int, Node> alle Knoten der Site, nach Position */
    public function nodes(Site $site): Collection
    {
        return $this->nodes[$site->id] ??= Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->orderBy('position')->orderBy('id')->get()->keyBy('id');
    }

    public function forget(): void
    {
        $this->nodes = [];
    }

    /** @return Collection<int, Node> Kinder eines Knotens (null = oberste Ebene) */
    public function children(Site $site, ?int $parentId): Collection
    {
        return $this->nodes($site)->filter(static fn (Node $n): bool => $n->parent_id === $parentId)->values();
    }

    public function bySlug(Site $site, string $slug): ?Node
    {
        return $this->nodes($site)->first(static fn (Node $n): bool => $n->slug === $slug);
    }

    /**
     * Die Kette für die Rechte: der Knoten, dann seine Vorfahren.
     *
     * @return list<array{0: string, 1: int}>
     */
    public function chain(Site $site, Node $node): array
    {
        $chain = [];
        $nodes = $this->nodes($site);
        for ($current = $node, $guard = 0; $current !== null && $guard < 50; $current = $current->parent_id === null ? null : $nodes->get($current->parent_id), $guard++) {
            $chain[] = [self::SUBJECT, $current->id];
        }

        return $chain;
    }

    /** @return list<Node> Vorfahren von oben nach unten (ohne den Knoten) */
    public function ancestors(Site $site, Node $node): array
    {
        $nodes = $this->nodes($site);
        $out = [];
        for ($current = $node->parent_id === null ? null : $nodes->get($node->parent_id), $guard = 0; $current !== null && $guard < 50; $current = $current->parent_id === null ? null : $nodes->get($current->parent_id), $guard++) {
            array_unshift($out, $current);
        }

        return $out;
    }

    public function can(Site $site, ?Customer $customer, string $permission, Node $node): bool
    {
        // Sehen setzt voraus, dass man auch jeden Vorfahren sieht
        if ($permission === self::VIEW || $permission === self::READ) {
            foreach ($this->ancestors($site, $node) as $ancestor) {
                if (! $this->access->allows($site, $customer, self::VIEW, $this->chain($site, $ancestor))) {
                    return false;
                }
            }
        }
        if ($permission !== self::VIEW && ! $this->access->allows($site, $customer, self::VIEW, $this->chain($site, $node))) {
            return false;
        }

        return $this->access->allows($site, $customer, $permission, $this->chain($site, $node));
    }

    /** Moderiert die Person dieses Forum (an Ort und Stelle)? */
    public function canModerate(Site $site, ?Customer $customer, Node $node): bool
    {
        return $customer !== null && $this->can($site, $customer, self::MODERATE, $node);
    }

    /**
     * Moderiert die Person irgendwo im Forum — für die Moderationsliste im Frontend, Verwarnungen und «Als Spammer
     * entfernen».
     */
    public function moderatesSomewhere(Site $site, ?Customer $customer): bool
    {
        if ($customer === null) {
            return false;
        }
        foreach ($this->nodes($site) as $node) {
            if ($this->canModerate($site, $customer, $node)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> die Foren, die die Person moderiert */
    public function moderatedNodes(Site $site, ?Customer $customer): array
    {
        return $customer === null ? [] : array_values($this->nodes($site)->filter(fn (Node $n): bool => $this->canModerate($site, $customer, $n))->keys()->map(static fn (mixed $id): int => (int) $id)->all());
    }

    /** Muss ein neuer Beitrag der Person in diesem Forum erst freigeschaltet werden? */
    public function needsApproval(Site $site, Customer $customer, Node $node): bool
    {
        return ! $this->can($site, $customer, self::NO_APPROVAL, $node) && ! $this->canModerate($site, $customer, $node);
    }

    /** Darf die Person in diesem Thema antworten? Geschlossene Themen und Foren nur mit «Moderieren». */
    public function canReply(Site $site, ?Customer $customer, Thread $thread, Node $node): bool
    {
        if ($customer === null || ! $this->can($site, $customer, self::REPLY, $node)) {
            return false;
        }

        return ! ($thread->is_closed || $node->isClosed()) || $this->can($site, $customer, self::MODERATE, $node);
    }

    public function canStart(Site $site, ?Customer $customer, Node $node): bool
    {
        return $customer !== null && $node->type === NodeType::Board && $this->can($site, $customer, self::START, $node)
            && (! $node->isClosed() || $this->can($site, $customer, self::MODERATE, $node));
    }

    /**
     * Darf die Person diesen Beitrag bearbeiten? Moderation immer; die Verfasserin mit «Eigene Beiträge bearbeiten»,
     * innerhalb des Zeitfensters (`forum.edit_minutes`, 0 = ohne Grenze) und solange Thema und Forum offen sind.
     */
    public function canEdit(Site $site, ?Customer $customer, Post $post, Thread $thread, Node $node): bool
    {
        if ($customer === null) {
            return false;
        }
        if ($this->can($site, $customer, self::MODERATE, $node)) {
            return true;
        }
        if ($post->customer_id !== $customer->id || $post->is_edit_locked || ! $this->can($site, $customer, self::EDIT_OWN, $node) || $thread->is_closed || $node->isClosed()) {
            return false;
        }
        $minutes = $this->editMinutes($site);

        return $minutes === 0 || $post->created_at === null || $post->created_at->gt(now()->subMinutes($minutes));
    }

    /** Offiziell schreiben (FO-12): das Team (verknüpfte Konten) und wer moderieren darf. */
    public function canMarkOfficial(Site $site, ?Customer $customer, Node $node): bool
    {
        if ($customer === null) {
            return false;
        }

        return CommunityProfile::query()->withoutGlobalScopes()->where('customer_id', $customer->id)->whereNotNull('user_id')->exists()
            || $this->can($site, $customer, self::MODERATE, $node);
    }

    /** Die hilfreichste Antwort wählt, wer das Thema eröffnet hat — oder die Moderation. */
    public function canMarkBest(Site $site, ?Customer $customer, Thread $thread, Node $node): bool
    {
        return $customer !== null && ($thread->customer_id === $customer->id || $this->can($site, $customer, self::MODERATE, $node));
    }

    /** Den Verlauf sehen Moderation und die Verfasserin. */
    public function canSeeHistory(Site $site, ?Customer $customer, Post $post, Node $node): bool
    {
        return $customer !== null && ($post->customer_id === $customer->id || $this->can($site, $customer, self::MODERATE, $node));
    }

    public function editMinutes(Site $site): int
    {
        return max(0, (int) app(Settings::class)->get('forum.edit_minutes', 0, $site));
    }

    /** Die Adresse der Einhängeseite des Forums in einer Sprache (mit Präfix, ohne Schrägstrich am Ende) oder null. */
    public function basePath(Site $site, string $locale): ?string
    {
        $entry = app(Mounts::class)->entryFor($site, self::MOUNT);
        $url = $entry === null ? null : app(Pages::class)->urlOf($entry, $locale);

        return $url === null ? null : rtrim($url, '/');
    }
}
