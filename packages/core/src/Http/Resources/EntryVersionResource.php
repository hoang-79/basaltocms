<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Resources;

use Basalto\Core\Models\EntryVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Eine Sprachfassung in der REST-API (M-17): Nummer, Status, Titel, Slug, Felder und Blöcke (`data`) und die
 * Zeitpunkte des Workflows. Zeiten in ISO 8601 (UTC).
 */
final class EntryVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var EntryVersion $version */
        $version = $this->resource;

        return [
            'id' => $version->id,
            'locale' => $version->locale,
            'number' => $version->number,
            'status' => $version->status->value,
            'status_label' => $version->status->label(),
            'title' => $version->title,
            'slug' => $version->slug,
            'data' => $version->data,
            'source_version_id' => $version->source_version_id,
            'author_id' => $version->author_id,
            'submitted_at' => $version->submitted_at?->toIso8601String(),
            'approved_by' => $version->approved_by,
            'scheduled_for' => $version->scheduled_for?->toIso8601String(),
            'published_at' => $version->published_at?->toIso8601String(),
            'created_at' => $version->created_at?->toIso8601String(),
            'updated_at' => $version->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Die Kurzform für Listen — ohne Felder.
     *
     * @return array{id: int, number: int, status: string, title: string, slug: string, published_at: string|null, updated_at: string|null}
     */
    public static function summary(EntryVersion $version): array
    {
        return ['id' => $version->id, 'number' => $version->number, 'status' => $version->status->value, 'title' => $version->title, 'slug' => $version->slug,
            'published_at' => $version->published_at?->toIso8601String(), 'updated_at' => $version->updated_at?->toIso8601String()];
    }
}
