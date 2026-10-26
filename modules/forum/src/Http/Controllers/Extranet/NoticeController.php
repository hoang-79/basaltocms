<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Http\Controllers\Extranet;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Cache\PageCache;
use Basalto\Core\Community\CommunityNotice;
use Basalto\Core\Community\VisitorGroup;
use Basalto\Core\Community\VisitorGroups;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Modules\Forum\Http\ForumRoutes;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\NodeType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Extranet › Community › Hinweise (CR-006 FO-17), Vorbild WoltLab: Banner mit Text je Sprache, Ton, Bereich (überall,
 * Übersicht oder ein Forum), Zielgruppe (alle, Gäste, angemeldet, bestimmte Gruppen), Zeitraum, ausblendbar.
 */
final class NoticeController
{
    public function __construct(private readonly CurrentSite $current, private readonly Recorder $recorder) {}

    public function index(): Response
    {
        $site = CommunityController::site($this->current);
        $lang = $site->default_locale;

        return Inertia::render('forum::Notices', [
            'notices' => array_values(CommunityNotice::query()->withoutGlobalScopes()->where('site_id', $site->id)->orderBy('position')->orderBy('id')->get()
                ->map(static fn (CommunityNotice $n): array => ['id' => $n->id, 'text' => $n->text, 'tone' => $n->tone, 'area' => $n->conditions['area'] ?? '*',
                    'audience' => $n->conditions['audience'] ?? 'all', 'groups' => array_map('intval', $n->conditions['groups'] ?? []), 'dismissible' => $n->dismissible,
                    'is_active' => $n->is_active, 'position' => $n->position, 'starts_at' => $n->starts_at?->format('Y-m-d'), 'ends_at' => $n->ends_at?->format('Y-m-d'),
                    'update_url' => ForumRoutes::url('notices.update', $n->id), 'destroy_url' => ForumRoutes::url('notices.destroy', $n->id)])->all()),
            'locales' => $site->localeCodes(),
            'areas' => [['value' => '*', 'label' => __('Überall in der Community')], ['value' => 'forum', 'label' => __('Forenübersicht')],
                ...Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('type', '!=', NodeType::Link->value)->orderBy('position')->get()
                    ->map(static fn (Node $n): array => ['value' => 'forum:'.$n->id, 'label' => __('Forum: :title (mit Unterforen und Themen)', ['title' => $n->titleIn($lang)])])->all()],
            'groups' => array_values(app(VisitorGroups::class)->ensure($site)->filter(static fn (VisitorGroup $g): bool => $g->key !== VisitorGroup::EVERYONE)
                ->map(static fn (VisitorGroup $g): array => ['id' => $g->id, 'name' => (string) __($g->name)])->all()),
            'urls' => ['store' => ForumRoutes::url('notices.store'), 'overview' => ForumRoutes::url('index')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        $notice = new CommunityNotice(['site_id' => $site->id]);
        $this->fill($notice, $request, $site);
        $this->recorder->record('forum.notice_created', null, null, ['hinweis' => $notice->id], $site);

        return back()->with('status', __('Hinweis angelegt.'));
    }

    public function update(Request $request, int $notice): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        $model = CommunityNotice::query()->withoutGlobalScopes()->where('site_id', $site->id)->findOrFail($notice);
        $this->fill($model, $request, $site);

        return back()->with('status', __('Hinweis gespeichert.'));
    }

    public function destroy(int $notice): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        CommunityNotice::query()->withoutGlobalScopes()->where('site_id', $site->id)->findOrFail($notice)->delete();
        app(PageCache::class)->invalidate($site->id);

        return back()->with('status', __('Hinweis gelöscht.'));
    }

    private function fill(CommunityNotice $notice, Request $request, Site $site): void
    {
        $locales = $site->localeCodes();
        $data = $request->validate([
            'text' => ['required', 'array'], 'text.'.$site->default_locale => ['required', 'string', 'max:1000'], 'text.*' => ['nullable', 'string', 'max:1000'],
            'tone' => ['required', 'in:'.implode(',', CommunityNotice::TONES)], 'area' => ['required', 'string', 'max:30', 'regex:/^(\*|forum|forum:\d+)$/'],
            'audience' => ['required', 'in:all,guests,members'], 'groups' => ['array'], 'groups.*' => ['integer'], 'dismissible' => ['boolean'], 'is_active' => ['boolean'],
            'position' => ['nullable', 'integer', 'min:0', 'max:999'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ], [], ['text.'.$site->default_locale => __('Text'), 'ends_at' => __('Ende')]);
        $text = [];
        foreach ($locales as $locale) {
            $value = trim((string) ($data['text'][$locale] ?? ''));
            if ($value !== '') {
                $text[$locale] = $value;
            }
        }
        $groups = array_values(array_intersect(array_map('intval', $data['groups'] ?? []), app(VisitorGroups::class)->ensure($site)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all()));
        $notice->forceFill(['text' => $text, 'tone' => $data['tone'], 'conditions' => ['area' => $data['area'], 'audience' => $data['audience'], 'groups' => $groups],
            'dismissible' => (bool) ($data['dismissible'] ?? true), 'is_active' => (bool) ($data['is_active'] ?? true), 'position' => (int) ($data['position'] ?? 0),
            'starts_at' => isset($data['starts_at']) ? now()->parse((string) $data['starts_at'])->startOfDay() : null,
            'ends_at' => isset($data['ends_at']) ? now()->parse((string) $data['ends_at'])->endOfDay() : null])->save();
        app(PageCache::class)->invalidate($site->id);
    }
}
