<?php

declare(strict_types=1);

namespace Basalto\Lava\Runtime;

use Basalto\Lava\Expression\Node as Expr;
use Basalto\Lava\Markup\Node;
use Basalto\Lava\Markup\Parser;
use Closure;

/**
 * Der Interpreter: läuft über den Markup-Baum und schreibt HTML. Ausgaben sind escaped, ausser «raw».
 * Kein PHP, keine Methodenaufrufe — Zugriff nur auf Arrays und öffentliche Eigenschaften; Funktionen,
 * Filter und Tags kommen aus der Registrierung; Grenzen aus Limits (R-99).
 */
final class Renderer
{
    /** @var Closure(string, Context): string|null Liefert eingebundene Teile («include "parts/card"») */
    private ?Closure $includeResolver = null;

    public function __construct(private readonly Registry $registry) {}

    /** @param  Closure(string, Context): string  $resolver */
    public function withIncludes(Closure $resolver): self
    {
        $this->includeResolver = $resolver;

        return $this;
    }

    /** Elemente, die nie Träger einer Feldmarke werden: ihr Inhalt ist kein bearbeitbarer Text im Dokument. */
    private const UNMARKABLE = ['script', 'style', 'title', 'textarea', 'option', 'select', 'template', 'noscript', 'iframe', 'object', 'button', 'head', 'html', 'body'];

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, array{path: string, kind: string}>  $marks  Feldmarken für den Frontend-Editor — leer heisst: keine
     */
    public function render(string $markup, array $variables, string $file = 'markup', ?Limits $limits = null, array $marks = []): string
    {
        $nodes = Parser::parseCached($markup, $file, $this->registry);
        $ctx = new Context($variables, $this->registry, $limits ?? new Limits, $file, $marks);

        return $this->nodes($nodes, $ctx);
    }

    /** @param  list<Node>  $nodes */
    public function nodes(array $nodes, Context $ctx): string
    {
        $out = '';
        foreach ($nodes as $i => $node) {
            if ($ctx->marks !== [] && $node->kind === 'output') {
                $out = $this->markHost($out, $node, $nodes[$i - 1] ?? null, $nodes[$i + 1] ?? null, $ctx);
            }
            $out .= $this->node($node, $ctx);
        }

        return $out;
    }

    /**
     * Feldmarke (Frontend-Editor, M-19): Steht eine Ausgabe wie «<h1>{{ title }}</h1>» allein in einem Element des
     * Markups und nennt die Anwendung «title» als bearbeitbares Feld, bekommt das öffnende Tag `data-basalto-field`
     * und `data-basalto-kind` — am Element des Themes, ohne zusätzliches Element, ohne Regex über fertiges HTML.
     * Nur mit Feldmarken (angemeldete Redaktion); Gäste rendern ohne sie, das HTML bleibt Byte für Byte gleich.
     * Gezählt wird nur der blanke Ausdruck (ohne Filter) aus den Variablen des Laufs; Fliesstext nur roh («raw»),
     * Text nur maskiert. Alles andere bleibt unmarkiert — und damit im Backend bearbeitbar.
     */
    private function markHost(string $out, Node $node, ?Node $before, ?Node $after, Context $ctx): string
    {
        $key = $this->markKey($node->data['expr'], $ctx);
        $mark = $key === null ? null : ($ctx->marks[$key] ?? null);
        if ($mark === null || ($mark['kind'] === 'richtext') !== (bool) $node->data['raw']) {
            return $out;
        }
        if ($before?->kind !== 'text' || $after?->kind !== 'text' || ! str_ends_with($out, (string) $before->data['text'])) {
            return $out;
        }
        $open = strrpos((string) $before->data['text'], '<');
        $tag = $open === false ? '' : substr((string) $before->data['text'], $open);
        if (preg_match('/^<([a-z][a-z0-9-]*)((?:\s[^<>]*)?)>$/i', $tag, $m) !== 1 || str_contains($m[2], 'data-basalto-field') || str_ends_with($m[2], '/')) {
            return $out;
        }
        $name = strtolower($m[1]);
        if (in_array($name, self::UNMARKABLE, true) || preg_match('/^<\/'.preg_quote($name, '/').'\s*>/i', (string) $after->data['text']) !== 1) {
            return $out;
        }

        return substr($out, 0, -1).sprintf(' data-basalto-field="%s" data-basalto-kind="%s">', htmlspecialchars($mark['path'], ENT_QUOTES, 'UTF-8'), htmlspecialchars($mark['kind'], ENT_QUOTES, 'UTF-8'));
    }

    /** «title» oder «page.title» — nur Namen und Punkte, und der erste Name stammt aus den Variablen des Laufs. */
    private function markKey(Expr $e, Context $ctx): ?string
    {
        if ($e->kind === 'name') {
            return $ctx->isFromBase($e->data['name']) ? $e->data['name'] : null;
        }
        if ($e->kind === 'attr') {
            $object = $this->markKey($e->data['object'], $ctx);

            return $object === null ? null : $object.'.'.$e->data['name'];
        }

        return null;
    }

