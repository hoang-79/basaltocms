<?php

declare(strict_types=1);

namespace Basalto\Core\Sso;

use RuntimeException;

/**
 * TB 13 Scheibe 4 (M-25): Eine Anmeldung über SSO wird abgewiesen. Der Grund steht nur im Protokoll; nach aussen geht
 * eine freundliche Meldung ohne Einzelheiten ({@see self::message()}).
 */
final class SsoRefusal extends RuntimeException
{
    public const TENANT = 'tenant';

    public const GUEST = 'guest';

    public const UNVERIFIED = 'unverified';

    public const DOMAIN = 'domain';

    public const INCOMPLETE = 'incomplete';

    public const UNKNOWN = 'unknown';

    public const NO_ROLE = 'no_role';

    public const DEACTIVATED = 'deactivated';

    public function __construct(public readonly string $reason, public readonly ?string $email = null)
    {
        parent::__construct('SSO abgewiesen: '.$reason);
    }

    /** Die Meldung für die Anmeldeseite — ausser bei deaktivierten Konten für alle Gründe dieselbe. */
    public function message(): string
    {
        return $this->reason === self::DEACTIVATED
            ? __('Dieses Konto ist deaktiviert. Wenden Sie sich an die Verwaltung der Site.')
            : __('Mit diesem Konto ist keine Anmeldung bei dieser Site möglich. Wenden Sie sich an die Verwaltung der Site oder melden Sie sich per Mail-Code an.');
    }
}
