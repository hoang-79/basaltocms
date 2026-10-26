<?php

declare(strict_types=1);

namespace Basalto\Lava\Lsp;

use Basalto\Lava\Diagnostics\LavaSyntaxException;
use Basalto\Lava\Structure\Lexer;
use Basalto\Lava\Structure\Token;

/**
 * Wo steht der Cursor? Liest den Text vor der Stelle und bestimmt den «Platz»: Schlüsselwort auf oberster Ebene,
 * Element in einem Block, Feldtyp, Art eines Teils, Verweis auf einen Namen, Tag, Filter oder Funktion im Markup.
 * Zeile und Spalte zählen ab 1, die Spalte in Bytes — wie in den Befunden des Compilers.
 *
 * @phpstan-type Frame array{kind: string, name: string|null, typed: string|null}
 */
final class Cursor
{
    /** Kein bekannter Platz — keine Vorschläge. */
    public const NONE = 'none';

    /** Anfang einer Definition auf oberster Ebene (site, design, layout …). */
    public const KEYWORD = 'keyword';

    /** Anfang eines Elements in einem Block (Eigenschaft oder Unterelement). */
    public const ITEM = 'item';

    /** Name einer Definition direkt hinter ihrem Schlüsselwort («block hero»). */
    public const HEADING = 'heading';

    /** Feldtyp hinter «field name:». */
    public const FIELD_TYPE = 'fieldType';

    /** Art eines Teils hinter «part name:». */
    public const PART_KIND = 'partKind';

    /** Verweis auf Namen bestimmter Arten (oder feste Werte). */
    public const NAMES = 'names';

    /** Tag direkt hinter «{%». */
    public const TAG = 'tag';

    /** Filter hinter «|» im Markup. */
    public const FILTER = 'filter';

    /** Funktion oder Variable in einem Ausdruck. */
    public const FUNCTION = 'function';

    /** Teile, auf die ein Template eine Region verweisen kann. */
    private const REGION_WORDS = ['part', 'nav', 'global', 'content'];

    /**
     * @param  list<string>  $kinds  Arten der Namen bei NAMES und HEADING
     * @param  list<string>  $values  feste Werte bei NAMES («pages», «db» …)
     * @param  list<Frame>  $frames  umschliessende Blöcke, aussen zuerst
     */
    private function __construct(
        public readonly string $slot,
        public readonly string $prefix = '',
        public readonly array $kinds = [],
        public readonly array $values = [],
        public readonly array $frames = [],
    ) {}

    public static function at(string $path, string $text, int $line, int $column): self
    {
        $lines = explode("\n", $text);
        $lineText = $lines[$line - 1] ?? '';
        $before = substr($lineText, 0, max(0, $column - 1));
        $lineStart = 0;
        for ($i = 0; $i < $line - 1 && $i < count($lines); $i++) {
            $lineStart += strlen($lines[$i]) + 1;
        }
        $head = substr($text, 0, $lineStart).$before;
        if (str_ends_with($path, '.lava.html')) {
            return self::markup($head);
        }
        if (substr_count($head, '```') % 2 === 1) {
            return self::markup(substr($head, (int) strrpos($head, '```') + 3));
        }

        return self::structure($before, self::frames(substr($text, 0, $lineStart)));
    }

    /**
     * Das Wort unter dem Cursor (Buchstaben, Ziffern, «_», «-», «.» und Nicht-ASCII) und seine erste Spalte.
     *
     * @return array{word: string, column: int}|null
     */
    public static function wordAt(string $text, int $line, int $column): ?array
    {
        $lineText = explode("\n", $text)[$line - 1] ?? '';
        $isWord = static fn (string $c): bool => ctype_alnum($c) || $c === '_' || $c === '-' || $c === '.' || ord($c) >= 0x80;
        $start = min(max(0, $column - 1), strlen($lineText));
        $end = $start;
        while ($start > 0 && $isWord($lineText[$start - 1])) {
            $start--;
        }
        while ($end < strlen($lineText) && $isWord($lineText[$end])) {
            $end++;
        }
        $word = trim(substr($lineText, $start, $end - $start), '.-');

        return $word === '' ? null : ['word' => $word, 'column' => $start + 1];
    }

