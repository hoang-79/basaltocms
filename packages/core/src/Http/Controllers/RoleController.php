<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Access\Access;
use Basalto\Core\Access\Areas;
use Basalto\Core\Access\Level;
use Basalto\Core\Access\RoleDefinition;
use Basalto\Core\Access\RoleTemplates;
use Basalto\Core\Activity\Recorder;
use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Models\Role;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rollen-Editor (CR-005, R-03, R-08–R-10) unter Verwaltung › Benutzer › Rollen: Matrix Bereich × Stufe, Sprachen,
 * Zahl der Personen. Vorlagen lassen sich anpassen und zurücksetzen, eigene Rollen anlegen, kopieren und löschen,
 * solange niemand sie hat. «Betreiber» bleibt unveränderlich; niemand verschenkt Rechte, die er selbst nicht hat.
 */
final class RoleController
{
    public function __construct(private readonly CurrentSite $current, private readonly Access $access, private readonly Areas $areas, private readonly Recorder $recorder) {}

    public function index(Request $request): Response
    {
        $site = $this->current->require();
        $accountId = $site->account_id;
        $canManage = Gate::allows('area', [$site, 'users', Level::All]);
        $people = $this->access->people($accountId);
        $rows = Role::query()->where('account_id', $accountId)->get()->keyBy('key');
        $roles = [];
        $options = $this->access->options($accountId);
        if (Extranet::enabled()) {
            // TB 19 (R-12): die Vorlagen des Extranets im selben Editor
            $options = [...$options, ...array_values(array_filter($this->access->options($accountId, true), static fn (array $o): bool => in_array($o['value'], RoleTemplates::EXTRANET_KEYS, true)))];
        }
        foreach ($options as $option) {
            $key = $option['value'];
            $definition = $this->access->resolve($key, $accountId) ?? Access::fromTemplate($key);
            $template = RoleTemplates::isTemplate($key);
            $customized = $template && $rows->has($key) && $key !== RoleTemplates::OWNER;
            $count = $people[$key] ?? 0;
            $roles[] = [
                'key' => $key, 'name' => $option['label'], 'description' => $rows->get($key)?->description,
                'template' => $template, 'customized' => $customized, 'locked' => $key === RoleTemplates::OWNER, 'people' => $count,
                'levels' => $this->levelsOf($definition), 'languages' => $this->languagesOf($definition),
                'urls' => [
                    'update' => route('basalto.roles.update', $key),
                    'reset' => $customized ? route('basalto.roles.reset', $key) : null,
                    'destroy' => ! $template && $count === 0 ? route('basalto.roles.destroy', $key) : null,
                ],
            ];
        }
        $areas = [];
        foreach ($this->areas->all() as $key => $area) {
            $areas[] = ['key' => $key, 'label' => __($area['label']), 'group' => $area['group'], 'module' => $area['module']];
        }

        return Inertia::render('core::Users/Roles', [
            'roles' => $roles,
            'areas' => $areas,
            'groups' => array_values(array_filter([['key' => Areas::CONTENT, 'label' => __('Inhalte')], ['key' => Areas::SETUP, 'label' => __('Einrichtung')], ['key' => Areas::ADMIN, 'label' => __('Verwaltung')],
                ['key' => Areas::EXTRANET, 'label' => __('Extranet')]], fn (array $g): bool => $this->areas->inGroup($g['key']) !== [])),
            'levels' => array_map(static fn (Level $l): array => ['value' => $l->value, 'label' => $l->label()], Level::cases()),
            'locales' => array_map(static fn (string $code): array => ['code' => $code, 'is_default' => $code === $site->default_locale], $site->localeCodes()),
            'can_manage' => $canManage,
            'selected' => is_string($request->query('rolle')) ? $request->query('rolle') : null,
            'urls' => ['store' => route('basalto.roles.store'), 'users' => route('basalto.users.index')],
        ]);
    }

