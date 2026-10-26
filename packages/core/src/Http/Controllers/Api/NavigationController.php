<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Api;

use Basalto\Core\Http\Resources\NavigationItemResource;
use Basalto\Core\Http\Resources\NavigationResource;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\NavigationItem;
use Basalto\Core\Navigation\Navigation;
use Basalto\Core\Navigation\NavigationItems;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\ThemeLoader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Navigation per REST-API (M-17): die Menüs des Themes, je Menü die Einträge wie im Frontend (`items`) und roh
 * (`raw`, nur freie Menüs); freie Einträge (`items: db`) anlegen, ändern, löschen — mit denselben Regeln wie
 * Station D ({@see NavigationItems}) und demselben Recht (Inhalte bearbeiten).
 */
final class NavigationController
{
    public function __construct(private readonly CurrentSite $current, private readonly ThemeLoader $themes, private readonly Navigation $navigation, private readonly NavigationItems $editor) {}

    public function index(): AnonymousResourceCollection
    {
        $site = $this->current->require();

        return NavigationResource::collection($this->themes->forSite($site)->document->all('nav'));
    }

    /** Ein Menü in einer Sprache (`locale`, sonst Standardsprache). */
    public function show(Request $request, string $nav): NavigationResource
    {
        $site = $this->current->require();
        $theme = $this->themes->forSite($site);
        $def = $theme->nav($nav);
        abort_if($def === null, 404, __('Das Theme hat kein Menü «:nav».', ['nav' => $nav]));
        $locale = (string) ($request->validate(['locale' => ['nullable', Rule::in($site->localeCodes())]])['locale'] ?? $site->default_locale);
        $raw = NavigationItems::editable($def) ? NavigationItem::query()->where('nav', $nav)->orderBy('position')->orderBy('id')->get()->all() : [];

        return (new NavigationResource($def))->withItems($locale, $this->navigation->items($site, $theme, $def, $locale), array_values($raw));
    }

    public function store(Request $request, string $nav): JsonResponse
    {
        $site = $this->current->require();
        $this->authorizeEdit();
        $item = $this->editor->create($request, $site, $nav);

        return (new NavigationItemResource($item->refresh()))->response()->setStatusCode(201);
    }

    /** Ändern — mit allen Feldern wie beim Anlegen (`kind`, `entry_id` oder `url`, `label`, `parent_id`, `visible`, `new_window`). */
    public function update(Request $request, int $id): NavigationItemResource
    {
        $site = $this->current->require();
        $this->authorizeEdit();
        $item = NavigationItem::query()->findOrFail($id);
        $this->editor->update($request, $site, $item);

        return new NavigationItemResource($item->refresh());
    }

    public function destroy(int $id): Response
    {
        $this->current->require();
        $this->authorizeEdit();
        $this->editor->delete(NavigationItem::query()->findOrFail($id));

        return response()->noContent();
    }

    /** Wie im Backend: wer Inhalte anlegen darf, pflegt freie Menüs. */
    private function authorizeEdit(): void
    {
        Gate::authorize('create', new Entry(['site_id' => $this->current->require()->id]));
    }
}
