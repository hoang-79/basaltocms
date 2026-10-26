<?php

declare(strict_types=1);

namespace Basalto\Modules\Ai;

use RuntimeException;

/** Eine freundliche Absage des KI-Moduls (kein Schlüssel, Limit, Anbieterfehler) — die Meldung geht so an die Oberfläche. */
final class AssistantException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
