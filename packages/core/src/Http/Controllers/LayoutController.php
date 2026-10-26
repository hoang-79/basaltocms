<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Content\EntryRenderer;
use Basalto\Core\Content\Pages;
use Basalto\Core\Content\ThemeSchema;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\LayoutEditor;
use Basalto\Core\Theme\ThemeEditor;
use Basalto\Core\Theme\ThemeEditorException;
use Basalto\Core\Theme\ThemeException;
use Basalto\Lava\Runtime\Registry;
use Basalto\Lava\Structure\Definition;
use Basalto\Lava\Structure\Printer;
use Basalto\Lava\Theme\PageRenderer;
use Basalto\Lava\Theme\Theme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Station C und G als Formular (M-28, Mockup 27 Bilder 2–3): Layouts mit Regionen, Vererbung und Lava-Teilen in
 * Reihenfolge, Teil-Instanzen mit ihren Einstellungen, Templates je Typ × Layout — daneben der Lava-Code derselben
 * Quelle und die Vorschau. Jede Änderung wird über den Drucker zu Lava und als Entwurf gespeichert (Prüfung, Vorschau,
 * Freigabe und Fassungen wie im Theme-Editor). Ansehen darf jedes Mitglied, ändern Betreiber und Administration.
 *
 * @phpstan-import-type Change from LayoutEditor
 */
final class LayoutController
{
    public function __construct(private readonly CurrentSite $current, private readonly LayoutEditor $layouts, private readonly ThemeEditor $editor, private readonly Registry $registry, private readonly Pages $pages) {}

