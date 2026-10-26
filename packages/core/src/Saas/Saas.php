<?php

declare(strict_types=1);

namespace Basalto\Core\Saas;

use Basalto\Core\Models\Account;

/**
 * Der SaaS-Modus einer Installation (TB 12, M-21, ADR-008): an mit `BASALTO_SAAS=true`. Die Instanz («start» oder
 * «pro») steht in `BASALTO_INSTANCE`; registriert wird nur dort, wo die Testphase läuft. Kunden sind Konten mit einem
 * SaaS-Plan (`basalto.saas.plans`); das Konto der Installation (Betreiber, Plan «download») gehört nicht dazu.
 */
final class Saas
{
    public static function enabled(): bool
    {
        return (bool) config('basalto.saas.enabled', false);
    }

    public static function instance(): string
    {
        return (string) config('basalto.saas.instance', 'start');
    }

    /** Selbstregistrierung nur im SaaS-Modus und nur auf der Instanz der Testphase. */
    public static function registrationOpen(): bool
    {
        return self::enabled() && Plan::find(Account::PLAN_TRIAL)?->instance === self::instance();
    }

    /** Ein Kundenkonto der SaaS — Testphase oder einer der Pläne, nicht das Konto der Installation. */
    public static function isCustomer(?Account $account): bool
    {
        return $account !== null && Plan::find($account->plan) !== null;
    }

    public static function trialDays(): int
    {
        return max(1, (int) config('basalto.saas.trial_days', 14));
    }

    public static function aliasDomain(): string
    {
        return (string) config('basalto.saas.alias_domain', 'basaltocms.com');
    }

    /** Die Adresse einer Site unter ihrem Alias, mit dem Schema der Anwendung (lokal http, sonst https). */
    public static function aliasUrl(string $alias): string
    {
        $scheme = LocalAliasFolders::scheme() ?? parse_url((string) config('app.url'), PHP_URL_SCHEME);

        return (is_string($scheme) && $scheme !== '' ? $scheme : 'https').'://'.$alias.'.'.self::aliasDomain();
    }
}