    /** @return Frame|null der innerste umschliessende Block */
    public function parent(): ?array
    {
        return $this->frames[count($this->frames) - 1] ?? null;
    }

    /** @return Frame|null der Block um den innersten */
    public function grandparent(): ?array
    {
        return $this->frames[count($this->frames) - 2] ?? null;
    }

    /** Steht der Cursor in einem Formular (Formularfelder haben eigene Typen)? */
    public function inForm(): bool
    {
        foreach ($this->frames as $frame) {
            if ($frame['kind'] === 'form') {
                return true;
            }
        }

        return false;
    }

    /** Im Markup: nach «{{» oder «{%», sofern noch nicht geschlossen. */
    private static function markup(string $segment): self
    {
        $output = strrpos($segment, '{{');
        $tag = strrpos($segment, '{%');
        $open = max($output === false ? -1 : $output, $tag === false ? -1 : $tag);
        if ($open < 0) {
            return new self(self::NONE);
        }
        $inner = substr($segment, $open + 2);
        if (str_contains($inner, '}}') || str_contains($inner, '%}')) {
            return new self(self::NONE);
        }
        if ($open === $tag) {
            if (preg_match('/^\s*([A-Za-z_][\w.]*)?$/', $inner, $m)) {
                return new self(self::TAG, $m[1] ?? '');
            }
            if (preg_match('/^\s*(nav|part|form)\s+["\']([\w\-]*)$/', $inner, $m)) {
                return new self(self::NAMES, $m[2], [$m[1]]);
            }
        }
        if ((substr_count($inner, '"') % 2 === 1) || (substr_count($inner, "'") % 2 === 1)) {
            return new self(self::NONE);
        }
        if (preg_match('/\|\s*([A-Za-z_][\w.]*)?$/', $inner, $m)) {
            return new self(self::FILTER, $m[1] ?? '');
        }
        if (preg_match('/(?:^|[^\w.])([A-Za-z_]\w*)?$/', $inner, $m)) {
            return new self(self::FUNCTION, $m[1] ?? '');
        }

        return new self(self::NONE);
    }

    /** @param  list<Frame>  $frames */
    private static function structure(string $before, array $frames): self
    {
        $parent = $frames[count($frames) - 1] ?? null;
        $patterns = [
            ['/^\s*field\s+[\w\-]+\s*:\s*relation\s+([\w\-]*)$/', ['type'], ['page', 'form', 'global']],
            ['/(?:->|→)\s*([\w\-]*)$/', ['page'], []],
            ['/\bextends\s+([\w\-]*)$/', ['layout'], []],
            ['/\ballow\s*:\s*(?:[\w\-]+\s*,\s*|[\w\-]+\s+)*([\w\-]*)$/', ['block'], []],
            ['/\btemplate\s*:\s*([\w\-]*)$/', ['template'], []],
            ['/\blayout\s*:\s*([\w\-]*)$/', ['layout'], []],
            ['/\bfor\s*:\s*([\w\-]*)$/', ['type'], []],
            ['/\b(?:only|exclude)\s*:\s*(?:[\w\-]+\s*,\s*|[\w\-]+\s+)*([\w\-]*)$/', ['template'], []],
            ['/\bfrom\s*:\s*([\w\-]*)$/', $parent !== null && $parent['kind'] === 'nav' ? ['type'] : [], $parent !== null && $parent['kind'] === 'nav' ? ['pages'] : []],
            ['/\bitems\s*:\s*([\w\-]*)$/', [], ['db']],
        ];
        if (preg_match('/^\s*field\s+[\w\-]+\s*:\s*([\w.]*)$/', $before, $m)) {
            return new self(self::FIELD_TYPE, $m[1], frames: $frames);
        }
        if ($parent === null && preg_match('/^\s*part\s+[\w\-]+\s*:\s*([\w.]*)$/', $before, $m)) {
            return new self(self::PART_KIND, $m[1], frames: $frames);
        }
        if (preg_match('/^\s*region\s+[\w\-]+\s*:.*\b(part|nav|global)\s+([\w\-]*)$/', $before, $m)) {
            return new self(self::NAMES, $m[2], [$m[1]], frames: $frames);
        }
        if (preg_match('/^\s*region\s+[\w\-]+\s*:(?:.*,)?\s*([\w\-]*)$/', $before, $m)) {
            return new self(self::NAMES, $m[1], [], self::REGION_WORDS, $frames);
        }
        foreach ($patterns as [$pattern, $kinds, $values]) {
            if (preg_match($pattern, $before, $m)) {
                return new self(self::NAMES, $m[1], $kinds, $values, $frames);
            }
        }
        if (preg_match('/\bsource\s*:\s*([\w\-]*)$/', $before, $m)) {
            $kind = $parent !== null && in_array($parent['typed'], ['nav', 'global', 'form'], true) ? [(string) $parent['typed']] : ['nav', 'global', 'form'];

            return new self(self::NAMES, $m[1], $kind, frames: $frames);
        }
        if ($parent === null && preg_match('/^(block|part|layout|template|type|nav|form|global)\s+([\w\-]*)$/', $before, $m)) {
            return new self(self::HEADING, $m[2], [$m[1]], frames: $frames);
        }
        if ($parent !== null && $parent['kind'] === 'seed' && preg_match('/^\s*page\s+([\w\-]*)$/', $before, $m)) {
            return new self(self::HEADING, $m[1], ['page'], frames: $frames);
        }
        if (preg_match('/^\s*([\w\-]*)$/', $before, $m)) {
            return new self($parent === null ? self::KEYWORD : self::ITEM, $m[1], frames: $frames);
        }

        return new self(self::NONE, frames: $frames);
    }

