<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Content\ContentTypes;
use Basalto\Core\Content\PageType;
use Basalto\Core\Http\Requests\ContentTypeRequest;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\ThemeException;
use Basalto\Core\Theme\ThemeLoader;
use Basalto\Lava\Runtime\Registry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Station E «type» im Modus Einrichtung (M-02, Mockup 17/Bereich 10): Typen aus dem Theme (sichtbar, gesperrt) und
 * Typen der Site (Formular und Code) — beide sind Lava, die Site-Typen schreibt der Drucker in `site/types.lava`.
 */
final class ContentTypeController
{
    public function __construct(private readonly CurrentSite $current, private readonly ContentTypes $types, private readonly ThemeLoader $themes, private readonly Registry $registry) {}

    public function index(): Response
    {
        $site = $this->current->require();
        try {
            $types = $this->types->all($site);
            $themeError = null;
        } catch (ThemeException $e) {
            $types = [];
            $themeError = $e->toArray();
        }

        return Inertia::render('core::Types/Index', [
            'types' => array_map(static fn (array $t): array => ['kind' => $t['kind'], 'name' => $t['name'], 'label' => $t['label'], 'source' => $t['source'], 'is_page_type' => $t['is_page_type'],
                'fields' => count($t['fields']), 'entries' => $t['entries'], 'routes' => $t['routes'], 'template' => $t['template'], 'lava' => $t['lava'],
                'edit_url' => route('basalto.setup.types.edit', $t['name']), 'list_url' => ($t['kind'] === 'type' && ! $t['is_page_type']) || $t['kind'] === 'global' ? route('basalto.entries.index', ['type' => $t['name']]) : null], $types),
            'theme_error' => $themeError,
            'can_edit' => Gate::allows('update', $site),
            'urls' => ['create' => route('basalto.setup.types.create')],
        ]);
    }

