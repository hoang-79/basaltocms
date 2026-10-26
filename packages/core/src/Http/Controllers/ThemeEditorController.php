<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Content\EntryRenderer;
use Basalto\Core\Content\Pages;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteTheme;
use Basalto\Core\Models\ThemeDraft;
use Basalto\Core\Models\ThemeVersion;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\ThemeEditor;
use Basalto\Core\Theme\ThemeEditorException;
use Basalto\Core\Theme\ThemeException;
use Basalto\Core\Theme\ThemeLibrary;
use Basalto\Core\Theme\ThemeLoader;
use Basalto\Lava\Lsp\Columns;
use Basalto\Lava\Lsp\LanguageService;
use Basalto\Lava\Lsp\OverlaySource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Lava-Editor im Backend (L-17, Mockup 27): Dateien des Themes aus der Datenbank bearbeiten — mit Befunden, Vorschlägen
 * und Erklärungen desselben Sprachdienstes wie in VS Code, Entwürfen mit Vorschau, Freigabe und Fassungen.
 */
final class ThemeEditorController
{
    public function __construct(private readonly CurrentSite $current, private readonly ThemeEditor $editor, private readonly LanguageService $language, private readonly ThemeLoader $themes, private readonly ThemeLibrary $library) {}

    public function index(Request $request): Response
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $subject = $this->subject($request, $site);
        $own = $this->editor->theme($subject);
        $database = $own !== null;
        $live = $this->editor->liveFiles($subject);
        $drafts = $own === null ? collect() : ThemeDraft::query()->where('site_theme_id', $own->id)->orderBy('path')->get();
        $paths = array_values(array_unique([...array_keys($live), ...$drafts->pluck('path')->all()]));
        sort($paths);
        $path = (string) $request->query('datei', 'theme.lava');
        if (! in_array($path, $paths, true) && ! ThemeEditor::isEditablePath($path)) {
            $path = 'theme.lava';
        }
        $text = $this->editor->currentText($subject, $path);
        $userId = (int) $request->user()?->getAuthIdentifier();
        $activeName = ThemeLibrary::activeName($site);
        $name = $own->name ?? $activeName;

