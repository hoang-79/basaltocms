<?php

declare(strict_types=1);

namespace Basalto\Lava\Structure;

/** Ein Wert in einer Eigenschaft oder Modifikatorliste: Zeichenkette, Zahl, Wahrheit, Farbe, Pfad, Verweis oder Pfeil. */
final class Value
{
    public const STRING = 'string';

    public const NUMBER = 'number';

    public const BOOL = 'bool';

    public const COLOR = 'color';

    public const PATH = 'path';

    public const REF = 'ref';

    public const ARROW = 'arrow';

    public function __construct(public readonly string $type, public readonly string|int|float|bool $value, public readonly int $line, public readonly int $column) {}

    public static function fromToken(Token $token): self
    {
        return match ($token->type) {
            Token::STRING => new self(self::STRING, $token->value, $token->line, $token->column),
            Token::NUMBER => new self(self::NUMBER, str_contains($token->value, '.') ? (float) $token->value : (int) $token->value, $token->line, $token->column),
            Token::COLOR => new self(self::COLOR, $token->value, $token->line, $token->column),
            Token::PATH => new self(self::PATH, $token->value, $token->line, $token->column),
            Token::ARROW => new self(self::ARROW, '->', $token->line, $token->column),
            default => in_array($token->value, ['true', 'false'], true)
                ? new self(self::BOOL, $token->value === 'true', $token->line, $token->column)
                : new self(self::REF, $token->value, $token->line, $token->column),
        };
    }

    public function text(): string
    {
        return is_bool($this->value) ? ($this->value ? 'true' : 'false') : (string) $this->value;
    }
}