    /** Neue Rolle — leer oder als Kopie einer bestehenden («kopieren»). */
    public function store(Request $request): RedirectResponse
    {
        $site = $this->current->require();
        $accountId = $site->account_id;
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'from' => ['nullable', 'string', Rule::in([...$this->access->keys($accountId), ...RoleTemplates::EXTRANET_KEYS])],
        ], [], ['name' => __('Name')]);
        $name = trim($validated['name']);
        $this->guardUniqueName($accountId, $name, null);
        $source = isset($validated['from']) ? $this->access->resolve($validated['from'], $accountId) : null;
        $levels = $source === null ? [] : $this->levelsOf($source);
        $languages = $source?->languages;
        $definition = new RoleDefinition('', $name, $levels, $languages);
        $this->guardCovers($request, $site, $definition);
        $role = Role::query()->create(['account_id' => $accountId, 'key' => $this->newKey($accountId, $name), 'name' => $name, 'levels' => $levels, 'languages' => $languages]);
        $this->recorder->record('role.created', $role, null, ['key' => $role->key, 'name' => $name, 'from' => $validated['from'] ?? null, 'levels' => $levels, 'languages' => $languages], $site);

        return redirect()->route('basalto.roles.index', ['rolle' => $role->key])->with('status', __('Rolle «:name» angelegt.', ['name' => $name]));
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $site = $this->current->require();
        $accountId = $site->account_id;
        abort_if($key === RoleTemplates::OWNER, 403, __('Die Rolle Betreiber ist unveränderlich — sonst könnte sich eine Site aussperren.'));
        $before = $this->access->resolve($key, $accountId) ?? abort(404);
        $areaKeys = array_keys($this->areas->all());
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'levels' => ['required', 'array'],
            'levels.*' => ['integer', Rule::in(array_map(static fn (Level $l): int => $l->value, Level::cases()))],
            'languages' => ['required', 'array'],
            'languages.mode' => ['required', Rule::in(['all', 'except_default', 'only'])],
            'languages.codes' => ['nullable', 'array'],
            'languages.codes.*' => ['string', 'max:10'],
        ], [], ['name' => __('Name'), 'languages.codes' => __('Sprachen')]);
        $name = trim($validated['name']);
        $this->guardUniqueName($accountId, $name, $key);
        $levels = [];
        foreach ($areaKeys as $area) {
            $levels[$area] = (int) ($validated['levels'][$area] ?? 0);
        }
        $languages = match ($validated['languages']['mode']) {
            'except_default' => ['mode' => 'except_default'],
            'only' => ['mode' => 'only', 'codes' => array_values(array_unique(array_map('strval', $validated['languages']['codes'] ?? [])))],
            default => null,
        };
        if ($languages !== null && $languages['mode'] === 'only' && $languages['codes'] === []) {
            throw ValidationException::withMessages(['languages.codes' => __('Wählen Sie mindestens eine Sprache.')]);
        }
        $after = new RoleDefinition($key, $name, $levels, $languages);
        // Wer ändert, braucht beide Stände selbst: den bisherigen (nicht fremde Rechte wegnehmen) und den neuen
        $this->guardCovers($request, $site, $before);
        $this->guardCovers($request, $site, $after);
        $role = Role::query()->firstOrNew(['account_id' => $accountId, 'key' => $key]);
        $role->fill(['name' => $name, 'description' => $validated['description'] ?? null, 'levels' => $levels, 'languages' => $languages])->save();
        $this->access->forget();
        $this->recorder->record('role.updated', $role, ['name' => $before->name, 'levels' => $this->levelsOf($before), 'languages' => $before->languages], ['name' => $name, 'levels' => $levels, 'languages' => $languages], $site);

        return back()->with('status', __('Rolle «:name» gespeichert — gilt ab der nächsten Anfrage.', ['name' => $name]));
    }

    /** Eine angepasste Vorlage auf den Stand aus dem Code zurücksetzen. */
    public function reset(Request $request, string $key): RedirectResponse
    {
        $site = $this->current->require();
        abort_unless(RoleTemplates::isTemplate($key) && $key !== RoleTemplates::OWNER, 404);
        $role = Role::query()->where('account_id', $site->account_id)->where('key', $key)->first() ?? abort(404);
        $this->guardCovers($request, $site, $role->definition());
        $this->guardCovers($request, $site, Access::fromTemplate($key));
        $before = ['name' => $role->name, 'levels' => $role->levels, 'languages' => $role->languages];
        $role->delete();
        $this->access->forget();
        $this->recorder->record('role.reset', null, $before, ['key' => $key], $site);

        return back()->with('status', __('Vorlage «:name» zurückgesetzt.', ['name' => (string) RoleTemplates::label($key)]));
    }

    public function destroy(Request $request, string $key): RedirectResponse
    {
        $site = $this->current->require();
        abort_if(RoleTemplates::isTemplate($key), 403, __('Vorlagen lassen sich nicht löschen — nur zurücksetzen.'));
        $role = Role::query()->where('account_id', $site->account_id)->where('key', $key)->first() ?? abort(404);
        $this->guardCovers($request, $site, $role->definition());
        $inUse = ($this->access->people($site->account_id)[$key] ?? 0) > 0
            || DB::table('invitations')->whereNull('accepted_at')->where('role', $key)->whereIn('site_id', Site::query()->where('account_id', $site->account_id)->select('id'))->exists();
        if ($inUse) {
            return back()->withErrors(['role' => __('«:name» ist noch vergeben oder in einer offenen Einladung — weisen Sie zuerst eine andere Rolle zu.', ['name' => $role->name])]);
        }
        $before = ['key' => $key, 'name' => $role->name, 'levels' => $role->levels, 'languages' => $role->languages];
        $role->delete();
        $this->recorder->record('role.deleted', null, $before, null, $site);

        return redirect()->route('basalto.roles.index')->with('status', __('Rolle «:name» gelöscht.', ['name' => $before['name']]));
    }

    /** @return array<string, int> Stufe je angemeldetem Bereich — «*» aufgelöst */
    private function levelsOf(RoleDefinition $definition): array
    {
        $levels = [];
        foreach (array_keys($this->areas->all()) as $area) {
            $levels[$area] = $definition->level($area)->value;
        }

        return $levels;
    }

    /** @return array{mode: string, codes: list<string>} */
    private function languagesOf(RoleDefinition $definition): array
    {
        return ['mode' => $definition->languages['mode'] ?? 'all', 'codes' => $definition->languages['codes'] ?? []];
    }

    private function guardCovers(Request $request, Site $site, RoleDefinition $role): void
    {
        $user = $request->user();
        abort_if($user === null || ! $this->access->covers($user, $site, $role), 403, __('Diese Rolle geht über Ihre eigenen Rechte hinaus — ändern kann sie nur, wer mindestens dasselbe darf.'));
    }

    private function guardUniqueName(?int $accountId, string $name, ?string $except): void
    {
        foreach ($this->access->options($accountId) as $option) {
            if ($option['value'] !== $except && mb_strtolower($option['label']) === mb_strtolower($name)) {
                throw ValidationException::withMessages(['name' => __('Eine Rolle «:name» gibt es schon.', ['name' => $name])]);
            }
        }
    }

    private function newKey(?int $accountId, string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 48, '') ?: 'rolle';
        $key = $base;
        for ($i = 2; RoleTemplates::isTemplate($key) || in_array($key, RoleTemplates::RESERVED, true) || Role::query()->where('account_id', $accountId)->where('key', $key)->exists(); $i++) {
            $key = $base.'_'.$i;
        }

        return $key;
    }
}
