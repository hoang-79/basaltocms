<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Access\Level;
use Basalto\Core\Activity\Recorder;
use Basalto\Core\Content\Snippets;
use Basalto\Core\Content\ThemeSchema;
use Basalto\Core\Models\Media;
use Basalto\Core\Models\Snippet;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\ThemeException;
use Basalto\Core\Theme\ThemeLoader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Station F — Bausteine (M-28, Mockup 27 Bild 4): wiederverwendbare Blockgruppen je Site mit Verwendungsnachweis. Ein
 * Baustein wird im Editor über «+ Baustein» als Verweis eingesetzt; eine Änderung hier wirkt sofort auf allen Seiten,
 * die ihn tragen. Verwalten dürfen Betreiber und Administration, einsetzen jede Redaktion.
 */
final class SnippetController
{
    public function __construct(private readonly CurrentSite $current, private readonly ThemeLoader $themes, private readonly Snippets $snippets, private readonly Recorder $recorder) {}

    public function index(Request $request): Response
    {
        $site = $this->current->require();
        $usage = $this->snippets->usage($site);
        $schema = null;
        $broken = null;
        try {
            $schema = ThemeSchema::fromTheme($this->themes->forSite($site));
        } catch (ThemeException $e) {
            $broken = $e->getMessage();
        }
        $list = Snippet::query()->orderBy('name')->get();
        $selected = $request->query('baustein');
        $userModel = config('auth.providers.users.model');

        return Inertia::render('core::Snippets/Index', [
            'broken' => $broken,
            'can_edit' => Gate::allows('update', $site),
            'snippets' => $list->map(static fn (Snippet $s): array => ['id' => $s->id, 'handle' => $s->handle, 'name' => $s->name, 'blocks' => $s->blocks, 'used_in' => $usage[$s->handle] ?? [],
                'updated_at' => $s->updated_at?->toIso8601String(), 'updated_by' => $s->updated_by === null ? null : (string) ($userModel::query()->whereKey($s->updated_by)->value('name') ?? '—')])->all(),
            'selected' => is_string($selected) ? $selected : null,
            'schema' => $schema?->toArray()['blocks'] ?? [],
            'parts' => $schema === null ? [] : $schema->parts,
            'media' => Media::query()->latest('id')->limit(100)->get()->map(static fn (Media $m): array => ['id' => $m->id, 'name' => $m->name, 'url' => $m->isImage() ? $m->url('thumb') : null,
                'mime' => $m->mime, 'width' => $m->width, 'height' => $m->height])->all(),
            'urls' => ['index' => route('basalto.setup.snippets.index'), 'store' => route('basalto.setup.snippets.store'), 'paste' => route('basalto.media.paste'),
                'entry' => route('basalto.entries.edit', ['entry' => '__id__', 'locale' => '__locale__'])],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $data = $this->input($request, null);
        $snippet = Snippet::query()->create([...$data, 'updated_by' => (int) $request->user()?->getAuthIdentifier()]);
        $this->recorder->record('snippet.create', $snippet, null, ['handle' => $snippet->handle, 'bloecke' => count($snippet->blocks)], $site);

        return redirect()->route('basalto.setup.snippets.index', ['baustein' => $snippet->handle])->with('status', __('Baustein «:name» angelegt — im Editor über «+ Baustein» einsetzen.', ['name' => $snippet->name]));
    }

    public function update(Request $request, Snippet $snippet): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $before = ['name' => $snippet->name, 'bloecke' => count($snippet->blocks)];
        $data = $this->input($request, $snippet);
        $snippet->fill([...$data, 'updated_by' => (int) $request->user()?->getAuthIdentifier()])->save();
        $this->recorder->record('snippet.update', $snippet, $before, ['name' => $snippet->name, 'bloecke' => count($snippet->blocks)], $site);
        $pages = count($this->snippets->usage($site)[$snippet->handle] ?? []);

        return redirect()->route('basalto.setup.snippets.index', ['baustein' => $snippet->handle])
            ->with('status', __('Baustein «:name» gespeichert — wirkt sofort :where.', ['name' => $snippet->name, 'where' => trans_choice('auf :count Seite|auf :count Seiten', $pages, ['count' => $pages])]));
    }

    /** Löschen nur, wenn ihn keine Seite trägt — sonst «Auflösen». */
    public function destroy(Snippet $snippet): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $pages = count($this->snippets->usage($site)[$snippet->handle] ?? []);
        if ($pages > 0) {
            return back()->withErrors(['snippet' => __('«:name» steht auf :count Seite(n) — «Auflösen» macht daraus eigene Blöcke, dann ist er weg.', ['name' => $snippet->name, 'count' => $pages])]);
        }
        $this->recorder->record('snippet.delete', $snippet, ['handle' => $snippet->handle, 'name' => $snippet->name], null, $site);
        $snippet->delete();

        return redirect()->route('basalto.setup.snippets.index')->with('status', __('Baustein gelöscht.'));
    }

