<?php

declare(strict_types=1);

namespace Basalto\Lava\Runtime;

use RuntimeException;
use Throwable;

/** Fehler beim Rendern: verbotene Funktion, überschrittene Grenze, fehlendes Tag — mit Zeile. */
final class LavaRuntimeException extends RuntimeException
{
    public function __construct(string $message, public readonly int $lavaLine = 0, ?Throwable $previous = null)
    {
        parent::__construct($lavaLine ? sprintf('%s (Zeile %d)', $message, $lavaLine) : $message, 0, $previous);
    }
}
