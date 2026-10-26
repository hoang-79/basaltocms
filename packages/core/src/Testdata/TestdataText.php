<?php

declare(strict_types=1);

namespace Basalto\Core\Testdata;

/**
 * Bausteine für Testdaten: Namen aus allen Landesteilen und darüber hinaus, Firmen, Orte, Sätze in vier Sprachen —
 * und absichtlich heikle Werte (sehr lang, ohne Umbruchstelle, HTML, Anführungszeichen, Emoji, andere Schriften),
 * an denen Ansichten oft brechen.
 */
final class TestdataText
{
    public const FIRST = ['Anna', 'Lukas', 'Laura', 'Noah', 'Mia', 'Luca', 'Lea', 'Elias', 'Sophie', 'Jonas', 'Lina', 'Matteo', 'Nina', 'Fabian', 'Selina', 'Reto',
        'Ursula', 'Beat', 'Heidi', 'Urs', 'Jürg', 'Margrit', 'Käthi', 'Rüdiger', 'Sören', 'Zoë', 'Chloé', 'Léa', 'Théo', 'Amélie', 'Frédéric', 'Giulia',
        'Alessandro', 'Chiara', 'Francesca', 'Lorenzo', 'Emma', 'Oliver', 'Charlotte', 'James', 'Aylin', 'Mehmet', 'Dragana', 'Nikola', 'Ana-Maria',
        'Jean-Luc', 'Hans-Peter', 'Marie-Claire', 'Björn', 'Zoltán', 'Małgorzata', 'Linh', 'Kenji', 'Priya', 'Gian', 'Mattia', 'Seraina', 'Flurina'];

    public const LAST = ['Müller', 'Meier', 'Schmid', 'Keller', 'Weber', 'Huber', 'Schneider', 'Meyer', 'Steiner', 'Fischer', 'Gerber', 'Brunner', 'Baumann',
        'Frei', 'Zimmermann', 'Moser', 'Widmer', 'Wyss', 'Graf', 'Roth', 'Bühler', 'Bärtschi', 'Lüthi', 'Rossi', 'Bernasconi', 'Ferrari', 'Bianchi',
        'Dubois', 'Favre', 'Rochat', 'Morel', 'Python', 'Nguyen', 'Yılmaz', 'Smith', 'O’Connor', 'D’Alessandro', 'von Gunten', 'de Montmollin', 'Kälin',
        'Jäggi', 'Würsch', 'Schärer', 'Tschümperlin', 'Caflisch', 'Derungs', 'Petrović'];

    /** Heikle Namen: lang, mit Markup, Anführungszeichen, anderen Schriften, Emoji, Leerzeichen am Rand. */
    public const TRICKY_NAMES = ['Maximiliane Theresia Wilhelmine von und zu Hohenzollern-Sigmaringen', '<script>alert("xss")</script>', 'Robert\'); DROP TABLE customers;--',
        '李小龍', 'محمد العلي', 'Lea 🌸 Blümli', '  Leerzeichen am Rand  ', 'ALLES IN GROSSBUCHSTABEN', 'x', '"Anführungszeichen" & <b>Fett</b>',
        'Donaudampfschifffahrtsgesellschaftskapitänswitwe'];

    public const COMPANIES = ['Bäckerei Moser AG', 'Pixelwerk GmbH', 'Gemeinde Wil', 'Musikschule Thun', 'Hotel Bellevue', 'Agentur Nordlicht', 'Weingut Rochat SA',
        'Studio Bianchi Sagl', 'Schreinerei Kälin & Söhne', 'Open Source Collective e. V.', 'Treuhand Gerber + Partner', 'Velo Brunner', 'Zahnarztpraxis Dr. Huber',
        'Kita Sonnenschein', 'FC Seefeld', 'Architekturbüro Widmer', 'Café du Lac', 'Bergbahnen Flims AG', 'Druckerei Steiner', 'Webagentur «Löwenzahn»'];

