<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Resources;

use Basalto\Core\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ein Medium in der REST-API (M-17): Datei, Metadaten mit Alt-Text je Sprache der Site, Fokuspunkt, Stand der
 * Varianten und die Adresse der Originaldatei (mit demselben Token abrufbar).
 */
final class MediaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Media $media */
        $media = $this->resource;
        $alt = [];
        foreach ($media->site->localeCodes() as $locale) {
            $alt[$locale] = $media->altFor($locale);
        }
        $variants = [];
        foreach ($media->variants ?? [] as $name => $file) {
            $variants[(string) $name] = ['width' => $file['width'], 'height' => $file['height']];
        }

        return [
            'id' => $media->id,
            'name' => $media->name,
            'title' => $media->title,
            'credit' => $media->credit,
            'alt' => $alt,
            'mime' => $media->mime,
            'size' => $media->size,
            'width' => $media->width,
            'height' => $media->height,
            'is_image' => $media->isImage(),
            'focus' => ['x' => $media->focus_x, 'y' => $media->focus_y],
            'folder_id' => $media->folder_id,
            'variants_state' => $media->variantsState(),
            'variants' => $variants,
            'file_url' => route('basalto.api.media.file', ['id' => $media->id]),
            'created_at' => $media->created_at?->toIso8601String(),
            'updated_at' => $media->updated_at?->toIso8601String(),
        ];
    }
}
