<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\PageType;
use Basalto\Core\Database\Factories\EntryFactory;
use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Ein Inhalt einer Site (Lava «type»); seine Sprachfassungen sind `EntryVersion`.
 *
 * @property int $id
 * @property int $site_id
 * @property string $type
 * @property string $uuid
 * @property int|null $parent_id
 * @property int $position
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $redirect_to
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class Entry extends Model
{
    use BelongsToSite;

    /** @use HasFactory<EntryFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = ['site_id', 'type', 'uuid', 'parent_id', 'position', 'settings', 'redirect_to', 'created_by'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'redirect_to' => 'array', 'position' => 'integer'];
    }

    protected static function booted(): void
    {
        self::creating(static function (Entry $entry): void {
            $entry->uuid ??= (string) Str::uuid();
        });
    }

    protected static function newFactory(): EntryFactory
    {
        return EntryFactory::new();
    }

    /** @return BelongsTo<Entry, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Entry, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    /** Der Seitentyp (Seite, Weiterleitung, Fehlerseite, Feed, Ordner) — null für Inhaltstypen aus Lava. */
    public function pageType(): ?PageType
    {
        return PageType::tryFrom($this->type);
    }

    /**
     * Ziel einer externen Weiterleitung in einer Sprache: die Adresse dieser Sprache, sonst die der Hauptsprache —
     * null bei internen Weiterleitungen und ohne Ziel.
     */
    public function externalRedirect(string $locale): ?string
    {
        $to = $this->redirect_to;
        if (! is_array($to) || ($to['kind'] ?? 'internal') !== 'external') {
            return null;
        }
        $localized = is_array($to['urls'] ?? null) ? ($to['urls'][$locale] ?? null) : null;
        $url = is_string($localized) && $localized !== '' ? $localized : ($to['url'] ?? null);

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** Eine sprachunabhängige Seiteneinstellung («layout.mode», «cache.shared_max_age», M-01). */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /** @return HasMany<EntryVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(EntryVersion::class);
    }

    public function latestVersion(string $locale): ?EntryVersion
    {
        return $this->versions()->where('locale', $locale)->orderByDesc('number')->first();
    }

    public function publishedVersion(string $locale): ?EntryVersion
    {
        return $this->versions()->where('locale', $locale)->where('status', EntryStatus::Published->value)->orderByDesc('number')->first();
    }

    /** @return list<string> Sprachen, in denen eine veröffentlichte Fassung existiert */
    public function publishedLocales(): array
    {
        $codes = [];
        foreach ($this->versions()->where('status', EntryStatus::Published->value)->get(['locale']) as $version) {
            $codes[$version->locale] = true;
        }

        return array_keys($codes);
    }

    /**
     * Die Fassung, die ein Leser in dieser Sprache sieht: veröffentlicht in der Sprache selbst, sonst entlang der
     * Fallback-Kette der Site bis zur Standardsprache (R-20). Null, wenn nirgends etwas veröffentlicht ist.
     */
    public function resolve(string $locale): ?EntryVersion
    {
        foreach ($this->site->fallbackChain($locale) as $candidate) {
            $version = $this->publishedVersion($candidate);
            if ($version !== null) {
                return $version;
            }
        }

        return null;
    }

    /**
     * Speichern aus Editor und Übersetzung (Autosave): Ist die neueste Fassung der Sprache ein Entwurf, wird er
     * weitergeschrieben (gleiche Nummer) — sonst entsteht ein neuer Entwurf. So entsteht nicht bei jedem Satz eine
     * Fassung; eine neue Fassung beginnt erst nach Einreichen, Freigabe oder Veröffentlichung — oder wenn eine
     * Übersetzung auf dem Entwurf beruht (er bleibt dann als Bezugspunkt stehen).
     *
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(string $locale, array $data, ?int $authorId = null, ?int $sourceVersionId = null): EntryVersion
    {
        $latest = $this->latestVersion($locale);
        // Eine Fassung, auf der eine Übersetzung beruht, bleibt eingefroren — sonst würde die Übersetzung nie «veraltet»
        $isSource = $latest !== null && EntryVersion::query()->where('source_version_id', $latest->id)->exists();
        if ($latest === null || $latest->status !== EntryStatus::Draft || $isSource) {
            return $this->newDraft($locale, $data, $authorId, $sourceVersionId);
        }
        $title = (string) ($data['title'] ?? '');
        $latest->forceFill([
            'title' => $title,
            'slug' => (string) ($data['slug'] ?? Str::slug($title)),
            'data' => $data,
            'source_version_id' => $sourceVersionId ?? $latest->source_version_id,
        ])->save();

        return $latest;
    }

    /**
     * Neuer Entwurf in einer Sprache — als nächste Nummer; Titel und Slug aus den Feldern. Eine Übersetzung nennt die
     * Fassung der Standardsprache, auf der sie beruht (M-06).
     *
     * @param  array<string, mixed>  $data
     */
    public function newDraft(string $locale, array $data, ?int $authorId = null, ?int $sourceVersionId = null): EntryVersion
    {
        $title = (string) ($data['title'] ?? '');
        $number = (int) $this->versions()->where('locale', $locale)->max('number') + 1;

        return $this->versions()->create([
            'locale' => $locale,
            'number' => $number,
            'status' => EntryStatus::Draft->value,
            'title' => $title,
            'slug' => (string) ($data['slug'] ?? Str::slug($title)),
            'data' => $data,
            'author_id' => $authorId,
            'source_version_id' => $sourceVersionId,
        ]);
    }
}
