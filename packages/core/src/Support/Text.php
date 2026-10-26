<?php

declare(strict_types=1);

namespace Basalto\Core\Support;

/**
 * Übersetzter Text als Zeichenkette: `__()` liefert ein Array, wenn der Schlüssel eine Gruppe der PHP-Sprachdateien
 * nennt — bei deutschen Sätzen als Schlüssel nie; dann gilt der Schlüssel selbst.
 */
final class Text
{
    /** @param  array<string, mixed>  $replace */
    public static function t(string $key, array $replace = [], ?string $locale = null): string
    {
        $text = __($key, $replace, $locale);

        return is_string($text) ? $text : $key;
    }

    /**
     * Anfangsbuchstaben statt eines Bilds: erster und letzter Teil, der mit einem Buchstaben beginnt — Zahlen, Zeichen
     * und Markup zählen nicht («Bergler 2» → B, «Ana-Maria Moser» → AM); ohne Buchstaben «?».
     */
    public static function initials(string $name): string
    {
        preg_match_all('/\p{L}[\p{L}\p{M}\']*/u', $name, $matches);
        $words = $matches[0];
        $letters = '';
        foreach (count($words) > 1 ? [$words[0], $words[count($words) - 1]] : $words as $word) {
            $letters .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return $letters !== '' ? $letters : '?';
    }
}
