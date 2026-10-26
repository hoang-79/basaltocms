<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Basalto\Core\Search\SearchText;
use Basalto\Core\Search\SiteSearch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Scout\Searchable;

/**
 * Ein Suchdokument (M-12): veröffentlichter Inhalt in einer Sprache. Bewusst ohne Site-Scope — Scout kennt keine
 * globalen Scopes; jede Suche filtert die Site ausdrücklich mit `where('site_id', …)`.
 *
 * @property int $id
 * @property int $site_id
 * @property int|null $entry_id
 * @property string $source
 * @property int|null $source_id
 * @property string $locale
 * @property string $type
 * @property string $title
 * @property string $body
 * @property string $path
 * @property string|null $search_title
 * @property string|null $search_body
 * @property Carbon|null $published_at
 */
final class SearchDocument extends Model
{
    use Searchable;

    /** Index-Einstellungen für Meilisearch — der Kern setzt sie, `scout:sync-index-settings` überträgt sie. */
    public const INDEX_SETTINGS = ['filterableAttributes' => ['site_id', 'locale', 'source'], 'searchableAttributes' => ['title', 'body'], 'sortableAttributes' => ['published_at']];

    protected $fillable = ['site_id', 'entry_id', 'source', 'source_id', 'locale', 'type', 'title', 'body', 'path', 'published_at'];

    /** TB 71: Titel und Text gefaltet mitschreiben — danach sucht {@see SiteSearch}. */
    protected static function booted(): void
    {
        self::saving(static function (self $doc): void {
            if ($doc->isDirty('title') || $doc->search_title === null) {
                $doc->search_title = SearchText::fold($doc->title);
            }
            if ($doc->isDirty('body') || $doc->search_body === null) {
                $doc->search_body = SearchText::fold($doc->body);
            }
        });
    }

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    /**
     * Gesucht wird in Titel und Text. Der Datenbank-Treiber sucht mit `LIKE` in jedem Feld dieser Liste — Site und
     * Sprache stehen deshalb nur bei externen Treibern im Dokument (Meilisearch filtert `where` im Index, siehe
     * {@see self::INDEX_SETTINGS}); sonst fände «de» jedes deutsche Dokument.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $text = ['title' => $this->title, 'body' => $this->body];
        if (in_array(config('scout.driver'), ['database', 'collection', 'null', null], true)) {
            return $text;
        }

        return ['id' => $this->id, 'site_id' => $this->site_id, 'locale' => $this->locale, 'source' => $this->source, 'type' => $this->type] + $text + ['path' => $this->path, 'published_at' => $this->published_at?->getTimestamp()];
    }

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }
}
