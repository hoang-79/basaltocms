<?php

declare(strict_types=1);

namespace Basalto\Core\Extranet;

use Basalto\Core\Access\Areas;
use Basalto\Core\Access\Level;
use Basalto\Core\Extranet\Statistics\CoreStatistics;
use Basalto\Core\Models\AccountInvoice;
use Basalto\Core\Models\ContractCancellation;
use Carbon\CarbonImmutable;

/**
 * Der Teil des Extranets aus dem Kern (CR-004, P-09–P-16): Bereiche, Menü, Aufgaben der Übersicht, Quellen für Kunden
 * und Rechnungen der SaaS. Lizenzverkauf und Marktplatz melden ihren Teil in ihren Modulen an.
 */
final class CoreExtranet
{
    public static function register(Areas $areas, ExtranetRegistry $registry): void
    {
        $areas->register('extranet_customers', 'Kunden', Areas::EXTRANET)
            ->register('extranet_statistics', 'Statistik', Areas::EXTRANET)
            ->register('extranet_invoices', 'Rechnungen', Areas::EXTRANET)
            ->register('extranet_saas', 'SaaS-Betrieb', Areas::EXTRANET)
            ->register('extranet_support', 'Support-Zugriff', Areas::EXTRANET)
            ->register('extranet_team', 'Team', Areas::EXTRANET);
        $areas->map('extranet.customers.', 'extranet_customers')
            ->map('extranet.cancellations.', 'extranet_customers')
            ->map('extranet.statistics.', 'extranet_statistics')
            ->map('extranet.invoices.', 'extranet_invoices')
            ->route('extranet.invoices.credit', 'extranet_invoices', Level::All)
            ->map('extranet.saas.', 'extranet_saas')
            ->map('extranet.support.', 'extranet_support')
            ->map('extranet.team.', 'extranet_team')
            ->open('extranet.home', 'extranet.missing', 'extranet.notifications.');

        $registry->menu('Übersicht', 'extranet.home', 10, null, 'dashboard')
            ->menu('Statistik', 'extranet.statistics.index', 15, 'extranet_statistics', 'statistics')
            ->menu('Kunden', 'extranet.customers.index', 20, 'extranet_customers', 'users')
            ->menu('Kündigungen', 'extranet.cancellations.index', 25, 'extranet_customers', 'log')
            ->menu('Rechnungen', 'extranet.invoices.index', 30, 'extranet_invoices', 'license')
            ->menu('SaaS-Betrieb', 'extranet.saas.index', 60, 'extranet_saas', 'activity')
            ->menu('Support', 'extranet.support.index', 70, 'extranet_support', 'user')
            ->menu('Team', 'extranet.team.index', 80, 'extranet_team', 'users');
        $registry->count('extranet.cancellations.index', static fn (): int => ContractCancellation::query()->where('status', ContractCancellation::REVIEW)->count());
        $registry->count('extranet.invoices.index', static fn (): int => array_sum(array_map(static fn (InvoiceSource $s): int => count($s->list('overdue')), $registry->invoiceSources())));

        $registry->customers(app(SaasCustomers::class))->invoices(app(SaasInvoices::class));
        // TB 55: Abschnitte der Statistik aus dem Kern
        $registry->statistics('konten', 'Kundenkonten', 'extranet_customers', 60, CoreStatistics::accounts(...))
            ->statistics('saas', 'SaaS', 'extranet_saas', 70, CoreStatistics::saas(...));

        // P-10: «Heute zu tun» — jede Zahl führt auf die Liste mit demselben Filter
        $registry->todo('overdue_invoices', 'Überfällige Rechnungen', 'extranet_invoices',
            static fn (): int => array_sum(array_map(static fn (InvoiceSource $s): int => count($s->list('overdue')), $registry->invoiceSources())),
            static fn (): string => route('extranet.invoices.index', ['filter' => 'overdue']));
        $registry->todo('trials_ending', 'Testphasen, die bald enden', 'extranet_customers',
            static fn (): int => SaasCustomers::query(SaasCustomers::TRIAL_ENDING)->count(),
            static fn (): string => route('extranet.customers.index', ['filter' => SaasCustomers::TRIAL_ENDING]));
        $registry->todo('cancellations', 'Kündigungen', 'extranet_customers',
            static fn (): int => SaasCustomers::query(SaasCustomers::CANCELLED)->count(),
            static fn (): string => route('extranet.customers.index', ['filter' => SaasCustomers::CANCELLED]));
        // CR-011 TB 59: Kündigungen über «Verträge hier kündigen», die Basalto nicht selbst zuordnen konnte
        $registry->todo('contract_cancellations', 'Kündigungen zuordnen', 'extranet_customers',
            static fn (): int => ContractCancellation::query()->where('status', ContractCancellation::REVIEW)->count(),
            static fn (): string => route('extranet.cancellations.index', ['filter' => 'review']));
        $registry->todo('pending_domains', 'Hängige Domains', 'extranet_saas',
            static fn (): int => InstanceSummary::pendingDomains(),
            static fn (): string => route('extranet.saas.index', ['tab' => 'domains', 'filter' => 'pending']));
    }

    /** Überfällige SaaS-Rechnung: offen, per QR, Frist vorbei — wie die Erinnerung des Abrechnungslaufs. */
    public static function overdue(AccountInvoice $invoice): bool
    {
        return $invoice->status === AccountInvoice::STATUS_OPEN && $invoice->method === AccountInvoice::METHOD_INVOICE && $invoice->due_at !== null && $invoice->due_at->lt(CarbonImmutable::today());
    }
}
