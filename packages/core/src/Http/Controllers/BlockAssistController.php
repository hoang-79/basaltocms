<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Content\EntryRenderer;
use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\Pages;
use Basalto\Core\Content\ThemeSchema;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\ThemeAssets;
use Basalto\Core\Theme\ThemeLoader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Hilfen für Block-Formulare (TB 30, geführtes Blockformular): Linkziele der Site für das Feld «Link», die Bilder des
 * Themes für Bildfelder mit einer Datei unter assets/ und die
 * Live-Vorschau beliebiger Blöcke mit dem echten Theme — für Bausteine, die keine eigene Seite haben. Gespeichert
 * wird nichts; die Vorschau trägt Feldmarken, damit Formular und Vorschau einander zeigen, wo ein Feld steht.
 */
final class BlockAssistController
{
    public function __construct(private readonly CurrentSite $current, private readonly ThemeLoader $themes, private readonly Pages $pages) {}

    public function links(Request $request): JsonResponse
    {
        $site = $this->authorized();
        $locale = $this->locale($request->query('locale'), $site->localeCodes(), $site->default_locale);

        return response()->json(['targets' => $this->pages->linkTargets($site, $locale, (string) $request->query('q', ''))]);
    }

    /** Bilder unter assets/ des Themes — für Bildfelder, die eine Datei des Themes statt der Mediathek nutzen. */
    public function themeImages(ThemeAssets $assets): JsonResponse
    {
        return response()->json(['images' => $assets->images($this->authorized())]);
    }

    public function preview(Request $request, EntryRenderer $renderer): Response
    {
        $site = $this->authorized();
        $locale = $this->locale($request->input('locale'), $site->localeCodes(), $site->default_locale);
        $content = $request->input('content', []);
        $schema = ThemeSchema::fromTheme($this->themes->forSite($site));
        // Ein Typ, dessen Template Blöcke zeigt: «page», sonst der erste mit einem Feld «blocks»
        $type = isset($schema->types['page']) ? 'page' : (array_key_first(array_filter($schema->types, static fn (array $t): bool => $t['allowed'] !== [])) ?? array_key_first($schema->types) ?? 'page');
        $entry = new Entry(['type' => $type, 'settings' => []]);
        $entry->site_id = $site->id;
        $entry->setRelation('site', $site);
        $entry->setRelation('parent', null);
        $title = trim((string) $request->input('title', '')) ?: __('Vorschau');
        $version = new EntryVersion(['locale' => $locale, 'number' => 0, 'status' => EntryStatus::Draft->value, 'title' => $title, 'slug' => 'vorschau',
            'data' => ['content' => is_array($content) ? array_values($content) : []]]);
        $version->setRelation('entry', $entry);

        return response($renderer->html($version, [], true))->header('X-Robots-Tag', 'noindex')->header('Cache-Control', 'no-store');
    }

    /** Wer Seiten oder Bausteine sieht — die zwei Orte mit Block-Formularen. */
    private function authorized(): Site
    {
        $site = $this->current->require();
        abort_unless(Gate::allows('area', [$site, 'pages']) || Gate::allows('area', [$site, 'structure']), 403);

        return $site;
    }

    /** @param  list<string>  $codes */
    private function locale(mixed $wanted, array $codes, string $fallback): string
    {
        return is_string($wanted) && in_array($wanted, $codes, true) ? $wanted : $fallback;
    }
}
