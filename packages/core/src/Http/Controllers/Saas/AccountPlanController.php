<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Saas;

use Basalto\Core\Saas\AccountContext;
use Basalto\Core\Saas\Plan;
use Basalto\Core\Saas\Usage;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Konto › Plan (TB 12 Scheibe 1, Mockup 23): Testphase mit Resttagen, Kontingente mit Belegung und Hinweis bei
 * Überschreitung (nie eine Sperre), die Stufen Start, Standard und Pro. Das Wählen eines Plans gehört zu Abo und
 * Rechnungen (Scheibe 2) — die Knöpfe führen dorthin (`urls.choose`).
 */
final class AccountPlanController
{
    public function __invoke(Request $request): Response
    {
        $account = AccountContext::from($request);
        $usage = Usage::of($account);

        return AccountPage::render($request, 'Plan', 'plan', [
            'usage' => $usage,
            'hints' => Usage::hints($account, $usage),
            'plans' => array_map(static fn (Plan $plan): array => $plan->toArray() + ['current' => $plan->key === $account->plan], Plan::purchasable()),
            'extra' => ['site' => (int) config('basalto.saas.extra_site_price', 15), 'domain' => (int) config('basalto.saas.extra_domain_price', 5)],
            'urls' => ['choose' => route('basalto.saas.account.billing')],
        ]);
    }
}
