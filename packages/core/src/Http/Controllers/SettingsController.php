<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Backend\Dates;
use Basalto\Core\Models\SettingRevision;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Catalog;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Einstellungen mit Verlauf (K-07, Mockup 18/3): der Katalog in Gruppen, je Wert wer ihn wann geändert hat, der
 * Verlauf alt → neu und Wiederherstellen als neue Revision. Site-Werte gelten für die aktuelle Site.
 */
final class SettingsController
{
    public function __construct(private readonly CurrentSite $current, private readonly Dates $dates, private readonly Settings $settings) {}

    public function index(Request $request): Response
    {
        $site = $this->current->require();
        $group = array_key_exists((string) $request->query('group'), Catalog::GROUPS) ? (string) $request->query('group') : array_key_first(Catalog::GROUPS);
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $groups = [];
        foreach (Catalog::groups() as $key => $label) {
            $items = [];
            foreach (Catalog::items() as $item) {
                if ($item['group'] !== $key) {
                    continue;
                }
                $scopeSite = $item['scope'] === 'site' ? $site : null;
                $history = $item['type'] === 'link' ? new Collection : $this->settings->history($item['key'], $scopeSite)->sortByDesc('id')->values();
                $names = $userModel::query()->whereIn('id', $history->pluck('user_id')->filter()->unique())->pluck('name', 'id');
                $latest = $history->first();
                $items[] = [
                    'key' => $item['key'], 'label' => $item['label'], 'help' => $item['help'], 'type' => $item['type'],
                    'value' => $item['type'] === 'link' ? null : $this->settings->get($item['key'], $item['default'], $scopeSite),
                    'default' => $item['default'], 'unit' => $item['unit'], 'min' => $item['min'], 'max' => $item['max'], 'scope' => $item['scope'],
                    'href' => $item['route'] === null ? null : route($item['route'], $site),
                    'updated' => $latest === null ? null : ['by' => $latest->user_id === null ? null : (string) ($names->get($latest->user_id) ?? '—'), 'at' => $this->dates->dateTime($latest->created_at)],
                    'history' => $history->map(fn (SettingRevision $r, int $i): array => [
                        'id' => $r->id, 'old' => $r->old, 'new' => $r->new, 'by' => $r->user_id === null ? null : (string) ($names->get($r->user_id) ?? '—'),
                        'at' => $this->dates->dateTime($r->created_at), 'current' => $i === 0, 'restore_url' => route('basalto.settings.restore', $r),
                    ])->all(),
                ];
            }
            $groups[] = ['key' => $key, 'label' => $label, 'items' => $items];
        }

        return Inertia::render('core::Settings/Index', [
            'groups' => $groups, 'group' => $group, 'can_edit' => $this->canEdit($site),
            'urls' => ['index' => route('basalto.settings.index'), 'update' => route('basalto.settings.update')],
            'status' => session('status'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $site = $this->current->require();
        abort_unless($this->canEdit($site), 403);
        $item = Catalog::find((string) $request->input('key'));
        abort_if($item === null || $item['type'] === 'link', 404);
        $value = match ($item['type']) {
            'bool' => $request->validate(['value' => ['required', 'boolean']])['value'],
            default => $request->validate(['value' => ['required', 'integer', 'min:'.($item['min'] ?? 0), 'max:'.($item['max'] ?? PHP_INT_MAX)]], [], ['value' => $item['label']])['value'],
        };
        $value = $item['type'] === 'bool' ? (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN) : (int) $value;
        $user = $request->user();
        $this->settings->set($item['key'], $value, $item['scope'] === 'site' ? $site : null, $user instanceof Authenticatable ? $user : null, $item['default']);

        return back()->with('status', __('«:setting» gespeichert.', ['setting' => $item['label']]));
    }

    public function restore(Request $request, SettingRevision $revision): RedirectResponse
    {
        $site = $this->current->require();
        abort_unless($this->canEdit($site), 403);
        $setting = $revision->setting()->firstOrFail();
        abort_unless($setting->site_id === null || $setting->site_id === $site->id, 404);
        $user = $request->user();
        $item = Catalog::find($setting->key);
        // Eine Zeile «alt → neu» stellt ihren Zustand danach her — den neuen Wert (Mockup 18/3)
        $this->settings->set($setting->key, $revision->new, $setting->site_id === null ? null : $site, $user instanceof Authenticatable ? $user : null, $item['default'] ?? null);

        return back()->with('status', __('«:name» wiederhergestellt.', ['name' => $item['label'] ?? $setting->key]));
    }

    private function canEdit(Site $site): bool
    {
        return Gate::allows('update', $site);
    }
}
