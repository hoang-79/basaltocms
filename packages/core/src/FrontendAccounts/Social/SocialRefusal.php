<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Social;

use RuntimeException;

/**
 * TB 53: Eine Anmeldung über einen Anbieter wird abgewiesen — mit einem Grund fürs Protokoll und einer Meldung in der
 * Sprache der Seite, die nichts über fremde Konten verrät.
 */
final class SocialRefusal extends RuntimeException
{
    public const UNVERIFIED = 'unverified';

    public const INCOMPLETE = 'incomplete';

    public const GUEST = 'guest';

    public const CLOSED = 'closed';

    public const TAKEN = 'taken';

    public const SPAM = 'spam';

    public function __construct(public readonly string $reason, public readonly SocialProvider $provider)
    {
        parent::__construct($reason);
    }

    public function text(string $lang): string
    {
        $name = ['provider' => $this->provider->label()];

        return (string) match ($this->reason) {
            self::UNVERIFIED => __(':provider hat Ihre E-Mail-Adresse nicht bestätigt. Bestätigen Sie sie dort oder melden Sie sich mit einem Code per Mail an.', $name, $lang),
            self::GUEST => __('Gastkonten in einem fremden Verzeichnis gehen nicht. Nehmen Sie Ihr eigenes Konto oder einen Code per Mail.', $name, $lang),
            self::CLOSED => __('Für diese Adresse gibt es hier kein Konto, und neue Konten gibt es auf dieser Site nur auf Einladung.', $name, $lang),
            self::TAKEN => __('Dieses Konto bei :provider ist schon mit einem anderen Kundenkonto verknüpft.', $name, $lang),
            self::SPAM => __('Diese Adresse oder Verbindung steht auf einer Liste bekannter Spam-Absender (Stop Forum Spam). Bitte wenden Sie sich an das Team der Site.', $name, $lang),
            default => __(':provider hat keine E-Mail-Adresse mitgeteilt. Melden Sie sich mit einem Code per Mail an.', $name, $lang),
        };
    }
}
