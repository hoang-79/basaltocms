<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Models\SiteTheme;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\ThemeCatalog;
use Basalto\Core\Theme\ThemeEditorException;
use Basalto\Core\Theme\ThemeLibrary;
use Basalto\Core\Theme\ThemeLoader;
use Basalto\Core\Theme\ThemePackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Station «Themes» (M-15, Mockup 27): installierte Themes mit Umfang und Prüfstand, Vorschau in der eigenen Sitzung und
 * der Wechsel je Site — vorher der Bericht, was die Site mit dem neuen Theme nicht mehr zeigen würde. Gelöscht wird beim
 * Wechsel nichts; ein Theme mit Fehlern lässt sich nicht wählen.
 *
 * TB 22 (Theme-Bibliothek): Eigene Themes hinzufügen (Paket hochladen, Kopie, Marktplatz) und entfernen, mitgelieferte
 * Theme-Ordner ausblenden.
 */
final class ThemeController
{
    public function __construct(private readonly CurrentSite $current, private readonly ThemeCatalog $catalog, private readonly ThemeLoader $themes, private readonly Recorder $recorder, private readonly ThemeLibrary $library) {}

    public function index(Request $request): Response
    {
        $site = $this->current->require();
        $active = $this->themes->sourceFor($site, false);
        $hidden = $this->library->hidden($site);
        $own = $this->library->all($site);
        $links = static fn (array $meta): array => $meta + [
            'preview_url' => $meta['preview'] === null ? null : route('basalto.setup.themes.image', $meta['name']),
            'preview_link' => route('basalto.setup.themes.preview', $meta['name']),
            'impact_url' => route('basalto.setup.themes.impact', $meta['name']),
            'export_url' => route('basalto.setup.themes.export', $meta['name']),
            'updated' => $meta['updated_at'] > 0 ? date('d.m.Y', $meta['updated_at']) : null,
        ];
        $ownNames = $own->pluck('name')->all();
        $folders = array_values(array_filter($this->catalog->all(), static fn (array $meta): bool => ! in_array($meta['name'], $ownNames, true)));

        return Inertia::render('core::Themes', [
            'own' => $own->map(fn (SiteTheme $theme): array => $links($this->catalog->ownMeta($theme)) + [
                'kind' => 'own', 'origin' => $theme->origin, 'origin_name' => $theme->origin_name, 'signed' => $theme->signed,
                'replaces_folder' => ThemeLibrary::hasFolder($theme->name),
                'destroy_url' => route('basalto.setup.themes.destroy', $theme->name),
                'edit_url' => route('basalto.setup.theme-editor', ['theme' => $theme->name]),
            ])->values()->all(),
            'themes' => array_map(static fn (array $meta): array => $links($meta) + [
                'kind' => 'folder', 'hidden' => in_array($meta['name'], $hidden, true), 'hide_url' => route('basalto.setup.themes.hide', $meta['name']),
            ], $folders),
            'active' => $active['name'],
            'source' => $active['kind'] === 'files' ? 'files' : 'database',
            'preview' => $request->session()->get('basalto.theme_preview.'.$site->id),
            'can_edit' => Gate::allows('update', $site),
            'limits' => ['package_mb' => 20],
            'urls' => [
                'switch' => route('basalto.setup.themes.switch'), 'preview_end' => route('basalto.setup.themes.preview.end'), 'check' => route('basalto.setup'),
                'upload' => route('basalto.setup.themes.upload'), 'copy' => route('basalto.setup.themes.copy'), 'marketplace' => route('basalto.modules'),
                'editor' => route('basalto.setup.theme-editor'),
            ],
        ]);
    }

    /** Was beim Wechsel nicht mehr gezeigt würde — als JSON für den Dialog vor dem Wechsel. */
    public function impact(string $name): JsonResponse
    {
        $site = $this->current->require();
        abort_unless($this->library->exists($site, $name), 404);

        return response()->json($this->catalog->impact($site, $name));
    }

    public function switch(Request $request): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $data = $request->validate(['name' => ['required', 'string'], 'confirm' => ['boolean']]);
        $name = (string) $data['name'];
        abort_unless($this->library->exists($site, $name), 404);
        $impact = $this->catalog->impact($site, $name);
        if (! $impact['ok']) {
            return back()->withErrors(['name' => __('Theme «:name» hat Fehler und kann nicht gewählt werden: :findings', ['name' => $name, 'findings' => implode(' · ', array_slice($impact['diagnostics'], 0, 3))])]);
        }
        if (! $impact['empty'] && ! ($data['confirm'] ?? false)) {
            return back()->withErrors(['confirm' => __('Bitte den Bericht bestätigen — einiges würde mit dem neuen Theme nicht mehr angezeigt.')]);
        }
        $before = $site->theme;
        $site->forceFill(['theme' => $name])->save();
        $this->themes->forget();
        $request->session()->forget('basalto.theme_preview.'.$site->id);
        $this->recorder->record('site.theme', $site, ['theme' => $before], ['theme' => $name, 'bericht' => array_filter([
            'typen' => $impact['types'], 'bloecke' => $impact['blocks'], 'templates' => $impact['templates'], 'menues' => $impact['navs'], 'formulare' => $impact['forms'],
        ])], $site);