    /** @var list<array{0: string, 1: string, 2: string}> PLZ, Ort, Land */
    public const PLACES = [['8001', 'Zürich', 'CH'], ['3011', 'Bern', 'CH'], ['4051', 'Basel', 'CH'], ['6003', 'Luzern', 'CH'], ['9000', 'St. Gallen', 'CH'],
        ['1003', 'Lausanne', 'CH'], ['1201', 'Genève', 'CH'], ['6900', 'Lugano', 'CH'], ['6500', 'Bellinzona', 'CH'], ['7000', 'Chur', 'CH'], ['8400', 'Winterthur', 'CH'],
        ['3600', 'Thun', 'CH'], ['1700', 'Fribourg', 'CH'], ['2000', 'Neuchâtel', 'CH'], ['9490', 'Vaduz', 'LI'], ['80331', 'München', 'DE'], ['10115', 'Berlin', 'DE'],
        ['1010', 'Wien', 'AT'], ['20121', 'Milano', 'IT'], ['69001', 'Lyon', 'FR']];

    public const STREETS = ['Bahnhofstrasse', 'Hauptstrasse', 'Dorfstrasse', 'Seestrasse', 'Rue du Lac', 'Via Cantonale', 'Kirchgasse', 'Industriestrasse', 'Chemin des Vignes'];

    /** Sätze je Sprache für Fliesstext. */
    public const SENTENCES = [
        'de' => ['Seit dem letzten Update sehe ich diese Meldung bei jedem Aufruf.', 'Ich habe es auf zwei Servern versucht, mit demselben Ergebnis.',
            'Im Log steht leider nichts Hilfreiches.', 'Gibt es dafür eine Einstellung, die ich übersehen habe?', 'Auf der Testumgebung funktioniert es einwandfrei.',
            'Der Fehler tritt nur im Dunkelmodus auf.', 'Mit dem Starter-Theme ist alles in Ordnung, mit meinem eigenen nicht.', 'Ich nutze Laravel Herd unter macOS.',
            'Nach dem Leeren des Caches war es kurz weg, kam dann aber wieder.', 'Die Dokumentation hat mir hier schon sehr geholfen.', 'Vielen Dank für die schnelle Antwort!',
            'Das wäre eine grosse Erleichterung für unsere Redaktion.', 'Wir pflegen damit inzwischen drei Sites in vier Sprachen.', 'Kann jemand das nachstellen?',
            'Ich hänge einen Ausschnitt der Konfiguration an.', 'Die Übersetzung ins Italienische fehlt an dieser Stelle noch.', 'Das Formular sendet die Bestätigung nicht mehr.',
            'Die Bilder werden im Editor korrekt angezeigt, im Frontend aber nicht.', 'Bei mehr als hundert Seiten wird der Seitenbaum spürbar langsamer.',
            'Ich würde mir wünschen, dass man Blöcke zwischen Seiten kopieren kann.', 'Mit PostgreSQL klappt es, mit MariaDB nicht.', 'Das Problem ist mit Version 1.2.1 behoben.',
            'Ich habe das Theme von Grund auf in Lava gebaut.', 'Eine kurze Rückmeldung, ob das geplant ist, wäre toll.', 'Der Export enthält alle Medien, aber keine Formulareingänge.'],
        'en' => ['Since the last update I see this message on every request.', 'I tried it on two servers with the same result.', 'The log does not say anything useful.',
            'Is there a setting I have missed?', 'It works fine on the staging environment.', 'Thanks a lot for the quick reply!', 'Can anyone reproduce this?',
            'We now run three sites in four languages with it.', 'The images show up in the editor but not on the front end.'],
        'fr' => ['Depuis la dernière mise à jour, ce message s’affiche à chaque visite.', 'J’ai essayé sur deux serveurs, avec le même résultat.', 'Merci beaucoup pour la réponse rapide !',
            'Quelqu’un peut-il reproduire le problème ?', 'La traduction française manque encore à cet endroit.'],
        'it' => ['Dall’ultimo aggiornamento vedo questo messaggio a ogni visita.', 'Ho provato su due server, con lo stesso risultato.', 'Grazie mille per la risposta veloce!',
            'Qualcuno riesce a riprodurre il problema?', 'La traduzione italiana manca ancora in questo punto.'],
    ];

