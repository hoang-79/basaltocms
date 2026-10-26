<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Api;

use Basalto\Core\Events\MediaTrashed;
use Basalto\Core\Http\Requests\StoreMediaRequest;
use Basalto\Core\Http\Resources\MediaResource;
use Basalto\Core\Media\MediaUploads;
use Basalto\Core\Models\Media;
use Basalto\Core\Models\MediaFolder;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mediathek per REST-API (M-17): Liste mit Suche und Typ, einzeln, Originaldatei, Hochladen über denselben Weg wie das
 * Backend ({@see MediaUploads}: Typ, Grösse, Kontingent, SVG ohne Skript, Varianten), Metadaten (Titel, Urheber,
 * Alt-Text je Sprache) und Papierkorb. Rechte über die MediaPolicy.
 */
final class MediaController
{
    private const PER_PAGE = 25;

    public function __construct(private readonly CurrentSite $current, private readonly MediaUploads $uploads) {}

    /** Liste: `q` (Name, Titel, Urheber), `type` (image, document), `folder_id`, `per_page`. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $site = $this->current->require();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', Rule::in(['image', 'document'])],
            'folder_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = Media::query()->orderByDesc('id');
        if (($filters['q'] ?? '') !== '') {
            $q = (string) $filters['q'];
            $query->where(static fn (Builder $b) => $b->whereLike('name', '%'.$q.'%')->orWhereLike('title', '%'.$q.'%')->orWhereLike('credit', '%'.$q.'%'));
        }
        if (($filters['type'] ?? null) === 'image') {
            $query->where('mime', 'like', 'image/%');
        } elseif (($filters['type'] ?? null) === 'document') {
            $query->where('mime', 'not like', 'image/%');
        }
        if (($filters['folder_id'] ?? null) !== null) {
            $query->where('folder_id', (int) $filters['folder_id']);
        }
        $page = $query->paginate((int) ($filters['per_page'] ?? self::PER_PAGE))->withQueryString();
        $page->getCollection()->each(static fn (Media $media) => $media->setRelation('site', $site));

        return MediaResource::collection($page);
    }

    public function show(int $id): MediaResource
    {
        $media = $this->find($id, $this->current->require());
        Gate::authorize('view', $media);

        return new MediaResource($media);
    }

    /** Die Originaldatei. */
    public function file(int $id): StreamedResponse
    {
        $media = $this->find($id, $this->current->require());
        Gate::authorize('view', $media);

        return $media->storage()->download($media->path, $media->name);
    }

    /** Hochladen: eine Datei (`file`, multipart), optional in einen Ordner der Site (`folder_id`). */
    public function store(Request $request): JsonResponse
    {
        $site = $this->current->require();
        Gate::authorize('upload', [Media::class, $site]);
        $data = $request->validate([
            'file' => ['required', ...StoreMediaRequest::fileRules()],
            'folder_id' => ['nullable', 'integer', Rule::exists(MediaFolder::class, 'id')->where('site_id', $site->id)],
        ], [], ['file' => __('Datei'), 'folder_id' => __('Ordner')]);
        $file = $data['file'];
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => __('Keine Datei empfangen.')]);
        }
        $reason = $this->uploads->refuse($file, $site);
        if ($reason !== null) {
            throw ValidationException::withMessages(['file' => $reason]);
        }
        $media = $this->uploads->store($file, $site, isset($data['folder_id']) ? (int) $data['folder_id'] : null, $request->user()?->getAuthIdentifier());
        $media->setRelation('site', $site);

        return (new MediaResource($media))->response()->setStatusCode(201);
    }

    /** Metadaten: nur, was die Anfrage nennt — `title`, `credit`, `alt` je Sprache der Site (andere Sprachen bleiben). */
    public function update(Request $request, int $id): MediaResource
    {
        $site = $this->current->require();
        $media = $this->find($id, $site);
        Gate::authorize('update', $media);
        $locales = $site->localeCodes();
        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'credit' => ['sometimes', 'nullable', 'string', 'max:200'],
            'alt' => ['sometimes', 'array:'.implode(',', $locales)],
            'alt.*' => ['nullable', 'string', 'max:500'],
        ], ['alt.array' => __('Alt-Texte je Sprache der Site: :locales.', ['locales' => implode(', ', $locales)])], ['title' => __('Titel'), 'credit' => __('Urheber'), 'alt.*' => __('Alt-Text')]);
        $clean = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $changes = [];
        foreach (['title', 'credit'] as $key) {
            if (array_key_exists($key, $data)) {
                $changes[$key] = $clean($data[$key]);
            }
        }
        if (array_key_exists('alt', $data)) {
            $alt = $media->alt ?? [];
            foreach ((array) $data['alt'] as $locale => $value) {
                $alt[(string) $locale] = $clean($value) ?? '';
            }
            $changes['alt'] = $alt;
        }
        $media->fill($changes)->save();

        return new MediaResource($media);
    }

    /** In den Papierkorb — Verwendungen bleiben, endgültig gelöscht nach der Frist der Site. */
    public function destroy(int $id): Response
    {
        $media = $this->find($id, $this->current->require());
        Gate::authorize('delete', $media);
        $usages = $media->usages()->count();
        $media->delete();
        event(new MediaTrashed($media, $usages));

        return response()->noContent();
    }

    private function find(int $id, Site $site): Media
    {
        $media = Media::query()->findOrFail($id);
        $media->setRelation('site', $site);

        return $media;
    }
}