    /** Auflösen: In allen Seiten werden die Verweise zu eigenen Blöcken — nichts geht verloren, der Baustein ist danach weg. */
    public function dissolve(Request $request, Snippet $snippet): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $versions = $this->snippets->dissolve($site, $snippet, $request->user() ?? abort(403));

        return redirect()->route('basalto.setup.snippets.index')->with('status', __('«:name» aufgelöst — :count Fassung(en) tragen jetzt eigene Blöcke.', ['name' => $snippet->name, 'count' => $versions]));
    }

    /**
     * Auswahl für den Block-Editor: Bausteine mit Kurzinhalt und die Lava-Teile des Themes (Block «@part»).
     */
    public function options(): JsonResponse
    {
        $site = $this->current->require();
        Gate::authorize('view', $site);
        $parts = [];
        try {
            $theme = $this->themes->forSite($site);
            foreach ($theme->document->all('part') as $part) {
                $parts[] = ['name' => (string) $part->name, 'kind' => (string) $part->typedFirst()];
            }
        } catch (ThemeException) {
            // Ohne gültiges Theme gibt es keine Teile zur Auswahl
        }

        return response()->json([
            'snippets' => Snippet::query()->orderBy('name')->get()->map(static fn (Snippet $s): array => ['handle' => $s->handle, 'name' => $s->name, 'blocks' => $s->blocks])->all(),
            'parts' => $parts,
            'manage' => Gate::allows('area', [$site, 'structure', Level::Edit]) ? route('basalto.setup.snippets.index') : null,
        ]);
    }

    /** @return array{handle: string, name: string, blocks: list<mixed>} */
    private function input(Request $request, ?Snippet $snippet): array
    {
        $site = $this->current->require();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'handle' => ['required', 'string', 'regex:'.Snippets::HANDLE, Rule::unique('snippets', 'handle')->where('site_id', $site->id)->ignore($snippet?->id)],
            'blocks' => ['present', 'array', 'max:50'],
            'blocks.*.type' => ['required', 'string'],
            'blocks.*.data' => ['array'],
        ], ['handle.regex' => __('Kleinbuchstaben, Ziffern und Bindestrich.'), 'handle.unique' => __('Diese Kennung hat schon ein anderer Baustein.')]);
        $blocks = array_values($data['blocks'] ?? []);
        try {
            $errors = ThemeSchema::fromTheme($this->themes->forSite($site))->withoutRequired()->validateSnippet($blocks);
        } catch (ThemeException) {
            $errors = ['blocks' => __('Das Theme hat Fehler — Blöcke lassen sich erst prüfen, wenn es wieder kompiliert.')];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if ($snippet !== null && $snippet->handle !== $data['handle'] && ($this->snippets->usage($site)[$snippet->handle] ?? []) !== []) {
            throw ValidationException::withMessages(['handle' => __('Die Kennung bleibt, solange Seiten den Baustein tragen.')]);
        }

        return ['handle' => (string) $data['handle'], 'name' => (string) $data['name'], 'blocks' => $blocks];
    }
}
