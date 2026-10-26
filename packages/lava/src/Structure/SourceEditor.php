<?php

declare(strict_types=1);

namespace Basalto\Lava\Structure;

use LogicException;

/**
 * Ändert eine Lava-Quelle an genau einer Definition und lässt den Rest Byte für Byte stehen — Kommentare, Leerzeilen
 * und die Schreibweise der übrigen Definitionen (M-28, Roundtrip ADR-005-Nachtrag). Der Bereich einer Definition reicht
 * vom Schlüsselwort bis zur passenden schliessenden Klammer, ohne Block bis zum letzten Wert der Zeile; ein Kommentar
 * am Ende dieser Zeile bleibt draussen. Kommentare *innerhalb* einer geänderten Definition kennt der Drucker nicht —
 * sie gehen mit ihr verloren, unveränderte Definitionen bleiben unberührt.
 */
final class SourceEditor
{
    /**
     * Ersetzt eine Definition durch ihren neuen Text (vom Drucker, ohne Einzug der ersten Zeile).
     */
    public static function replace(string $source, Definition $old, string $printed): string
    {
        [$start, $end] = self::range($source, $old);

        return substr($source, 0, $start).self::reindent($printed, $old->column - 1).substr($source, $end);
    }

    /** Entfernt eine Definition samt ihrer Zeile und einer folgenden Leerzeile. */
    public static function remove(string $source, Definition $old): string
    {
        [$start, $end] = self::range($source, $old);
        $lineStart = strrpos(substr($source, 0, $start), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        if (trim(substr($source, $lineStart, $start - $lineStart)) !== '') {
            return substr($source, 0, $start).substr($source, $end);
        }
        $after = $end;
        if (substr($source, $after, 1) === "\n") {
            $after++;
        }
        if (substr($source, $after, 1) === "\n" && ($lineStart === 0 || substr($source, $lineStart - 2, 2) === "\n\n")) {
            $after++;
        }

        return substr($source, 0, $lineStart).substr($source, $after);
    }

    /**
     * Fügt eine neue Definition nach `$anchor` ein, auf derselben Einrückung — oben mit einer Leerzeile dazwischen,
     * als Unterelement (`$blankLine = false`) direkt darunter. Ohne Anker kommt sie ans Ende der Datei.
     */
    public static function insertAfter(string $source, ?Definition $anchor, string $printed, bool $blankLine = true): string
    {
        $printed = rtrim($printed, "\n");
        if ($anchor === null) {
            return rtrim($source, "\n")."\n\n".$printed."\n";
        }
        [, $end] = self::range($source, $anchor);
        $pad = str_repeat(' ', $anchor->column - 1);

        return substr($source, 0, $end).($blankLine ? "\n\n" : "\n").$pad.self::reindent($printed, $anchor->column - 1).substr($source, $end);
    }

    /**
     * Byte-Bereich einer Definition in der Quelle: [Anfang, Ende) — Anfang am Schlüsselwort, Ende nach der passenden
     * «}» oder nach dem letzten Wert ihrer Zeile.
     *
     * @return array{0: int, 1: int}
     */
    public static function range(string $source, Definition $def): array
    {
        $tokens = (new Lexer($source, $def->file))->tokenize();
        $offsets = self::lineOffsets($source);
        $index = null;
        foreach ($tokens as $i => $token) {
            if ($token->line === $def->line && $token->column === $def->column && $token->type !== Token::NEWLINE) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            throw new LogicException(sprintf('Definition «%s %s» steht nicht an Zeile %d, Spalte %d der Quelle', $def->kind, $def->name ?? '', $def->line, $def->column));
        }
        $start = self::offset($offsets, $tokens[$index]);
        $depth = 0;
        $last = $tokens[$index];
        for ($i = $index; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if ($token->type === Token::LBRACE) {
                $depth++;
            } elseif ($token->type === Token::RBRACE) {
                $depth--;
                if ($depth === 0) {
                    return [$start, self::offset($offsets, $token) + 1];
                }
            } elseif (($token->type === Token::NEWLINE || $token->type === Token::EOF) && $depth === 0) {
                break;
            }
            if ($token->type !== Token::NEWLINE && $token->type !== Token::EOF) {
                $last = $token;
            }
        }

        return [$start, self::tokenEnd($source, $offsets, $last)];
    }

    /** Rückt alle Zeilen ausser der ersten um `$columns` Leerzeichen ein (die erste steht schon an der Stelle). */
    private static function reindent(string $printed, int $columns): string
    {
        $printed = rtrim($printed, "\n");
        if ($columns <= 0) {
            return $printed;
        }
        $pad = str_repeat(' ', $columns);

        return implode("\n", array_map(static fn (string $line, int $i): string => $i === 0 || $line === '' ? $line : $pad.$line, explode("\n", $printed), array_keys(explode("\n", $printed))));
    }

    /** @return list<int> Byte-Anfang jeder Zeile (Index 0 = Zeile 1) */
    private static function lineOffsets(string $source): array
    {
        $offsets = [0];
        $position = -1;
        while (($position = strpos($source, "\n", $position + 1)) !== false) {
            $offsets[] = $position + 1;
        }

        return $offsets;
    }

    /** @param  list<int>  $offsets */
    private static function offset(array $offsets, Token $token): int
    {
        return ($offsets[$token->line - 1] ?? 0) + $token->column - 1;
    }

    /**
     * Wo ein Token in der Quelle endet — Zeichenketten bis zum schliessenden Anführungszeichen (mit Escapes), Pfeile
     * in beiden Schreibweisen, alles andere so lang wie sein Wert.
     *
     * @param  list<int>  $offsets
     */
    private static function tokenEnd(string $source, array $offsets, Token $token): int
    {
        $start = self::offset($offsets, $token);
        if ($token->type === Token::STRING) {
            $quote = $source[$start];
            $i = $start + 1;
            while ($i < strlen($source) && $source[$i] !== $quote) {
                $i += $source[$i] === '\\' ? 2 : 1;
            }

            return $i + 1;
        }
        if ($token->type === Token::ARROW) {
            return $start + (str_starts_with(substr($source, $start, 3), '→') ? strlen('→') : 2);
        }
        if ($token->type === Token::FENCE) {
            $close = strpos($source, '```', $start);

            return $close === false ? strlen($source) : $close + 3;
        }

        return $start + max(1, strlen($token->value));
    }
}
