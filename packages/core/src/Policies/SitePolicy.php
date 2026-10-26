<?php

declare(strict_types=1);

namespace Basalto\Core\Policies;

use Basalto\Core\Access\Access;
use Basalto\Core\Access\Areas;
use Basalto\Core\Access\Level;
use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Sites verwalten (K-01, K-06): sehen darf jedes Mitglied; anlegen darf, wer Besitzer oder Verwaltung eines Kontos ist;
 * bearbeiten darf Betreiber oder Administrator der Site — oder Besitzer/Verwaltung ihres Kontos.
 */
final class SitePolicy
{
    public function view(Authenticatable $user, Site $site): bool
    {
        return app(Access::class)->definition($user, $site) !== null || $this->managesAccount($user, $site->account);
    }

    public function create(Authenticatable $user): bool
    {
        // TB 12 Scheibe 1: eine Abfrage statt aller Konten — auf der SaaS gibt es Tausende
        return DB::table('account_user')->where('user_id', $user->getAuthIdentifier())->whereIn('role', [AccountRole::Owner->value, AccountRole::Admin->value])->exists();
    }

    /**
     * Ändern im Bereich der aktuellen Route (CR-005): Im Design-Controller heisst das «Design bearbeiten», in den
     * Mitgliedern «Mitglieder bearbeiten» — die Controller fragen weiter «update Site», die Antwort folgt dem Bereich.
     * Ohne Route mit Bereich (Konsole, Übersicht, Suche) gilt der Bereich «Einstellungen».
     */
    public function update(Authenticatable $user, Site $site): bool
    {
        return app(Access::class)->allows($user, $site, self::currentArea(), Level::Edit);
    }

    /** Benutzer und Rollen der Site verwalten (CR-005: Bereich «Benutzer und Rollen» bearbeiten). */
    public function manageMembers(Authenticatable $user, Site $site): bool
    {
        return app(Access::class)->allows($user, $site, 'users', Level::Edit);
    }

    /**
     * TB 13 Scheibe 4 (M-25): SSO einrichten — nur Betreiber der Site oder Besitzer/Verwaltung ihres Kontos, nicht der
     * Administrator: wer den Anbieter festlegt, bestimmt, wessen Konten sich anmelden.
     */
    public function manageSso(Authenticatable $user, Site $site): bool
    {
        // Betreiber (unveränderliche Rolle) und Besitzer/Verwaltung des Kontos — Access bildet beide auf «Betreiber» ab
        return app(Access::class)->definition($user, $site)?->locked === true;
    }

    /** Bereich der Route, die gerade läuft — oder «settings». */
    public static function currentArea(): string
    {
        $route = request()->route();
        $name = $route?->getName();
        if (! is_string($name)) {
            return 'settings';
        }
        $requirement = app(Areas::class)->requirement($name, 'GET');

        return is_string($requirement['area'] ?? null) ? $requirement['area'] : 'settings';
    }

    private function managesAccount(Authenticatable $user, ?Account $account): bool
    {
        $role = $account?->roleOf($user);

        return $role === AccountRole::Owner || $role === AccountRole::Admin;
    }
}
