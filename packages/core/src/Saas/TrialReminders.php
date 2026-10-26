<?php

declare(strict_types=1);

namespace Basalto\Core\Saas;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\AccountNotice;
use Basalto\Core\Saas\Notifications\TrialEndedNotification;
use Basalto\Core\Saas\Notifications\TrialEndingNotification;

/**
 * Erinnerungen der Testphase (TB 12 Scheibe 1): {@see Trial} — eine Mail `basalto.saas.trial_reminder_days` Tage vor dem
 * Ende, eine am Ende; an Besitzer und Abrechnung des Kontos. Idempotent über {@see AccountNotice} (Bezug = Enddatum): Der
 * Scheduler darf stündlich laufen, jede Mail geht je Testphase genau einmal. Wer die Erinnerung verpasst hat (Ende schon
 * erreicht), bekommt nur noch die zweite.
 */
final class TrialReminders
{
    public const ENDING = 'trial.ending';

    public const ENDED = 'trial.ended';

    /** @return array{ending: int, ended: int} Anzahl Konten, an die je Art eine Mail hinausging */
    public function run(): array
    {
        $sent = ['ending' => 0, 'ended' => 0];
        $days = max(1, (int) config('basalto.saas.trial_reminder_days', 3));
        $accounts = Account::query()->where('plan', Account::PLAN_TRIAL)->whereNull('cancelled_at')->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now()->addDays($days))->orderBy('id');
        foreach ($accounts->lazyById(100) as $account) {
            $end = $account->trial_ends_at;
            if ($end === null) {
                continue;
            }
            $reference = $end->toDateString();
            if ($end->isPast()) {
                $sent['ended'] += AccountNotice::once($account, self::ENDED, $reference, fn () => $this->notify($account, new TrialEndedNotification(
                    $account->name, (string) Trial::bannerFrom($account)?->format('d.m.Y'), route('basalto.saas.account.plan')))) ? 1 : 0;

                continue;
            }
            $sent['ending'] += AccountNotice::once($account, self::ENDING, $reference, fn () => $this->notify($account, new TrialEndingNotification(
                $account->name, $end->format('d.m.Y'), Trial::daysLeft($account), route('basalto.saas.account.plan')))) ? 1 : 0;
        }

        return $sent;
    }

    private function notify(Account $account, TrialEndingNotification|TrialEndedNotification $notification): void
    {
        $recipients = $account->users()->wherePivotIn('role', [AccountRole::Owner->value, AccountRole::Billing->value])->get();
        foreach ($recipients as $user) {
            if ($user->getAttribute('deactivated_at') === null && method_exists($user, 'notify')) {
                $user->notify($notification);
            }
        }
    }
}
