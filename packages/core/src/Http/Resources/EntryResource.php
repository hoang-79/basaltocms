<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Resources;

use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\Pages;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ein Inhalt in der REST-API (M-17): Typ, Platz im Seitenbaum und je Sprache der Site die neueste und die
 * veröffentlichte Fassung (Kurzform). Mit {@see withLocale()} kommt eine Sprache ganz dazu: die Fassung, die ein Leser
 * sieht (veröffentlicht, entlang der Fallback-Kette der Site), und der neueste Stand genau dieser Sprache.
 */
final class EntryResource extends JsonResource
{
    private ?string $locale = null;

    public function withLocale(?string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Entry $entry */
        $entry = $this->resource;
        $site = $entry->site;
        $versions = $entry->relationLoaded('versions') ? $entry->versions : $entry->versions()->get();
        $locales = [];
        foreach ($site->localeCodes() as $code) {
            $own = $versions->where('locale', $code)->sortByDesc('number');
            $latest = $own->first();
            $published = $own->first(static fn (EntryVersion $v): bool => $v->status === EntryStatus::Published);
            $locales[$code] = ['latest' => $latest === null ? null : EntryVersionResource::summary($latest), 'published' => $published === null ? null : EntryVersionResource::summary($published)];
        }
        $out = [
            'id' => $entry->id,
            'uuid' => $entry->uuid,
            'type' => $entry->type,
            'is_page' => $entry->pageType() !== null,
            'parent_id' => $entry->parent_id,
            'position' => $entry->position,
            'locales' => $locales,
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ];
        if ($this->locale !== null) {
            $resolved = $entry->resolve($this->locale);
            $draft = $entry->latestVersion($this->locale);
            $out['locale'] = [
                'requested' => $this->locale,
                'resolved' => $resolved?->locale,
                'fallback' => $resolved !== null && $resolved->locale !== $this->locale,
                'url' => $resolved === null ? null : app(Pages::class)->urlOf($entry, $resolved->locale),
                'published' => $resolved === null ? null : new EntryVersionResource($resolved),
                'latest' => $draft === null ? null : new EntryVersionResource($draft),
            ];
        }

        return $out;
    }
}