    public function __invoke(Request $request): Response
    {
        $site = $this->current->require();
        $tab = in_array($request->query('tab'), ['parts', 'templates'], true) ? (string) $request->query('tab') : 'layouts';
        $database = $this->editor->isDatabaseTheme($site);
        try {
            $theme = $this->layouts->theme($site);
        } catch (ThemeException $e) {
            return Inertia::render('core::Layouts/Index', ['tab' => $tab, 'broken' => $e->getMessage(), 'layouts' => [], 'parts' => [], 'part_kinds' => [], 'templates' => [],
                'database' => $database, 'can_edit' => false, 'drafts' => 0, 'sources' => null, 'urls' => $this->urls()]);
        }
        $printer = new Printer;

        $layouts = [];
        foreach (LayoutEditor::all($theme, 'layout') as $layout) {
            $resolved = $this->layouts->resolvedRegions($theme, $layout);
            $layouts[] = [
                'name' => (string) $layout->name, 'extends' => $layout->modifierAfter('extends'),
                // Lesesicht wie bisher: Füllung als Wörter, geerbt, Platz
                'regions' => array_map(static fn (array $r): array => ['name' => $r['name'], 'fill' => self::words($r['fill']), 'inherited' => $r['inherited'],
                    'placement' => $r['where'] === null ? null : $r['where'].' '.$r['target']], $resolved),
                'resolved' => $resolved,
                'form' => LayoutEditor::layoutForm($layout),
                'markup_regions' => $this->layouts->markupRegions($theme, $layout->modifierAfter('extends'), $layout),
                'has_markup' => $layout->markups !== [] || $theme->source->has('layouts/'.$layout->name.'.lava.html'),
                'code' => rtrim($printer->printDefinition($layout, 0, true)), 'file' => $layout->file, 'line' => $layout->line,
            ];
        }

        $usage = [];
        foreach ([...$theme->document->all('layout'), ...$theme->document->all('template')] as $def) {
            foreach ($def->childrenOf('region') as $region) {
                foreach (PageRenderer::fill($region->typed) as $item) {
                    if ($item['word'] === 'part' && $item['name'] !== null) {
                        $usage[$item['name']][] = sprintf('%s %s › %s%s', $def->kind === 'layout' ? __('Layout') : __('Template'), $def->name, $region->name, $item['off'] ? ' '.__('(aus)') : '');
                    }
                }
            }
        }
        $kinds = $this->registry->parts();
        $parts = [];
        foreach (LayoutEditor::all($theme, 'part') as $part) {
            $kind = (string) $part->typedFirst();
            $form = LayoutEditor::partForm($part);
            $parts[] = ['name' => (string) $part->name, 'kind' => $kind, 'kind_label' => $kinds[$kind]['label'] ?? $kind, 'origin' => $kinds[$kind]['origin'] ?? __('unbekannt'),
                'settings' => $form['settings'], 'used_in' => $usage[(string) $part->name] ?? [], 'form' => $form,
                'code' => rtrim($printer->printDefinition($part)), 'file' => $part->file, 'line' => $part->line];
        }
        $backend = app(Backend::class);
        $partKinds = [];
        foreach ($kinds as $name => $kind) {
            $module = str_starts_with($kind['origin'], 'Modul ') ? substr($kind['origin'], 6) : null;
            $partKinds[] = ['name' => $name, 'label' => $kind['label'], 'origin' => $kind['origin'], 'settings' => $kind['settings'],
                'enabled' => $module === null || $backend->moduleEnabled($module, $site), 'instances' => count(array_filter($parts, static fn (array $p): bool => $p['kind'] === $name))];
        }

        $schema = ThemeSchema::fromTheme($theme);
        $renderer = app(EntryRenderer::class);
        $usedBy = [];
        // TB 35: je Template eine Seite, die es zeigt — für die Vorschau im Formular
        $examples = [];
        foreach ($this->pages->entries($site) as $entry) {
            if (isset($schema->types[$entry->type])) {
                $name = $renderer->templateFor($entry, $schema);
                $usedBy[$name] = ($usedBy[$name] ?? 0) + 1;
                $examples[$name] ??= $this->pages->pathOf($entry, $site->default_locale);
            }
        }
        $templates = [];
        foreach (LayoutEditor::all($theme, 'template') as $tpl) {
            $regions = [];
            foreach ($tpl->childrenOf('region') as $region) {
                $regions[] = ['name' => (string) $region->name, 'fill' => self::words(PageRenderer::fill($region->typed))];
            }
            $templates[] = ['name' => (string) $tpl->name, 'for' => $tpl->value('for'), 'layout' => (string) $tpl->value('layout'), 'noindex' => $tpl->value('noindex') === 'true',
                'regions' => $regions, 'used_by' => $usedBy[(string) $tpl->name] ?? 0, 'form' => LayoutEditor::templateForm($tpl),
                'example' => $examples[(string) $tpl->name] ?? null,
                'for_label' => $schema->types[(string) $tpl->value('for')]['label'] ?? $tpl->value('for'),
                // TB 35: auch Regionen, die nur das Markup einsetzt («{% region "header" %}») — sonst fehlten sie hier
                'layout_regions' => array_values(array_unique([...$this->layouts->regionNames($theme, (string) $tpl->value('layout')),
                    ...$this->layouts->markupRegions($theme, (string) $tpl->value('layout'))])),
                'code' => rtrim($printer->printDefinition($tpl, 0, true))];
        }

        return Inertia::render('core::Layouts/Index', [
            'tab' => $tab,
            'broken' => null,
            'database' => $database,
            'can_edit' => Gate::allows('update', $site),
            'theme' => $site->theme ?? (string) config('basalto.default_theme', 'nguyen-development'),
            'drafts' => count($this->editor->draftChanges($site)),
            'layouts' => $layouts,
            'parts' => $parts,
            'part_kinds' => $partKinds,
            'templates' => $templates,
            'sources' => $this->sources($site, $theme),
            'preview_path' => $this->previewPath($site),
            'urls' => $this->urls(),
        ]);
    }

    /** Die Seite für die Vorschau: die Startseite, sonst die erste veröffentlichte Seite. */
    private function previewPath(Site $site): string
    {
        if ($this->pages->resolve($site, $site->default_locale, '/') !== null) {
            return '/';
        }
        foreach ($this->pages->entries($site) as $entry) {
            $path = $this->pages->pathOf($entry, $site->default_locale);
            if ($path !== null && $this->pages->resolve($site, $site->default_locale, $path) !== null) {
                return $path;
            }
        }

        return '/';
    }

    public function storeLayout(Request $request): RedirectResponse
    {
        return $this->persist($request, fn (Site $site): ?array => $this->layouts->layoutChange($site, null, $this->layoutInput($request)), 'layouts', __('Layout angelegt'));
    }

    public function updateLayout(Request $request, string $name): RedirectResponse
    {
        return $this->persist($request, fn (Site $site): ?array => $this->layouts->layoutChange($site, $name, $this->layoutInput($request)), 'layouts', __('Layout gespeichert'));
    }

    public function destroyLayout(Request $request, string $name): RedirectResponse
    {
        return $this->persist($request, fn (Site $site): ?array => $this->layouts->layoutRemoval($site, $name), 'layouts', __('Layout entfernt'));
    }

