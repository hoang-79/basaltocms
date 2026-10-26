<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Saas;

use Basalto\Core\Models\Account;
use Basalto\Core\Saas\AccountContext;
use Basalto\Core\Saas\Plan;
use Basalto\Core\Saas\Trial;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Der gemeinsame Rahmen des Konto-Bereichs (TB 12 Scheibe 1, Mockup 23): Kopf mit Konto, Plan und Testphase, darunter
 * die Reiter als eigene Seiten — so füllt jede Scheibe ihren Reiter mit eigenem Controller und eigener Vue-Seite.
 *
 * Reiter → Route → Seite: Sites `basalto.saas.account.sites` (`core::Saas/Account/Sites`) · Plan `….plan` (`…/Plan`) ·
 * Abo und Rechnungen `….billing` (`…/Billing`, Scheibe 2) · Domains `….domains` (`…/Domains`, Scheibe 3) · Benutzer
 * `….users` (`…/Users`) · Export und Kündigung `….exit` (`…/Exit`, Scheibe 4).
 */
final class AccountPage
{
    public const TABS = ['sites', 'plan', 'billing', 'domains', 'users', 'exit'];

    /** @param  array<string, mixed>  $props */
    public static function render(Request $request, string $component, string $tab, array $props = []): Response
    {
        return Inertia::render('core::Saas/Account/'.$component, ['account' => self::header($request), 'tab' => $tab] + $props);
    }

    /** @return array<string, mixed> */
    public static function header(Request $request): array
    {
        $account = AccountContext::from($request);
        $user = $request->user();
        $plan = Plan::find($account->plan);
        $role = $user === null ? null : $account->roleOf($user);

        return [
            'id' => $account->id,
            'name' => $account->name,
            'plan' => $plan?->toArray(),
            'role' => $role?->label(),
            'can_manage' => $user !== null && AccountContext::manages($user, $account),
            'can_bill' => $user !== null && AccountContext::bills($user, $account),
            'trial' => self::trial($account),
            'tabs' => [
                ['key' => 'sites', 'label' => __('Sites'), 'href' => route('basalto.saas.account.sites')],
                ['key' => 'plan', 'label' => __('Plan'), 'href' => route('basalto.saas.account.plan')],
                ['key' => 'billing', 'label' => __('Abo und Rechnungen'), 'href' => route('basalto.saas.account.billing')],
                ['key' => 'domains', 'label' => __('Domains'), 'href' => route('basalto.saas.account.domains')],
                ['key' => 'users', 'label' => __('Benutzer'), 'href' => route('basalto.saas.account.users')],
                ['key' => 'exit', 'label' => __('Export und Kündigung'), 'href' => route('basalto.saas.account.exit')],
            ],
        ];
    }

    /** @return array{ends: string|null, days_left: int, expired: bool, banner_from: string|null}|null */
    public static function trial(Account $account): ?array
    {
        if (! Trial::inTrial($account)) {
            return null;
        }
        $timezone = $account->sites()->orderBy('id')->value('timezone');
        $zone = is_string($timezone) && $timezone !== '' ? $timezone : 'Europe/Zurich';

        return ['ends' => $account->trial_ends_at?->timezone($zone)->format('d.m.Y'), 'days_left' => Trial::daysLeft($account), 'expired' => Trial::expired($account),
            'banner_from' => Trial::bannerFrom($account)?->timezone($zone)->format('d.m.Y')];
    }
}
