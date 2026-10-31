<?php

declare(strict_types=1);

namespace Basalto\Lava\Structure;

use Basalto\Lava\Diagnostics\LavaSyntaxException;

/**
 * Zerlegt eine .lava-Datei in Tokens. Zeilenumbrüche sind bedeutsam (sie beenden Eigenschaften),
 * Kommentare beginnen mit «#», Markup steht zwischen drei Backticks und wird roh übernommen.
 */
final class Lexer
{
    /** @var list<Token> */
    private array $tokens = [];

    private int $position = 0;

    private int $line = 1;

    private int $column = 1;

    private readonly int $length;

    public function __construct(private readonly string $source, private readonly string $file = 'theme.lava')
    {
        $this->length = strlen($source);
    }

    /** @return list<Token> */
    public function tokenize(): array
    {
        while ($this->position < $this->length) {
            $char = $this->source[$this->position];

            if ($char === "\n") {
                $this->push(Token::NEWLINE, "\n");
                $this->advance();
                $this->line++;
                $this->column = 1;

                continue;
            }
            if ($char === ' ' || $char === "\t" || $char === "\r") {
                $this->advance();

                continue;
            }
            if ($char === '#' && ! $this->isColorAhead()) {
                $this->skipComment();

                continue;
            }
            if ($this->startsWith('```')) {
                $this->readFence();

                continue;
            }
            if ($this->startsWith('->') || $this->startsWith('→')) {
                $arrow = $this->startsWith('->') ? '->' : '→';
                $this->push(Token::ARROW, '->');
                $this->advance(strlen($arrow));

                continue;
            }
            if ($char === '"' || $char === "'") {
                $this->readString($char);

                continue;
            }
            $single = [':' => Token::COLON, ',' => Token::COMMA, '{' => Token::LBRACE, '}' => Token::RBRACE];
            if (isset($single[$char])) {
                $this->push($single[$char], $char);
                $this->advance();

                continue;
            }
            if ($char === '#') {
                $this->readWhile(Token::COLOR, static fn (string $c): bool => ctype_xdigit($c) || $c === '#');

                continue;
            }
            if ($char === '/') {
                $this->readPath();

                continue;
            }
            if (ctype_digit($char) || ($char === '-' && $this->position + 1 < $this->length && ctype_digit($this->source[$this->position + 1]))) {
                $this->readWhile(Token::NUMBER, static fn (string $c): bool => ctype_digit($c) || $c === '.' || $c === '-');

                continue;
            }
            if (ctype_alpha($char) || $char === '_' || ord($char) >= 0x80) {
                $this->readWhile(Token::IDENT, static fn (string $c): bool => ctype_alnum($c) || $c === '_' || $c === '-' || $c === '.' || $c === '@' || $c === '/' || ord($c) >= 0x80);

                continue;
            }

            throw new LavaSyntaxException(sprintf('Unerwartetes Zeichen «%s»', $char), $this->file, $this->line, $this->column);
        }
        $this->push(Token::EOF, '');

        return $this->tokens;
    }

    private function push(string $type, string $value): void
    {
        $this->tokens[] = new Token($type, $value, $this->line, $this->column);
    }

    private function advance(int $n = 1): void
    {
        $this->position += $n;
        $this->column += $n;
    }

    private function startsWith(string $needle): bool
    {
        return substr($this->source, $this->position, strlen($needle)) === $needle;
    }

