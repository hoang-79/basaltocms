<?php

declare(strict_types=1);

namespace Basalto\Core\Extranet\Statistics;

use Basalto\Core\Analytics\Period;
use Basalto\Core\Extranet\SaasCustomers;
use Basalto\Core\FrontendAccounts\Social\SocialProvider;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\AccountInvoice;
use Basalto\Core\Models\Site;
use Basalto\Core\Support\Text;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Abschnitte der Statistik aus dem Kern (TB 55): Kundenkonten der Site des Herstellers (mit Anmeldearten) und die
 * SaaS-Konten dieser Installation (Registrierungen, Pläne, Kündigungen, bezahlte Rechnungen).
 */
final class CoreStatistics
{
    public static function accounts(Period $period, Site $site): MetricSection
    {
        $customers = static fn (Period $p): Builder => DB::table('customers')->where('site_id', $site->id)->whereBetween('created_at', $p->between());
        $previous = $period->previous();
        $identities = DB::table('customer_identities')->where('site_id', $site->id)->selectRaw('provider, COUNT(DISTINCT customer_id) as n')->groupBy('provider')
            ->orderByDesc('n')->get();
        $passkeys = (int) DB::table('customer_passkeys')->where('site_id', $site->id)->distinct()->count('customer_id');

        return (new MetricSection('konten', Text::t('Kundenkonten')))
            ->lead(Text::t('Kundenkonten der Website — für Käufe, Lizenzen, Marktplatz und Community.'))
            ->kpi(Text::t('Neue Kundenkonten'), $customers($period)->count(), $customers($previous)->count(), headline: true)
            ->kpi(Text::t('Kundenkonten insgesamt'), DB::table('customers')->where('site_id', $site->id)->count())
            ->kpi(Text::t('Angemeldet im Zeitraum'), DB::table('customers')->where('site_id', $site->id)->whereBetween('last_login_at', $period->between())->count(),
                DB::table('customers')->where('site_id', $site->id)->whereBetween('last_login_at', $previous->between())->count())
            ->kpi(Text::t('Mit Passkey'), $passkeys)
            ->series(Text::t('Neue Kundenkonten je Tag'), $period->daily($customers($period)))
            ->list(Text::t('Anmeldung über andere Konten'), $identities->map(static fn (object $row): array => [
                'label' => SocialProvider::tryFrom((string) $row->provider)?->label() ?? (string) $row->provider, 'value' => (int) $row->n,
            ]), empty: Text::t('Noch kein Kundenkonto ist mit Google, Microsoft, GitHub oder LinkedIn verbunden.'));
    }

    public static function saas(Period $period, Site $site): MetricSection
    {
        $previous = $period->previous();
        $registered = static fn (Period $p): int => SaasCustomers::query()->whereBetween('created_at', $p->between())->count();
        $cancelled = static fn (Period $p): int => SaasCustomers::query()->whereBetween('cancelled_at', $p->between())->count();
        $revenue = static fn (Period $p): int => (int) AccountInvoice::query()->where('status', AccountInvoice::STATUS_PAID)->whereBetween('paid_at', $p->between())->sum('total');
        $plans = SaasCustomers::query()->whereNull('cancelled_at')->selectRaw('plan, COUNT(*) as n')->groupBy('plan')->orderByDesc('n')->toBase()->get();

        return (new MetricSection('saas', Text::t('SaaS')))
            ->lead(Text::t('Konten der SaaS auf dieser Installation — Registrierungen, Pläne und bezahlte Rechnungen.'))
            ->link(Text::t('SaaS-Betrieb öffnen'), route('extranet.saas.index'))
            ->kpi(Text::t('Neue SaaS-Konten'), $registered($period), $registered($previous), headline: true)
            ->kpi(Text::t('Aktive SaaS-Konten'), SaasCustomers::query()->whereNull('cancelled_at')->where('plan', '!=', Account::PLAN_TRIAL)->count())
            ->kpi(Text::t('In der Testphase'), SaasCustomers::query()->whereNull('cancelled_at')->where('plan', Account::PLAN_TRIAL)->where('trial_ends_at', '>', now())->count())
            ->kpi(Text::t('Kündigungen'), $cancelled($period), $cancelled($previous))
            ->kpi(Text::t('Umsatz SaaS'), $revenue($period), $revenue($previous), 'money', Text::t('Bezahlte Rechnungen inkl. MWST'), headline: true)
            ->series(Text::t('Neue SaaS-Konten je Tag'), $period->daily(SaasCustomers::query()->whereBetween('created_at', $period->between())))
            ->list(Text::t('Konten je Plan'), $plans->map(static fn (object $row): array => ['label' => SaasCustomers::planLabel((string) $row->plan), 'value' => (int) $row->n]),
                empty: Text::t('Noch keine SaaS-Konten.'));
    }
}
