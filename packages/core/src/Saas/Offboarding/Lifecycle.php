<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Offboarding;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Basalto\Core\Saas\Saas;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Der Lebenslauf eines Kundenkontos nach der Kündigung (TB 12 Scheibe 4, M-21, ADR-008 «nach Kündigung 30 Tage gesperrt,
 * dann gelöscht»): aktiv → gekündigt (läuft bis `ends_at`) → gesperrt (bis `delete_after`) → gelöscht; dazu der Umzug
 * zwischen Instanzen (umziehend → umgezogen).
 *
 * Der Stand wird aus den Daten **berechnet**, nicht nur aus `status` gelesen: Läuft die Laufzeit ab, ist die Site sofort
 * gesperrt — auch wenn der Scheduler noch nicht gelaufen ist. `status` folgt nach (Übersichten, Abfragen).
 */
final class Lifecycle
{
    public const ACTIVE = 'active';

    public const CANCELLED = 'cancelled';

    public const LOCKED = 'locked';

    public const ERASED = 'erased';

    public const MOVING = 'moving';

    public const MOVED = 'moved';

    public static function state(?Account $account): string
    {
        if ($account === null) {
            return self::ACTIVE;
        }
        if ($account->erased_at !== null) {
            return self::ERASED;
        }
        if (in_array($account->status, [self::MOVING, self::MOVED], true)) {
            return $account->status;
        }
        if ($account->cancelled_at !== null && $account->ends_at !== null) {
            return $account->ends_at->isPast() ? self::LOCKED : self::CANCELLED;
        }

        return self::ACTIVE;
    }

    /** Ein Kundenkonto der SaaS in diesem Stand — ausserhalb der SaaS und für das Konto der Installation nie. */
    public static function is(?Account $account, string $state): bool
    {
        return Saas::enabled() && Saas::isCustomer($account) && self::state($account) === $state;
    }

    /** Tage der Sperre nach dem Ende der Laufzeit (M-04: 30), danach Löschung. */
    public static function lockDays(): int
    {
        return max(1, (int) config('basalto.saas.lock_days', 30));
    }

    public static function timezone(Account $account): string
    {
        $timezone = $account->sites()->orderBy('id')->value('timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Europe/Zurich';
    }

    public static function date(?CarbonImmutable $moment, string $timezone): ?string
    {
        return $moment?->timezone($timezone)->format('d.m.Y');
    }

    /**
     * Die Daten der Kündigung für Konto-Bereich und Hinweisband.
     *
     * `ends` ist der letzte Tag der Laufzeit (einschliesslich), `locked` der erste Tag der Sperre, `deletes` der Tag der
     * Löschung — je in der Zeitzone der Site.
     *
     * @return array{state: string, cancelled: string|null, ends: string|null, locked: string|null, deletes: string|null, lock_days: int}
     */
    public static function dates(Account $account): array
    {
        $zone = self::timezone($account);

        return ['state' => self::state($account), 'cancelled' => self::date($account->cancelled_at, $zone), 'ends' => self::lastDay($account->ends_at, $zone),
            'locked' => self::date($account->ends_at, $zone), 'deletes' => self::date($account->delete_after, $zone), 'lock_days' => self::lockDays()];
    }

    /** Der letzte Tag vor einem Zeitpunkt (Ende 00:00 Uhr → Vortag), aber nie vor heute. */
    public static function lastDay(?CarbonImmutable $end, string $timezone): ?string
    {
        if ($end === null) {
            return null;
        }
        $local = $end->timezone($timezone);
        $last = $local->subSecond();

        return ($last->isBefore(CarbonImmutable::now($timezone)->startOfDay()) ? $local : $last)->format('d.m.Y');
    }

    /**
     * Übersetzung als Zeichenkette — `__()` liefert eine Liste, wenn der Schlüssel eine Gruppe trifft; hier nie gewollt.
     *
     * @param  array<string, string|int>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $value = __($key, $replace);

        return is_string($value) ? $value : $key;
    }

    /**
     * Das Hinweisband im Backend (Mockup 24 «Gekündigt · Laufzeit bis … · danach 30 Tage gesperrt · Löschung am …») —
     * für Konto-Rollen mit Link auf Konto › Export und Kündigung, für alle anderen nur der Satz.
     *
     * @return array{text: string, href: string|null, label: string|null, tone: string}|null
     */
    public static function backendNotice(?Authenticatable $user, ?Site $site): ?array
    {
        if (! Saas::enabled() || $user === null || $site === null) {
            return null;
        }
        $account = $site->account;
        if ($account === null || ! Saas::isCustomer($account)) {
            return null;
        }
        $state = self::state($account);
        $dates = self::dates($account);
        $role = $account->roleOf($user);
        $href = $role !== null ? route('basalto.saas.account.exit') : null;

        $owner = $role === AccountRole::Owner;
        if ($state === self::CANCELLED) {
            return ['tone' => 'warn', 'href' => $owner ? $href : null, 'label' => $owner ? self::text('Kündigung zurücknehmen') : null,
                'text' => self::text('Gekündigt · Laufzeit bis :ends · danach :days Tage gesperrt · Löschung am :deletes', ['ends' => $dates['ends'] ?? '', 'days' => $dates['lock_days'], 'deletes' => $dates['deletes'] ?? ''])];
        }
        if ($state === self::LOCKED) {
            return ['tone' => 'danger', 'href' => $owner ? $href : null, 'label' => $owner ? self::text('Export erstellen') : null,
                'text' => self::text('Gesperrt seit :ends — die Sites sind offline, das Backend ist nur lesbar · Löschung am :deletes', ['ends' => $dates['locked'] ?? '', 'deletes' => $dates['deletes'] ?? ''])];
        }
        if ($state === self::MOVING) {
            return ['tone' => 'warn', 'href' => null, 'label' => null,
                'text' => self::text('Umzug auf die Instanz «:instance» läuft — bis er fertig ist, lassen sich keine Änderungen speichern.', ['instance' => (string) $account->instance_target])];
        }

        return null;
    }
}
