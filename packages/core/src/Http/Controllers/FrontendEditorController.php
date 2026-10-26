<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Access\Access;
use Basalto\Core\Content\DraftInput;
use Basalto\Core\Content\FrontendEditor;
use Basalto\Core\Events\EntrySaved;
use Basalto\Core\Http\Middleware\SelectBackendSite;
use Basalto\Core\Models\Entry;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Sites\SiteScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Frontend-Editor (M-19): Modus schalten, Texte von der Seite in den offenen Entwurf speichern, ins Backend wechseln.
 * Die Site kommt aus dem Host (wie die Auslieferung), nicht aus der Backend-Sitzung; ein Inhalt einer anderen Site
 * ist hier nicht zu finden (404). Freigabe und Veröffentlichung bleiben im Backend.
 */
final class FrontendEditorController
{
    public function __construct(private readonly CurrentSite $current, private readonly FrontendEditor $editor) {}

    /** «Auf der Seite bearbeiten» / «Fertig» — gilt für diese Sitzung; was bearbeitbar ist, entscheidet je Seite die Policy. */
    public function mode(Request $request): JsonResponse
    {
        abort_if($request->user() === null, 403);
        $on = $request->boolean('on');
        if ($on) {
            $request->session()->put(FrontendEditor::SESSION_KEY, true);
        } else {
            $request->session()->forget(FrontendEditor::SESSION_KEY);
        }

        return response()->json(['editing' => $on]);
    }

    /**
     * Speichern wie der Autosave des Editors: Änderungen auf die neueste Fassung der Sprache, geprüft über DraftInput,
     * in den offenen Entwurf (`saveDraft`) — oder ein neuer Entwurf, wenn die neueste Fassung keiner ist. Live bleibt.
     * Hat sich die Fassung seit dem Laden geändert, antwortet 409 statt fremde Änderungen zu überschreiben.
     */
    public function save(Request $request, int $entry, string $locale): JsonResponse
    {
        $model = $this->entry($entry);
        Gate::authorize('editOnPage', $model);
        abort_unless(in_array($locale, $model->site->localeCodes(), true), 404);
        app(Access::class)->authorizeLocale($request->user(), $model->site, $locale);
        $validated = $request->validate([
            'base' => ['required', 'array'],
            'base.id' => ['required', 'integer'],
            'base.stamp' => ['required', 'string', 'max:64'],
            'changes' => ['required', 'array', 'min:1', 'max:50'],
            'changes.*' => ['array'],
            'changes.*.field' => ['required', 'string', 'max:200'],
        ]);
        $latest = $model->latestVersion($locale);
        abort_if($latest === null, 404);
        if ($latest->id !== (int) $validated['base']['id'] || FrontendEditor::stamp($latest) !== $validated['base']['stamp']) {
            return response()->json(['message' => __('Diese Seite wurde inzwischen anderswo geändert — bitte neu laden, damit nichts überschrieben wird.')], 409);
        }
        $changes = $request->input('changes');
        $input = $this->editor->input($model, $latest, is_array($changes) ? array_values($changes) : []);
        $data = app(DraftInput::class)->data($model, $input, $latest);
        $author = $request->user()?->getAuthIdentifier();
        $version = $model->saveDraft($locale, $data, is_numeric($author) ? (int) $author : null, $latest->source_version_id);
        event(new EntrySaved($model, $version));

        return response()->json([
            'message' => __('Als Entwurf gespeichert (Fassung :number) — live unverändert bis zur Freigabe im Backend.', ['number' => $version->number]),
            'version' => FrontendEditor::describe($version->refresh()),
        ]);
    }

    /** «Im Backend öffnen»: die Site dieser Seite im Backend wählen, dann der Editor der Sprachfassung. */
    public function open(Request $request, int $entry, string $locale): RedirectResponse
    {
        $model = $this->entry($entry);
        Gate::authorize('view', $model);
        abort_unless(in_array($locale, $model->site->localeCodes(), true), 404);
        $request->session()->put(SelectBackendSite::SESSION_KEY, $model->site_id);

        return redirect()->route('basalto.entries.edit', [$model, $locale]);
    }

    private function entry(int $id): Entry
    {
        $site = $this->current->get();
        abort_if($site === null, 404);

        return Entry::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->findOrFail($id);
    }
}
