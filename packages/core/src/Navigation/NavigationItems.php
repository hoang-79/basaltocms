<?php

declare(strict_types=1);

namespace Basalto\Core\Navigation;

use Basalto\Core\Models\NavigationItem;
use Basalto\Core\Models\Site;
use Basalto\Core\Theme\ThemeLoader;
use Basalto\Lava\Structure\Definition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Freie Menüeinträge (M-07, Station D) anlegen, ändern und löschen — eine Quelle für Backend und REST-API (M-17):
 * nur in Menüs mit `items: db`, Ziel Seite der Site oder URL, Beschriftung je Sprache, Tiefe höchstens die des Menüs,
 * kein Eintrag unter sich selbst. Fehler kommen als Validierungsfehler (422) zurück.
 */
final class NavigationItems
{
    public function __construct(private readonly ThemeLoader $themes, private readonly Navigation $navigation) {}

    public function create(Request $request, Site $site, string $nav): NavigationItem
    {
        $def = $this->editableNav($site, $nav);
        $data = $this->validated($request, $site, $nav, null, $def);
        $data['position'] = (int) NavigationItem::query()->where('nav', $nav)->where('parent_id', $data['parent_id'])->max('position') + 1;
        $item = NavigationItem::query()->create(['site_id' => $site->id, 'nav' => $nav] + $data);
        $this->navigation->forget();

        return $item;
    }

    public function update(Request $request, Site $site, NavigationItem $item): void
    {
        $def = $this->editableNav($site, $item->nav);
        $item->update($this->validated($request, $site, $item->nav, $item, $def));
        $this->navigation->forget();
    }

    /** Löschen — Untereinträge rücken an seine Stelle. */
    public function delete(NavigationItem $item): void
    {
        DB::transaction(static function () use ($item): void {
            NavigationItem::query()->where('parent_id', $item->id)->update(['parent_id' => $item->parent_id]);
            $item->delete();
        });
        $this->navigation->forget();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Site $site, string $nav, ?NavigationItem $item, Definition $def): array
    {
        $locales = $site->localeCodes();
        $validated = $request->validate([
            'label' => ['nullable', 'array'],
            'label.*' => ['nullable', 'string', 'max:120'],
            'kind' => ['required', Rule::in(['entry', 'url'])],
            'entry_id' => ['nullable', 'required_if:kind,entry', 'integer', Rule::exists('entries', 'id')->where('site_id', $site->id)->whereNull('deleted_at')],
            'url' => ['nullable', 'required_if:kind,url', 'string', 'max:500', 'regex:#^(https?://|/|mailto:|tel:|\\#)#'],
            'parent_id' => ['nullable', 'integer', Rule::exists('navigation_items', 'id')->where('site_id', $site->id)->where('nav', $nav)],
            'visible' => ['boolean'],
            'new_window' => ['boolean'],
        ], ['url.regex' => __('Die URL beginnt mit https://, /, mailto:, tel: oder #.')], ['label.*' => __('Beschriftung'), 'entry_id' => __('Seite'), 'url' => __('URL'), 'parent_id' => __('Übergeordneter Eintrag')]);
        $label = [];
        foreach ($locales as $locale) {
            $value = $validated['label'][$locale] ?? null;
            $label[$locale] = is_string($value) ? trim($value) : '';
        }
        if (($validated['kind'] === 'url') && implode('', $label) === '') {
            throw ValidationException::withMessages(['label.'.$site->default_locale => __('Ein freier Eintrag braucht eine Beschriftung.')]);
        }
        $parentId = isset($validated['parent_id']) ? (int) $validated['parent_id'] : null;
        if ($item !== null && $parentId !== null && ($parentId === $item->id || $this->isDescendant($parentId, $item->id))) {
            throw ValidationException::withMessages(['parent_id' => __('Ein Eintrag kann nicht unter sich selbst hängen.')]);
        }
        $parentDepth = 0;
        for ($p = $parentId; $p !== null; $p = NavigationItem::query()->whereKey($p)->value('parent_id')) {
            $parentDepth++;
        }
        $maxDepth = max(1, (int) ($def->value('depth') ?? 1));
        if ($parentDepth + 1 > $maxDepth) {
            throw ValidationException::withMessages(['parent_id' => trans_choice('Dieses Menü hat höchstens :count Ebene.|Dieses Menü hat höchstens :count Ebenen.', $maxDepth, ['count' => $maxDepth])]);
        }

        return [
            'label' => $label,
            'entry_id' => $validated['kind'] === 'entry' ? (int) $validated['entry_id'] : null,
            'url' => $validated['kind'] === 'url' ? (string) $validated['url'] : null,
            'parent_id' => $parentId,
            'visible' => $request->boolean('visible', true),
            'new_window' => $request->boolean('new_window'),
        ];
    }

    private function isDescendant(int $candidate, int $ancestor): bool
    {
        for ($p = NavigationItem::query()->whereKey($candidate)->value('parent_id'); $p !== null; $p = NavigationItem::query()->whereKey($p)->value('parent_id')) {
            if ((int) $p === $ancestor) {
                return true;
            }
        }

        return false;
    }

    /** Ein Menü mit freien Einträgen (`items: db`) — sonst 404. */
    public function editableNav(Site $site, string $nav): Definition
    {
        $def = $this->themes->forSite($site)->nav($nav);
        abort_if($def === null || ! self::editable($def), 404, __('Dieses Menü nimmt keine freien Einträge (im Theme fehlt «items: db»)'));

        return $def;
    }

    public static function editable(Definition $def): bool
    {
        return $def->value('items') === 'db';
    }
}
