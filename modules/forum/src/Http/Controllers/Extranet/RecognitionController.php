<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Http\Controllers\Extranet;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Community\CommunityGroupRule;
use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\CommunityRank;
use Basalto\Core\Community\CommunityRules;
use Basalto\Core\Community\CommunityTrophy;
use Basalto\Core\Community\ProfileFields;
use Basalto\Core\Community\Recognition;
use Basalto\Core\Community\VisitorGroup;
use Basalto\Core\Community\VisitorGroups;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Modules\Forum\Http\ForumRoutes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Extranet › Community › Profile und Auszeichnungen (CR-006 § 6, FO-18, FO-20): Ränge, Trophäen (mit Regel oder von
 * Hand vergeben), Regeln der automatischen Gruppenzuordnung mit Vorschau «trifft heute n Mitglieder», eigene
 * Profilfelder. «Jetzt anwenden» startet denselben Lauf wie der tägliche Befehl.
 */
final class RecognitionController
{
    public function __construct(private readonly CurrentSite $current, private readonly CommunityRules $rules, private readonly Recognition $recognition, private readonly Recorder $recorder) {}

    public function index(): Response
    {
        $site = CommunityController::site($this->current);
        $groups = app(VisitorGroups::class)->ensure($site);
        $counts = DB::table('community_customer_trophies')->selectRaw('trophy_id, count(*) as n')->groupBy('trophy_id')->pluck('n', 'trophy_id');

        return Inertia::render('forum::Recognition', [
            'ranks' => array_values(CommunityRank::query()->withoutGlobalScopes()->where('site_id', $site->id)->orderBy('min_posts')->orderBy('id')->get()
                ->map(static fn (CommunityRank $r): array => ['id' => $r->id, 'title' => $r->title, 'visitor_group_id' => $r->visitor_group_id, 'min_posts' => $r->min_posts, 'color' => $r->color, 'icon' => $r->icon,
                    'update_url' => ForumRoutes::url('ranks.update', $r->id), 'destroy_url' => ForumRoutes::url('ranks.destroy', $r->id)])->all()),
            'trophies' => array_values(CommunityTrophy::query()->withoutGlobalScopes()->where('site_id', $site->id)->orderBy('position')->orderBy('id')->get()
                ->map(static fn (CommunityTrophy $t): array => ['id' => $t->id, 'title' => $t->title, 'description' => $t->description, 'icon' => $t->icon, 'rule' => $t->rule, 'threshold' => $t->threshold,
                    'is_active' => $t->is_active, 'awarded' => (int) ($counts[$t->id] ?? 0), 'update_url' => ForumRoutes::url('trophies.update', $t->id),
                    'destroy_url' => ForumRoutes::url('trophies.destroy', $t->id), 'award_url' => ForumRoutes::url('trophies.award', $t->id)])->all()),
            'group_rules' => array_values(CommunityGroupRule::query()->withoutGlobalScopes()->where('site_id', $site->id)->orderBy('id')->get()
                ->map(static fn (CommunityGroupRule $g): array => ['id' => $g->id, 'visitor_group_id' => $g->visitor_group_id, 'rule' => $g->rule, 'threshold' => $g->threshold, 'is_active' => $g->is_active,
                    'members' => DB::table('visitor_group_customer')->where('rule_id', $g->id)->count(), 'last_run' => $g->last_run_at?->format('d.m.Y H:i'),
                    'update_url' => ForumRoutes::url('group-rules.update', $g->id), 'destroy_url' => ForumRoutes::url('group-rules.destroy', $g->id)])->all()),
            'rules' => collect($this->rules->all())->map(static fn (array $r, string $key): array => ['value' => $key, 'label' => (string) __($r['label']), 'help' => (string) __($r['help']), 'threshold' => $r['threshold']])->values()->all(),
            'groups' => array_values($groups->filter(static fn (VisitorGroup $g): bool => $g->isAssignable())->map(static fn (VisitorGroup $g): array => ['id' => $g->id, 'name' => (string) __($g->name)])->all()),
            'all_groups' => array_values($groups->filter(static fn (VisitorGroup $g): bool => ! in_array($g->key, [VisitorGroup::EVERYONE, VisitorGroup::GUESTS], true))
                ->map(static fn (VisitorGroup $g): array => ['id' => $g->id, 'name' => (string) __($g->name)])->all()),
            'fields' => app(ProfileFields::class)->definitions($site),
            'colors' => CommunityRank::COLORS,
            'urls' => ['ranks' => ForumRoutes::url('ranks.store'), 'trophies' => ForumRoutes::url('trophies.store'), 'group_rules' => ForumRoutes::url('group-rules.store'),
                'preview' => ForumRoutes::url('recognition.preview'), 'run' => ForumRoutes::url('recognition.run'), 'fields' => ForumRoutes::url('profile-fields'),
                'overview' => ForumRoutes::url('index'), 'customers' => ForumRoutes::url('customers')],
        ]);
    }

