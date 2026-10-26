<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Http\Controllers\Extranet;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Cache\PageCache;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Prefix;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Extranet › Community › Foren › Präfixe (CR-006 FO-09): Schilder wie «Frage», «Gelöst», «Fehler» anlegen, ändern,
 * löschen. Welche ein Forum anbietet, steht am Forum. Gelöschte Präfixe verschwinden aus Themen und Foren.
 */
final class PrefixController
{
    public function __construct(private readonly CurrentSite $current, private readonly Recorder $recorder) {}

    public function store(Request $request): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        $data = $this->validated($request, $site);
        $prefix = Prefix::query()->withoutGlobalScopes()->create($data + ['site_id' => $site->id,
            'position' => (int) Prefix::query()->withoutGlobalScopes()->where('site_id', $site->id)->max('position') + 1]);
        $this->changed($site, 'forum.prefix_created', null, $prefix->only(['label', 'color']));

        return back()->with('status', __('Präfix «:label» ist angelegt.', ['label' => $prefix->labelIn($site->default_locale)]));
    }

    public function update(Request $request, int $prefix): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        $model = $this->find($site, $prefix);
        $before = $model->only(['label', 'color']);
        $model->fill($this->validated($request, $site))->save();
        $this->changed($site, 'forum.prefix_updated', $before, $model->only(['label', 'color']));

        return back()->with('status', __('Gespeichert.'));
    }

    public function destroy(int $prefix): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        $model = $this->find($site, $prefix);
        foreach (Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->get() as $node) {
            $ids = array_map('intval', (array) $node->setting('prefixes', []));
            if (in_array($model->id, $ids, true)) {
                $settings = $node->settings ?? [];
                $settings['prefixes'] = array_values(array_diff($ids, [$model->id]));
                $settings['prefix_required'] = $settings['prefixes'] !== [] && (bool) ($settings['prefix_required'] ?? false);
                $node->forceFill(['settings' => $settings])->save();
            }
        }
        $label = $model->labelIn($site->default_locale);
        $model->delete();
        $this->changed($site, 'forum.prefix_deleted', ['praefix' => $label], null);

        return back()->with('status', __('Präfix «:label» ist gelöscht.', ['label' => $label]));
    }

    /** @return array{label: array<string, string>, color: string} */
    private function validated(Request $request, Site $site): array
    {
        $locales = $site->localeCodes();
        $rules = ['label' => ['required', 'array'], 'label.'.$site->default_locale => ['required', 'string', 'max:30'], 'color' => ['required', Rule::in(Prefix::COLORS)]];
        foreach ($locales as $locale) {
            $rules['label.'.$locale] ??= ['nullable', 'string', 'max:30'];
        }
        $data = $request->validate($rules, [], ['label.'.$site->default_locale => __('Bezeichnung'), 'color' => __('Farbe')]);
        $label = array_filter(array_map(static fn (mixed $v): string => trim((string) $v), array_intersect_key((array) $data['label'], array_flip($locales))), static fn (string $v): bool => $v !== '');

        return ['label' => $label, 'color' => (string) $data['color']];
    }

    private function find(Site $site, int $id): Prefix
    {
        $prefix = Prefix::query()->withoutGlobalScopes()->where('site_id', $site->id)->find($id);
        abort_if($prefix === null, 404);

        return $prefix;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function changed(Site $site, string $action, ?array $before, ?array $after): void
    {
        app(PageCache::class)->invalidate($site->id);
        $this->recorder->record($action, null, $before, $after, $site);
    }
}
