<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Backend\Dates;
use Basalto\Core\Events\MediaRestored;
use Basalto\Core\Events\MediaTrashed;
use Basalto\Core\Http\Requests\MediaFolderRequest;
use Basalto\Core\Http\Requests\StoreMediaRequest;
use Basalto\Core\Http\Requests\UpdateMediaRequest;
use Basalto\Core\Jobs\GenerateMediaVariants;
use Basalto\Core\Media\ImageVariants;
use Basalto\Core\Media\MediaUploads;
use Basalto\Core\Media\RemoteImage;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Media;
use Basalto\Core\Models\MediaFolder;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Support\Preferences;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mediathek je Site (K-08, Mockup 14): Ordner, Suche, Kacheln mit Stand der Varianten und fehlenden Alt-Texten,
 * Mehrfach-Upload mit Antwort je Datei, Detail mit Metadaten und Fokuspunkt, Verwendungsnachweis, Papierkorb mit Frist,
 * Ersetzen, Herunterladen; Dateien liegen auf der Standard-Disk unter `sites/<id>/media/JJJJ/MM`.
 */
final class MediaController
{
    public function __construct(private readonly CurrentSite $current, private readonly ImageVariants $variants, private readonly Dates $dates, private readonly MediaUploads $uploads) {}

    public function index(Request $request): Response
    {
        $site = $this->current->require();
        $view = $request->query('view') === 'trash' ? 'trash' : 'library';
        $folderId = $request->filled('folder') ? (int) $request->query('folder') : null;
        $q = trim((string) $request->query('q', ''));
        $type = in_array($request->query('type'), ['image', 'document'], true) ? (string) $request->query('type') : '';
        $locales = $site->localeCodes();
        $altRequired = app(Settings::class)->get('media.alt_required', false, $site) === true;

        $query = Media::query()->withCount('usages')->with('uploader');
        if ($view === 'trash') {
            $query->onlyTrashed()->latest('deleted_at');
        } else {
            // TB 21: Sortierung nach dem Profil — «nach Titel» heisst hier nach Dateiname
            Preferences::sort($query, $request->user(), static fn ($q) => $q->orderBy('name')->orderBy('id'));
            if ($folderId !== null) {
                $query->where('folder_id', $folderId);
            }
        }
        if ($q !== '') {
            $query->where(static fn (Builder $b) => $b->where('name', 'like', '%'.$q.'%')->orWhere('title', 'like', '%'.$q.'%')->orWhere('credit', 'like', '%'.$q.'%'));
        }
        if ($type === 'image') {
            $query->where('mime', 'like', 'image/%');
        } elseif ($type === 'document') {
            $query->where('mime', 'not like', 'image/%');
        }
        $paginator = $query->paginate(Preferences::perPage($request->user()))->appends($request->query());
        $rows = [];
        foreach ($paginator->items() as $m) {
            $rows[] = $this->row($m, $locales, $altRequired, $request);
        }

        $selected = null;
        if ($request->filled('media')) {
            $chosen = Media::query()->withTrashed()->withCount('usages')->with(['uploader', 'usages.entry'])->find((int) $request->query('media'));
            if ($chosen !== null) {
                $selected = $this->detail($chosen, $site, $locales);
            }
        }

        $folders = [];
        $this->folderTree($site, null, 0, $folders);
        $used = (int) Media::query()->sum('size');
        $quotaMb = config('basalto.media.quota_megabytes');
        $variantNames = $this->variants->namesFor($site);

        return Inertia::render('core::Media', [
            'folders' => $folders,
            'counts' => ['all' => Media::query()->count(), 'trash' => Media::query()->onlyTrashed()->count()],
            'folder' => $folderId,
            'view' => $view,
            'filters' => ['q' => $q, 'type' => $type],
            'media' => $rows,
            'pagination' => ['next' => $paginator->nextPageUrl(), 'prev' => $paginator->previousPageUrl(), 'page' => $paginator->currentPage(), 'total' => $paginator->total()],
            'selected' => $selected,
            'variant_names' => $variantNames,
            'locales' => $site->locales->map(static fn ($l): array => ['locale' => $l->locale, 'is_default' => (bool) $l->is_default, 'is_required' => (bool) $l->is_required])->values()->all()
                ?: [['locale' => $site->default_locale, 'is_default' => true, 'is_required' => true]],
            'alt_required' => $altRequired,
            'quota' => is_int($quotaMb) && $quotaMb > 0 ? ['used' => $used, 'limit' => $quotaMb * 1024 * 1024] : null,
            'storage_used' => $used,
            'trash_days' => (int) app(Settings::class)->get('media.trash_days', (int) config('basalto.media.trash_days', 30)),
            'can_manage' => Gate::allows('manage', [Media::class, $site]),
            // TB 29: Hochladen und Angaben pflegen wie auf dem Server ab «bearbeiten» — Ordner, Ersetzen, Papierkorb ab «löschen»
            'can_upload' => Gate::allows('upload', [Media::class, $site]),
            'urls' => ['index' => route('basalto.media.index'), 'upload' => route('basalto.media.store'), 'folders' => route('basalto.media.folders.store')],
            'status' => session('status'),
        ]);
    }