    private function node(Node $node, Context $ctx): string
    {
        switch ($node->kind) {
            case 'text':
                return $node->data['text'];
            case 'output':
                $value = $this->eval($node->data['expr'], $ctx);

                return $node->data['raw'] ? $this->stringify($value) : htmlspecialchars($this->stringify($value), ENT_QUOTES, 'UTF-8');
            case 'set':
                $ctx->set($node->data['name'], $this->eval($node->data['expr'], $ctx));

                return '';
            case 'macro':
                $ctx->macros[$node->data['name']] = $node;

                return '';
            case 'if':
                foreach ($node->data['branches'] as $branch) {
                    if ($branch['condition'] === null || $this->truthy($this->eval($branch['condition'], $ctx))) {
                        $ctx->limits->enter($node->line);
                        try {
                            return $this->nodes($branch['body'], $ctx);
                        } finally {
                            $ctx->limits->leave();
                        }
                    }
                }

                return '';
            case 'each':
                return $this->each($node, $ctx);
            case 'tag':
                return $this->tag($node, $ctx);
        }
        throw new LavaRuntimeException(sprintf('Unbekannter Knoten «%s»', $node->kind), $node->line);
    }

    private function each(Node $node, Context $ctx): string
    {
        $iterable = $this->eval($node->data['iterable'], $ctx);
        if ($iterable instanceof \Traversable) {
            $iterable = iterator_to_array($iterable);
        }
        if (! is_array($iterable) || $iterable === []) {
            return $this->nodes($node->data['else'], $ctx);
        }
        $ctx->limits->enter($node->line);
        $out = '';
        $i = 0;
        $n = count($iterable);
        try {
            foreach ($iterable as $key => $value) {
                $ctx->limits->iteration($node->line);
                $vars = [$node->data['value'] => $value, 'loop' => ['index' => $i + 1, 'index0' => $i, 'first' => $i === 0, 'last' => $i === $n - 1, 'length' => $n]];
                if ($node->data['key'] !== null) {
                    $vars[$node->data['key']] = $key;
                }
                $ctx->push($vars);
                $out .= $this->nodes($node->children, $ctx);
                $ctx->pop();
                $i++;
            }
        } finally {
            $ctx->limits->leave();
        }

        return $out;
    }

    private function tag(Node $node, Context $ctx): string
    {
        $name = $node->data['name'];
        $args = array_values(array_map(fn (Expr $e): mixed => $this->eval($e, $ctx), $node->data['args']));
        $named = array_map(fn (Expr $e): mixed => $this->eval($e, $ctx), $node->data['named']);
        if ($name === 'call') {
            return $this->callMacro((string) ($args[0] ?? ''), array_slice($args, 1), $ctx, $node->line);
        }
        if ($name === 'include') {
            if ($this->includeResolver === null) {
                throw new LavaRuntimeException('«include» ist hier nicht verfügbar', $node->line);
            }
            $with = $node->data['with'] !== null ? $this->eval($node->data['with'], $ctx) : [];
            $ctx->limits->enter($node->line);
            $ctx->push(is_array($with) ? $with : ['item' => $with]);
            try {
                return ($this->includeResolver)((string) ($args[0] ?? ''), $ctx);
            } finally {
                $ctx->pop();
                $ctx->limits->leave();
            }
        }
        if (isset($ctx->macros[$name])) {
            return $this->callMacro($name, $args, $ctx, $node->line);
        }

        return $this->registry->callTag($name, $args, $named, $ctx, $node->line);
    }

    /** @param  list<mixed>  $args */
    private function callMacro(string $name, array $args, Context $ctx, int $line): string
    {
        $macro = $ctx->macros[$name] ?? throw new LavaRuntimeException(sprintf('Unbekanntes Makro «%s»', $name), $line);
        $vars = [];
        foreach ($macro->data['params'] as $i => $param) {
            $vars[$param] = $args[$i] ?? null;
        }
        $ctx->limits->enter($line);
        $ctx->push($vars);
        try {
            return $this->nodes($macro->children, $ctx);
        } finally {
            $ctx->pop();
            $ctx->limits->leave();
        }
    }

