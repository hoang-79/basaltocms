<?php

declare(strict_types=1);

namespace Basalto\Core\Search;

use Normalizer;

/**
 * Gefalteter Suchtext (TB 71): Die Suche vergleicht klein geschrieben und ohne Akzente — «Übersetzung», «übersetzung»
 * und «ubersetzung» sind dasselbe Wort, «Straße» findet man auch als «strasse». Die Datenbank kann das nicht überall
 * (SQLite faltet nur ASCII, PostgreSQL unterscheidet bei `LIKE` die Grösse), darum steht der gefaltete Text in eigenen
 * Spalten des Suchdokuments und die Abfrage wird gleich gefaltet. Gesucht wird nach Wörtern: Jedes muss vorkommen, die
 * Reihenfolge ist frei.
 */
final class SearchText
{
    /** Höchstens so viele Wörter einer Abfrage zählen — die Abfrage bleibt kurz. */
    public const MAX_WORDS = 12;

    /** Buchstaben, die ohne Akzent nicht in ein einziges Zeichen zerfallen. */
    private const SPECIAL = ['ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ø' => 'o', 'đ' => 'd', 'ð' => 'd', 'ł' => 'l', 'þ' => 'th', 'ı' => 'i', 'ς' => 'σ'];

    public static function fold(string $text): string
    {
        $text = mb_strtolower($text);
        if (class_exists(Normalizer::class)) {
            $text = (string) Normalizer::normalize($text, Normalizer::FORM_D);
        }

        return strtr((string) preg_replace('/\p{Mn}+/u', '', $text), self::SPECIAL);
    }

    /**
     * Die Wörter einer Abfrage, gefaltet und ohne Doppel. Wörter aus einem Zeichen zählen nur, wenn es keine längeren
     * gibt («C» allein sucht nach «c»).
     *
     * @return list<string>
     */
    public static function words(string $query): array
    {
        $parts = array_values(array_unique(preg_split('/[^\p{L}\p{N}]+/u', self::fold($query), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        $long = array_values(array_filter($parts, static fn (string $w): bool => mb_strlen($w) >= 2));

        return array_slice($long !== [] ? $long : $parts, 0, self::MAX_WORDS);
    }

    /**
     * Wo der Ausschnitt ansetzt (in Zeichen des Originals): an der ganzen Wortfolge, sonst am ersten Vorkommen des
     * längsten Worts — kurze Wörter wie «die» stehen überall. Null, wenn nichts vorkommt.
     *
     * @param  list<string>  $words  gefaltet, siehe {@see self::words()}
     */
    public static function anchor(string $text, array $words): ?int
    {
        $folded = self::fold($text);
        $at = count($words) > 1 ? mb_strpos($folded, implode(' ', $words)) : false;
        if ($at === false) {
            $longest = $words;
            usort($longest, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
            foreach ($longest as $word) {
                if (($at = mb_strpos($folded, $word)) !== false) {
                    break;
                }
            }
        }
        if ($at === false) {
            return null;
        }
        // Faltung kann ein Zeichen länger machen (ß → ss) oder verschlucken (Akzent als eigenes Zeichen) — zurückzählen
        $length = 0;
        $cache = [];
        foreach (mb_str_split($text) as $index => $char) {
            $length += mb_strlen(self::foldChar($char, $cache));
            if ($length > $at) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Text maskieren und jedes Vorkommen der Wörter am Wortanfang mit `<mark>` auszeichnen — auch «Übersetzung» für
     * «ubersetzung», «Schleifen» für «schleife», aber nicht «ins» in «Einstellung». Kurze Wörter (bis drei Zeichen)
     * nur als ganzes Wort — sonst leuchtet «die» auch in «diese».
     *
     * @param  list<string>  $words  gefaltet, siehe {@see self::words()}
     */
    public static function mark(string $text, array $words): string
    {
        $chars = mb_str_split($text);
        $folded = '';
        $owner = [];
        $cache = [];
        foreach ($chars as $index => $char) {
            $piece = self::foldChar($char, $cache);
            $folded .= $piece;
            foreach (mb_str_split($piece) as $_) {
                $owner[] = $index;
            }
        }
        $hit = [];
        foreach ($words as $word) {
            $length = mb_strlen($word);
            $offset = 0;
            while ($length > 0 && ($at = mb_strpos($folded, $word, $offset)) !== false) {
                $offset = $at + 1;
                $insideWord = $at > 0 && self::isLetter(mb_substr($folded, $at - 1, 1));
                if ($insideWord || ($length <= 3 && self::isLetter(mb_substr($folded, $at + $length, 1)))) {
                    continue;
                }
                for ($i = $owner[$at]; $i <= $owner[$at + $length - 1]; $i++) {
                    $hit[$i] = true;
                }
            }
        }
        $html = '';
        $open = false;
        foreach ($chars as $index => $char) {
            // ein Akzent als eigenes Zeichen gehört zum Buchstaben davor
            $marked = isset($hit[$index]) || ($open && self::foldChar($char, $cache) === '');
            if ($marked !== $open) {
                $html .= $marked ? '<mark>' : '</mark>';
                $open = $marked;
            }
            $html .= htmlspecialchars($char, ENT_QUOTES);
        }

        return $html.($open ? '</mark>' : '');
    }

    private static function isLetter(string $char): bool
    {
        return $char !== '' && preg_match('/^[\p{L}\p{N}]$/u', $char) === 1;
    }

    /**
     * Ein Zeichen falten — mit einem Zwischenspeicher je Aufruf (kein statischer Zustand, siehe Octane).
     *
     * @param  array<string, string>  $cache
     */
    private static function foldChar(string $char, array &$cache): string
    {
        return $cache[$char] ??= self::fold($char);
    }
}