    public function storeRank(Request $request): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        CommunityRank::query()->withoutGlobalScopes()->create(['site_id' => $site->id, ...$this->rankData($request)]);

        return back()->with('status', __('Rang angelegt.'));
    }

    public function updateRank(Request $request, int $rank): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        CommunityRank::query()->withoutGlobalScopes()->where('site_id', $site->id)->findOrFail($rank)->forceFill($this->rankData($request))->save();

        return back()->with('status', __('Rang gespeichert.'));
    }

    public function destroyRank(int $rank): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        CommunityRank::query()->withoutGlobalScopes()->where('site_id', $site->id)->findOrFail($rank)->delete();

        return back()->with('status', __('Rang gelöscht.'));
    }

    public function storeTrophy(Request $request): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        CommunityTrophy::query()->withoutGlobalScopes()->create(['site_id' => $site->id, ...$this->trophyData($request)]);

        return back()->with('status', __('Trophäe angelegt — sie wird beim nächsten Lauf vergeben.'));
    }

    public function updateTrophy(Request $request, int $trophy): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        CommunityTrophy::query()->withoutGlobalScopes()->where('site_id', $site->id)->findOrFail($trophy)->forceFill($this->trophyData($request))->save();

        return back()->with('status', __('Trophäe gespeichert.'));
    }

    public function destroyTrophy(int $trophy): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        CommunityTrophy::query()->withoutGlobalScopes()->where('site_id', $site->id)->findOrFail($trophy)->delete();

        return back()->with('status', __('Trophäe gelöscht.'));
    }

    /** Von Hand vergeben — an ein Mitglied mit Profil. */
    public function award(Request $request, int $trophy): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        $model = CommunityTrophy::query()->withoutGlobalScopes()->where('site_id', $site->id)->findOrFail($trophy);
        $customer = (int) $request->validate(['customer_id' => ['required', 'integer']])['customer_id'];
        abort_unless(CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('customer_id', $customer)->exists(), 422);
        $this->recognition->award($site, $model, $customer, $request->user());
        $this->recorder->record('forum.trophy_awarded', null, null, ['trophaee' => $model->title, 'konto' => $customer], $site);

        return back()->with('status', __('Trophäe vergeben.'));
    }

    public function storeGroupRule(Request $request): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        CommunityGroupRule::query()->withoutGlobalScopes()->create(['site_id' => $site->id, ...$this->groupRuleData($request, $site->id)]);

        return back()->with('status', __('Regel angelegt — sie greift beim nächsten Lauf.'));
    }

    public function updateGroupRule(Request $request, int $rule): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        CommunityGroupRule::query()->withoutGlobalScopes()->where('site_id', $site->id)->findOrFail($rule)->forceFill($this->groupRuleData($request, $site->id))->save();

        return back()->with('status', __('Regel gespeichert.'));
    }

    /** Löschen nimmt auch die Mitgliedschaften weg, die die Regel gesetzt hat. */
    public function destroyGroupRule(int $rule): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        $model = CommunityGroupRule::query()->withoutGlobalScopes()->where('site_id', $site->id)->findOrFail($rule);
        DB::table('visitor_group_customer')->where('rule_id', $model->id)->delete();
        $model->delete();

        return back()->with('status', __('Regel gelöscht.'));
    }

    public function preview(Request $request): JsonResponse
    {
        $site = CommunityController::site($this->current);
        $data = $request->validate(['rule' => ['required', 'string', Rule::in(array_keys($this->rules->all()))], 'threshold' => ['nullable', 'integer', 'min:0']]);

        return response()->json(['count' => $this->recognition->preview($site, (string) $data['rule'], (int) ($data['threshold'] ?? 0))]);
    }

    public function run(Request $request): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        $result = $this->recognition->run($site);
        $this->recorder->record('forum.recognition_run', null, null, $result, $site);

        return back()->with('status', __('Angewendet: :trophies Trophäen vergeben, :joined Gruppen beigetreten, :left verlassen.', $result));
    }

    public function fields(Request $request): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        $data = $request->validate(['fields' => ['present', 'array', 'max:20'], 'fields.*.key' => ['required', 'string', 'max:30', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:60'], 'fields.*.type' => ['required', Rule::in(ProfileFields::TYPES)],
            'fields.*.visibility' => ['required', Rule::in(ProfileFields::VISIBILITY)], 'fields.*.required' => ['boolean'], 'fields.*.options' => ['nullable', 'string', 'max:3000']],
            [], ['fields.*.key' => __('Schlüssel'), 'fields.*.label' => __('Beschriftung'), 'fields.*.options' => __('Möglichkeiten')]);
        $errors = [];
        foreach ($data['fields'] as $i => $f) {
            if ($f['type'] === 'select' && ProfileFields::options($f['options'] ?? '') === []) {
                $errors['fields.'.$i.'.options'] = __('Eine Auswahl braucht mindestens eine Möglichkeit (eine je Zeile).');
            }
        }
        if ($errors !== []) {
            return back()->withErrors($errors)->withInput();
        }
        app(ProfileFields::class)->save($site, array_values(array_map(static fn (array $f): array => ['key' => (string) $f['key'], 'label' => (string) $f['label'], 'type' => (string) $f['type'],
            'visibility' => (string) $f['visibility'], 'required' => (bool) ($f['required'] ?? false), 'options' => $f['type'] === 'select' ? ProfileFields::options($f['options'] ?? '') : []], $data['fields'])));

        return back()->with('status', __('Profilfelder gespeichert.'));
    }

    /** @return array<string, mixed> */
    private function rankData(Request $request): array
    {
        $site = CommunityController::site($this->current);
        $data = $request->validate(['title' => ['required', 'string', 'max:60'], 'min_posts' => ['required', 'integer', 'min:0', 'max:1000000'],
            'color' => ['required', Rule::in(CommunityRank::COLORS)], 'icon' => ['nullable', 'string', 'max:16'], 'visitor_group_id' => ['nullable', 'integer', Rule::exists('visitor_groups', 'id')->where('site_id', $site->id)]],
            [], ['title' => __('Titel'), 'min_posts' => __('Ab Beiträgen')]);

        $icon = is_string($data['icon'] ?? null) ? trim(strip_tags($data['icon'])) : '';

        return ['title' => $data['title'], 'min_posts' => (int) $data['min_posts'], 'color' => $data['color'], 'icon' => $icon === '' ? null : $icon, 'visitor_group_id' => $data['visitor_group_id'] ?? null];
    }

    /** @return array<string, mixed> */
    private function trophyData(Request $request): array
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:255'], 'icon' => ['required', 'string', 'max:16'],
            'rule' => ['nullable', 'string', Rule::in(array_keys($this->rules->all()))], 'threshold' => ['nullable', 'integer', 'min:0', 'max:1000000'], 'is_active' => ['boolean']],
            [], ['title' => __('Titel'), 'icon' => __('Symbol')]);

        return ['title' => $data['title'], 'description' => $data['description'] ?? null, 'icon' => $data['icon'], 'rule' => $data['rule'] ?? null,
            'threshold' => isset($data['threshold']) ? (int) $data['threshold'] : null, 'is_active' => (bool) ($data['is_active'] ?? true)];
    }

    /** @return array<string, mixed> */
    private function groupRuleData(Request $request, int $siteId): array
    {
        $assignable = VisitorGroup::query()->withoutGlobalScopes()->where('site_id', $siteId)->get()->filter(static fn (VisitorGroup $g): bool => $g->isAssignable())->modelKeys();
        $data = $request->validate(['visitor_group_id' => ['required', 'integer', Rule::in($assignable)], 'rule' => ['required', 'string', Rule::in(array_keys($this->rules->all()))],
            'threshold' => ['nullable', 'integer', 'min:0', 'max:1000000'], 'is_active' => ['boolean']], [], ['visitor_group_id' => __('Gruppe'), 'rule' => __('Regel')]);

        return ['visitor_group_id' => (int) $data['visitor_group_id'], 'rule' => $data['rule'], 'threshold' => (int) ($data['threshold'] ?? 0), 'is_active' => (bool) ($data['is_active'] ?? true)];
    }
}
