<?php

declare(strict_types=1);

namespace Basalto\Modules\Members;

use Basalto\Core\FrontendAccounts\RegistrationPolicy;
use Basalto\Core\Models\Site;

/** «Nur auf Einladung» schliesst die Registrierung: ohne Einladung (also ohne Konto) geht kein Code hinaus. */
final class MembersRegistration implements RegistrationPolicy
{
    public function __construct(private readonly Members $members) {}

    public function opensRegistration(Site $site): bool
    {
        return $this->members->registration($site) !== Registration::Invite;
    }
}