    /** Sehr lange Wörter und Adressen ohne Umbruchstelle — brechen gern das Layout. */
    public const UNBREAKABLE = ['https://www.example.com/ein/sehr/langer/pfad/der/nicht/umbricht/und/trotzdem/in/die/spalte/passen/muss?parameter=wert&noch=einer',
        'Rindfleischetikettierungsüberwachungsaufgabenübertragungsgesetz', 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'];

    public const CODE = ["{% for page in site.pages %}\n  <a href=\"{{ page.url }}\">{{ page.title }}</a>\n{% endfor %}",
        "php artisan basalto:publish meine-site\nphp artisan basalto:search-index meine-site", "SQLSTATE[HY000]: General error: 1366 Incorrect string value: '\\xF0\\x9F\\x8C\\xB8' for column 'title'",
        "{\n  \"name\": \"pixelwerk/karten\",\n  \"type\": \"basalto-plugin\",\n  \"require\": { \"basalto/core\": \"^1.2\" }\n}"];

    /** Ein Satz in der Sprache (sonst Deutsch). */
    public static function sentence(TestdataRun $run, string $locale = 'de'): string
    {
        return $run->pick(self::SENTENCES[$locale] ?? self::SENTENCES['de']);
    }

    /** Ein Absatz aus zwei bis vier Sätzen. */
    public static function paragraph(TestdataRun $run, string $locale = 'de', int $min = 2, int $max = 4): string
    {
        return implode(' ', $run->picks(self::SENTENCES[$locale] ?? self::SENTENCES['de'], $run->int($min, $max)));
    }

    /** @return array{0: string, 1: string} Vor- und Nachname */
    public static function name(TestdataRun $run): array
    {
        return [$run->pick(self::FIRST), $run->pick(self::LAST)];
    }

    /** Lokaler Teil einer Adresse aus dem Namen (ASCII, eindeutig mit Nummer). */
    public static function local(string $first, string $last, int $n): string
    {
        $ascii = static fn (string $s): string => trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ß' => 'ss',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'à' => 'a', 'á' => 'a', 'ó' => 'o', 'ł' => 'l', 'ı' => 'i', 'ć' => 'c', '’' => '']))), '-');

        return ($ascii($first) ?: 'person').'.'.($ascii($last) ?: 'test').($n > 0 ? '.'.$n : '');
    }

    /**
     * Ein Community-Dokument (Positivliste von CommunityRichText) aus Blöcken: `p` Absatz, `ul` Liste, `code`,
     * `quote`, `spoiler`, `long` (Wort ohne Umbruchstelle), `marks` (fett/kursiv/Link).
     *
     * @param  list<string>  $blocks
     * @return array{type: string, content: list<array<string, mixed>>}
     */
    public static function doc(TestdataRun $run, array $blocks, string $locale = 'de', ?string $quoteAuthor = null): array
    {
        $content = [];
        foreach ($blocks as $block) {
            $content[] = match ($block) {
                'ul' => ['type' => 'bulletList', 'content' => array_map(static fn (string $s): array => ['type' => 'listItem', 'content' => [self::p($s)]], $run->picks(self::SENTENCES[$locale] ?? self::SENTENCES['de'], $run->int(2, 4)))],
                'ol' => ['type' => 'orderedList', 'content' => array_map(static fn (string $s): array => ['type' => 'listItem', 'content' => [self::p($s)]], ['Backend öffnen und die Site wählen.', 'Unter «Einstellungen» den Reiter «Sprachen» öffnen.', 'Speichern und die Seite neu laden.'])],
                'code' => ['type' => 'codeBlock', 'attrs' => ['language' => 'twig'], 'content' => [['type' => 'text', 'text' => $run->pick(self::CODE)]]],
                'quote' => ['type' => 'blockquote'] + ($quoteAuthor !== null ? ['attrs' => ['author' => $quoteAuthor]] : []) + ['content' => [self::p(self::sentence($run, $locale))]],
                'spoiler' => ['type' => 'spoiler', 'attrs' => ['title' => 'Lösung anzeigen'], 'content' => [self::p(self::paragraph($run, $locale))]],
                'long' => self::p('Siehe '.$run->pick(self::UNBREAKABLE)),
                'marks' => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Wichtig: ', 'marks' => [['type' => 'bold']]], ['type' => 'text', 'text' => self::sentence($run, $locale).' '],
                    ['type' => 'text', 'text' => 'Mehr dazu in der Doku', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/docs']], ['type' => 'italic']]], ['type' => 'text', 'text' => '.']]],
                'hr' => ['type' => 'horizontalRule'],
                default => self::p(self::paragraph($run, $locale)),
            };
        }

        return ['type' => 'doc', 'content' => $content];
    }

    /** @return array<string, mixed> */
    public static function p(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }
}