    public function storePart(Request $request): RedirectResponse
    {
        return $this->persist($request, fn (Site $site): ?array => $this->layouts->partChange($site, null, $this->partInput($request)), 'parts', __('Lava-Teil angelegt'));
    }

    public function updatePart(Request $request, string $name): RedirectResponse
    {
        return $this->persist($request, fn (Site $site): ?array => $this->layouts->partChange($site, $name, $this->partInput($request)), 'parts', __('Lava-Teil gespeichert'));
    }

    public function destroyPart(Request $request, string $name): RedirectResponse
    {
        return $this->persist($request, fn (Site $site): ?array => $this->layouts->partRemoval($site, $name), 'parts', __('Lava-Teil entfernt'));
    }

    public function updateTemplate(Request $request, string $name): RedirectResponse
    {
        return $this->persist($request, fn (Site $site): ?array => $this->layouts->templateChange($site, $name, $this->templateInput($request)), 'templates', __('Template gespeichert'));
    }

    /**
     * Probe neben dem Formular: der Lava-Code, den das Formular schreiben würde, und die Befunde des Compilers — ohne
     * zu speichern.
     */
    public function probe(Request $request): JsonResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $data = $request->validate(['probe' => ['required', 'in:layout,part,template'], 'current' => ['nullable', 'string', 'max:64']]);
        $current = isset($data['current']) ? (string) $data['current'] : null;
        try {
            $change = match ($data['probe']) {
                'layout' => $this->layouts->layoutChange($site, $current, $this->layoutInput($request)),
                'part' => $this->layouts->partChange($site, $current, $this->partInput($request)),
                default => $this->layouts->templateChange($site, (string) $current, $this->templateInput($request)),
            };
        } catch (ThemeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if ($change === null) {
            return response()->json(['changed' => false, 'code' => null, 'findings' => []]);
        }

        return response()->json(['changed' => true, 'code' => rtrim($change['code']), 'file' => $change['file'], 'findings' => $this->layouts->probe($site, $change)]);
    }

    /**
     * Speichert eine Änderung als Entwurf und führt zurück in den Reiter — mit dem Fehler des Compilers am Formular,
     * wenn das Theme damit nicht kompiliert (dann bleibt alles, wie es war).
     *
     * @param  callable(Site): (Change|null)  $build
     */
    private function persist(Request $request, callable $build, string $tab, string $done): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        try {
            $change = $build($site);
        } catch (ThemeException $e) {
            return back()->withErrors(['lava' => __('Das Theme hat Fehler — zuerst im Theme-Editor beheben.').' '.$e->getMessage()]);
        }
        if ($change === null) {
            return back()->with('status', __('Nichts geändert.'));
        }
        $user = $request->user() ?? abort(403);
        try {
            $warnings = $this->layouts->save($site, $change, $user);
        } catch (ThemeEditorException $e) {
            return back()->withErrors(['lava' => $e->getMessage()])->withInput();
        }
        $hint = $warnings === [] ? '' : ' '.trans_choice(':count Warnung im Theme.|:count Warnungen im Theme.', count($warnings), ['count' => count($warnings)]);