    public function eval(Expr $e, Context $ctx): mixed
    {
        $d = $e->data;
        switch ($e->kind) {
            case 'literal':
                return $d['value'];
            case 'name':
                if ($ctx->has($d['name'])) {
                    return $ctx->get($d['name']);
                }
                if ($this->registry->hasFunction($d['name'])) {
                    throw new LavaRuntimeException(sprintf('«%s» ist eine Funktion — mit Klammern aufrufen', $d['name']), $e->line);
                }

                return null;
            case 'attr':
                return $this->attribute($this->eval($d['object'], $ctx), $d['name']);
            case 'item':
                $obj = $this->eval($d['object'], $ctx);
                $index = $this->eval($d['index'], $ctx);

                return is_array($obj) ? ($obj[$index] ?? null) : (is_string($obj) && is_int($index) ? mb_substr($obj, $index, 1) : null);
            case 'call':
                if (isset($ctx->macros[$d['name']])) {
                    return $this->callMacro($d['name'], array_values(array_map(fn (Expr $a): mixed => $this->eval($a, $ctx), $d['args'])), $ctx, $e->line);
                }

                return $this->registry->callFunction($d['name'], array_values(array_map(fn (Expr $a): mixed => $this->eval($a, $ctx), $d['args'])),
                    array_map(fn (Expr $a): mixed => $this->eval($a, $ctx), $d['named']), $e->line);
            case 'filter':
                return $this->registry->callFunction($d['name'], array_merge([$this->eval($d['value'], $ctx)], array_values(array_map(fn (Expr $a): mixed => $this->eval($a, $ctx), $d['args']))),
                    array_map(fn (Expr $a): mixed => $this->eval($a, $ctx), $d['named']), $e->line);
            case 'not':
                return ! $this->truthy($this->eval($d['value'], $ctx));
            case 'negate':
                return -$this->number($this->eval($d['value'], $ctx), $e->line);
            case 'and':
                return $this->truthy($this->eval($d['left'], $ctx)) && $this->truthy($this->eval($d['right'], $ctx));
            case 'or':
                return $this->truthy($this->eval($d['left'], $ctx)) || $this->truthy($this->eval($d['right'], $ctx));
            case 'conditional':
                return $this->truthy($this->eval($d['condition'], $ctx)) ? $this->eval($d['then'], $ctx) : $this->eval($d['else'], $ctx);
            case 'list':
                return array_map(fn (Expr $a): mixed => $this->eval($a, $ctx), $d['items']);
            case 'map':
                return array_map(fn (Expr $a): mixed => $this->eval($a, $ctx), $d['items']);
            case 'binary':
                return $this->binary($d['op'], $this->eval($d['left'], $ctx), $this->eval($d['right'], $ctx), $e->line);
        }
        throw new LavaRuntimeException(sprintf('Unbekannter Ausdruck «%s»', $e->kind), $e->line);
    }

    private function binary(string $op, mixed $l, mixed $r, int $line): mixed
    {
        return match ($op) {
            '==' => $l == $r,
            '!=' => $l != $r,
            '<' => $l < $r,
            '>' => $l > $r,
            '<=' => $l <= $r,
            '>=' => $l >= $r,
            'in' => is_array($r) ? in_array($l, $r, false) : (is_string($r) && str_contains($r, (string) $l)),
            'not in' => ! (is_array($r) ? in_array($l, $r, false) : (is_string($r) && str_contains($r, (string) $l))),
            '~' => $this->stringify($l).$this->stringify($r),
            '+' => $this->number($l, $line) + $this->number($r, $line),
            '-' => $this->number($l, $line) - $this->number($r, $line),
            '*' => $this->number($l, $line) * $this->number($r, $line),
            '/' => $this->number($r, $line) == 0 ? throw new LavaRuntimeException('Division durch null', $line) : $this->number($l, $line) / $this->number($r, $line),
            '%' => $this->number($r, $line) == 0 ? throw new LavaRuntimeException('Division durch null', $line) : $this->number($l, $line) % $this->number($r, $line),
            default => throw new LavaRuntimeException(sprintf('Unbekannter Operator «%s»', $op), $line),
        };
    }

    /** Zugriff auf Arrays und öffentliche Eigenschaften — nie auf Methoden. */
    private function attribute(mixed $obj, string $name): mixed
    {
        if (is_array($obj)) {
            return $obj[$name] ?? null;
        }
        if ($obj instanceof \ArrayAccess) {
            return $obj->offsetExists($name) ? $obj->offsetGet($name) : null;
        }
        if (is_object($obj)) {
            $props = get_object_vars($obj);

            return $props[$name] ?? null;
        }

        return null;
    }

    private function truthy(mixed $v): bool
    {
        return ! ($v === null || $v === false || $v === '' || $v === 0 || $v === 0.0 || $v === []);
    }

    private function number(mixed $v, int $line): int|float
    {
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return str_contains((string) $v, '.') ? (float) $v : (int) $v;
        }
        if ($v === null || $v === '' || $v === false) {
            return 0;
        }
        throw new LavaRuntimeException(sprintf('Keine Zahl: «%s»', $this->stringify($v)), $line);
    }

    private function stringify(mixed $v): string
    {
        if ($v === null || $v === false) {
            return '';
        }
        if ($v === true) {
            return '1';
        }
        if (is_array($v)) {
            return implode(', ', array_map(fn (mixed $x): string => $this->stringify($x), $v));
        }
        if (is_object($v) && ! $v instanceof \Stringable) {
            return '';
        }

        return (string) $v;
    }
}
