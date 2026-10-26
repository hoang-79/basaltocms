<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Resources;

use Basalto\Core\Models\NavigationItem;
use Basalto\Core\Navigation\Navigation;
use Basalto\Core\Navigation\NavigationItems;
use Basalto\Lava\Structure\Definition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ein Menü des Themes in der REST-API (M-17): Herkunft und Tiefe; mit Sprache dazu die Einträge, wie das Frontend sie
 * zeigt (`items`, gerendert über {@see Navigation}), und bei freien Menüs die gespeicherten Einträge (`raw`).
 *
 * @phpstan-import-type Item from Navigation
 */
final class NavigationResource extends JsonResource
{
    /** @var list<Item>|null */
    private ?array $items = null;

    private ?string $locale = null;

    /** @var list<NavigationItem> */
    private array $raw = [];

    /**
     * @param  list<Item>  $items
     * @param  list<NavigationItem>  $raw
     */
    public function withItems(string $locale, array $items, array $raw): self
    {
        $this->locale = $locale;
        $this->items = $items;
        $this->raw = $raw;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Definition $def */
        $def = $this->resource;
        $from = $def->value('from');
        $out = [
            'handle' => (string) $def->name,
            'label' => $def->value('label') ?? ucfirst((string) $def->name),
            'source' => match (true) {
                NavigationItems::editable($def) => 'db',
                $from === 'pages' => 'pages',
                $from !== null => 'type',
                default => 'theme',
            },
            'editable' => NavigationItems::editable($def),
            'depth' => max(1, (int) ($def->value('depth') ?? 1)),
        ];
        if ($this->items !== null) {
            $out['locale'] = $this->locale;
            $out['items'] = self::rendered($this->items);
            $out['raw'] = NavigationItemResource::collection($this->raw);
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $items
     * @return list<array<string, mixed>>
     */
    private static function rendered(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $out[] = ['label' => (string) $item['label'], 'href' => (string) $item['href'], 'new_window' => (bool) $item['new_window'], 'entry_id' => $item['entry_id'],
                'children' => self::rendered(is_array($item['children'] ?? null) ? $item['children'] : [])];
        }

        return $out;
    }
}
