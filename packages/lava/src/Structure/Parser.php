<?php

declare(strict_types=1);

namespace Basalto\Lava\Structure;

use Basalto\Lava\Diagnostics\LavaSyntaxException;

/**
 * Rekursiver Abstieg über die Tokens. Eine Datei besteht aus Definitionen «wort name { … }»;
 * ein Block enthält Eigenschaften («key: wert»), typisierte Unterelemente («field name: typ flags {…}»),
 * verschachtelte Definitionen («page home { … }») und Markup-Zäune («markup ```…```»).
 */
final class Parser
{
    /** @var list<Token> */
    private array $tokens;

    private int $pos = 0;

    public function __construct(string $source, private readonly string $file = 'theme.lava')
    {
        $this->tokens = (new Lexer($source, $file))->tokenize();
    }

    public static function parse(string $source, string $file = 'theme.lava'): Document
    {
        return (new self($source, $file))->document();
    }

    public function document(): Document
    {
        $doc = new Document($this->file);
        $this->skipNewlines();
        if ($this->peek()->is(Token::IDENT, 'lava') && $this->peek(1)->is(Token::NUMBER)) {
            $doc->version = (int) $this->peek(1)->value;
            $this->pos += 2;
        }
        while (! $this->peek()->is(Token::EOF)) {
            $this->skipNewlines();
            if ($this->peek()->is(Token::EOF)) {
                break;
            }
            if ($this->peek()->is(Token::IDENT, 'include')) {
                $t = $this->next();
                $target = $this->expect(Token::STRING, 'Dateiname in Anführungszeichen nach «include»');
                $doc->includes[] = ['file' => $target->value, 'line' => $t->line, 'column' => $t->column];

                continue;
            }
            $item = $this->item();
            if ($item instanceof Property) {
                throw new LavaSyntaxException(sprintf('Eigenschaft «%s» ausserhalb eines Blocks', $item->key), $this->file, $item->line, $item->column);
            }
            $doc->definitions[] = $item;
        }

        return $doc;
    }

    /** Ein Element innerhalb eines Blocks oder auf oberster Ebene. Gibt Property oder Definition zurück. */
    private function item(): Definition|Property
    {
        $next = $this->peek();
        // Anonyme Zeile «{ … }» (z. B. eine Repeater-Zeile in «items { { title: … } }»)
        if ($next->is(Token::LBRACE)) {
            $row = new Definition('row', null, [], [], $this->file, $next->line, $next->column);
            $this->block($row);
            $this->skipNewlines();

            return $row;
        }
        // Pfad als Kopf: «/alt/leistungen -> /» (Weiterleitungen)
        if ($next->is(Token::PATH)) {
            $t = $this->next();
            $def = new Definition('redirect', $t->value, [], $this->values(false), $this->file, $t->line, $t->column);
            $this->skipNewlines();

            return $def;
        }
        $head = $this->expect(Token::IDENT, 'Schlüsselwort erwartet');

        // Eigenschaft «key: …» oder «key.lang: …»
        $next = $this->peek();
        if ($next->is(Token::COLON)) {
            $this->next();
            [$key, $lang] = $this->splitLang($head->value);
            $values = $this->values(true);
            $block = null;
            $next = $this->peek();
            if ($next->is(Token::LBRACE)) {
                $block = new Definition($key, null, [], [], $this->file, $head->line, $head->column);
                $this->block($block);
            }

            return new Property($key, $lang, $values, $block, $head->line, $head->column);
        }

        // Markup-Zaun: «markup ```…```» oder «markup main ```…```»
        if ($head->value === 'markup') {
            $name = 'main';
            if ($next->is(Token::IDENT)) {
                $name = $this->next()->value;
            }
            $fence = $this->expect(Token::FENCE, 'Markup-Zaun ``` nach «markup» erwartet');
            $def = new Definition('markup', $name, [], [], $this->file, $head->line, $head->column);
            $def->markups[$name] = $fence->value;
            $def->markupPositions[$name] = ['line' => $fence->line, 'column' => $fence->column];

            return $def;
        }

        // Definition oder Unterelement: optionaler Name, optional «: typ flags», Modifikatoren, optionaler Block
        $name = null;
        $quoted = false;
        if ($next->is(Token::IDENT) && ! in_array($next->value, ['extends', 'before', 'after'], true)) {
            $name = $this->next()->value;
        } elseif ($next->is(Token::STRING) || $next->is(Token::PATH)) {
            $quoted = $next->is(Token::STRING);
            $name = $this->next()->value;
        }
        $typed = [];
        $next = $this->peek();
        if ($next->is(Token::COLON)) {
            $this->next();
            $typed = $this->values(false);
        }
        $modifiers = $typed === [] ? $this->values(false) : [];
        if ($head->value === 'region' && $typed !== []) {
            // Füllung und Platz zusammen (M-28): «region banner: part promo after header», «region aside: part cta optional»
            [$typed, $modifiers] = self::regionModifiers($typed);
        }
        $def = new Definition($head->value, $name, $typed, $modifiers, $this->file, $head->line, $head->column, $quoted);
        $next = $this->peek();
        if ($next->is(Token::LBRACE)) {
            $this->block($def);
        }
        $this->skipNewlines();

        return $def;
    }

