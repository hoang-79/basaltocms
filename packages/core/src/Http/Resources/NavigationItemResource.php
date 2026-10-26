<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Resources;

use Basalto\Core\Models\NavigationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Ein freier Menüeintrag (`items: db`) roh, wie er gespeichert ist: Ziel, Beschriftung je Sprache, Platz. */
final class NavigationItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var NavigationItem $item */
        $item = $this->resource;

        return [
            'id' => $item->id,
            'nav' => $item->nav,
            'parent_id' => $item->parent_id,
            'position' => $item->position,
            'kind' => $item->entry_id !== null ? 'entry' : 'url',
            'entry_id' => $item->entry_id,
            'url' => $item->url,
            'label' => $item->label ?? [],
            'visible' => $item->visible,
            'new_window' => $item->new_window,
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }
}
