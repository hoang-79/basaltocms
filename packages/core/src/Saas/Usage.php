<?php

declare(strict_types=1);

namespace Basalto\Core\Saas;

use Basalto\Core\Models\Account;
use Basalto\Core\Models\SiteDomain;
use Illuminate\Support\Facades\DB;

/**
 * Kontingente eines Kontos mit Belegung (TB 12 Scheibe 1, ADR-008 Konsequenz 3): Sites, eigene Domains, Speicher und
 * Benutzer gegen den Plan. Eine Überschreitung ergibt einen Hinweis im Konto-Bereich — **nie eine Sperre** der Inhalte.
 * Nur das Anlegen einer weiteren Site richtet sich nach dem Kontingent ({@see canAddSite()}); Zusätze (Site CHF 15,
 * Domain CHF 5) zählt Scheibe 2 über `billing.extra_sites` und `billing.extra_domains` mit.
 *
 * @phpstan-type Row array{key: string, label: string, used: int, limit: int, unit: string, over: bool}
 */
final class Usage
{
    public const SITES = 'sites';

    public const DOMAINS = 'domains';

    public const STORAGE = 'storage';

    public const USERS = 'users';

    /** @return list<Row> */
    public static function of(Account $account): array
    {
        $plan = Plan::find($account->plan) ?? Plan::find(Account::PLAN_TRIAL);
        $siteIds = array_values($account->sites()->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
        $rows = [
            self::row(self::SITES, __('Sites'), count($siteIds), self::siteLimit($account), 'count'),
            self::row(self::DOMAINS, __('Eigene Domains'), self::customDomains($siteIds), ($plan->domains ?? 0) + self::extra($account, 'extra_domains'), 'count'),
            self::row(self::STORAGE, __('Speicher'), $siteIds === [] ? 0 : (int) DB::table('media')->whereIn('site_id', $siteIds)->sum('size'), ($plan->storageMb ?? 0) * 1024 * 1024, 'bytes'),
            self::row(self::USERS, __('Benutzer'), self::users($account, $siteIds), $plan->users ?? 1, 'count'),
        ];

        return $rows;
    }

    /**
     * Hinweise zu überschrittenen Kontingenten — ein Satz je Kontingent.
     *
     * @param  list<Row>|null  $rows
     * @return list<string>
     */
    public static function hints(Account $account, ?array $rows = null): array
    {
        $hints = [];
        foreach ($rows ?? self::of($account) as $row) {
            if ($row['over']) {
                $hints[] = __('Kontingent «:what» überschritten — Ihre Inhalte bleiben erreichbar; wählen Sie einen grösseren Plan oder einen Zusatz.', ['what' => $row['label']]);
            }
        }

        return $hints;
    }

    public static function siteLimit(Account $account): int
    {
        return (Plan::find($account->plan)->sites ?? 1) + self::extra($account, 'extra_sites');
    }

    /** Eine weitere Site passt ins Kontingent (Plan plus Zusatz-Sites). */
    public static function canAddSite(Account $account): bool
    {
        return $account->sites()->count() < self::siteLimit($account);
    }

    /** @return Row */
    private static function row(string $key, string $label, int $used, int $limit, string $unit): array
    {
        return ['key' => $key, 'label' => $label, 'used' => $used, 'limit' => $limit, 'unit' => $unit, 'over' => $used > $limit];
    }

    private static function extra(Account $account, string $key): int
    {
        return max(0, (int) ($account->billing[$key] ?? 0));
    }

    /**
     * Eigene Domains: Apex und `www` zählen als eine Domain (D-03).
     *
     * @param  list<int>  $siteIds
     */
    private static function customDomains(array $siteIds): int
    {
        if ($siteIds === []) {
            return 0;
        }
        $hosts = SiteDomain::query()->whereIn('site_id', $siteIds)->where('kind', SiteDomain::KIND_CUSTOM)->pluck('host')->map(static fn (mixed $host): string => (string) preg_replace('/^www\./', '', (string) $host));

        return $hosts->unique()->count();
    }

    /**
     * Benutzer des Kontos: Konto-Rollen und Mitglieder seiner Sites, jede Person einmal.
     *
     * @param  list<int>  $siteIds
     */
    private static function users(Account $account, array $siteIds): int
    {
        $ids = DB::table('account_user')->where('account_id', $account->id)->pluck('user_id');
        if ($siteIds !== []) {
            $ids = $ids->merge(DB::table('site_user')->whereIn('site_id', $siteIds)->pluck('user_id'));
        }

        return $ids->map(static fn (mixed $id): int => (int) $id)->unique()->count();
    }
}
