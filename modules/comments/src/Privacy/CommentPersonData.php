<?php

declare(strict_types=1);

namespace Basalto\Modules\Comments\Privacy;

use Basalto\Core\Cache\PageCache;
use Basalto\Core\Models\Site;
use Basalto\Core\Privacy\PersonScope;
use Basalto\Core\Settings\Settings;
use Basalto\Modules\Comments\CommentStatus;
use Basalto\Modules\Comments\Models\Comment;

/**
 * Kommentare im Datenschutz des Kerns (N-05, M-27): Auskunft und Löschung über die E-Mail-Adresse
 * (`Privacy\PersonData`) und die Frist im Löschlauf (`basalto:cleanup`) — gelöschte und als Spam markierte Kommentare
 * nach `comments.retention_days` endgültig weg, freigegebene und wartende bleiben.
 */
final class CommentPersonData
{
    /**
     * Auskunft (`$delete` falsch) oder Löschung über alle Sites.
     *
     * @return array{kommentare: list<array<string, mixed>>}|array{kommentare: int}
     */
    public function __invoke(string $email, bool $delete): array
    {
        // CR-011 TB 61: aus dem Kundenkonto nur die Kommentare dieser Site
        $site = app(PersonScope::class)->siteId();
        $query = Comment::query()->withoutGlobalScopes()->whereRaw('LOWER(author_email) = ?', [mb_strtolower(trim($email))])->when($site !== null, static fn ($q) => $q->where('site_id', $site));
        if (! $delete) {
            return ['kommentare' => array_values($query->orderBy('id')->get()->map(static fn (Comment $c): array => [
                'site' => $c->site_id, 'inhalt' => $c->entry_id, 'antwort_auf' => $c->parent_id, 'sprache' => $c->locale, 'zeit' => $c->created_at?->toIso8601String(),
                'status' => $c->status->value, 'name' => $c->author_name, 'website' => $c->author_url, 'text' => $c->body, 'zustimmung' => $c->consent_at?->toIso8601String(),
            ])->all())];
        }
        $sites = (clone $query)->distinct()->pluck('site_id')->all();
        $count = 0;
        foreach ($query->get() as $comment) {
            $comment->delete();
            $count++;
        }
        foreach ($sites as $siteId) {
            app(PageCache::class)->invalidate((int) $siteId);
        }

        return ['kommentare' => $count];
    }

    /**
     * Der Löschlauf: je Site die eigene Frist.
     *
     * @return array{Kommentare: int}
     */
    public static function cleanup(Settings $settings): array
    {
        $count = 0;
        foreach (Site::query()->get() as $site) {
            $days = (int) $settings->get('comments.retention_days', 365, $site);
            $count += Comment::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereIn('status', [CommentStatus::Spam->value, CommentStatus::Deleted->value])
                ->where('updated_at', '<', now()->subDays($days))->delete();
        }

        return ['Kommentare' => $count];
    }
}
