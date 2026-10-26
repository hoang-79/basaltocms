<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Extranet;

use Basalto\Core\Access\Level;
use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Extranet\ExtranetAccess;
use Basalto\Core\Extranet\SupportAccess;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SupportSession;
use Basalto\Core\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Support-Zugriff (CR-004, P-16): eine Kunden-Site dieser Installation befristet und nur lesend öffnen — Grund Pflicht,
 * Band beim Kunden, Protokoll der Site. Geöffnet wird im CMS-Backend des Herstellers (`/admin/support/<id>`).
 */
final class SupportController
{
    public function __construct(private readonly SupportAccess $support) {}

    public function index(Request $request): Response
    {
        $search = is_string($request->query('q')) ? trim($request->query('q')) : '';
        $vendor = Extranet::vendorSite();
        $installation = Account::installation()->id;
        $sites = Site::query()->where('account_id', '!=', $installation)->when($vendor !== null, static fn ($q) => $q->whereKeyNot($vendor?->id))
            ->when($search !== '', static fn ($q) => $q->where(static fn ($w) => $w->where('name', 'like', '%'.$search.'%')->orWhere('handle', 'like', '%'.$search.'%')))
            ->with('account')->orderBy('name')->limit(50)->get()
            ->map(static fn (Site $s): array => ['id' => $s->id, 'name' => $s->name, 'handle' => $s->handle, 'account' => $s->account->name ?? '—'])->all();
        $user = $request->user();
        $canEndAll = $user !== null && app(ExtranetAccess::class)->allows($user, 'extranet_support', Level::All);
        $sessions = SupportSession::query()->with(['site', 'user'])->latest('id')->limit(50)->get()->map(fn (SupportSession $s): array => [
            'id' => $s->id, 'site' => $s->site->name ?? '—', 'person' => $s->user instanceof Model ? (string) $s->user->getAttribute('name') : '—', 'reason' => $s->reason,
            'started' => $s->created_at?->format('d.m.Y, H:i'), 'until' => $s->expires_at->format('d.m.Y, H:i'), 'ended' => $s->ended_at?->format('d.m.Y, H:i'), 'active' => $s->isActive(),
            'own' => $user !== null && $s->user_id === (int) $user->getAuthIdentifier(),
            'urls' => ['open' => $s->isActive() && $user !== null && $s->user_id === (int) $user->getAuthIdentifier() ? $this->openUrl($s) : null,
                'end' => $s->isActive() && ($canEndAll || ($user !== null && $s->user_id === (int) $user->getAuthIdentifier())) ? route('extranet.support.end', $s->id) : null],
        ])->all();

        return Inertia::render('core::Extranet/Support', [
            'sites' => $sites,
            'sessions' => $sessions,
            'current' => ['q' => $search],
            'minutes' => ['default' => (int) config('basalto.extranet.support_minutes', 60), 'max' => (int) config('basalto.extranet.support_max_minutes', 240)],
            'urls' => ['index' => route('extranet.support.index'), 'start' => route('extranet.support.start')],
        ]);
    }

    public function start(Request $request): SymfonyResponse
    {
        $data = $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'minutes' => ['required', 'integer', 'min:5', 'max:'.(int) config('basalto.extranet.support_max_minutes', 240)],
        ], [], ['reason' => __('Grund'), 'minutes' => __('Dauer')]);
        $site = Site::query()->findOrFail((int) $data['site_id']);
        abort_if($site->account_id === Account::installation()->id, 422, __('Sites des Herstellers öffnet man nicht über den Support-Zugriff.'));
        $user = $request->user();
        abort_if($user === null, 403);
        $session = $this->support->start($user, $site, trim((string) $data['reason']), (int) $data['minutes']);

        // Anderer Host: Inertia folgt einer Weiterleitung per XHR nicht über Hosts — `location` lädt die Seite neu
        return Inertia::location($this->openUrl($session));
    }

    public function end(Request $request, int $session): RedirectResponse
    {
        $item = SupportSession::query()->findOrFail($session);
        $user = $request->user();
        abort_if($user === null, 403);
        abort_unless($item->user_id === (int) $user->getAuthIdentifier() || app(ExtranetAccess::class)->allows($user, 'extranet_support', Level::All), 403);
        $this->support->end($item, $user);

        return back()->with('status', __('Support-Zugriff beendet.'));
    }

    private function openUrl(SupportSession $session): string
    {
        return Vendor::url((string) config('basalto.backend.path', 'admin').'/support/'.$session->id);
    }
}
