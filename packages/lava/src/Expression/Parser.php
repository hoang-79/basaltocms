<?php

declare(strict_types=1);

namespace Basalto\Lava\Expression;

use Basalto\Lava\Diagnostics\LavaSyntaxException;
use Basalto\Lava\Runtime\Registry;

/**
 * Pratt-Parser für Ausdrücke: Literale, Namen, Punkt- und Indexzugriff, Funktionsaufrufe (nur auf Namen),
 * Filter «| name(args)», Arithmetik, Verkettung «~», Vergleiche, and/or/not, «in», «a if c else b», Listen und Maps.
 * Methodenaufrufe und PHP gibt es nicht — alles, was fehlt, liefert eine registrierte Funktion. Namen mit Punkt
 * («example.money(x)», «x | example.money») gelten nur als Funktion, wenn sie in der Registrierung stehen (L-12);
 * sonst ist «a.b(…)» ein Methodenaufruf und damit ein Syntaxfehler.
 */
final class Parser
{
    /** @var list<array{0: string, 1: string}> */
    private array $tokens;

    private int $pos = 0;

    public function __construct(string $source, private readonly string $file, private readonly int $line, private readonly ?Registry $registry = null)
    {
        $this->tokens = Tokenizer::tokenize($source, $file, $line);
    }

    public static function parse(string $source, string $file, int $line, ?Registry $registry = null): Node
    {
        $p = new self($source, $file, $line, $registry);
        $node = $p->expression();
        if (! $p->at('eof')) {
            throw new LavaSyntaxException(sprintf('Unerwartetes «%s» im Ausdruck', $p->tokens[$p->pos][1]), $file, $line, 1);
        }

        return $node;
    }

    /**
     * Eine Argumentliste «a, b, name: c» bis zum Ende.
     *
     * @return array{0: list<Node>, 1: array<string, Node>}
     */
    public static function parseArguments(string $source, string $file, int $line, ?Registry $registry = null): array
    {
        $p = new self($source, $file, $line, $registry);
        $result = $p->arguments('eof');
        if (! $p->at('eof')) {
            throw new LavaSyntaxException(sprintf('Unerwartetes «%s» in den Argumenten', $p->tokens[$p->pos][1]), $file, $line, 1);
        }

        return $result;
    }

    public function expression(): Node
    {
        $node = $this->orExpr();
        if ($this->accept('keyword', 'if')) {
            $condition = $this->orExpr();
            $else = Node::of('literal', ['value' => null], $this->line);
            if ($this->accept('keyword', 'else')) {
                $else = $this->orExpr();
            }

            return Node::of('conditional', ['condition' => $condition, 'then' => $node, 'else' => $else], $this->line);
        }

        return $node;
    }

    private function orExpr(): Node
    {
        $left = $this->andExpr();
        while ($this->accept('keyword', 'or') || $this->accept('op', '||')) {
            $left = Node::of('or', ['left' => $left, 'right' => $this->andExpr()], $this->line);
        }

        return $left;
    }

    private function andExpr(): Node
    {
        $left = $this->notExpr();
        while ($this->accept('keyword', 'and') || $this->accept('op', '&&')) {
            $left = Node::of('and', ['left' => $left, 'right' => $this->notExpr()], $this->line);
        }

        return $left;
    }

    private function notExpr(): Node
    {
        if ($this->accept('keyword', 'not') || $this->accept('op', '!')) {
            return Node::of('not', ['value' => $this->notExpr()], $this->line);
        }

        return $this->comparison();
    }

    private function comparison(): Node
    {
        $left = $this->concat();
        while (true) {
            $op = null;
            foreach (['==', '!=', '<=', '>=', '<', '>'] as $candidate) {
                if ($this->accept('op', $candidate)) {
                    $op = $candidate;
                    break;
                }
            }
            if ($op === null && $this->accept('keyword', 'in')) {
                $op = 'in';
            }
            if ($op === null && $this->at('keyword', 'not') && ($this->tokens[$this->pos + 1] ?? ['eof', ''])[1] === 'in') {
                $this->pos += 2;
                $op = 'not in';
            }
            if ($op === null) {
                return $left;
            }
            $left = Node::of('binary', ['op' => $op, 'left' => $left, 'right' => $this->concat()], $this->line);
        }
    }