    private function isColorAhead(): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{3,8}(?![0-9a-zA-Z])/', substr($this->source, $this->position, 10));
    }

    private function skipComment(): void
    {
        while ($this->position < $this->length && $this->source[$this->position] !== "\n") {
            $this->advance();
        }
    }

    /** @param callable(string): bool $accept */
    private function readWhile(string $type, callable $accept): void
    {
        $start = $this->position;
        $line = $this->line;
        $column = $this->column;
        while ($this->position < $this->length && $accept($this->source[$this->position])) {
            $this->advance();
        }
        $this->tokens[] = new Token($type, substr($this->source, $start, $this->position - $start), $line, $column);
    }

    /** Pfade wie «/projekte/{slug}» — Platzhalter in geschweiften Klammern gehören zum Pfad. */
    private function readPath(): void
    {
        $start = $this->position;
        $line = $this->line;
        $column = $this->column;
        $inPlaceholder = false;
        while ($this->position < $this->length) {
            $c = $this->source[$this->position];
            if ($inPlaceholder) {
                $inPlaceholder = $c !== '}';
            } elseif ($c === '{') {
                $inPlaceholder = true;
            } elseif (ctype_space($c) || $c === ',' || $c === '}') {
                break;
            }
            $this->advance();
        }
        $this->tokens[] = new Token(Token::PATH, substr($this->source, $start, $this->position - $start), $line, $column);
    }

    private function readString(string $quote): void
    {
        $line = $this->line;
        $column = $this->column;
        $this->advance();
        $value = '';
        while ($this->position < $this->length && $this->source[$this->position] !== $quote) {
            $char = $this->source[$this->position];
            if ($char === "\n") {
                throw new LavaSyntaxException('Zeichenkette ohne schliessendes Anführungszeichen', $this->file, $line, $column);
            }
            if ($char === '\\' && $this->position + 1 < $this->length) {
                $this->advance();
                // «\n» und «\t» sind Zeilenumbruch und Tabulator (wie in Ausdrücken), jedes andere Zeichen gilt wörtlich (\" \' \\);
                // ändert sich hier etwas am Ergebnis, ThemeCache::REVISION erhöhen
                $char = match ($this->source[$this->position]) {
                    'n' => "\n",
                    't' => "\t",
                    default => $this->source[$this->position],
                };
            }
            $value .= $char;
            $this->advance();
        }
        if ($this->position >= $this->length) {
            throw new LavaSyntaxException('Zeichenkette ohne schliessendes Anführungszeichen', $this->file, $line, $column);
        }
        $this->advance();
        $this->tokens[] = new Token(Token::STRING, $value, $line, $column);
    }

    private function readFence(): void
    {
        $line = $this->line;
        $column = $this->column;
        $this->advance(3);
        $end = strpos($this->source, '```', $this->position);
        if ($end === false) {
            throw new LavaSyntaxException('Markup-Zaun ``` ohne Ende', $this->file, $line, $column);
        }
        $raw = substr($this->source, $this->position, $end - $this->position);
        $this->line += substr_count($raw, "\n");
        $this->position = $end + 3;
        $this->column = 1;
        // Das Token zeigt auf die erste Inhaltszeile und deren Spalte, damit Markup-Fehler die echte Stelle nennen.
        $leading = strlen($raw) - strlen(ltrim($raw, "\n"));
        $contentLine = $line + $leading;
        $contentColumn = $leading > 0 ? self::indentOf($raw) + 1 : $column + 3;
        $this->tokens[] = new Token(Token::FENCE, self::dedent($raw), $contentLine, $contentColumn);
    }

    /** Die gemeinsame Einrückung eines Markup-Blocks (Leerzeichen vor der am wenigsten eingerückten Zeile). */
    public static function indentOf(string $raw): int
    {
        $indent = null;
        foreach (explode("\n", self::trimEdges($raw)) as $l) {
            if (trim($l) === '') {
                continue;
            }
            $n = strlen($l) - strlen(ltrim($l));
            $indent = $indent === null ? $n : min($indent, $n);
        }

        return $indent ?? 0;
    }

    /**
     * Entfernt die gemeinsame Einrückung eines Markup-Blocks sowie Zeilenumbrüche am Anfang und Leerraum am Ende
     * (die Einrückung vor dem schliessenden Zaun) — so ergibt Parse → Print → Parse dasselbe Markup.
     */
    public static function dedent(string $raw): string
    {
        $trimmed = self::trimEdges($raw);
        $lines = explode("\n", $trimmed);
        $indent = self::indentOf($raw);
        if ($indent === 0) {
            return $trimmed;
        }

        return implode("\n", array_map(static fn (string $l): string => substr($l, min($indent, strlen($l) - strlen(ltrim($l)))), $lines));
    }

    private static function trimEdges(string $raw): string
    {
        return ltrim(rtrim($raw), "\n");
    }
}