    private function block(Definition $def): void
    {
        $this->expect(Token::LBRACE, '«{» erwartet');
        while (true) {
            $this->skipNewlines();
            $t = $this->peek();
            if ($t->is(Token::RBRACE)) {
                $this->next();

                return;
            }
            if ($t->is(Token::EOF)) {
                throw new LavaSyntaxException(sprintf('Block «%s%s» ohne schliessende Klammer «}»', $def->kind, $def->name ? ' '.$def->name : ''), $this->file, $def->line, $def->column);
            }
            $item = $this->item();
            if ($item instanceof Property) {
                $def->properties[] = $item;
                $def->order[] = ['p', count($def->properties) - 1];
            } elseif ($item->kind === 'markup') {
                $def->markups = array_merge($def->markups, $item->markups);
                $def->markupPositions = array_merge($def->markupPositions, $item->markupPositions);
                foreach (array_keys($item->markups) as $markupName) {
                    $def->order[] = ['m', $markupName];
                }
            } else {
                $def->children[] = $item;
                $def->order[] = ['c', count($def->children) - 1];
            }
        }
    }

    /**
     * Werte bis zum Zeilenende, «{» oder «}» — Kommas sind freiwillig. Mehrere Elemente dürfen auf einer Zeile
     * stehen («title: "X"  title.en: "Y"  content { … }»): ein Bezeichner, der ein neues Element beginnt, beendet die Werte.
     *
     * @return list<Value>
     */
    private function values(bool $inProperty): array
    {
        $values = [];
        while (true) {
            $t = $this->peek();
            if ($t->is(Token::NEWLINE) || $t->is(Token::LBRACE) || $t->is(Token::RBRACE) || $t->is(Token::EOF) || $t->is(Token::FENCE)) {
                return $values;
            }
            if ($t->is(Token::IDENT) && $this->startsNewItem($inProperty, $values !== [])) {
                return $values;
            }
            if ($t->is(Token::COMMA)) {
                $this->next();

                continue;
            }
            if ($t->is(Token::COLON)) {
                throw new LavaSyntaxException('Unerwarteter Doppelpunkt', $this->file, $t->line, $t->column);
            }
            $values[] = Value::fromToken($this->next());
        }
    }

    /**
     * Beginnt am aktuellen Bezeichner ein neues Element? Immer bei «key:» und «markup ```». In Eigenschaftswerten
     * zusätzlich bei «name {» nach mindestens einem Wert («columns: 3 items { … }»); in Typangaben und Modifikatoren
     * zusätzlich bei «field name:» und «markup main ```». Bare Werte vor «name:» bleiben in Eigenschaften mehrdeutig —
     * dort gilt: ein Element pro Zeile.
     */
    private function startsNewItem(bool $inProperty, bool $hasValues): bool
    {
        $next = $this->peek(1);
        if ($next->is(Token::COLON) || $next->is(Token::FENCE)) {
            return true;
        }
        if ($inProperty) {
            return $hasValues && $next->is(Token::LBRACE);
        }
        $after = $this->peek(2);

        return ($next->is(Token::IDENT) || $next->is(Token::STRING) || $next->is(Token::PATH)) && ($after->is(Token::COLON) || $after->is(Token::FENCE));
    }

    /**
     * Trennt hinter der Füllung einer Region stehende Modifikatoren ab: «optional», «before <region>», «after <region>».
     * Ein Name nach «part», «nav» oder «global» bleibt ein Name, auch wenn er so heisst.
     *
     * @param  list<Value>  $values
     * @return array{0: list<Value>, 1: list<Value>}
     */
    private static function regionModifiers(array $values): array
    {
        $fill = [];
        $modifiers = [];
        for ($i = 0; $i < count($values); $i++) {
            $value = $values[$i];
            $word = $value->type === Value::REF ? $value->text() : null;
            if (in_array($word, ['part', 'nav', 'global'], true) && isset($values[$i + 1])) {
                $fill[] = $value;
                $fill[] = $values[++$i];
            } elseif ($word === 'optional') {
                $modifiers[] = $value;
            } elseif (in_array($word, ['before', 'after'], true) && isset($values[$i + 1])) {
                $modifiers[] = $value;
                $modifiers[] = $values[++$i];
            } else {
                $fill[] = $value;
            }
        }

        return [$fill, $modifiers];
    }

    /** @return array{0: string, 1: ?string} */
    private function splitLang(string $key): array
    {
        if (preg_match('/^(.+)\.([a-z]{2})$/', $key, $m)) {
            return [$m[1], $m[2]];
        }

        return [$key, null];
    }

    /** Das Token am Lesekopf — abhängig vom Zustand, den next() verändert. @phpstan-impure */
    private function peek(int $offset = 0): Token
    {
        return $this->tokens[$this->pos + $offset] ?? $this->tokens[count($this->tokens) - 1];
    }

    /** Rückt den Lesekopf vor — danach liefert peek() ein anderes Token. @phpstan-impure */
    private function next(): Token
    {
        return $this->tokens[$this->pos++];
    }

    private function expect(string $type, string $message): Token
    {
        $t = $this->peek();
        if (! $t->is($type)) {
            throw new LavaSyntaxException($message.sprintf(', gefunden: «%s»', $t->value === "\n" ? 'Zeilenende' : $t->value), $this->file, $t->line, $t->column);
        }

        return $this->next();
    }

    private function skipNewlines(): void
    {
        while ($this->peek()->is(Token::NEWLINE)) {
            $this->next();
        }
    }
}
