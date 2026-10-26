<?php

declare(strict_types=1);

namespace Basalto\Lava\Structure;

/**
 * Ein Token der Struktur-Sprache (site, layout, block …) mit Position für Fehlermeldungen.
 */
final class Token
{
    public const IDENT = 'ident';

    public const STRING = 'string';

    public const NUMBER = 'number';

    public const COLOR = 'color';

    public const PATH = 'path';

    public const ARROW = 'arrow';

    public const COLON = 'colon';

    public const COMMA = 'comma';

    public const LBRACE = 'lbrace';

    public const RBRACE = 'rbrace';

    public const FENCE = 'fence';

    public const NEWLINE = 'newline';

    public const EOF = 'eof';

    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly int $line,
        public readonly int $column,
    ) {}

    public function is(string $type, ?string $value = null): bool
    {
        return $this->type === $type && ($value === null || $this->value === $value);
    }
}
