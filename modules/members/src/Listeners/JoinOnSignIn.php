<?php

declare(strict_types=1);

namespace Basalto\Modules\Members\Listeners;

use Basalto\Core\FrontendAccounts\Events\CustomerSignedIn;
use Basalto\Modules\Members\Members;

/** Wer sich über die Anmeldung der Mitglieder anmeldet (Zweck «members»), tritt dabei bei — nach der Regel der Site. */
final class JoinOnSignIn
{
    public const PURPOSE = 'members';

    public function __construct(private readonly Members $members) {}

    public function handle(CustomerSignedIn $event): void
    {
        if ($event->purpose === self::PURPOSE && $this->members->enabled($event->site)) {
            $this->members->join($event->customer, $event->site);
        }
    }
}
