<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Social;

/** TB 53: Was nach der Prüfung vom Konto beim Anbieter bleibt — feste Kennung, bestätigte E-Mail, Name. */
final readonly class VerifiedAccount
{
    public function __construct(public SocialProvider $provider, public string $subject, public string $email, public ?string $name) {}
}
