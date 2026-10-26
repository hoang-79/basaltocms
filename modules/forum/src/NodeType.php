<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum;

/** Die Arten von Knoten im Forenbaum (wie WoltLab): Kategorie gliedert, Forum enthält Themen, Link führt weg. */
enum NodeType: string
{
    case Category = 'category';
    case Board = 'board';
    case Link = 'link';

    public function label(?string $locale = null): string
    {
        return match ($this) {
            self::Category => __('Kategorie', [], $locale),
            self::Board => __('Forum', [], $locale),
            self::Link => __('Link', [], $locale),
        };
    }
}
