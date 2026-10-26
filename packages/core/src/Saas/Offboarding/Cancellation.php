<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Offboarding;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Activity\Recorder;
use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\AccountNotice;
use Basalto\Core\Notifications\Notify;
use Basalto\Core\Saas\Offboarding\Notifications\AccountCancelledNotification;
use Basalto\Core\Saas\Trial;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kündigen und Zurücknehmen (TB 12 Scheibe 4, M-21, Mockup 24): Der Besitzer kündigt, das Abo endet mit der bezahlten
 * Periode ({@see CancellationBilling}), in der Testphase sofort. Danach {@see Lifecycle::lockDays()} Tage gesperrt, dann
 * löscht der Löschlauf. Zurücknehmen geht bis zum Ende der Laufzeit.
 */
final class Cancellation
{
    public const NOTICE_CANCELLED = 'exit.cancelled';

    public function __construct(private readonly CancellationBilling $subscriptions, private readonly Recorder $recorder) {}

    /** Ende der Laufzeit, wenn jetzt gekündigt würde — für die Rückfrage. */
    public function previewEnd(Account $account): CarbonImmutable
    {
        if (Trial::inTrial($account)) {
            return CarbonImmutable::now();
        }

        return $this->subscriptions->periodEnd($account) ?? CarbonImmutable::now();
    }

    /** Mit `$notify = false` mailt der Aufrufer selbst (CR-011 TB 59: die Bestätigung von «Verträge hier kündigen»). */
    public function cancel(Account $account, Authenticatable $by, bool $notify = true): Account
    {
        if (Lifecycle::state($account) !== Lifecycle::ACTIVE) {
            throw ValidationException::withMessages(['confirm' => __('Dieses Konto ist nicht aktiv — es lässt sich nicht kündigen.')]);
        }
        $now = CarbonImmutable::now();
        $end = Trial::inTrial($account) ? $now : ($this->subscriptions->cancelAtPeriodEnd($account) ?? $now);
        // In der Zeitzone der Anwendung speichern — Eloquent schreibt die Uhrzeit ohne Umrechnung
        $end = $end->lessThan($now) ? $now : $end->setTimezone((string) config('app.timezone', 'UTC'));
        DB::transaction(static function () use ($account, $now, $end): void {
            $account->forceFill(['status' => Lifecycle::CANCELLED, 'cancelled_at' => $now, 'ends_at' => $end, 'delete_after' => $end->addDays(Lifecycle::lockDays())])->save();
        });
        $this->recorder->record('account.cancelled', $account, null, ['ends_at' => $end->toIso8601String(), 'delete_after' => $account->delete_after?->toIso8601String()], null, $by);
        $dates = Lifecycle::dates($account);
        // TB 27: das Team des Herstellers sieht Kündigungen in der Glocke des Extranets
        if (Extranet::enabled()) {
            Notify::make('extranet.cancellations')->title('Das Konto «:account» kündigt', ['account' => $account->name])
                ->body('Laufzeit bis :ends, Löschung am :deletes.', ['ends' => (string) $dates['ends'], 'deletes' => (string) $dates['deletes']])
                ->url(route('extranet.customers.index'), 'Kunden öffnen')->toExtranet()->send();
        }
        if (! $notify) {
            return $account;
        }
        AccountNotice::once($account, self::NOTICE_CANCELLED, $now->format('Y-m-d H:i:s'), static fn () => self::notify($account,
            new AccountCancelledNotification($account->name, (string) $dates['ends'], (string) $dates['deletes'], Lifecycle::lockDays(), route('basalto.saas.account.exit')),
            [AccountRole::Owner, AccountRole::Billing]));

        return $account;
    }

    public function revoke(Account $account, Authenticatable $by): Account
    {
        if (Lifecycle::state($account) !== Lifecycle::CANCELLED) {
            throw ValidationException::withMessages(['revoke' => __('Die Laufzeit ist zu Ende — die Kündigung lässt sich nicht mehr zurücknehmen.')]);
        }
        $this->subscriptions->resume($account);
        $before = ['ends_at' => $account->ends_at?->toIso8601String()];
        $account->forceFill(['status' => Lifecycle::ACTIVE, 'cancelled_at' => null, 'ends_at' => null, 'delete_after' => null])->save();
        $this->recorder->record('account.cancellation_revoked', $account, $before, null, null, $by);

        return $account;
    }

    /**
     * Mail an die Konto-Rollen — je Person in ihrer Sprache (User::preferredLocale), deaktivierte nicht.
     *
     * @param  list<AccountRole>  $roles
     */
    public static function notify(Account $account, Notification $notification, array $roles = [AccountRole::Owner]): void
    {
        $recipients = $account->users()->wherePivotIn('role', array_map(static fn (AccountRole $role): string => $role->value, $roles))->get();
        foreach ($recipients as $user) {
            if ($user->getAttribute('deactivated_at') === null && method_exists($user, 'notify')) {
                $user->notify($notification);
            }
        }
    }
}