    public function create(): Response
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);

        return $this->form($site, null);
    }

    public function store(ContentTypeRequest $request): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $definition = $request->definition();
        $this->types->save($site, $definition);

        return $this->done($request, $definition['name'], __('Typ «:name» angelegt — als Lava in der Site-Quelle.', ['name' => $definition['name']]));
    }

    public function edit(string $name): Response
    {
        $site = $this->current->require();
        $type = $this->types->find($site, $name);
        abort_if($type === null, 404);

        return $this->form($site, $type);
    }

    public function update(ContentTypeRequest $request, string $name): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $type = $this->types->find($site, $name);
        abort_if($type === null, 404);
        abort_if($type['source'] === 'theme', 403, __('Dieser Typ kommt aus dem Theme und wird dort bearbeitet.'));
        $definition = $request->definition();
        $this->types->save($site, $definition, $name);

        return $this->done($request, $definition['name'], __('Typ «:name» gespeichert.', ['name' => $definition['name']]));
    }

    public function destroy(string $name): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $type = $this->types->find($site, $name);
        abort_if($type === null, 404);
        abort_if($type['source'] === 'theme', 403, __('Dieser Typ kommt aus dem Theme und wird dort entfernt.'));
        $this->types->delete($site, $name);
        $hint = $type['entries'] > 0 ? ' '.__(':count Inhalte bleiben erhalten und sind nicht mehr bearbeitbar, bis der Typ zurück ist.', ['count' => $type['entries']]) : '';

        return redirect()->route('basalto.setup.types.index')->with('status', __('Typ «:name» entfernt.', ['name' => $name]).$hint);
    }

    /** @param  array<string, mixed>|null  $type */
    private function form(Site $site, ?array $type): Response
    {
        $locales = $site->localeCodes();
        $titleLabels = [];
        foreach ($locales as $locale) {
            $titleLabels[$locale] = match ($locale) {
                'de' => 'Titel', 'en' => 'Title', 'it' => 'Titolo', 'fr' => 'Titre', default => '',
            };
        }
        $form = ['kind' => 'type', 'name' => '', 'labels' => [], 'fields' => [['name' => 'title', 'type' => 'text', 'required' => true, 'translatable' => true, 'options' => '', 'default' => '', 'relation' => '', 'allow' => [],
            'labels' => $titleLabels, 'help' => '']], 'route' => [], 'template' => ''];
        foreach ($locales as $locale) {
            $form['labels'][$locale] = '';
            $form['route'][$locale] = '';
        }
        if ($type !== null) {
            $form['kind'] = $type['kind'];
            $form['name'] = $type['name'];
            foreach ($locales as $locale) {
                $key = $locale === $site->default_locale ? '*' : $locale;
                $form['labels'][$locale] = (string) ($type['labels'][$key] ?? ($key === '*' ? ($type['labels']['*'] ?? '') : ''));
                $form['route'][$locale] = (string) ($type['routes'][$key] ?? '');
            }
            $form['template'] = (string) ($type['template'] ?? '');
            $form['fields'] = array_map(static fn (array $f): array => [
                'name' => $f['name'], 'type' => $f['type'], 'required' => $f['required'], 'translatable' => $f['translatable'],
                'options' => implode(', ', $f['options']), 'default' => $f['default'] === null ? '' : (string) $f['default'], 'relation' => (string) ($f['relation'] ?? ''), 'allow' => $f['allow'] ?? [],
                'labels' => array_combine($locales, array_map(static fn (string $l): string => (string) ($f['labels'][$l === $site->default_locale ? '*' : $l] ?? ''), $locales)),
                'help' => (string) ($f['help_raw'] ?? ''),
            ], $type['fields']);
        }
        $blocks = [];
        $templates = [];
        $typeNames = [];
        try {
            $theme = $this->themes->forSite($site);
            $blocks = $theme->document->names('block');
            foreach ($theme->document->all('template') as $template) {
                $templates[] = ['name' => (string) $template->name, 'for' => (string) $template->value('for')];
            }
            $typeNames = array_values(array_filter($theme->document->names('type'), static fn (string $n): bool => ! PageType::isPageType($n)));
        } catch (ThemeException) {
        }
        $fieldTypes = [];
        foreach ($this->registry->fieldTypes() as $fieldType) {
            $fieldTypes[] = ['value' => $fieldType, 'label' => self::fieldTypeLabel($fieldType)];
        }

        return Inertia::render('core::Types/Form', [
            'type' => $type === null ? null : ['name' => $type['name'], 'kind' => $type['kind'], 'source' => $type['source'], 'entries' => $type['entries'], 'lava' => $type['lava'], 'file' => $type['file'], 'line' => $type['line']],
            'form' => $form,
            'options' => [
                'field_types' => $fieldTypes,
                'blocks' => $blocks,
                'templates' => $templates,
                'types' => $typeNames,
                'locales' => $locales,
                'default_locale' => $site->default_locale,
            ],
            'read_only' => $type !== null && $type['source'] === 'theme',
            'urls' => ['index' => route('basalto.setup.types.index'), 'save' => $type === null ? route('basalto.setup.types.store') : route('basalto.setup.types.update', $type['name']),
                'delete' => $type === null ? null : route('basalto.setup.types.destroy', $type['name'])],
            'method' => $type === null ? 'post' : 'put',
        ]);
    }

    private function done(ContentTypeRequest $request, string $name, string $status): RedirectResponse
    {
        if ($request->boolean('close')) {
            return redirect()->route('basalto.setup.types.index')->with('status', $status);
        }

        return redirect()->route('basalto.setup.types.edit', $name)->with('status', $status);
    }

    private static function fieldTypeLabel(string $type): string
    {
        return match ($type) {
            'text' => __('Text'), 'textarea' => __('Mehrzeiliger Text'), 'richtext' => __('Fliesstext'), 'blocks' => __('Blöcke'), 'markdown' => __('Markdown'), 'number' => __('Zahl'),
            'checkbox', 'bool' => __('Ja/Nein'), 'date' => __('Datum'), 'datetime' => __('Datum und Zeit'), 'select' => __('Auswahl'), 'tags' => __('Schlagworte'), 'image' => __('Bild'),
            'gallery' => __('Galerie'), 'file' => __('Datei'), 'link' => __('Link'), 'relation' => __('Verweis'), 'repeater' => __('Wiederholung'), 'slug' => __('Slug'), 'email' => __('E-Mail'),
            'url' => __('URL'), 'color' => __('Farbe'), 'code' => __('Code'), 'seo' => __('SEO'), 'json' => __('JSON'),
            default => $type,
        };
    }
}
