<?php

declare(strict_types=1);

namespace Basalto\Core\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/** Domänenereignis (ADR-011): Anmeldung im Backend (Code, Link oder Passkey). */
final class UserLoggedIn
{
    public function __construct(public readonly Authenticatable $user, public readonly string $method) {}
}
