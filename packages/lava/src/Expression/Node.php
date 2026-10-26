<?php

declare(strict_types=1);

namespace Basalto\Lava\Expression;

/** Knoten eines Ausdrucks — ein einfacher Baum, den der Interpreter auswertet. */
final class Node
{
    /** @param array<string, mixed> $data */
    public function __construct(public readonly string $kind, public readonly array $data, public readonly int $line) {}

    /** @param array<string, mixed> $data */
    public static function of(string $kind, array $data, int $line): self
    {
        return new self($kind, $data, $line);
    }
}