        return redirect()->route('basalto.setup.themes')->with('status', __('Theme «:name» ist aktiv — die Inhalte sind unverändert.', ['name' => $name]));
    }

    public function preview(Request $request, string $name): RedirectResponse
    {
        $site = $this->current->require();
        abort_unless($this->library->exists($site, $name), 404);
        $request->session()->put('basalto.theme_preview.'.$site->id, $name);

        return redirect()->to('/');
    }

    public function endPreview(Request $request): RedirectResponse
    {
        $site = $this->current->require();
        $request->session()->forget('basalto.theme_preview.'.$site->id);

        return redirect()->route('basalto.setup.themes')->with('status', __('Vorschau beendet.'));
    }

    public function image(string $name): HttpResponse
    {
        $site = $this->current->require();
        abort_unless($this->library->exists($site, $name), 404);
        $image = $this->catalog->previewImage($site, $name) ?? abort(404);

        return response($image['content'], 200, ['Content-Type' => $image['type'], 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Theme-Paket (ZIP) hochladen — wird ein neues eigenes Theme, aktiv erst nach dem Wechsel. */
    public function upload(Request $request): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $request->validate(['package' => ['required', 'file', 'max:20480', 'mimetypes:application/zip,application/x-zip-compressed']]);
        $user = $request->user() ?? abort(403);

        return $this->run(function () use ($site, $request, $user): string {
            $theme = $this->library->importPackage($site, (string) $request->file('package')?->getRealPath(), $user);

            return $theme->signed
                ? __('Theme «:name» (signiert) hinzugefügt — Vorschau ansehen und dann wählen.', ['name' => $theme->label ?? $theme->name])
                : __('Theme «:name» hinzugefügt — Vorschau ansehen und dann wählen.', ['name' => $theme->label ?? $theme->name]);
        }, 'package');
    }

    /** Kopie eines Themes (eigenes oder Ordner) als neues eigenes Theme. */
    public function copy(Request $request): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $data = $request->validate([
            'from' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:60', 'regex:'.ThemeLibrary::NAME],
            'label' => ['nullable', 'string', 'max:120'],
        ], ['name.regex' => __('Der Name darf nur Kleinbuchstaben, Ziffern und Bindestriche enthalten (z. B. «mein-theme»).')]);
        $user = $request->user() ?? abort(403);

        return $this->run(fn (): string => __('Kopie «:name» angelegt — im Theme-Editor anpassen, dann wählen.', [
            'name' => $this->library->copy($site, (string) $data['from'], (string) $data['name'], isset($data['label']) ? (string) $data['label'] : null, $user)->name,
        ]), 'name');
    }

    public function destroy(Request $request, string $name): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $theme = $this->library->find($site, $name) ?? abort(404);
        $user = $request->user() ?? abort(403);

        return $this->run(function () use ($theme, $user, $request, $site): string {
            $this->library->remove($theme, $user);
            $this->themes->forget();
            if ($request->session()->get('basalto.theme_preview.'.$site->id) === $theme->name) {
                $request->session()->forget('basalto.theme_preview.'.$site->id);
            }

            return __('Theme «:name» entfernt.', ['name' => $theme->label ?? $theme->name]);
        }, 'theme');
    }

    /** Mitgeliefertes Theme aus- oder wieder einblenden. */
    public function hide(Request $request, string $name): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $hidden = (bool) $request->validate(['hidden' => ['required', 'boolean']])['hidden'];
        $user = $request->user() ?? abort(403);

        return $this->run(function () use ($site, $name, $hidden, $user): string {
            $this->library->setHidden($site, $name, $hidden, $user);

            return $hidden ? __('Theme «:name» ausgeblendet.', ['name' => $name]) : __('Theme «:name» wieder eingeblendet.', ['name' => $name]);
        }, 'theme');
    }

    /** Ein Theme als Paket herunterladen — eigenes mit Bildern, Ordner wie er ist. */
    public function export(string $name): BinaryFileResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        abort_unless($this->library->exists($site, $name), 404);
        $dir = storage_path('app/private/theme-export/'.bin2hex(random_bytes(8)));
        $own = $this->library->find($site, $name);
        $path = $own !== null ? $this->library->export($own, $dir) : (new ThemePackage)->pack($name, $dir)['path'];

        return response()->download($path, basename($path), ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /** @param  callable(): string  $action */
    private function run(callable $action, string $field): RedirectResponse
    {
        try {
            $message = $action();
        } catch (ThemeEditorException $e) {
            return back()->withErrors([$field => $e->getMessage()]);
        }

        return redirect()->route('basalto.setup.themes')->with('status', $message);
    }
}
