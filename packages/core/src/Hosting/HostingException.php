<?php

declare(strict_types=1);

namespace Basalto\Core\Hosting;

use RuntimeException;

/**
 * CR-017: Einrichten beim Hoster gescheitert. `waiting` heisst: Eine Voraussetzung fehlt noch (DNS, Unterdomain noch
 * nicht aktiv) — kein Fehler von Let's Encrypt, ein neuer Versuch ist bald sinnvoll.
 */
final class HostingException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $waiting = false)
    {
        parent::__construct($message);
    }
}
