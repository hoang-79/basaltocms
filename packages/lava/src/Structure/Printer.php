<?php

declare(strict_types=1);

namespace Basalto\Lava\Structure;

/**
 * Der Lava-Drucker: schreibt ein geparstes Dokument als Lava-Text zurück (Roundtrip, ADR-005-Nachtrag) — das Backend
 * bearbeitet Struktur-Teile als Formular, der Drucker schreibt die Quelle. Zwei Leerzeichen Einzug, eine Eigenschaft
 * je Zeile, Elemente in der Reihenfolge der Quelle; Kommentare kennt der Lexer nicht, sie gehen beim Roundtrip verloren.
 */
final class Printer
{
    private const RESERVED = ['extends', 'before', 'after', 'true', 'false', 'include', 'markup', 'lava'];

    public function print(Document $document): string
    {
        $blocks = [];
        if ($document->version !== null) {
            $blocks[] = 'lava '.$document->version;
        }
        $includes = array_map(static fn (array $i): string => 'include '.self::quote($i['file']), $document->includes);
        if ($includes !== []) {
            $blocks[] = implode("\n", $includes);
        }
        foreach ($document->definitions as $definition) {
            $blocks[] = $this->printDefinition($definition, 0, true);
        }

        return implode("\n\n", $blocks)."\n";
    }

    /** @param  bool  $forceBlock  Oberste Definitionen bekommen immer Klammern («publish { }»), Unterelemente nur mit Inhalt. */
    public function printDefinition(Definition $def, int $indent = 0, bool $forceBlock = false): string
    {
        $pad = str_repeat('  ', $indent);
        $head = $this->head($def);
        $items = $this->items($def, $indent + 1);
        if ($items === [] && ! $forceBlock && $def->kind !== 'row') {
            return $pad.$head;
        }
        $open = $head === '' ? '{' : $head.' {';

        return $pad.$open."\n".implode("\n", $items)."\n".$pad.'}';
    }

    public function printProperty(Property $property, int $indent = 0): string
    {
        $pad = str_repeat('  ', $indent);
        $key = $property->lang !== null ? $property->key.'.'.$property->lang : $property->key;
        $line = $pad.$key.':';
        if ($property->values !== []) {
            $line .= ' '.self::joinValues($property->values, ', ');
        }
        if ($property->block !== null) {
            $items = $this->items($property->block, $indent + 1);
            $line .= ' {'.($items === [] ? '' : "\n".implode("\n", $items)."\n".$pad).'}';
        }

        return $line;
    }

    /** Ein einzelner Wert als Lava-Text. */
    public static function value(Value $value): string
    {
        return match ($value->type) {
            Value::STRING => self::quote((string) $value->value),
            Value::NUMBER => self::number($value->value),
            Value::BOOL => $value->value === true ? 'true' : 'false',
            Value::ARROW => '->',
            default => (string) $value->value,
        };
    }

    public static function quote(string $text): string
    {
        // Zeilenumbruch und Tabulator als «\n» und «\t» — ein echter Umbruch in Anführungszeichen wäre keine Lava
        return '"'.addcslashes($text, "\"\\\n\t").'"';
    }

    private function head(Definition $def): string
    {
        if ($def->kind === 'row') {
            return '';
        }
        $parts = [];
        if ($def->kind === 'redirect') {
            $parts[] = (string) $def->name;
        } else {
            $parts[] = $def->kind;
            if ($def->name !== null) {
                $parts[] = $this->name($def);
            }
        }
        $head = implode(' ', $parts);
        if ($def->typed !== []) {
            $head .= ': '.($def->kind === 'region' ? self::regionFill($def->typed) : self::joinValues($def->typed, ' '));
        }
        if ($def->modifiers !== []) {
            $head .= ' '.self::joinValues($def->modifiers, ' ');
        }

        return $head;
    }

    private function name(Definition $def): string
    {
        $name = (string) $def->name;
        if ($def->quotedName) {
            return self::quote($name);
        }
        if (str_starts_with($name, '/')) {
            return $name;
        }
        $bare = preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\-.@\/\x80-\xff]*$/', $name) === 1 && ! in_array($name, self::RESERVED, true);