        return redirect()->route('basalto.setup.layouts.index', array_filter(['tab' => $tab === 'layouts' ? null : $tab]))
            ->with('status', __(':done — als Entwurf. Die Vorschau zeigt ihn; live geht er mit «Veröffentlichen» im Theme-Editor.', ['done' => $done]).$hint);
    }

    /** @return array{name: string, extends: string|null, regions: list<array{name: string, optional: bool, where: string|null, target: string|null, fill: list<array{word: string, name: string|null, off: bool}>}>} */
    private function layoutInput(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'extends' => ['nullable', 'string', 'max:64'],
            'regions' => ['present', 'array', 'max:30'],
            'regions.*.name' => ['required', 'string', 'max:64', 'regex:'.LayoutEditor::REGION],
            'regions.*.optional' => ['boolean'],
            'regions.*.where' => ['nullable', 'in:before,after'],
            'regions.*.target' => ['nullable', 'string', 'max:64'],
            ...self::fillRules('regions.*.fill'),
        ], ['regions.*.name.regex' => __('Kleinbuchstaben, Ziffern, Binde- und Unterstrich.')]);

        return ['name' => (string) $data['name'], 'extends' => isset($data['extends']) && $data['extends'] !== '' ? (string) $data['extends'] : null,
            'regions' => array_values(array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'optional' => (bool) ($r['optional'] ?? false),
                'where' => isset($r['where']) ? (string) $r['where'] : null, 'target' => isset($r['where'], $r['target']) ? (string) $r['target'] : null, 'fill' => self::fillInput($r['fill'] ?? [])], $data['regions'] ?? []))];
    }

    /** @return array{name: string, for: string, layout: string, noindex: bool, regions: list<array{name: string, fill: list<array{word: string, name: string|null, off: bool}>}>} */
    private function templateInput(Request $request): array
    {
        $data = $request->validate([
            'layout' => ['required', 'string', 'max:64'],
            'noindex' => ['boolean'],
            'regions' => ['present', 'array', 'max:30'],
            'regions.*.name' => ['required', 'string', 'max:64'],
            ...self::fillRules('regions.*.fill'),
        ]);

        return ['name' => '', 'for' => '', 'layout' => (string) $data['layout'], 'noindex' => (bool) ($data['noindex'] ?? false),
            'regions' => array_values(array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'fill' => self::fillInput($r['fill'] ?? [])], $data['regions'] ?? []))];
    }

    /** @return array{name: string, kind: string, settings: array<string, string>, markup: string|null} */
    private function partInput(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'kind' => ['required', 'string', 'max:64'],
            'settings' => ['array', 'max:40'],
            'settings.*' => ['nullable', 'string', 'max:500'],
            'markup' => ['nullable', 'string', 'max:20000'],
        ]);
        $settings = [];
        foreach ($data['settings'] ?? [] as $key => $value) {
            if (preg_match('/^[a-z][a-z_]*(\.[a-z]{2})?$/', (string) $key) === 1) {
                $settings[(string) $key] = trim((string) $value);
            }
        }

        return ['name' => (string) $data['name'], 'kind' => (string) $data['kind'], 'settings' => $settings, 'markup' => isset($data['markup']) ? (string) $data['markup'] : null];
    }

    /** @return array<string, list<mixed>> */
    private static function fillRules(string $path): array
    {
        return [
            $path => ['present', 'array', 'max:30'],
            $path.'.*.word' => ['required', Rule::in(LayoutEditor::WORDS)],
            $path.'.*.name' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z_][A-Za-z0-9_.\-]*$/'],
            $path.'.*.off' => ['boolean'],
        ];
    }

    /**
     * @param  array<mixed>  $fill
     * @return list<array{word: string, name: string|null, off: bool}>
     */
    private static function fillInput(array $fill): array
    {
        return array_values(array_map(static fn (mixed $i): array => ['word' => (string) ($i['word'] ?? ''), 'name' => isset($i['name']) && $i['name'] !== '' ? (string) $i['name'] : null,
            'off' => (bool) ($i['off'] ?? false)], array_filter($fill, 'is_array')));
    }

    /**
     * Die Füllung als Wörter wie in der Lesesicht: «part main_nav», «content», «part newsletter off».
     *
     * @param  list<array{word: string, name: string|null, off: bool}>  $fill
     * @return list<string>
     */
    private static function words(array $fill): array
    {
        $out = [];
        foreach ($fill as $item) {
            $out[] = $item['word'].($item['name'] !== null ? ' '.$item['name'] : '');
            if ($item['off']) {
                $out[] = 'off';
            }
        }

        return $out;
    }

    /**
     * Was die Auswahlfelder anbieten: Navigationen, globale Inhalte, Formulare, Typen, Templates, Layouts, Sprachen.
     *
     * @return array<string, list<string>>
     */
    private function sources(Site $site, Theme $theme): array
    {
        $names = static fn (string $kind): array => array_values(array_unique(array_map(static fn (Definition $d): string => (string) $d->name, $theme->document->all($kind))));

        return ['nav' => $names('nav'), 'global' => $names('global'), 'form' => $names('form'), 'type' => $names('type'), 'template' => $names('template'),
            'layout' => $names('layout'), 'part' => $names('part'), 'locales' => array_values(array_diff($site->localeCodes(), [$site->default_locale]))];
    }

    /** @return array<string, string> */
    private function urls(): array
    {
        return [
            'index' => route('basalto.setup.layouts.index'),
            'layouts' => route('basalto.setup.layouts.store'),
            'parts' => route('basalto.setup.parts.store'),
            'templates' => route('basalto.setup.templates.update', ['name' => '__name__']),
            'probe' => route('basalto.setup.layouts.probe'),
            'preview_page' => route('basalto.setup.theme-editor.preview.page'),
            'theme_editor' => route('basalto.setup.theme-editor'),
            'adopt' => route('basalto.setup.theme-editor.adopt'),
            'variants' => route('basalto.setup.variants.index'),
        ];
    }
}
