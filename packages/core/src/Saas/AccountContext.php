<?php

declare(strict_types=1);

namespace Basalto\Core\Saas;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Welches Konto ein Benutzer im Konto-Bereich sieht (TB 12 Scheibe 1, Mockup 23): das Konto der gewählten Site, wenn
 * er dort eine Konto-Rolle hat (Besitzer, Verwaltung, Abrechnung) — sonst sein erstes Kundenkonto. Ohne Konto-Rolle
 * gibt es den Bereich nicht (404). Die Middleware legt das Konto in die Anfrage; Controller holen es mit {@see from()}.
 *
 * Erweiterungspunkt für die weiteren Scheiben: {@see from()}, {@see Site()} (Site des Kontos oder 404),
 * {@see manages()} (Besitzer/Verwaltung) und {@see bills()} (Besitzer/Abrechnung).
 */
final class AccountContext
{
    public const ATTRIBUTE = 'basalto.saas.account';

    public static function resolve(Authenticatable $user, ?Site $site): ?Account
    {
        $account = $site?->account;
        if (Saas::isCustomer($account) && $account !== null && $account->roleOf($user) !== null) {
            return $account;
        }
        $ids = DB::table('account_user')->where('user_id', $user->getAuthIdentifier())->orderBy('account_id')->pluck('account_id')->all();
        foreach (Account::query()->whereIn('id', $ids)->orderBy('id')->get() as $candidate) {
            if (Saas::isCustomer($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function from(Request $request): Account
    {
        $account = $request->attributes->get(self::ATTRIBUTE);
        abort_unless($account instanceof Account, 404);

        return $account;
    }

    /** Eine Site des Kontos — fremde Sites sind 404, nicht 403 (ADR-008). */
    public static function site(Account $account, int $siteId): Site
    {
        $site = $account->sites()->whereKey($siteId)->first();
        abort_if($site === null, 404);

        return $site;
    }

    /** Besitzer und Verwaltung: Sites anlegen, Benutzer des Kontos verwalten. */
    public static function manages(Authenticatable $user, Account $account): bool
    {
        $role = $account->roleOf($user);

        return $role === AccountRole::Owner || $role === AccountRole::Admin;
    }

    /** Besitzer und Abrechnung: Plan, Abo und Rechnungen (Scheibe 2). */
    public static function bills(Authenticatable $user, Account $account): bool
    {
        $role = $account->roleOf($user);

        return $role === AccountRole::Owner || $role === AccountRole::Billing;
    }
}