        return Inertia::render('core::ThemeEditor', [
            'database' => $database,
            'theme' => $name,
            'theme_label' => $own?->label,
            'is_active' => $name === $activeName,
            'active_theme' => $activeName,
            'replaces_folder' => $own !== null && ThemeLibrary::hasFolder($own->name),
            'library' => $this->library->all($site)->map(static fn (SiteTheme $t): array => ['name' => $t->name, 'label' => $t->label ?? $t->name, 'active' => $t->name === $activeName])->values()->all(),
            'assets' => $own === null ? [] : array_map(static fn (string $p, array $a): array => ['path' => $p, 'size' => $a['size']], array_keys($own->assets ?? []), array_values($own->assets ?? [])),
            'asset_types' => ThemeLibrary::BINARY,
            'files' => array_map(static fn (string $p): array => [
                'path' => $p,
                'draft' => $drafts->firstWhere('path', $p) !== null,
                'deleted' => $drafts->firstWhere('path', $p)?->source === null && $drafts->firstWhere('path', $p) !== null,
                'editable' => ThemeEditor::isEditablePath($p),
            ], $paths),
            'file' => ['path' => $path, 'text' => $text ?? '', 'exists' => $text !== null, 'base' => hash('sha256', $text ?? ''), 'live' => $live[$path] ?? null, 'editable' => ThemeEditor::isEditablePath($path)],
            'drafts' => $drafts->map(static fn (ThemeDraft $d): array => ['path' => $d->path, 'deleted' => $d->source === null, 'submitted' => $d->submitted_at !== null, 'mine' => $d->updated_by === $userId])->all(),
            'findings' => $database ? $this->editor->check($subject) : [],
            'versions' => $this->editor->versions($subject),
            'four_eyes' => $this->editor->requiresFourEyes($site),
            'preview' => $request->session()->get(self::previewKey($site)) === $name,
            'urls' => [
                'self' => route('basalto.setup.theme-editor'), 'adopt' => route('basalto.setup.theme-editor.adopt'), 'release' => route('basalto.setup.theme-editor.release'),
                'save' => route('basalto.setup.theme-editor.save'), 'discard' => route('basalto.setup.theme-editor.discard'), 'submit' => route('basalto.setup.theme-editor.submit'),
                'publish' => route('basalto.setup.theme-editor.publish'), 'restore' => route('basalto.setup.theme-editor.restore', ['version' => 0]),
                'import' => route('basalto.setup.theme-editor.import'), 'export' => route('basalto.setup.theme-editor.export'),
                'preview' => route('basalto.setup.theme-editor.preview'), 'preview_page' => route('basalto.setup.theme-editor.preview.page'), 'preview_end' => route('basalto.setup.theme-editor.preview.end'),
                'language' => route('basalto.setup.lava.language'), 'themes' => route('basalto.setup.themes'),
                'asset' => route('basalto.setup.theme-editor.asset'), 'asset_destroy' => route('basalto.setup.theme-editor.asset.destroy'),
            ],
        ]);
    }

    public function adopt(Request $request): RedirectResponse
    {
        return $this->run($request, fn (): string => __('Theme in die Datenbank übernommen — Fassung :number hält den Ausgangsstand fest.', ['number' => $this->editor->adopt($this->current->require(), $this->user($request))->number]));
    }

    public function release(Request $request): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        try {
            $kept = $this->editor->release($this->subject($request, $site), $this->user($request));
        } catch (ThemeEditorException $e) {
            return back()->withErrors(['editor' => $e->getMessage()]);
        }
        $request->session()->forget(self::previewKey($site));

        return redirect()->route('basalto.setup.theme-editor')->with('status', __('Die Site nutzt wieder das Theme aus dem Ordner. Ihre Fassung bleibt als «:name» in der Bibliothek.', ['name' => $kept->name]));
    }

    /** Bild oder Schrift ins eigene Theme laden (TB 22) — gilt sofort, ohne Entwurf. */
    public function storeAsset(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.(ThemeLibrary::MAX_ASSET_BYTES / 1024), 'extensions:'.implode(',', ThemeLibrary::BINARY)],
            'folder' => ['nullable', 'string', 'max:120', 'regex:/^assets(\/[A-Za-z0-9_\-]+)*$/'],
        ]);
        $file = $request->file('file') ?? abort(422);
        $name = strtolower((string) preg_replace('/[^A-Za-z0-9_.\-]+/', '-', $file->getClientOriginalName()));
        $path = trim((string) ($request->input('folder') ?: 'assets'), '/').'/'.ltrim($name, '.-');

        return $this->runOn($request, function (SiteTheme $theme) use ($path, $file, $request): string {
            $this->library->storeAsset($theme, $path, (string) file_get_contents((string) $file->getRealPath()), $this->user($request));

            return __('«:path» gespeichert — im Theme unter diesem Pfad verwenden.', ['path' => $path]);
        });
    }

    public function destroyAsset(Request $request): RedirectResponse
    {
        $path = (string) $request->validate(['path' => ['required', 'string', 'max:190']])['path'];

        return $this->runOn($request, function (SiteTheme $theme) use ($path, $request): string {
            $this->library->deleteAsset($theme, $path, $this->user($request));

            return __('«:path» gelöscht.', ['path' => $path]);
        });
    }

    /** Entwurf speichern — als JSON für den Editor (Befunde am Text), ohne Skript mit Weiterleitung. */
    public function save(Request $request): JsonResponse|RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $data = $request->validate(['path' => ['required', 'string', 'max:190'], 'lava_source' => ['nullable', 'string'], 'delete' => ['boolean'], 'base' => ['nullable', 'string', 'size:64']]);
        $path = (string) $data['path'];
        $text = ($data['delete'] ?? false) ? null : (string) ($data['lava_source'] ?? '');
        try {
            $warnings = $this->editor->saveDraft($this->subject($request, $site), $path, $text, $this->user($request), isset($data['base']) ? (string) $data['base'] : null);
        } catch (ThemeEditorException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'findings' => $this->positions($e->diagnostics, $path, $text ?? '')], $e->status);
            }

            return back()->withErrors(['lava_source' => $e->getMessage()])->withInput();
        }
        if ($request->expectsJson()) {
            return response()->json(['message' => __('Entwurf gespeichert.'), 'base' => hash('sha256', $text ?? ''), 'findings' => $this->positions($warnings, $path, $text ?? '')]);
        }

        return redirect()->route('basalto.setup.theme-editor', array_filter(['datei' => $path, 'theme' => $request->input('theme')]))->with('status', __('Entwurf gespeichert.'));
    }

    public function discard(Request $request): RedirectResponse
    {
        $path = $request->input('path');

        return $this->run($request, fn (Site|SiteTheme $subject): string => __(':count Entwurf/Entwürfe verworfen.', ['count' => $this->editor->discard($subject, is_string($path) && $path !== '' ? $path : null, $this->user($request))]));
    }

    public function submit(Request $request): RedirectResponse
    {
        return $this->run($request, fn (Site|SiteTheme $subject): string => __(':count Entwurf/Entwürfe zur Freigabe eingereicht.', ['count' => $this->editor->submit($subject, $this->user($request))]));
    }

    public function publish(Request $request): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->run($request, function (Site|SiteTheme $subject) use ($request, $note): string {
            $version = $this->editor->publish($subject, $this->user($request), is_string($note) ? $note : null);
            $request->session()->forget(self::previewKey($this->current->require()));

            // TB 10 Scheibe 3: geänderte Bildvarianten schneiden die Bilder neu
            return __('Veröffentlicht als Fassung :number.', ['number' => $version->number]).($this->editor->regenerated > 0
                ? ' '.__(':count Bilder werden mit den neuen Bildvarianten neu geschnitten — bis dahin liefert der Cache die alten.', ['count' => $this->editor->regenerated]) : '');
        });
    }

    public function restore(Request $request, ThemeVersion $version): RedirectResponse
    {
        return $this->run($request, fn (Site|SiteTheme $subject): string => __('Fassung :number als :count Entwurf/Entwürfe geholt — prüfen und veröffentlichen.', ['number' => $version->number, 'count' => $this->editor->restore($subject, $version, $this->user($request))]));
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate(['package' => ['required', 'file', 'max:10240', 'mimetypes:application/zip,application/x-zip-compressed']]);

        return $this->run($request, function (Site|SiteTheme $subject) use ($request): string {
            $result = $this->editor->import($subject, (string) $request->file('package')?->getRealPath(), $this->user($request));

            return $result['signed']
                ? __('Paket «:name» (signiert) als :count Entwurf/Entwürfe eingelesen — prüfen und veröffentlichen.', ['name' => $result['name'], 'count' => $result['drafts']])
                : __('Paket «:name» als :count Entwurf/Entwürfe eingelesen — prüfen und veröffentlichen.', ['name' => $result['name'], 'count' => $result['drafts']]);
        });
    }

    public function export(Request $request): BinaryFileResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $dir = storage_path('app/private/theme-export/'.bin2hex(random_bytes(8)));
        $path = $this->editor->export($this->subject($request, $site), $dir);

        return response()->download($path, basename($path), ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /**
     * Eine Seite der Site mit den Entwürfen, gerendert im Backend — für die Vorschau neben dem Editor, unabhängig davon,
     * unter welcher Domain die Site läuft.
     */
    public function previewPage(Request $request, Pages $pages, EntryRenderer $renderer): HttpResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $subject = $this->subject($request, $site);
        // TB 22: ein Theme der Bibliothek, das die Site (noch) nicht zeigt — nur für diese Vorschau
        if ($subject instanceof SiteTheme) {
            $this->themes->previewTheme($site, $subject->name);
        }
        $this->themes->previewDrafts($site);
        $pages->forget();
        [$locale, $path] = $pages->splitLocale($site, '/'.trim((string) $request->query('pfad', '/'), '/'));
        app()->setLocale($locale);
        try {
            $hit = $pages->resolve($site, $locale, $path);
            if ($hit === null) {
                return response('<!doctype html><meta charset="utf-8"><title>'.e(__('Vorschau')).'</title><p style="font:14px system-ui;padding:12px">'.e(__('Unter «:path» ist keine Seite veröffentlicht — oben einen anderen Pfad wählen.', ['path' => $path])).'</p>', 404)->header('X-Robots-Tag', 'noindex');
            }
            $html = $renderer->html($hit['version'], $pages->pageVariables($site, $hit['entry'], $hit['version'], $locale, $hit['path'], $request->getSchemeAndHttpHost()));
        } catch (ThemeException $e) {
            $html = '<!doctype html><meta charset="utf-8"><title>'.e(__('Theme-Fehler')).'</title><p>'.e($e->getMessage()).'</p>';
        }

        return response($html)->header('X-Robots-Tag', 'noindex')->header('Cache-Control', 'no-store, private');
    }

    /** Vorschau der Entwürfe in der eigenen Sitzung — die Site selbst bleibt unverändert. */
    public function preview(Request $request): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $subject = $this->subject($request, $site);
        $request->session()->put(self::previewKey($site), $subject instanceof SiteTheme ? $subject->name : ThemeLibrary::activeName($site));

        return redirect()->to((string) $request->query('pfad', '/'));
    }

    public function endPreview(Request $request): RedirectResponse
    {
        $site = $this->current->require();
        $name = $request->session()->pull(self::previewKey($site));

        return redirect()->route('basalto.setup.theme-editor', array_filter(['theme' => is_string($name) && $name !== ThemeLibrary::activeName($site) ? $name : null]))->with('status', __('Vorschau der Entwürfe beendet.'));
    }

    /**
     * Sprachdienst über HTTP für den Editor im Browser: Befunde, Vorschläge, Erklärung, Definition — auf dem Theme mit
     * Entwürfen und dem ungespeicherten Puffer. Positionen wie im Browser: Zeile ab 1, Zeichen (UTF-16) ab 0.
     */
    public function language(Request $request): JsonResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $data = $request->validate([
            'action' => ['required', 'in:diagnostics,completions,hover,definition'], 'path' => ['required', 'string', 'max:190'],
            'lava_source' => ['present', 'nullable', 'string', 'max:'.ThemeEditor::MAX_BYTES], 'line' => ['nullable', 'integer', 'min:1'], 'character' => ['nullable', 'integer', 'min:0'],
        ]);
        $path = (string) $data['path'];
        abort_unless(ThemeEditor::isEditablePath($path), 422, __('Kein bearbeitbarer Pfad.'));
        $text = (string) ($data['lava_source'] ?? '');
        $subject = $this->subject($request, $site);
        $source = new OverlaySource($this->editor->draftSource($subject), [$path => $text]);
        if ($data['action'] === 'diagnostics') {
            return response()->json(['findings' => $this->positions($this->editor->check($subject, [$path => $text]), $path, $text)]);
        }
        $line = (int) ($data['line'] ?? 1);
        $column = Columns::toBytes(explode("\n", $text)[$line - 1] ?? '', (int) ($data['character'] ?? 0));

        return response()->json(match ($data['action']) {
            'completions' => ['items' => $this->language->completions($source, $path, $line, $column)],
            'hover' => ['markdown' => $this->language->hover($source, $path, $line, $column)],
            default => ['location' => $this->language->definition($source, $path, $line, $column)],
        });
    }

    public static function previewKey(Site $site): string
    {
        return 'basalto.theme_draft_preview.'.$site->id;
    }

    /**
     * Befunde mit Zeichenposition für den Editor (UTF-16 ab 0) — für die offene Datei aus dem Puffer gerechnet.
     *
     * @param  list<array{file: string, line: int, column: int, severity: string, message: string}>  $findings
     * @return list<array{file: string, line: int, column: int, character: int, severity: string, message: string}>
     */
    private function positions(array $findings, string $path, string $text): array
    {
        $lines = explode("\n", $text);

        return array_map(static fn (array $f): array => $f + ['character' => $f['file'] === $path ? Columns::toUtf16($lines[$f['line'] - 1] ?? '', $f['column']) : max(0, $f['column'] - 1)], $findings);
    }

    /** @param  callable(Site|SiteTheme): string  $action */
    private function run(Request $request, callable $action): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        try {
            $message = $action($this->subject($request, $site));
        } catch (ThemeEditorException $e) {
            return back()->withErrors(['editor' => $e->getMessage()]);
        }

        return redirect()->route('basalto.setup.theme-editor', array_filter(['datei' => $request->input('path'), 'theme' => $request->input('theme')]))->with('status', $message);
    }

    /** @param  callable(SiteTheme): string  $action */
    private function runOn(Request $request, callable $action): RedirectResponse
    {
        return $this->run($request, function (Site|SiteTheme $subject) use ($action): string {
            $theme = $this->editor->theme($subject) ?? throw new ThemeEditorException(__('Das Theme liegt im Ordner — zuerst «In die Datenbank übernehmen».'), status: 409);

            return $action($theme);
        });
    }

    /** Das Theme, das der Editor bearbeitet: `?theme=<name>` aus der Bibliothek, sonst das der Site. */
    private function subject(Request $request, Site $site): Site|SiteTheme
    {
        $name = $request->input('theme');

        return is_string($name) && $name !== '' ? ($this->library->find($site, $name) ?? abort(404)) : $site;
    }

    private function user(Request $request): Authenticatable
    {
        return $request->user() ?? abort(403);
    }
}
