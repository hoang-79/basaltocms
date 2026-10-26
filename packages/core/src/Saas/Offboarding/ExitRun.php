<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Offboarding;

use Basalto\Core\Models\Account;
use Basalto\Core\Models\AccountNotice;
use Basalto\Core\Saas\Move\InstanceMover;
use Basalto\Core\Saas\Offboarding\Notifications\AccountDeletionNotification;
use Basalto\Core\Saas\Offboarding\Notifications\AccountLockedNotification;
use Basalto\Core\Saas\Saas;
use Carbon\CarbonImmutable;

/**
 * Der stündliche Lauf des Austritts (`basalto:saas-exit`, TB 12 Scheibe 4): Laufzeit vorbei → Stand «gesperrt» und eine
 * Mail; vor der Löschung Mails sieben Tage und einen Tag vorher — je genau einmal über {@see AccountNotice} (Bezug =
 * Löschdatum). Wer die Erinnerung nach sieben Tagen verpasst hat, bekommt nur noch die nach einem Tag. Dazu Umzüge,
 * deren Zielinstanz Scheibe 2 gesetzt hat (`accounts.instance_target`), anstossen. Gelöscht wird im Löschlauf
 * (`basalto:cleanup`, {@see AccountEraser}).
 */
final class ExitRun
{
    public const NOTICE_LOCKED = 'exit.locked';

    public const NOTICE_DELETION = 'exit.deletion';

    /** Erinnerungen so viele Tage vor der Löschung (absteigend). */
    public const REMINDER_DAYS = [7, 1];

    public function __construct(private readonly InstanceMover $mover) {}

    /** @return array{locked: int, reminders: int, moves: int} */
    public function run(): array
    {
        $counts = ['locked' => 0, 'reminders' => 0, 'moves' => 0];
        $accounts = Account::query()->whereIn('status', [Lifecycle::CANCELLED, Lifecycle::LOCKED])->whereNull('erased_at')->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())->orderBy('id');
        foreach ($accounts->lazyById(100) as $account) {
            if (! Saas::isCustomer($account) || Lifecycle::state($account) !== Lifecycle::LOCKED || $account->delete_after === null) {
                continue;
            }
            if ($account->status !== Lifecycle::LOCKED) {
                $account->forceFill(['status' => Lifecycle::LOCKED])->save();
            }
            $deletes = (string) Lifecycle::date($account->delete_after, Lifecycle::timezone($account));
            $url = route('basalto.saas.account.exit');
            $reference = $account->delete_after->toDateString();
            $counts['locked'] += AccountNotice::once($account, self::NOTICE_LOCKED, $reference,
                static fn () => Cancellation::notify($account, new AccountLockedNotification($account->name, $deletes, $url))) ? 1 : 0;
            foreach (self::due($account->delete_after) as $days) {
                $counts['reminders'] += AccountNotice::once($account, self::NOTICE_DELETION.'.'.$days, $reference,
                    static fn () => Cancellation::notify($account, new AccountDeletionNotification($account->name, $deletes, $days, $url))) ? 1 : 0;
            }
        }
        $counts['moves'] = $this->mover->dispatchPending();

        return $counts;
    }

    /**
     * Die fällige Erinnerung: die mit der kleinsten Frist, die erreicht ist — höchstens eine je Lauf.
     *
     * @return list<int>
     */
    public static function due(CarbonImmutable $deleteAfter): array
    {
        $due = [];
        foreach (self::REMINDER_DAYS as $days) {
            if ($deleteAfter->subDays($days)->lessThanOrEqualTo(CarbonImmutable::now())) {
                $due = [$days];
            }
        }

        return $due;
    }
}
