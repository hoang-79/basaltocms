<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Http\Controllers\Extranet;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Cache\PageCache;
use Basalto\Core\Community\VisitorAccess;
use Basalto\Core\Community\VisitorGroup;
use Basalto\Core\Community\VisitorGroups;
use Basalto\Core\Community\VisitorPermission;
use Basalto\Core\Community\VisitorPermissions;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\ForumSearch;
use Basalto\Modules\Forum\Http\ForumRoutes;
use Basalto\Modules\Forum\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Extranet › Community › Gruppen und Rechte (CR-006 FO-03, Vorbild ACP «Benutzergruppen»): Besuchergruppen mit
 * Abzeichen, ihre Mitglieder (nur zuweisbare Gruppen) und die Rechte-Matrix — global oder für ein Forum. Je Zelle
 * Ja, Nein, Nie oder «erben»; angezeigt wird auch, was gerade gilt und woher es kommt.
 */
final class GroupController
{
    public function __construct(private readonly CurrentSite $current, private readonly VisitorGroups $groups, private readonly VisitorAccess $access,
        private readonly VisitorPermissions $permissions, private readonly Forum $forum, private readonly Recorder $recorder) {}

    public function index(Request $request): Response
    {
        $site = CommunityController::site($this->current);
        $groups = $this->groups->ensure($site);
        $node = $request->filled('forum') ? Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->find((int) $request->query('forum')) : null;
        $chain = $node === null ? [] : $this->forum->chain($site, $node);
        $definitions = $node === null ? $this->permissions->all() : $this->permissions->forSubject(Forum::SUBJECT);
        $nodes = $this->forum->nodes($site);
        $counts = DB::table('visitor_group_customer')->whereIn('visitor_group_id', $groups->modelKeys())->selectRaw('visitor_group_id, count(*) as n')->groupBy('visitor_group_id')->pluck('n', 'visitor_group_id');

        return Inertia::render('forum::Groups', [
            'groups' => $groups->map(fn (VisitorGroup $g): array => [
                'id' => $g->id, 'key' => $g->key, 'name' => $g->isSystem() ? __($g->name) : $g->name, 'badge' => $g->badge, 'system' => $g->isSystem(), 'assignable' => $g->isAssignable(),
                'members' => $g->isAssignable() ? (int) ($counts[$g->id] ?? 0) : null,
                'member_list' => $g->isAssignable() ? $g->customers()->withoutGlobalScopes()->orderBy('email')->limit(50)->get()->map(static fn (Customer $c): array => [
                    'id' => $c->id, 'email' => $c->email, 'name' => (string) $c->name, 'remove_url' => ForumRoutes::url('groups.members.remove', [$g->id, $c->id])])->values()->all() : [],
                'update_url' => ForumRoutes::url('groups.update', $g->id), 'destroy_url' => $g->isSystem() ? null : ForumRoutes::url('groups.destroy', $g->id),
                'add_member_url' => $g->isAssignable() ? ForumRoutes::url('groups.members.add', $g->id) : null,
            ])->values()->all(),
            'permissions' => array_map(fn (string $key, array $d): array => [
                'key' => $key, 'label' => __($d['label']), 'group' => __($d['group']), 'help' => $d['help'] === '' ? '' : __($d['help']),
                'cells' => $groups->map(function (VisitorGroup $g) use ($site, $key, $chain, $node, $nodes): array {
                    $own = $node === null ? $this->access->resolve($site, $g, $key) : $this->access->resolve($site, $g, $key, [[Forum::SUBJECT, $node->id]]);
                    $isOwn = $node === null ? $own['from'] === 'global' : $own['from'] === 'subject';
                    $effective = $this->access->resolve($site, $g, $key, $chain);
                    $from = match ($effective['from']) {
                        'subject' => $effective['subject'] !== null && $node !== null && $effective['subject'][1] !== $node->id ? (string) ($nodes->get($effective['subject'][1])?->titleIn($site->default_locale) ?? '') : '',
                        'global' => __('global'),
                        default => __('Vorgabe'),
                    };

                    return ['group' => $g->id, 'value' => $isOwn ? $own['value'] : null, 'effective' => $effective['value'], 'from' => $from];
                })->values()->all(),
            ], array_keys($definitions), $definitions),
            'forum' => $node === null ? null : ['id' => $node->id, 'title' => $node->titleIn($site->default_locale)],
            'forums' => $nodes->values()->map(fn (Node $n): array => ['id' => $n->id, 'title' => $n->titleIn($site->default_locale), 'depth' => count($this->forum->ancestors($site, $n))])->all(),
            'urls' => ['store' => ForumRoutes::url('groups.store'), 'permission' => ForumRoutes::url('permissions'), 'self' => ForumRoutes::url('groups'),
                'customers' => ForumRoutes::url('customers'), 'overview' => ForumRoutes::url('index')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'badge' => ['nullable', 'string', 'max:30']], [], ['name' => __('Name'), 'badge' => __('Abzeichen')]);
        $position = (int) VisitorGroup::query()->withoutGlobalScopes()->where('site_id', $site->id)->max('position') + 1;
        VisitorGroup::query()->withoutGlobalScopes()->create(['site_id' => $site->id, 'name' => $data['name'], 'badge' => $data['badge'] ?? null, 'position' => $position]);
        $this->recorder->record('forum.group_created', null, null, ['gruppe' => $data['name']], $site);

        return back()->with('status', __('Gruppe «:name» ist angelegt.', ['name' => $data['name']]));
    }

    public function update(Request $request, int $group): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        $model = $this->find($site, $group);
        $data = $request->validate(['name' => [$model->isSystem() ? 'nullable' : 'required', 'string', 'max:60'], 'badge' => ['nullable', 'string', 'max:30']]);
        $before = $model->only(['name', 'badge']);
        $model->forceFill(['badge' => $data['badge'] ?? null] + ($model->isSystem() ? [] : ['name' => $data['name']]))->save();
        $this->changed($site, 'forum.group_updated', $before, $model->only(['name', 'badge']));

        return back()->with('status', __('Gespeichert.'));
    }

