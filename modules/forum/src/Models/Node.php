<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Models;

use Basalto\Core\Cache\PageCache;
use Basalto\Core\Sites\BelongsToSite;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\NodeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ein Knoten im Forenbaum (CR-006 FO-04): Kategorie, Forum oder Link. Titel und Beschreibung je Sprache; der Slug ist
 * je Site eindeutig und ergibt die Adresse `/community/<slug>`, egal wie tief das Forum liegt.
 *
 * @property int $id
 * @property int $site_id
 * @property int|null $parent_id
 * @property NodeType $type
 * @property string $slug
 * @property int $position
 * @property array<string, string> $title
 * @property array<string, string>|null $description
 * @property string|null $link_url
 * @property array<string, mixed>|null $settings
 * @property int $threads_count
 * @property int $posts_count
 * @property int|null $last_post_id
 */
final class Node extends Model
{
    use BelongsToSite;

    /** Pfade, die das Forum selbst belegt — kein Forum darf so heissen. */
    public const RESERVED = ['konto', 'thema', 'neu', 'ungelesen', 'aktuell', 'meine', 'abonniert', 'mitglieder', 'mitglied', 'team', 'online',
        'nachrichten', 'benachrichtigungen', 'suche', 'feed', 'avatar', 'profile', 'markieren', 'moderation', 'beitrag', 'konversationen', 'aktivitaet', 'tag'];

    protected $table = 'forum_nodes';

    protected $fillable = ['site_id', 'parent_id', 'type', 'slug', 'position', 'title', 'description', 'link_url', 'settings'];

    /** Jede Änderung am Baum macht die Seiten der Site veraltet — egal, ob aus dem Extranet, einem Befehl oder einem Seed. */
    protected static function booted(): void
    {
        $changed = static function (self $node): void {
            app(Forum::class)->forget();
            app(PageCache::class)->invalidate($node->site_id);
        };
        self::saved($changed);
        self::deleted($changed);
    }

    protected function casts(): array
    {
        return ['type' => NodeType::class, 'title' => 'array', 'description' => 'array', 'settings' => 'array', 'position' => 'integer',
            'threads_count' => 'integer', 'posts_count' => 'integer'];
    }

    /** @return BelongsTo<Node, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Thread, $this> */
    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    /** @return BelongsTo<Post, $this> */
    public function lastPost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'last_post_id');
    }

    /** Titel in der Sprache, sonst in der ersten vorhandenen. */
    public function titleIn(string $locale): string
    {
        return self::text($this->title, $locale);
    }

    public function descriptionIn(string $locale): string
    {
        return self::text($this->description ?? [], $locale);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    public function isClosed(): bool
    {
        return (bool) $this->setting('closed', false);
    }

    /** @param  array<string, string>  $values */
    private static function text(array $values, string $locale): string
    {
        $value = $values[$locale] ?? null;
        if ($value !== null && trim($value) !== '') {
            return $value;
        }
        foreach ($values as $other) {
            if (trim($other) !== '') {
                return $other;
            }
        }

        return '';
    }
}