    /**
     * Die umschliessenden Blöcke am Anfang der Cursorzeile — mit dem echten Lexer, damit Zeichenketten, Kommentare
     * und Markup-Zäune keine Klammern vortäuschen. Scheitert der Lexer, gilt die oberste Ebene.
     *
     * @return list<Frame>
     */
    private static function frames(string $text): array
    {
        try {
            $tokens = (new Lexer($text, 'cursor'))->tokenize();
        } catch (LavaSyntaxException) {
            return [];
        }
        $frames = [];
        $segment = [];
        foreach ($tokens as $token) {
            if ($token->is(Token::LBRACE)) {
                $frames[] = self::frameOf($segment);
                $segment = [];
            } elseif ($token->is(Token::RBRACE)) {
                array_pop($frames);
                $segment = [];
            } elseif ($token->is(Token::NEWLINE) || $token->is(Token::FENCE)) {
                $segment = [];
            } elseif (! $token->is(Token::EOF)) {
                $segment[] = $token;
            }
        }

        return $frames;
    }

    /**
     * Wem gehört eine öffnende Klammer? «field content: blocks {» → field (Typ blocks), «legal {» → legal,
     * «title: "X" content {» → content, «{» allein → eine anonyme Zeile.
     *
     * @param  list<Token>  $segment
     * @return Frame
     */
    private static function frameOf(array $segment): array
    {
        if ($segment === []) {
            return ['kind' => 'row', 'name' => null, 'typed' => null];
        }
        $head = $segment[0];
        $last = $segment[count($segment) - 1];
        if (isset($segment[1]) && $segment[1]->is(Token::COLON)) {
            if (count($segment) > 3 && $last->is(Token::IDENT)) {
                return ['kind' => $last->value, 'name' => null, 'typed' => null];
            }

            return ['kind' => (string) preg_replace('/\.[a-z]{2}$/', '', $head->value), 'name' => null, 'typed' => null];
        }
        $name = isset($segment[1]) && ! $segment[1]->is(Token::COLON) ? $segment[1]->value : null;
        $typed = null;
        foreach ($segment as $i => $token) {
            if ($token->is(Token::COLON) && isset($segment[$i + 1])) {
                $typed = $segment[$i + 1]->value;
                break;
            }
        }

        return ['kind' => $head->value, 'name' => $name, 'typed' => $typed];
    }
}
