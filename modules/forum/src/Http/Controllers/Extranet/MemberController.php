<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Http\Controllers\Extranet;

use App\Models\User;
use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\CommunityWarning;
use Basalto\Core\Community\ModerationSettings;
use Basalto\Core\Community\Moderator;
use Basalto\Core\Community\Profiles;
use Basalto\Core\Community\Sanctions;
use Basalto\Core\Community\Warnings;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Modules\Forum\Actions\Moderation;
use Basalto\Modules\Forum\Http\ForumRoutes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Extranet › Community › Mitglieder (CR-006 § 6, FO-17): Suche und Filter (gesperrt, Schreibsperre, verwarnt), je
 * Mitglied Beiträge, gültige Punkte und Verwarnungen — verwarnen, Schreibsperre oder Sperre setzen und aufheben, als
 * Spammer entfernen. Dieselben Aktionen wie an Ort und Stelle im Forum.
 */
final class MemberController
{
    public const FILTERS = ['alle', 'gesperrt', 'schreibsperre', 'verwarnt'];

    public function __construct(private readonly CurrentSite $current, private readonly Warnings $warnings, private readonly Sanctions $sanctions) {}

    public function index(Request $request): Response
    {
        $site = CommunityController::site($this->current);
        $q = trim((string) $request->query('q', ''));
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? (string) $request->query('filter') : 'alle';
        $warned = CommunityWarning::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereNull('revoked_at')
            ->where(static fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))->pluck('customer_id')->unique()->all();
        $profiles = CommunityProfile::query()->withoutGlobalScopes()->with('customer')->where('site_id', $site->id)
            ->when($q !== '', static fn ($query) => $query->where(static fn ($w) => $w->where('name_key', 'like', '%'.Profiles::key($q).'%')
                ->orWhereIn('customer_id', Customer::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('email', 'like', '%'.mb_strtolower($q).'%')->select('id'))))
            ->when($filter === 'gesperrt', static fn ($query) => $query->whereNotNull('banned_at')->where(static fn ($w) => $w->whereNull('banned_until')->orWhere('banned_until', '>', now())))
            ->when($filter === 'schreibsperre', static fn ($query) => $query->whereNotNull('muted_at')->where(static fn ($w) => $w->whereNull('muted_until')->orWhere('muted_until', '>', now())))
            ->when($filter === 'verwarnt', static fn ($query) => $query->whereIn('customer_id', $warned))
            ->orderByDesc('last_activity_at')->orderBy('name')->limit(50)->get();
        $warnings = CommunityWarning::query()->withoutGlobalScopes()->whereIn('customer_id', $profiles->pluck('customer_id'))->orderByDesc('id')->get()->groupBy('customer_id');
        $users = User::query()->whereIn('id', $warnings->flatten()->pluck('issued_by')->filter())->pluck('name', 'id');
        $names = CommunityProfile::query()->withoutGlobalScopes()->whereIn('customer_id', $warnings->flatten()->pluck('issued_by_customer')->filter())->pluck('name', 'customer_id');

        $front = ForumRoutes::front($site);

