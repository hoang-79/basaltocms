<?php

declare(strict_types=1);

namespace Basalto\Lava\Lsp;

/**
 * Spalten umrechnen: Lava zählt Bytes ab 1, das Language Server Protocol (und CodeMirror) zählt UTF-16-Einheiten
 * ab 0. Ohne Umrechnung stünde jede Markierung hinter einem «ü» oder «»» um ein Zeichen falsch.
 */
final class Columns
{
    /** Byte-Spalte ab 1 → UTF-16-Zeichen ab 0. */
    public static function toUtf16(string $lineText, int $byteColumn): int
    {
        $prefix = substr($lineText, 0, max(0, $byteColumn - 1));

        return intdiv(strlen((string) mb_convert_encoding($prefix, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** UTF-16-Zeichen ab 0 → Byte-Spalte ab 1 (hinter dem Zeilenende: die Spalte nach dem letzten Zeichen). */
    public static function toBytes(string $lineText, int $utf16): int
    {
        $bytes = 0;
        $units = 0;
        foreach (mb_str_split($lineText, 1, 'UTF-8') as $char) {
            if ($units >= $utf16) {
                break;
            }
            $bytes += strlen($char);
            $units += strlen($char) >= 4 ? 2 : 1;
        }

        return $bytes + 1;
    }
}
