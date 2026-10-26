<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Content\PageType;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Media;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Sites\SiteScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Backend-Suche im Kopf (M-12): Inhalte nach Titel (auch Entwürfe, jede Sprache), Medien nach Name und Titel,
 * Benutzer der Site nach Name und E-Mail (nur für die Verwaltung) — je höchstens sechs Treffer mit Ziel.
 */
final class BackendSearchController
{
    private const LIMIT = 6;

    public function __construct(private readonly CurrentSite $current) {}

    public function __invoke(Request $request): JsonResponse
    {
        $site = $this->current->require();
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 100));
        if (mb_strlen($q) < 2) {
            return response()->json(['groups' => []]);
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($q)).'%';
        $groups = [];

        $versions = EntryVersion::query()->whereRaw('LOWER(title) LIKE ?', [$like])
            ->whereHas('entry', static fn ($e) => $e->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id))
            ->orderByDesc('updated_at')->limit(30)->get(['entry_id', 'locale', 'title', 'status']);
        $entries = Entry::query()->whereIn('id', $versions->pluck('entry_id')->unique())->get()->keyBy('id');
        $items = [];
        foreach ($versions->unique('entry_id')->take(self::LIMIT) as $version) {
            $entry = $entries->get($version->entry_id);
            if ($entry === null) {
                continue;
            }
            $type = $entry->pageType();
            $items[] = ['title' => $version->title, 'meta' => sprintf('%s · %s · %s', $type?->label() ?? $entry->type, strtoupper($version->locale), $version->status->label()),
                'url' => $type === null || $type === PageType::Page ? route('basalto.entries.edit', [$entry, $version->locale]) : route('basalto.pages.edit', $entry)];
        }
        if ($items !== []) {
            $groups[] = ['label' => __('Inhalte'), 'items' => $items];
        }

        $media = Media::query()->where(static fn ($m) => $m->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(COALESCE(title, \'\')) LIKE ?', [$like]))
            ->orderByDesc('id')->limit(self::LIMIT)->get(['id', 'name', 'mime']);
        if ($media->isNotEmpty()) {
            $groups[] = ['label' => __('Medien'), 'items' => $media->map(static fn (Media $m): array => ['title' => $m->name, 'meta' => $m->mime, 'url' => route('basalto.media.index', ['q' => $m->name])])->all()];
        }

        if (Gate::allows('manageMembers', $site)) {
            $users = $site->users()->where(static fn ($u) => $u->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(email) LIKE ?', [$like]))->limit(self::LIMIT)->get();
            if ($users->isNotEmpty()) {
                $groups[] = ['label' => __('Benutzer'), 'items' => $users->map(static fn ($u): array => ['title' => (string) $u->getAttribute('name'), 'meta' => (string) $u->getAttribute('email'), 'url' => route('basalto.users.index')])->all()];
            }
        }

        return response()->json(['groups' => $groups]);
    }
}