    public function destroy(int $group): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        $model = $this->find($site, $group);
        abort_if($model->isSystem(), 403);
        $model->delete();
        $this->changed($site, 'forum.group_deleted', ['gruppe' => $model->name], null);

        return back()->with('status', __('Gruppe «:name» ist gelöscht.', ['name' => $model->name]));
    }

    /** Eine Zelle der Matrix setzen: 1, 0, -1 oder leer (erben). */
    public function permission(Request $request): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        $data = $request->validate([
            'group' => ['required', 'integer'],
            'permission' => ['required', 'string', Rule::in(array_keys($this->permissions->all()))],
            'value' => ['nullable', 'integer', Rule::in([VisitorPermission::YES, VisitorPermission::NO, VisitorPermission::NEVER])],
            'forum' => ['nullable', 'integer'],
        ]);
        $group = $this->find($site, (int) $data['group']);
        $subject = null;
        if (isset($data['forum'])) {
            $node = Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->find((int) $data['forum']);
            abort_if($node === null || $this->permissions->get((string) $data['permission'])['subject'] !== Forum::SUBJECT, 422);
            $subject = [Forum::SUBJECT, $node->id];
        }
        $value = isset($data['value']) ? (int) $data['value'] : null;
        $this->access->set($site, $group, (string) $data['permission'], $value, $subject);
        $this->changed($site, 'forum.permission_set', null, ['gruppe' => $group->name, 'recht' => $data['permission'], 'wert' => $value, 'forum' => $subject[1] ?? null]);

        // TB 41: ändern sich die Rechte der Gäste, ändert sich, was die Suche zeigen darf
        app(ForumSearch::class)->rebuild($site);

        return back();
    }

    public function addMember(Request $request, int $group): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        $model = $this->find($site, $group);
        abort_unless($model->isAssignable(), 422);
        $data = $request->validate(['customer' => ['required', 'integer', Rule::exists('customers', 'id')->where('site_id', $site->id)]], [], ['customer' => __('Konto')]);
        $model->customers()->syncWithoutDetaching([(int) $data['customer'] => ['created_at' => now()]]);
        $this->changed($site, 'forum.member_added', null, ['gruppe' => $model->name, 'konto' => (int) $data['customer']]);

        return back()->with('status', __('Zur Gruppe hinzugefügt.'));
    }

    public function removeMember(int $group, int $customer): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        $model = $this->find($site, $group);
        $model->customers()->detach($customer);
        $this->changed($site, 'forum.member_removed', ['gruppe' => $model->name, 'konto' => $customer], null);

        return back()->with('status', __('Aus der Gruppe entfernt.'));
    }

    /** Kundenkonten zum Hinzufügen suchen (E-Mail oder Name). */
    public function customers(Request $request): JsonResponse
    {
        $site = CommunityController::site($this->current);

        return response()->json(CommunityController::customers($site, trim(mb_substr((string) $request->query('q', ''), 0, 80))));
    }

    private function find(Site $site, int $id): VisitorGroup
    {
        $group = VisitorGroup::query()->withoutGlobalScopes()->where('site_id', $site->id)->find($id);
        abort_if($group === null, 404);

        return $group;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function changed(Site $site, string $action, ?array $before, ?array $after): void
    {
        $this->groups->forget();
        app(PageCache::class)->invalidate($site->id);
        $this->recorder->record($action, null, $before, $after, $site);
    }
}
