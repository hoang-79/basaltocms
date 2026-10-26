<?php

declare(strict_types=1);

namespace Basalto\Lava\Markup;

use Basalto\Lava\Expression\Node as Expr;

/** Knoten des Markup-Baums: Text, Ausgabe, Logik (each, if, set, macro) und Tags. */
final class Node
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<Node>  $children
     */
    public function __construct(public readonly string $kind, public readonly array $data, public array $children, public readonly int $line) {}

    public static function text(string $text, int $line): self
    {
        return new self('text', ['text' => $text], [], $line);
    }

    public static function output(Expr $expr, bool $raw, int $line): self
    {
        return new self('output', ['expr' => $expr, 'raw' => $raw], [], $line);
    }
}
