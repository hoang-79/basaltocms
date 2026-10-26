<?php

declare(strict_types=1);

namespace Basalto\Lava\Diagnostics;

use RuntimeException;

/** Fehler beim Lesen einer Lava-Datei — immer mit Datei, Zeile und Spalte. */
final class LavaSyntaxException extends RuntimeException
{
    public function __construct(string $message, public readonly string $lavaFile, public readonly int $lavaLine, public readonly int $lavaColumn)
    {
        parent::__construct(sprintf('%s (%s, Zeile %d, Spalte %d)', $message, $lavaFile, $lavaLine, $lavaColumn));
    }
}