        return $bare ? $name : self::quote($name);
    }

    /**
     * Die Elemente eines Blocks als Zeilen — in der Reihenfolge der Quelle, sonst Eigenschaften, Unterelemente, Markup.
     *
     * @return list<string>
     */
    private function items(Definition $def, int $indent): array
    {
        $lines = [];
        foreach ($this->sequence($def) as [$kind, $ref]) {
            $lines[] = match ($kind) {
                'p' => $this->printProperty($def->properties[(int) $ref], $indent),
                'c' => $this->printDefinition($def->children[(int) $ref], $indent),
                default => $this->printMarkup((string) $ref, $def->markups[(string) $ref], $indent),
            };
        }

        return $lines;
    }

    /** @return list<array{0: string, 1: int|string}> */
    private function sequence(Definition $def): array
    {
        $expected = count($def->properties) + count($def->children) + count($def->markups);
        if (count($def->order) === $expected && $this->orderIsValid($def)) {
            return $def->order;
        }
        $sequence = [];
        foreach (array_keys($def->properties) as $i) {
            $sequence[] = ['p', $i];
        }
        foreach (array_keys($def->children) as $i) {
            $sequence[] = ['c', $i];
        }
        foreach (array_keys($def->markups) as $name) {
            $sequence[] = ['m', $name];
        }

        return $sequence;
    }

    /** Die aufgezeichnete Reihenfolge passt nur, wenn jedes Element genau einmal vorkommt — sonst (Backend hat umgebaut) die Standardfolge. */
    private function orderIsValid(Definition $def): bool
    {
        $seen = [];
        foreach ($def->order as [$kind, $ref]) {
            $exists = match ($kind) {
                'p' => isset($def->properties[(int) $ref]),
                'c' => isset($def->children[(int) $ref]),
                default => isset($def->markups[(string) $ref]),
            };
            if (! $exists || isset($seen[$kind.$ref])) {
                return false;
            }
            $seen[$kind.$ref] = true;
        }

        return true;
    }

    private function printMarkup(string $name, string $markup, int $indent): string
    {
        $pad = str_repeat('  ', $indent);
        $head = $pad.'markup '.($name === 'main' ? '' : $name.' ').'```';
        $body = array_map(static fn (string $l): string => trim($l) === '' ? '' : $pad.'  '.$l, explode("\n", $markup));

        return $head."\n".implode("\n", $body)."\n".$pad.'```';
    }

    /**
     * Werte verbinden: Trenner zwischen gewöhnlichen Werten, um den Pfeil nur Leerzeichen («cta: -> kontakt», «item "Blog" -> /blog»).
     *
     * @param  list<Value>  $values
     */
    private static function joinValues(array $values, string $separator): string
    {
        $out = '';
        $previous = null;
        foreach ($values as $i => $v) {
            $text = self::value($v);
            if ($i === 0) {
                $out = $text;
            } elseif ($v->type === Value::ARROW || ($previous !== null && $previous->type === Value::ARROW)) {
                $out .= ' '.$text;
            } else {
                $out .= $separator.$text;
            }
            $previous = $v;
        }

        return $out;
    }

    /**
     * Die Füllung einer Region in der Schreibweise der Themes (M-28): Einträge mit Komma getrennt, ein Eintrag ist ein
     * Wort mit seinem Namen und einem nachgestellten «off» — «part main_nav, nav footer, content, part newsletter off».
     *
     * @param  list<Value>  $values
     */
    private static function regionFill(array $values): string
    {
        $groups = [];
        for ($i = 0; $i < count($values); $i++) {
            $text = self::value($values[$i]);
            if ($text === 'off' && $groups !== []) {
                $groups[count($groups) - 1] .= ' off';
            } elseif (in_array($text, ['part', 'nav', 'global'], true) && isset($values[$i + 1])) {
                $groups[] = $text.' '.self::value($values[++$i]);
            } else {
                $groups[] = $text;
            }
        }

        return implode(', ', $groups);
    }

    private static function number(string|int|float|bool $value): string
    {
        if (is_float($value)) {
            $text = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

            return str_contains($text, '.') ? $text : $text.'.0';
        }

        return (string) $value;
    }
}
