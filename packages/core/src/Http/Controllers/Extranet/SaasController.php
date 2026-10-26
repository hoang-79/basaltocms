<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Extranet;

use Basalto\Core\Extranet\InstanceSummary;
use Basalto\Core\Extranet\SaasCustomers;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\InstanceMove;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Saas\Domains;
use Basalto\Core\Saas\Move\InstanceLink;
use Basalto\Core\Saas\Saas;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SaaS-Betrieb (CR-004, P-15): Zustand der Instanzen (eigene direkt, andere signiert), Domains mit Cloud-Limit,
 * Umzüge und anstehende Löschungen. Reiter `?tab=domains&filter=pending` ist das Ziel der Übersicht.
 */
final class SaasController
{
    public const TABS = ['instances', 'domains', 'moves', 'deletions'];

    public function index(Request $request): Response
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'instances';
        $filter = $request->query('filter') === 'pending' ? 'pending' : null;
        $instances = [InstanceSummary::local()];
        if ($tab === 'instances') {
            foreach (InstanceLink::instances() as $instance) {
                if ($instance !== Saas::instance()) {
                    $instances[] = InstanceSummary::remote($instance);
                }
            }
        }
        $domains = SiteDomain::query()->where('kind', SiteDomain::KIND_CUSTOM)->whereNotNull('cloud_domain_id')
            ->when($filter === 'pending', static fn ($q) => $q->whereNull('verified_at'))->with('site.account')->orderByDesc('id')->limit(300)->get()
            ->map(static fn (SiteDomain $d): array => ['id' => $d->id, 'host' => $d->host, 'site' => $d->site->name ?? '—', 'account' => $d->site->account->name ?? '—',
                'state' => Domains::state($d), 'error' => $d->last_error, 'created' => $d->created_at?->format('d.m.Y'),
                'url' => $d->site?->account_id !== null ? route('extranet.customers.show', ['saas', $d->site->account_id]) : null])->all();
        $moves = InstanceMove::query()->with('account')->latest('id')->limit(50)->get()->map(static fn (InstanceMove $m): array => [
            'id' => $m->id, 'account' => $m->account->name ?? '—', 'direction' => $m->direction, 'from' => $m->source, 'to' => $m->target, 'status' => $m->status,
            'error' => $m->error, 'created' => $m->created_at->format('d.m.Y, H:i'),
        ])->all();
        $deletions = SaasCustomers::query(SaasCustomers::CANCELLED)->orderBy('delete_after')->limit(100)->get()->map(static fn (Account $a): array => [
            'id' => $a->id, 'name' => $a->name, 'ends' => $a->ends_at?->format('d.m.Y'), 'delete_after' => $a->delete_after?->format('d.m.Y'),
            'url' => route('extranet.customers.show', ['saas', $a->id]),
        ])->all();

        return Inertia::render('core::Extranet/Saas', [
            'tab' => $tab,
            'filter' => $filter,
            'saas' => Saas::enabled(),
            'instances' => $instances,
            'domains' => $domains,
            'moves' => $moves,
            'deletions' => $deletions,
            'urls' => ['index' => route('extranet.saas.index')],
        ]);
    }
}