    private function concat(): Node
    {
        $left = $this->additive();
        while ($this->accept('op', '~')) {
            $left = Node::of('binary', ['op' => '~', 'left' => $left, 'right' => $this->additive()], $this->line);
        }

        return $left;
    }

    private function additive(): Node
    {
        $left = $this->multiplicative();
        while (true) {
            if ($this->accept('op', '+')) {
                $left = Node::of('binary', ['op' => '+', 'left' => $left, 'right' => $this->multiplicative()], $this->line);
            } elseif ($this->accept('op', '-')) {
                $left = Node::of('binary', ['op' => '-', 'left' => $left, 'right' => $this->multiplicative()], $this->line);
            } else {
                return $left;
            }
        }
    }

    private function multiplicative(): Node
    {
        $left = $this->unary();
        while (true) {
            $op = null;
            foreach (['*', '/', '%'] as $candidate) {
                if ($this->accept('op', $candidate)) {
                    $op = $candidate;
                    break;
                }
            }
            if ($op === null) {
                return $left;
            }
            $left = Node::of('binary', ['op' => $op, 'left' => $left, 'right' => $this->unary()], $this->line);
        }
    }

    private function unary(): Node
    {
        if ($this->accept('op', '-')) {
            return Node::of('negate', ['value' => $this->unary()], $this->line);
        }

        return $this->postfix($this->primary());
    }

    private function postfix(Node $node): Node
    {
        while (true) {
            if ($this->accept('op', '.')) {
                $name = $this->expectName();
                $node = Node::of('attr', ['object' => $node, 'name' => $name], $this->line);
            } elseif ($this->accept('op', '[')) {
                $index = $this->expression();
                $this->expectOp(']');
                $node = Node::of('item', ['object' => $node, 'index' => $index], $this->line);
            } elseif ($this->at('op', '(') && $node->kind === 'name') {
                $this->pos++;
                [$args, $named] = $this->arguments(')');
                $this->expectOp(')');
                $node = Node::of('call', ['name' => $node->data['name'], 'args' => $args, 'named' => $named], $this->line);
            } elseif ($this->at('op', '(') && $node->kind === 'attr') {
                $dotted = $this->dottedName($node);
                if ($dotted === null || $this->registry === null || ! $this->registry->hasFunction($dotted)) {
                    throw new LavaSyntaxException(sprintf('«%s()» ist kein Methodenaufruf — Lava kennt nur registrierte Funktionen und Filter', $dotted ?? '…'), $this->file, $this->line, 1);
                }
                $this->pos++;
                [$args, $named] = $this->arguments(')');
                $this->expectOp(')');
                $node = Node::of('call', ['name' => $dotted, 'args' => $args, 'named' => $named], $this->line);
            } elseif ($this->accept('op', '|')) {
                $name = $this->filterName();
                $args = [];
                $named = [];
                if ($this->accept('op', '(')) {
                    [$args, $named] = $this->arguments(')');
                    $this->expectOp(')');
                }
                $node = Node::of('filter', ['value' => $node, 'name' => $name, 'args' => $args, 'named' => $named], $this->line);
            } else {
                return $node;
            }
        }
    }

