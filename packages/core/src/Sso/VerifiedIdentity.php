<?php

declare(strict_types=1);

namespace Basalto\Core\Sso;

/**
 * TB 13 Scheibe 4 (M-25): Was vom Anbieter nach der Prüfung übrig bleibt — Aussteller und Kennung für die Verknüpfung,
 * die bestätigten E-Mail-Adressen in der Reihenfolge, in der sie einem Benutzer zugeordnet werden, und der Name.
 */
final readonly class VerifiedIdentity
{
    /** @param  non-empty-list<string>  $emails */
    public function __construct(
        public SsoProvider $provider,
        public string $issuer,
        public string $subject,
        public array $emails,
        public string $name,
    ) {}

    public function email(): string
    {
        return $this->emails[0];
    }
}
