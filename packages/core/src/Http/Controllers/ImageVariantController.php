<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Media\ImageVariant;
use Basalto\Core\Media\ImageVariants;
use Basalto\Core\Models\Media;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\LayoutEditor;
use Basalto\Core\Theme\ThemeEditor;
use Basalto\Core\Theme\ThemeEditorException;
use Basalto\Core\Theme\ThemeException;
use Basalto\Lava\Structure\Printer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Station B — Bildvarianten als Formular (K-09, M-28, Mockup 27 Bild 4): Name, Breite, Höhe, Modus, Dichten, `sizes`,
 * Lazy Loading je Variante in `design.images` des Themes. Speichern schreibt die Lava-Quelle als Entwurf; erst die
 * Veröffentlichung im Theme-Editor schneidet die Bilder der Site neu (in der Queue).
 */
final class ImageVariantController
{
    public function __construct(private readonly CurrentSite $current, private readonly LayoutEditor $layouts, private readonly ThemeEditor $editor) {}

    public function index(): Response
    {
        $site = $this->current->require();
        $printer = new Printer;
        $variants = [];
        $effective = [];
        $broken = null;
        try {
            $theme = $this->layouts->theme($site);
            $markup = '';
            foreach ($theme->document->definitions as $def) {
                foreach (array_keys($def->markups) as $name) {
                    $markup .= "\n".$def->markup($name);
                }
            }
            foreach (LayoutEditor::variants($theme) as $def) {
                $form = LayoutEditor::variantForm($def);
                preg_match_all('/\{%-?\s*image\b[^%]*["\']'.preg_quote($form['name'], '/').'["\']/', $markup, $uses);
                $variants[] = ['form' => $form, 'code' => rtrim($printer->printDefinition($def, 0, true)), 'uses' => count($uses[0]), 'line' => $def->line, 'file' => $def->file];
            }
            $effective = array_map(static fn (ImageVariant $v): array => $v->toArray() + ['describe' => $v->describe()], array_values(ImageVariants::fromTheme($theme)));
        } catch (ThemeException $e) {
            $broken = $e->getMessage();
        }
        $sample = Media::query()->where('mime', 'like', 'image/%')->where('mime', '!=', 'image/svg+xml')->whereNotNull('width')->latest('id')->first();

        return Inertia::render('core::Design/Variants', [
            'broken' => $broken,
            'database' => $this->editor->isDatabaseTheme($site),
            'can_edit' => Gate::allows('update', $site),
            'theme' => $site->theme ?? (string) config('basalto.default_theme', 'nguyen-development'),
            'variants' => $variants,
            'effective' => $effective,
            'images' => Media::query()->where('mime', 'like', 'image/%')->count(),
            'sample' => $sample === null ? null : ['name' => $sample->name, 'url' => $sample->url($sample->variants ? 'content' : null), 'width' => (int) $sample->width, 'height' => (int) $sample->height,
                'focus_x' => $sample->focus_x, 'focus_y' => $sample->focus_y],
            'urls' => ['index' => route('basalto.setup.variants.index'), 'store' => route('basalto.setup.variants.store'), 'design' => route('basalto.setup.design'),
                'theme_editor' => route('basalto.setup.theme-editor'), 'adopt' => route('basalto.setup.theme-editor.adopt')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->persist($request, null, false);
    }

    public function update(Request $request, string $name): RedirectResponse
    {
        return $this->persist($request, $name, false);
    }

    public function destroy(Request $request, string $name): RedirectResponse
    {
        return $this->persist($request, $name, true);
    }

    private function persist(Request $request, ?string $current, bool $remove): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        try {
            $change = $remove ? $this->layouts->variantRemoval($site, (string) $current) : $this->layouts->variantChange($site, $current, $this->input($request));
        } catch (ThemeException $e) {
            return back()->withErrors(['lava' => __('Das Theme hat Fehler — zuerst im Theme-Editor beheben.').' '.$e->getMessage()]);
        }
        if ($change === null) {
            return back()->with('status', __('Nichts geändert.'));
        }
        try {
            $this->layouts->save($site, $change, $request->user() ?? abort(403));
        } catch (ThemeEditorException $e) {
            return back()->withErrors(['lava' => $e->getMessage()])->withInput();
        }

        return redirect()->route('basalto.setup.variants.index')->with('status', $remove
            ? __('Variante entfernt — als Entwurf. Mit «Veröffentlichen» im Theme-Editor werden die Bilder der Site in der Queue neu geschnitten.')
            : __('Variante gespeichert — als Entwurf. Mit «Veröffentlichen» im Theme-Editor werden die Bilder der Site in der Queue neu geschnitten.'));
    }

    /** @return array{name: string, width: int, height: int|null, mode: string, densities: list<int>, sizes: string|null, lazy: bool} */
    private function input(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'width' => ['required', 'integer', 'min:16', 'max:8000'],
            'height' => ['nullable', 'integer', 'min:16', 'max:8000'],
            'mode' => ['required', 'in:'.ImageVariant::MODE_SCALE.','.ImageVariant::MODE_FIT.','.ImageVariant::MODE_EXACT],
            'densities' => ['array', 'max:3'],
            'densities.*' => ['integer', 'in:1,2,3'],
            'sizes' => ['nullable', 'string', 'max:200', 'not_regex:/["\\\\\r\n]/'],
            'lazy' => ['boolean'],
        ], ['sizes.not_regex' => __('Ohne Anführungszeichen, Backslash und Zeilenumbruch.')]);

        return ['name' => (string) $data['name'], 'width' => (int) $data['width'], 'height' => isset($data['height']) ? (int) $data['height'] : null, 'mode' => (string) $data['mode'],
            'densities' => array_values(array_map('intval', $data['densities'] ?? [1])), 'sizes' => isset($data['sizes']) && trim((string) $data['sizes']) !== '' ? trim((string) $data['sizes']) : null,
            'lazy' => (bool) ($data['lazy'] ?? true)];
    }
}
