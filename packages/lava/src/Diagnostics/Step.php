<?php

declare(strict_types=1);

namespace Basalto\Lava\Diagnostics;

/** Die rote Linie: zehn Schlüsselwörter, zehn Buchstaben — dieselben in Datei, Konsole und Backend. */
final class Step
{
    /** @var array<string, string> Schlüsselwort → Buchstabe */
    public const LETTERS = [
        'site' => 'A', 'design' => 'B', 'layout' => 'C', 'nav' => 'D', 'type' => 'E',
        'block' => 'F', 'template' => 'G', 'form' => 'H', 'seed' => 'I', 'publish' => 'Z',
    ];

    /** @var array<string, string> Buchstabe → Frage */
    public const QUESTIONS = [
        'A' => 'Wer bin ich?', 'B' => 'Wie sehe ich aus?', 'C' => 'Wo steht was?', 'D' => 'Wie findet man sich zurecht?',
        'E' => 'Welche Inhalte gibt es?', 'F' => 'Woraus bestehen Seiten?', 'G' => 'Wie wird ein Inhalt gezeigt?',
        'H' => 'Wie erreicht man mich?', 'I' => 'Womit fange ich an?', 'Z' => 'Darf es raus?',
    ];

    /** Schlüsselwörter, die zu einem Schritt gehören, ohne eigener Schritt zu sein. */
    public const ALIASES = ['global' => 'E', 'part' => 'C', 'include' => null];

    public static function letterFor(string $keyword): ?string
    {
        return self::LETTERS[$keyword] ?? self::ALIASES[$keyword] ?? null;
    }

    /** @return list<string> alle Schlüsselwörter der obersten Ebene */
    public static function keywords(): array
    {
        return array_merge(array_keys(self::LETTERS), array_keys(self::ALIASES));
    }
}