        return Inertia::render('forum::Members', [
            'q' => $q,
            'filter' => $filter,
            'members' => array_values($profiles->map(function (CommunityProfile $p) use ($warnings, $users, $names, $front): array {
                $list = $warnings->get($p->customer_id, collect());

                return ['id' => $p->customer_id, 'name' => $p->name, 'email' => (string) $p->customer?->email, 'avatar' => $front($p->avatarUrl()), 'posts' => $p->posts, 'team' => $p->user_id !== null,
                    'last' => $p->last_activity_at?->format('d.m.Y H:i'),
                    'points' => (int) $list->filter(static fn (CommunityWarning $w): bool => $w->isActive())->sum('points'),
                    'muted' => $p->isMuted() && ! $p->isBanned() ? ['until' => $p->muted_until?->format('d.m.Y H:i')] : null,
                    'banned' => $p->isBanned() ? ['until' => $p->banned_until?->format('d.m.Y H:i'), 'reason' => $p->ban_reason] : null,
                    'warnings' => array_values($list->take(10)->map(static fn (CommunityWarning $w): array => ['id' => $w->id, 'reason' => $w->reason, 'points' => $w->points, 'active' => $w->isActive(),
                        'date' => $w->created_at?->format('d.m.Y'), 'expires' => $w->expires_at?->format('d.m.Y'), 'revoked' => $w->revoked_at !== null,
                        'by' => $w->issued_by !== null ? (string) ($users[$w->issued_by] ?? '') : (string) ($names[$w->issued_by_customer] ?? ''),
                        'revoke_url' => ForumRoutes::url('warnings.revoke', $w->id)])->all()),
                    'urls' => ['warn' => ForumRoutes::url('members.warn', $p->customer_id), 'sanction' => ForumRoutes::url('members.sanction', $p->customer_id),
                        'spammer' => ForumRoutes::url('members.spammer', $p->customer_id)]];
            })->all()),
            'defaults' => app(ModerationSettings::class)->all($site),
            'urls' => ['index' => ForumRoutes::url('members'), 'overview' => ForumRoutes::url('index'), 'moderation' => ForumRoutes::url('moderation')],
        ]);
    }

    public function warn(Request $request, int $customer): RedirectResponse
    {
        [$site, $target] = $this->target($customer);
        $data = $request->validate(['grund' => ['required', 'string', 'min:3', 'max:200'], 'punkte' => ['required', 'integer', 'min:0', 'max:100'], 'tage' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'notiz' => ['nullable', 'string', 'max:500']], [], ['grund' => __('Grund'), 'punkte' => __('Punkte'), 'tage' => __('Gültig (Tage)')]);
        $result = $this->warnings->warn($site, $target, $this->staff($request), (string) $data['grund'], (int) $data['punkte'], isset($data['tage']) ? (int) $data['tage'] : null,
            isset($data['notiz']) ? (string) $data['notiz'] : null);

        return back()->with('status', match ($result['consequence']) {
            'banned' => __('Verwarnt — die Punkte reichen für eine Sperre.'),
            'muted' => __('Verwarnt — die Punkte reichen für eine Schreibsperre.'),
            default => __('Verwarnt.'),
        });
    }

    /** Schreibsperre oder Sperre setzen (mit Tagen oder ohne Ende) und aufheben. */
    public function sanction(Request $request, int $customer): RedirectResponse
    {
        [$site, $target] = $this->target($customer);
        $data = $request->validate(['art' => ['required', 'in:mute,unmute,ban,unban'], 'tage' => ['nullable', 'integer', 'min:1', 'max:3650'], 'grund' => ['nullable', 'string', 'max:500']]);
        $until = isset($data['tage']) ? now()->addDays((int) $data['tage']) : null;
        $reason = isset($data['grund']) ? (string) $data['grund'] : null;
        $by = $this->staff($request);
        match ($data['art']) {
            'mute' => $this->sanctions->mute($site, $target, $by, $until, $reason),
            'unmute' => $this->sanctions->unmute($site, $target, $by),
            'ban' => $this->sanctions->ban($site, $target, $by, $until, $reason),
            default => $this->sanctions->unban($site, $target, $by),
        };

        return back()->with('status', match ($data['art']) {
            'mute' => __('Schreibsperre gesetzt.'), 'unmute' => __('Schreibsperre aufgehoben.'), 'ban' => __('Gesperrt.'), default => __('Sperre aufgehoben.'),
        });
    }

    public function spammer(Request $request, int $customer): RedirectResponse
    {
        [$site, $target] = $this->target($customer);
        $result = app(Moderation::class)->removeSpammer($site, $this->staff($request), $target);

        return back()->with('status', __('Entfernt: :threads Themen und :posts Beiträge liegen im Papierkorb, das Konto ist gesperrt.', ['threads' => $result['threads'], 'posts' => $result['posts']]));
    }

    public function revoke(Request $request, int $warning): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        $model = CommunityWarning::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereNull('revoked_at')->findOrFail($warning);
        $this->warnings->revoke($site, $model, $this->staff($request));

        return back()->with('status', __('Verwarnung zurückgenommen.'));
    }

    /** @return array{0: Site, 1: Customer} */
    private function target(int $customer): array
    {
        $site = CommunityController::site($this->current);
        $target = Customer::query()->withoutGlobalScopes()->where('site_id', $site->id)->findOrFail($customer);

        return [$site, $target];
    }

    private function staff(Request $request): Moderator
    {
        $user = $request->user();
        assert($user instanceof User);

        return Moderator::staff($user);
    }
}
