<?php

declare(strict_types=1);

namespace Basalto\Lava\Markup;

use Basalto\Lava\Diagnostics\LavaSyntaxException;
use Basalto\Lava\Expression\Parser as Expression;
use Basalto\Lava\Runtime\Registry;

/**
 * Zerlegt Markup in Text, «{{ ausdruck }}», «{{ raw ausdruck }}», «{# Kommentar #}» und «{% tag … %}».
 * Blocktags: each … else … end · if … elseif … else … end · macro … end. Alles andere sind Einzeltags,
 * die der Interpreter über die Registrierung auflöst (region, nav, blocks, image, link, t, include, set, call …).
 */
final class Parser
{
    private const PATTERN = '/\{\{(.*?)\}\}|\{%(.*?)%\}|\{#.*?#\}/s';

    /** Höchstens so viele Bäume bleiben im Speicher eines Prozesses. */
    public const MAX_TREES = 500;

    /** @var array<string, list<Node>> Geparste Bäume je Markup-Hash — ein Markup wird je Anfrage nur einmal gelesen (ADR-013) */
    private static array $trees = [];

    /** @var list<array{kind: string, text: string, line: int}> */
    private array $pieces = [];

    private int $pos = 0;

    public function __construct(string $source, private readonly string $file, private readonly ?Registry $registry = null)
    {
        $offset = 0;
        $line = 1;
        preg_match_all(self::PATTERN, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($matches as $m) {
            [$whole, $at] = $m[0];
            if ($at > $offset) {
                $text = substr($source, $offset, $at - $offset);
                $this->pieces[] = ['kind' => 'text', 'text' => $text, 'line' => $line];
                $line += substr_count($text, "\n");
            }
            if (isset($m[1]) && $m[1][1] !== -1) {
                $this->pieces[] = ['kind' => 'output', 'text' => trim($m[1][0]), 'line' => $line];
            } elseif (isset($m[2]) && $m[2][1] !== -1) {
                $this->pieces[] = ['kind' => 'tag', 'text' => trim($m[2][0]), 'line' => $line];
            }
            $line += substr_count($whole, "\n");
            $offset = $at + strlen($whole);
        }
        if ($offset < strlen($source)) {
            $this->pieces[] = ['kind' => 'text', 'text' => substr($source, $offset), 'line' => $line];
        }
    }

    /** @return list<Node> */
    public static function parse(string $source, string $file = 'markup', ?Registry $registry = null): array
    {
        $p = new self($source, $file, $registry);
        $nodes = $p->body([]);
        if ($p->pos < count($p->pieces)) {
            $piece = $p->pieces[$p->pos];
            throw new LavaSyntaxException(sprintf('Unerwartetes «{%% %s %%}» — kein offener Block', $piece['text']), $file, $piece['line'], 1);
        }

        return $nodes;
    }

    /**
     * Wie parse(), aber der Baum bleibt je Anfrage im Speicher: Schlüssel aus Markup, Datei und den Punkt-Namen der
     * Registrierung (sie entscheiden, ob «a.b(x)» ein Aufruf ist). Knoten sind unveränderlich, der Baum darf geteilt werden.
     *
     * @return list<Node>
     */
    public static function parseCached(string $source, string $file = 'markup', ?Registry $registry = null): array
    {
        $key = md5($source.'|'.$file.'|'.($registry === null ? '' : implode(',', $registry->dottedFunctionNames())));
        if (count(self::$trees) >= self::MAX_TREES && ! isset(self::$trees[$key])) {
            // Lang laufende Prozesse (Octane, Queue): der Speicher wächst nicht über die Grenze hinaus.
            self::$trees = [];
        }

        return self::$trees[$key] ??= self::parse($source, $file, $registry);
    }

    public static function forgetTrees(): void
    {
        self::$trees = [];
    }

    public static function cachedTrees(): int
    {
        return count(self::$trees);
    }

    /**
     * Liest Knoten bis zu einem der Stoppwörter (else, elseif, end).
     *
     * @param  list<string>  $stops
     * @return list<Node>
     */
    private function body(array $stops): array
    {
        $nodes = [];
        while ($this->pos < count($this->pieces)) {
            $piece = $this->pieces[$this->pos];
            if ($piece['kind'] === 'text') {
                $this->pos++;
                $nodes[] = Node::text($piece['text'], $piece['line']);

                continue;
            }
            if ($piece['kind'] === 'output') {
                $this->pos++;
                $raw = str_starts_with($piece['text'], 'raw ');
                $expr = Expression::parse($raw ? substr($piece['text'], 4) : $piece['text'], $this->file, $piece['line'], $this->registry);
                $nodes[] = Node::output($expr, $raw, $piece['line']);

                continue;
            }
            [$word, $rest] = array_pad(preg_split('/\s+/', $piece['text'], 2) ?: ['', ''], 2, '');
            if (in_array($word, $stops, true)) {
                return $nodes;
            }
            $this->pos++;
            $nodes[] = match ($word) {
                'each' => $this->each($rest, $piece['line']),
                'if' => $this->if($rest, $piece['line']),
                'macro' => $this->macro($rest, $piece['line']),
                'set' => $this->set($rest, $piece['line']),
                'end', 'else', 'elseif' => throw new LavaSyntaxException(sprintf('«%s» ohne offenen Block', $word), $this->file, $piece['line'], 1),
                default => $this->tag($word, $rest, $piece['line']),
            };
        }

        return $nodes;
    }

    private function each(string $rest, int $line): Node
    {
        if (! preg_match('/^(.*?)\s+as\s+([A-Za-z_]\w*)(?:\s*,\s*([A-Za-z_]\w*))?\s*$/s', $rest, $m)) {
            throw new LavaSyntaxException('«each» erwartet «each liste as name» oder «each liste as schluessel, wert»', $this->file, $line, 1);
        }
        $iterable = Expression::parse($m[1], $this->file, $line, $this->registry);
        $body = $this->body(['else', 'end']);
        $else = [];
        if ($this->accept('else')) {
            $else = $this->body(['end']);
        }
        $this->expectEnd('each', $line);

        return new Node('each', ['iterable' => $iterable, 'key' => isset($m[3]) ? $m[2] : null, 'value' => $m[3] ?? $m[2], 'else' => $else], $body, $line);
    }

    private function if(string $rest, int $line): Node
    {
        $branches = [['condition' => Expression::parse($rest, $this->file, $line, $this->registry), 'body' => $this->body(['elseif', 'else', 'end'])]];
        while (true) {
            $piece = $this->pieces[$this->pos] ?? null;
            if ($piece === null) {
                throw new LavaSyntaxException('«if» ohne «end»', $this->file, $line, 1);
            }
            [$word, $r] = array_pad(preg_split('/\s+/', $piece['text'], 2) ?: ['', ''], 2, '');
            if ($word === 'elseif') {
                $this->pos++;
                $branches[] = ['condition' => Expression::parse($r, $this->file, $piece['line'], $this->registry), 'body' => $this->body(['elseif', 'else', 'end'])];
            } elseif ($word === 'else') {
                $this->pos++;
                $branches[] = ['condition' => null, 'body' => $this->body(['end'])];
            } else {
                break;
            }
        }
        $this->expectEnd('if', $line);

        return new Node('if', ['branches' => $branches], [], $line);
    }

    private function macro(string $rest, int $line): Node
    {
        if (! preg_match('/^([A-Za-z_]\w*)\s*\(([^)]*)\)\s*$/', $rest, $m)) {
            throw new LavaSyntaxException('«macro» erwartet «macro name(a, b)»', $this->file, $line, 1);
        }
        $params = array_values(array_filter(array_map('trim', explode(',', $m[2]))));
        $body = $this->body(['end']);
        $this->expectEnd('macro', $line);

        return new Node('macro', ['name' => $m[1], 'params' => $params], $body, $line);
    }

    private function set(string $rest, int $line): Node
    {
        if (! preg_match('/^([A-Za-z_]\w*)\s*=\s*(.+)$/s', $rest, $m)) {
            throw new LavaSyntaxException('«set» erwartet «set name = ausdruck»', $this->file, $line, 1);
        }

        return new Node('set', ['name' => $m[1], 'expr' => Expression::parse($m[2], $this->file, $line, $this->registry)], [], $line);
    }

    private function tag(string $word, string $rest, int $line): Node
    {
        if (! preg_match('/^[A-Za-z_][\w.]*$/', $word)) {
            throw new LavaSyntaxException(sprintf('Unverständliches Tag «%s»', $word), $this->file, $line, 1);
        }
        $with = null;
        if (preg_match('/^(.*?)\s+with\s+(.+)$/s', $rest, $m)) {
            $rest = $m[1];
            $with = Expression::parse($m[2], $this->file, $line, $this->registry);
        }
        [$args, $named] = Expression::parseArguments($rest, $this->file, $line, $this->registry);

        return new Node('tag', ['name' => $word, 'args' => $args, 'named' => $named, 'with' => $with], [], $line);
    }

    private function accept(string $word): bool
    {
        $piece = $this->pieces[$this->pos] ?? null;
        $words = $piece === null ? false : preg_split('/\s+/', $piece['text']);
        if ($piece !== null && $piece['kind'] === 'tag' && $words !== false && ($words[0] ?? '') === $word) {
            $this->pos++;

            return true;
        }

        return false;
    }

    private function expectEnd(string $block, int $line): void
    {
        if (! $this->accept('end')) {
            throw new LavaSyntaxException(sprintf('«%s» (Zeile %d) ohne «end»', $block, $line), $this->file, $line, 1);
        }
    }
}