    private function primary(): Node
    {
        [$type, $value] = $this->tokens[$this->pos];
        if ($type === 'number') {
            $this->pos++;

            return Node::of('literal', ['value' => str_contains($value, '.') ? (float) $value : (int) $value], $this->line);
        }
        if ($type === 'string') {
            $this->pos++;

            return Node::of('literal', ['value' => $value], $this->line);
        }
        if ($type === 'keyword' && in_array($value, ['true', 'false', 'null'], true)) {
            $this->pos++;

            return Node::of('literal', ['value' => $value === 'true' ? true : ($value === 'false' ? false : null)], $this->line);
        }
        if ($type === 'name') {
            $this->pos++;

            return Node::of('name', ['name' => $value], $this->line);
        }
        if ($this->accept('op', '(')) {
            $node = $this->expression();
            $this->expectOp(')');

            return $node;
        }
        if ($this->accept('op', '[')) {
            $items = [];
            while (! $this->at('op', ']')) {
                $items[] = $this->expression();
                if (! $this->accept('op', ',')) {
                    break;
                }
            }
            $this->expectOp(']');

            return Node::of('list', ['items' => $items], $this->line);
        }
        if ($this->accept('op', '{')) {
            $items = [];
            while (! $this->at('op', '}')) {
                $key = $this->tokens[$this->pos][0] === 'string' ? $this->tokens[$this->pos++][1] : $this->expectName();
                $this->expectOp(':');
                $items[$key] = $this->expression();
                if (! $this->accept('op', ',')) {
                    break;
                }
            }
            $this->expectOp('}');

            return Node::of('map', ['items' => $items], $this->line);
        }

        throw new LavaSyntaxException(sprintf('Ausdruck erwartet, gefunden: «%s»', $value === '' ? 'Ende' : $value), $this->file, $this->line, 1);
    }

    /** @return array{0: list<Node>, 1: array<string, Node>} */
    private function arguments(string $closer): array
    {
        $args = [];
        $named = [];
        while (! $this->at('op', $closer) && ! $this->at('eof')) {
            if ($this->tokens[$this->pos][0] === 'name' && ($this->tokens[$this->pos + 1] ?? ['eof', ''])[1] === ':' && ($this->tokens[$this->pos + 1] ?? ['eof', ''])[0] === 'op') {
                $key = $this->tokens[$this->pos][1];
                $this->pos += 2;
                $named[$key] = $this->expression();
            } else {
                $args[] = $this->expression();
            }
            // In Tags («{% image bild size: "hero" %}») trennen auch Leerzeichen die Argumente, in Aufrufen nur Kommas.
            if (! $this->accept('op', ',') && $closer !== 'eof') {
                break;
            }
        }

        return [$args, $named];
    }

    /** Der Name hinter einer Punktkette aus blossen Namen («example.money») — null, sobald ein anderes Glied vorkommt. */
    private function dottedName(Node $node): ?string
    {
        $parts = [];
        while ($node->kind === 'attr') {
            array_unshift($parts, (string) $node->data['name']);
            $node = $node->data['object'];
        }
        if ($node->kind !== 'name') {
            return null;
        }
        array_unshift($parts, (string) $node->data['name']);

        return implode('.', $parts);
    }

    /** Filtername, bei Bedarf mit Modul-Präfix («example.money») — nur, wenn die Registrierung den Namen mit Punkt kennt. */
    private function filterName(): string
    {
        $name = $this->expectName();
        while ($this->registry !== null && $this->at('op', '.')) {
            $next = $this->tokens[$this->pos + 1] ?? ['eof', ''];
            if (($next[0] !== 'name' && $next[0] !== 'keyword') || ! $this->registry->hasFunction($name.'.'.$next[1])) {
                break;
            }
            $this->pos += 2;
            $name .= '.'.$next[1];
        }

        return $name;
    }

    private function at(string $type, ?string $value = null): bool
    {
        [$t, $v] = $this->tokens[$this->pos];

        return $t === $type && ($value === null || $v === $value);
    }

    private function accept(string $type, string $value): bool
    {
        if ($this->at($type, $value)) {
            $this->pos++;

            return true;
        }

        return false;
    }

    private function expectOp(string $op): void
    {
        if (! $this->accept('op', $op)) {
            throw new LavaSyntaxException(sprintf('«%s» erwartet, gefunden: «%s»', $op, $this->tokens[$this->pos][1] ?: 'Ende'), $this->file, $this->line, 1);
        }
    }

    private function expectName(): string
    {
        [$type, $value] = $this->tokens[$this->pos];
        if ($type !== 'name' && $type !== 'keyword') {
            throw new LavaSyntaxException(sprintf('Name erwartet, gefunden: «%s»', $value ?: 'Ende'), $this->file, $this->line, 1);
        }
        $this->pos++;

        return $value;
    }
}