    /**
     * Upload: eine Datei (`file`) oder viele (`files[]`) — jede wird einzeln geprüft und gemeldet, eine Abweisung hält die
     * anderen nicht auf; über dem Kontingent nimmt die Site nichts mehr an. JSON für den Mehrfach-Upload, sonst Redirect.
     */
    public function store(StoreMediaRequest $request): JsonResponse|RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('upload', [Media::class, $site]);
        $folderId = $this->folderIdFor($request, $site);
        $files = $request->file('files');
        $files = is_array($files) ? array_values($files) : [$request->file('file')];
        $results = [];
        $stored = [];
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            $name = $file->getClientOriginalName();
            $validator = Validator::make(['file' => $file], ['file' => StoreMediaRequest::fileRules()], [], ['file' => __('Datei')]);
            if ($validator->fails()) {
                $results[] = ['name' => $name, 'ok' => false, 'error' => (string) $validator->errors()->first('file')];

                continue;
            }
            if (($reason = $this->uploads->refuse($file, $site)) !== null) {
                $results[] = ['name' => $name, 'ok' => false, 'error' => $reason];

                continue;
            }
            $media = $this->uploads->store($file, $site, $folderId, $request->user()?->getAuthIdentifier());
            $stored[] = $media;
            $results[] = ['name' => $name, 'ok' => true, 'id' => $media->id, 'media' => $this->row($media, $site->localeCodes(), app(Settings::class)->get('media.alt_required', false, $site) === true, $request)];
        }
        if ($request->expectsJson()) {
            return response()->json(['files' => $results]);
        }
        $rejected = array_values(array_filter($results, static fn (array $r): bool => ! $r['ok']));
        if ($rejected !== [] && $stored === []) {
            return redirect()->route('basalto.media.index')->withErrors(['file' => $rejected[0]['error']]);
        }
        $status = count($stored) === 1 ? __('«:name» hochgeladen — Varianten folgen aus der Warteschlange.', ['name' => $stored[0]->name]) : __(':count Dateien hochgeladen — Varianten folgen aus der Warteschlange.', ['count' => count($stored)]);
        if ($rejected !== []) {
            $status .= ' '.__(':count abgewiesen.', ['count' => count($rejected)]);
        }

        return redirect()->route('basalto.media.index', array_filter(['folder' => $folderId, 'media' => count($stored) === 1 ? $stored[0]->id : null]))->with('status', $status);
    }

    public function update(UpdateMediaRequest $request, Media $media): RedirectResponse
    {
        Gate::authorize('update', $media);
        $site = $media->site;
        // Alt-Texte nur überschreiben, wenn das Formular sie schickt — sonst bleiben sie (Fokuspunkt-Ziehen schickt nur den Punkt)
        $alt = $media->alt ?? [];
        if ($request->has('alt')) {
            foreach ($site->localeCodes() as $locale) {
                $value = $request->input('alt.'.$locale);
                $alt[$locale] = is_string($value) ? trim($value) : '';
            }
        }
        $focusX = $request->filled('focus_x') ? round((float) $request->input('focus_x'), 3) : $media->focus_x;
        $focusY = $request->filled('focus_y') ? round((float) $request->input('focus_y'), 3) : $media->focus_y;
        $focusChanged = abs($focusX - $media->focus_x) > 0.0005 || abs($focusY - $media->focus_y) > 0.0005;
        $media->fill([
            'title' => $request->filled('title') ? $request->string('title')->toString() : null,
            'credit' => $request->filled('credit') ? $request->string('credit')->toString() : null,
            'alt' => $alt,
            'focus_x' => $focusX,
            'focus_y' => $focusY,
            'folder_id' => $request->has('folder_id') ? $this->folderIdFor($request, $site) : $media->folder_id,
        ])->save();
        if ($focusChanged && $media->isRasterImage()) {
            GenerateMediaVariants::dispatch($media->id);
        }

        return back()->with('status', $focusChanged && $media->isRasterImage() ? __('«:name» gespeichert — Varianten werden neu geschnitten.', ['name' => $media->name]) : __('«:name» gespeichert.', ['name' => $media->name]));
    }

    /** In den Papierkorb — die Verwendungen bleiben stehen, die Seiten liefern bis zum endgültigen Löschen weiter (Mockup 14/2). */
    public function destroy(Media $media): RedirectResponse
    {
        Gate::authorize('delete', $media);
        $usages = $media->usages()->count();
        $media->delete();
        event(new MediaTrashed($media, $usages));
        $status = $usages > 0
            ? trans_choice('«:name» liegt im Papierkorb — :count Inhalt zeigen bis zum endgültigen Löschen die Variante aus dem Cache.|«:name» liegt im Papierkorb — :count Inhalte zeigen bis zum endgültigen Löschen die Variante aus dem Cache.', $usages, ['name' => $media->name, 'count' => $usages])
            : __('«:name» liegt im Papierkorb — endgültig gelöscht nach :days Tagen.', ['name' => $media->name, 'days' => (int) app(Settings::class)->get('media.trash_days', (int) config('basalto.media.trash_days', 30))]);

        return redirect()->route('basalto.media.index', array_filter(['folder' => $media->folder_id]))->with('status', $status);
    }

    public function restore(Media $media): RedirectResponse
    {
        Gate::authorize('delete', $media);
        $media->restore();
        event(new MediaRestored($media));

        return redirect()->route('basalto.media.index', ['media' => $media->id])->with('status', __('«:name» wiederhergestellt.', ['name' => $media->name]));
    }

    /** Datei ersetzen: dieselbe Nummer, dieselben Verwendungen — neue Datei, Varianten neu. */
    public function replace(Request $request, Media $media): RedirectResponse
    {
        Gate::authorize('delete', $media);
        $request->validate(['file' => ['required', ...StoreMediaRequest::fileRules()]], [], ['file' => __('Datei')]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        if (($reason = $this->uploads->refuse($file, $media->site, $media->size)) !== null) {
            return back()->withErrors(['file' => $reason]);
        }
        $old = array_values(array_unique([$media->path, ...$media->variantPaths()]));
        $path = $this->uploads->put($file, $media->site);
        $size = @getimagesize($file->getRealPath());
        $media->forceFill([
            'path' => $path, 'name' => $file->getClientOriginalName(), 'mime' => (string) $file->getMimeType(), 'size' => $file->getSize(),
            'width' => is_array($size) ? (int) $size[0] : null, 'height' => is_array($size) ? (int) $size[1] : null, 'variants' => null, 'variants_generated_at' => null,
        ])->save();
        $media->storage()->delete($old);
        if ($media->isRasterImage()) {
            GenerateMediaVariants::dispatch($media->id);
        }

        return back()->with('status', __('«:name» ersetzt — Varianten folgen aus der Warteschlange.', ['name' => $media->name]));
    }

    public function download(Media $media): StreamedResponse
    {
        Gate::authorize('view', $media);

        return $media->storage()->download($media->path, $media->name);
    }

    public function storeFolder(MediaFolderRequest $request): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('manage', [Media::class, $site]);
        $parent = $request->filled('parent_id') ? MediaFolder::query()->find((int) $request->input('parent_id')) : null;
        $folder = MediaFolder::query()->create(['site_id' => $site->id, 'parent_id' => $parent?->id, 'name' => $request->string('name')->toString()]);

        return redirect()->route('basalto.media.index', ['folder' => $folder->id])->with('status', __('Ordner «:name» angelegt.', ['name' => $folder->name]));
    }

    public function updateFolder(MediaFolderRequest $request, MediaFolder $folder): RedirectResponse
    {
        Gate::authorize('manage', [Media::class, $folder->site]);
        $folder->update(['name' => $request->string('name')->toString()]);

        return back()->with('status', __('Ordner «:name» umbenannt.', ['name' => $folder->name]));
    }

    /** Ordner löschen — die Dateien darin (und in Unterordnern) wandern nach «Alle». */
    public function destroyFolder(MediaFolder $folder): RedirectResponse
    {
        Gate::authorize('manage', [Media::class, $folder->site]);
        $name = $folder->name;
        $folder->delete();

        return redirect()->route('basalto.media.index')->with('status', __('Ordner «:name» gelöscht — die Dateien liegen jetzt unter «Alle».', ['name' => $name]));
    }

    /**
     * Einfügen im Editor (M-04): ein Bild aus der Zwischenablage (Datei) oder aus eingefügtem HTML (Data-URL) landet in der
     * Mediathek und kommt als Bildknoten zurück. Externe Adressen holt der Server nicht (kein Abruf fremder Hosts).
     */
    public function paste(Request $request, RemoteImage $remoteImage): JsonResponse
    {
        $site = $this->current->require();
        Gate::authorize('create', new Entry(['site_id' => $site->id]));
        $file = $request->file('file');
        $tmp = null;
        if (! $file instanceof UploadedFile) {
            $data = (string) $request->input('data', '');
            if (preg_match('#^https?://#i', $data) === 1) {
                // Bilder aus Webseiten und Google Docs: der Server holt sie — nur von öffentlichen Adressen
                try {
                    $remote = $remoteImage->fetch($data);
                } catch (RuntimeException $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }
                [$binary, $mime, $name] = [$remote['binary'], $remote['mime'], $remote['name']];
            } else {
                if (preg_match('#^data:(image/(?:png|jpeg|gif|webp));base64,(.+)$#s', $data, $m) !== 1) {
                    return response()->json(['message' => __('Kein Bild im eingefügten Inhalt.')], 422);
                }
                $binary = base64_decode($m[2], true);
                if ($binary === false || strlen($binary) > 10 * 1024 * 1024) {
                    return response()->json(['message' => __('Das Bild ist beschädigt oder grösser als 10 MB.')], 422);
                }
                $mime = $m[1];
                $extension = explode('/', $mime)[1] === 'jpeg' ? 'jpg' : explode('/', $mime)[1];
                $name = (string) $request->input('name', '');
                $name = $name !== '' ? $name : 'eingefuegt-'.now()->format('Ymd-His').'.'.$extension;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'paste');
            if ($tmp === false) {
                abort(500, 'Kein temporärer Speicher');
            }
            file_put_contents($tmp, $binary);
            $file = new UploadedFile($tmp, $name, $mime, null, true);
        }
        $request->validate(['file' => ['nullable', 'file', 'max:10240', 'mimetypes:image/jpeg,image/png,image/webp,image/gif']]);
        abort_unless(str_starts_with((string) $file->getMimeType(), 'image/'), 422, __('Nur Bilder lassen sich einfügen.'));
        if (($reason = $this->uploads->refuse($file, $site)) !== null) {
            return response()->json(['message' => $reason], 422);
        }
        $media = $this->uploads->store($file, $site, null, $request->user()?->getAuthIdentifier());
        if ($tmp !== null) {
            @unlink($tmp);
        }

        return response()->json(['id' => $media->id, 'url' => $media->url(), 'alt' => pathinfo($media->name, PATHINFO_FILENAME), 'width' => $media->width, 'height' => $media->height]);
    }

    /** Auslieferung einer Datei oder Variante (Format und Dichte wählbar) — auch aus dem Papierkorb, bis sie endgültig weg ist. */
    public function show(Request $request, Media $media, ?string $variant = null): StreamedResponse
    {
        $format = $request->query('format') === 'avif' ? 'avif' : 'webp';
        $density = max(1, (int) $request->query('density', 1));
        $path = $variant !== null ? ($media->variantPath($variant, $format, $density) ?? $media->path) : $media->path;

        return $media->storage()->response($path);
    }

    private function folderIdFor(Request $request, Site $site): ?int
    {
        if (! $request->filled('folder_id')) {
            return null;
        }
        $folder = MediaFolder::query()->find((int) $request->input('folder_id'));

        return $folder !== null && $folder->site_id === $site->id ? $folder->id : null;
    }

    /**
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    private function row(Media $m, array $locales, bool $altRequired, Request $request): array
    {
        $keep = array_filter(['folder' => $request->query('folder'), 'view' => $request->query('view'), 'q' => $request->query('q'), 'type' => $request->query('type')], static fn (mixed $v): bool => $v !== null && $v !== '');

        return [
            'id' => $m->id, 'name' => $m->name, 'mime' => $m->mime, 'size' => $m->size, 'width' => $m->width, 'height' => $m->height, 'is_image' => $m->isImage(),
            'url' => $m->isImage() ? $m->url(isset($m->variants['thumb']) ? 'thumb' : null) : null,
            'full' => $m->isImage() ? $m->url(isset($m->variants['content']) ? 'content' : null) : null,
            'variants_state' => $m->variantsState(),
            'created_at' => $this->dates->relative($m->created_at),
            'uploaded_by' => $m->uploader?->getAttribute('name'),
            'folder_id' => $m->folder_id,
            'alt_missing' => $altRequired ? $m->missingAlt($locales) : [],
            'usages' => (int) $m->getAttribute('usages_count'),
            'detail_url' => route('basalto.media.index', $keep + ['media' => $m->id]),
        ];
    }

    /**
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    private function detail(Media $m, Site $site, array $locales): array
    {
        $specs = $this->variants->forSite($site);
        $variants = [];
        foreach ($m->variants ?? [] as $name => $file) {
            $formats = ['webp'];
            if (($file['avif'] ?? null) !== null) {
                $formats[] = 'avif';
            }
            $variants[] = ['name' => $name, 'width' => $file['width'], 'height' => $file['height'], 'url' => $m->url($name), 'formats' => $formats,
                'densities' => array_merge([1], array_map('intval', array_keys($file['densities'] ?? []))), 'spec' => isset($specs[$name]) ? $specs[$name]->describe() : null];
        }
        $usages = [];
        foreach ($m->usages as $usage) {
            $entry = $usage->entry;
            if ($entry === null) {
                continue;
            }
            $version = $entry->latestVersion($usage->locale);
            $usages[] = ['entry_id' => $entry->id, 'title' => $version !== null ? $version->title : $entry->type, 'locale' => $usage->locale, 'field' => $usage->field,
                'url' => $entry->pageType() !== null && $usage->field === 'settings.image' ? route('basalto.pages.edit', $entry) : route('basalto.entries.edit', [$entry, $usage->locale])];
        }
        $alt = [];
        foreach ($locales as $locale) {
            $alt[$locale] = $m->altFor($locale) ?? '';
        }

        return [
            'id' => $m->id, 'name' => $m->name, 'mime' => $m->mime, 'size' => $m->size, 'width' => $m->width, 'height' => $m->height, 'is_image' => $m->isImage(),
            'title' => $m->title ?? '', 'credit' => $m->credit ?? '', 'alt' => $alt, 'focus_x' => $m->focus_x, 'focus_y' => $m->focus_y,
            'url' => $m->isImage() ? $m->url(isset($m->variants['content']) ? 'content' : null) : null, 'original_url' => $m->url(), 'folder_id' => $m->folder_id,
            'variants' => $variants, 'variants_state' => $m->variantsState(),
            'usages' => $usages,
            'uploaded' => ['by' => $m->uploader?->getAttribute('name'), 'at' => $this->dates->dateTime($m->created_at)],
            'deleted_at' => $this->dates->dateTime($m->deleted_at),
            'urls' => ['update' => route('basalto.media.update', $m), 'delete' => route('basalto.media.destroy', $m), 'restore' => route('basalto.media.restore', $m),
                'replace' => route('basalto.media.replace', $m), 'download' => route('basalto.media.download', $m)],
        ];
    }

    /** @param  list<array<string, mixed>>  $out */
    private function folderTree(Site $site, ?int $parentId, int $depth, array &$out): void
    {
        $folders = MediaFolder::query()->where('parent_id', $parentId)->withCount('media')->orderBy('position')->orderBy('name')->get();
        foreach ($folders as $folder) {
            $out[] = ['id' => $folder->id, 'name' => $folder->name, 'parent_id' => $folder->parent_id, 'depth' => $depth, 'count' => (int) $folder->getAttribute('media_count'),
                'urls' => ['update' => route('basalto.media.folders.update', $folder), 'delete' => route('basalto.media.folders.destroy', $folder)]];
            if ($depth < 5) {
                $this->folderTree($site, $folder->id, $depth + 1, $out);
            }
        }
    }
}
