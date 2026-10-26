<?php

declare(strict_types=1);

namespace Basalto\Core\Saas;

use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Basalto\Core\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Die Testphase eines Kontos (TB 12 Scheibe 1, M-21): 14 Tage ab der Registrierung, «nichts läuft automatisch an».
 * Am Ende bleibt alles erreichbar; das Backend zeigt ein Hinweisband «Testphase abgelaufen — Plan wählen», und
 * {@see bannerDays()} Tage nach dem Ende ohne Plan trägt das Frontend der Sites eine Fussleiste — wie eine unregistrierte
 * Download-Installation (D-12). Eine Sperre der Inhalte gibt es erst mit der Kündigung (Scheibe 4).
 */
final class Trial
{
    public static function inTrial(?Account $account): bool
    {
        return $account !== null && $account->plan === Account::PLAN_TRIAL && $account->trial_ends_at !== null;
    }

    public static function expired(?Account $account): bool
    {
        return self::inTrial($account) && $account?->trial_ends_at?->isPast() === true;
    }

    /** Ganze Tage bis zum Ende (aufgerundet), 0 wenn abgelaufen. */
    public static function daysLeft(Account $account): int
    {
        if (! self::inTrial($account) || $account->trial_ends_at === null || $account->trial_ends_at->isPast()) {
            return 0;
        }

        return (int) ceil(CarbonImmutable::now()->diffInSeconds($account->trial_ends_at) / 86400);
    }

    public static function bannerDays(): int
    {
        return max(0, (int) config('basalto.saas.trial_banner_days', 14));
    }

    public static function bannerFrom(Account $account): ?CarbonImmutable
    {
        return self::inTrial($account) ? $account->trial_ends_at?->addDays(self::bannerDays()) : null;
    }

    /** Fussleiste im Frontend: Testphase seit {@see bannerDays()} Tagen abgelaufen, noch kein Plan. */
    public static function frontendBanner(?Site $site): bool
    {
        $account = $site?->account;
        if (! Saas::enabled() || $account === null || ! self::expired($account)) {
            return false;
        }

        return self::bannerFrom($account)?->isPast() === true;
    }

    /**
     * Das Hinweisband im Backend, solange die Testphase des Kontos der gewählten Site abgelaufen ist und kein Plan gewählt
     * wurde — mit Link auf Konto › Plan für Besitzer, Verwaltung und Abrechnung.
     *
     * @return array{text: string, href: string|null, label: string|null}|null
     */
    public static function backendNotice(?Authenticatable $user, ?Site $site): ?array
    {
        $account = $site?->account;
        if (! Saas::enabled() || $user === null || $account === null || ! self::expired($account)) {
            return null;
        }
        $date = $account->trial_ends_at?->timezone($site->timezone ?: 'Europe/Zurich')->format('d.m.Y') ?? '';
        if ($account->roleOf($user) === null) {
            return ['text' => __('Die Testphase dieses Kontos ist am :date abgelaufen — die Verwaltung des Kontos wählt einen Plan. Die Site bleibt erreichbar.', ['date' => $date]), 'href' => null, 'label' => null];
        }

        return ['text' => __('Testphase abgelaufen am :date — Ihre Sites bleiben erreichbar, nichts läuft automatisch an.', ['date' => $date]),
            'href' => route('basalto.saas.account.plan'), 'label' => __('Plan wählen')];
    }

    /** Die Fussleiste im Frontend — in der Sprache der Seite. */
    public static function footerHtml(): string
    {
        return '<div id="basalto-trial-banner" style="position:fixed;left:0;right:0;bottom:0;z-index:2147483647;padding:10px 16px;background:#1f1d1c;color:#f5f1ea;font:14px/1.4 system-ui,sans-serif;text-align:center">'
            .e(__('Diese Website läuft in einer abgelaufenen Testphase von Basalto.')).' '
            .'<a href="'.e(Vendor::url()).'" style="color:#ff6a3d;text-decoration:underline">'.e(__('Basalto auf :vendor', ['vendor' => Vendor::host()])).'</a></div>';
    }
}
