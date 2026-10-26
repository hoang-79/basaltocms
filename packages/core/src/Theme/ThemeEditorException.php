<?php

declare(strict_types=1);

namespace Basalto\Core\Theme;

use DomainException;

/** Ein Schritt im Theme-Editor geht nicht — mit den Befunden des Compilers, falls es an der Quelle liegt. */
final class ThemeEditorException extends DomainException
{
    /** @param  list<array{file: string, line: int, column: int, severity: string, message: string}>  $diagnostics */
    public function __construct(string $message, public readonly array $diagnostics = [], public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
