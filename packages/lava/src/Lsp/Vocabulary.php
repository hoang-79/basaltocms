<?php

declare(strict_types=1);

namespace Basalto\Lava\Lsp;

use Basalto\Lava\Diagnostics\Step;

/**
 * Kurze deutsche Erklärungen für Hover und Vervollständigung: Schlüsselwörter der roten Linie, Unterelemente,
 * Feldtypen, Funktionen, Filter und Tags des Kerns. Was Module registrieren, kennt der Sprachdienst nur beim Namen.
 */
final class Vocabulary
{
    /** @var array<string, string> Schlüsselwort der obersten Ebene → Erklärung */
    public const KEYWORDS = [
        'site' => 'Name, Sprachen, Standardsprache, Domain, Weiterleitungen und Zeitzone der Site.',
        'design' => 'Farben, Schriften, Abstände, Radien und Bildvarianten.',
        'layout' => 'Seitengerüst mit Regionen und Markup; «extends» erbt von einem anderen Layout.',
        'part' => 'Lava-Teil: eine Art aus der Registrierung (nav, breadcrumbs, languages, global, form …) mit Einstellungen — in Regionen per «part name» eingesetzt.',
        'nav' => 'Navigation aus dem Seitenbaum («from: pages»), einer Typ-Liste oder festen Einträgen («item "…" -> seite»).',
        'type' => 'Inhaltstyp mit Feldern, Route und Template.',
        'global' => 'Globaler Inhalt (z. B. die Fusszeile) mit Feldern — einmal je Site.',
        'block' => 'Baustein für Seiteninhalte mit Feldern und Markup.',
        'template' => 'Wie ein Inhalt gezeigt wird: für welchen Typ («for»), in welchem Layout und womit die Regionen gefüllt werden.',
        'form' => 'Formular mit Feldern, Empfänger, Betreff, Dankestext, Spamschutz und Aufbewahrung.',
        'seed' => 'Startinhalte: Seiten, Globale und Einträge eigener Typen, die basalto:seed einspielt.',
        'publish' => 'SEO, Sitemap, robots, Cookie-Hinweis, Rechtsseiten, Umleitungen und Prüfungen vor basalto:publish.',
        'include' => 'Bindet eine weitere .lava-Datei des Themes ein — nur auf oberster Ebene, nie ausserhalb der Quelle.',
        'lava' => 'Versionszeile ganz am Anfang der Datei: «lava 1».',
    ];

    /** @var array<string, string> Unterelement → Erklärung */
    public const ELEMENTS = [
        'field' => 'Feld «field name: typ flags { … }» — Typ aus der Registrierung, Flags wie «required», «translatable» und «advanced» (unter «Weitere Möglichkeiten»).',
        'region' => 'Region eines Layouts («region main») oder ihre Füllung im Template («region main: content»).',
        'item' => 'Fester Menüeintrag «item "Titel" -> seite».',
        'markup' => 'Markup-Zaun ```…``` mit {{ Ausgabe }}, {% Tags %} und {# Notizen #}.',
        'color' => 'Farbe des Designs: «color name: #rrggbb».',
        'font' => 'Schrift des Designs: «font body: "IBM Plex Sans"».',
        'variant' => 'Bildvariante mit width, height, mode, sizes, densities und lazy.',
        'images' => 'Bildvarianten des Designs (Unterelemente «variant»).',
        'page' => 'Startseite in «seed» mit Titel, Slug, Template und Inhalt.',
        'content' => 'Inhalt einer Seite: Blöcke in der Reihenfolge, in der sie erscheinen.',
    ];

    /** @var array<string, string> Feldtyp des Kerns und der Formulare → Erklärung */
    public const FIELD_TYPES = [
        'text' => 'Einzeilige Zeichenkette.',
        'textarea' => 'Mehrzeiliger Text ohne Formatierung.',
        'richtext' => 'Formatierter Text aus dem Editor — Ausgabe mit «{{ raw feld }}».',
        'blocks' => 'Liste von Blöcken; «allow: …» legt fest, welche erlaubt sind.',
        'markdown' => 'Text in Markdown.',
        'number' => 'Zahl.',
        'checkbox' => 'Ja oder nein als Kontrollkästchen.',
        'bool' => 'Wahrheitswert.',
        'date' => 'Datum.',
        'datetime' => 'Datum mit Uhrzeit.',
        'select' => 'Auswahl aus «options: …».',
        'tags' => 'Schlagwörter.',
        'image' => 'Ein Bild aus der Mediathek (Alt-Text ist Pflicht) — Ausgabe mit «{% image feld size: "…" %}».',
        'gallery' => 'Mehrere Bilder aus der Mediathek.',
        'file' => 'Eine Datei aus der Mediathek.',
        'link' => 'Link nach innen oder aussen mit Beschriftung — Ausgabe mit «{% link feld %}».',
        'relation' => 'Verweis auf einen Inhalt eines Typs: «relation person».',
        'repeater' => 'Wiederholbare Gruppe von Feldern.',
        'slug' => 'URL-Teil, meist «slug from title».',
        'email' => 'E-Mail-Adresse.',
        'url' => 'Webadresse.',
        'color' => 'Farbe.',
        'code' => 'Quelltext.',
        'seo' => 'Titel, Beschreibung und Bild für Suchmaschinen.',
        'json' => 'Strukturierte Daten als JSON.',
        'tel' => 'Telefonnummer (Formular).',
        'radio' => 'Auswahl mit Optionsfeldern aus «options: …» (Formular).',
        'range' => 'Schieberegler mit min, max und step (Formular).',
        'hidden' => 'Verstecktes Feld (Formular).',
        'explanation' => 'Erklärender Text ohne Eingabe (Formular).',
        'group' => 'Fasst Formularfelder zusammen — mit eigenen «field …».',
    ];

    /** @var array<string, string> Funktion oder Filter des Kerns → Erklärung */
    public const FUNCTIONS = [
        'count' => 'Anzahl der Elemente einer Liste.',
        'length' => 'Länge einer Zeichenkette oder Anzahl der Elemente.',
        'upper' => 'In Grossbuchstaben.',
        'lower' => 'In Kleinbuchstaben.',
        'truncate' => 'Kürzt auf n Zeichen und hängt «…» an.',
        'date' => 'Formatiert ein Datum (PHP-Format, Vorgabe «d.m.Y»).',
        'join' => 'Verbindet eine Liste zu Text.',
        'first' => 'Erstes Element einer Liste.',
        'last' => 'Letztes Element einer Liste.',
        'keys' => 'Schlüssel einer Map.',
        'default' => 'Ersatzwert, wenn der Wert leer ist.',
        'json' => 'Wert als JSON.',
        'range' => 'Zahlenfolge von a bis b (höchstens 10 000 Elemente).',
        'url' => 'Macht einen Pfad zur URL.',
        't' => 'Übersetzt einen Text.',
        'nl2br' => 'Zeilenumbrüche als <br>, sicher escaped.',
        'replace' => 'Ersetzt Text.',
        'slug' => 'Macht aus Text einen URL-Teil.',
        'entries' => 'Inhalte eines Typs mit Seitenaufteilung: entries("post", 10).',
        'legal' => 'Ein Rechtstext in der Sprache der Seite als HTML: {{ raw legal("agb") }}.',
    ];

    /** @var array<string, string> Tag des Kerns oder der Anwendung → Form und Erklärung */
    public const TAGS = [
        'each' => '{% each liste as name %} … {% else %} … {% end %} — Schleife; «loop.index», «loop.first», «loop.last».',
        'if' => '{% if bedingung %} … {% elseif … %} … {% else %} … {% end %} — Bedingung.',
        'elseif' => '{% elseif bedingung %} — weiterer Zweig in «if».',
        'else' => '{% else %} — Sonst-Zweig in «if» oder leere Liste in «each».',
        'end' => '{% end %} — schliesst each, if und macro.',
        'macro' => '{% macro name(a, b) %} … {% end %} — wiederverwendbares Markup, Aufruf mit «{% call "name", x %}».',
        'set' => '{% set name = ausdruck %} — setzt eine Variable.',
        'call' => '{% call "makro", argumente %} — ruft ein Makro auf.',
        'include' => '{% include "parts/karte" with { … } %} — bindet ein Markup-Teil aus dem Theme ein.',
        'region' => '{% region "main" %} — gibt eine Region des Layouts aus.',
        'nav' => '{% nav "main" %} — gibt eine Navigation aus.',
        'part' => '{% part "main_nav" %} — gibt einen Lava-Teil aus.',
        'form' => '{% form "contact" %} — gibt ein Formular aus.',
        't' => '{% t "Text" %} — übersetzter Text.',
        'image' => '{% image bild size: "hero" class: "…" %} — responsives Bild aus einer Variante des Designs.',
        'link' => '{% link link class: "…" %} — Link mit Beschriftung.',
        'seo' => '{% seo %} — Titel und Beschreibung im <head>.',
        'assets' => '{% assets %} — Zeichensatz, Viewport und Assets im <head>.',
        'appearance' => '{% appearance %} — Umschalter hell/dunkel für Besucher (nur mit dunklen Farben in design: «color x: #… dark #…»).',
        'pagination' => '{% pagination %} — Seitenaufteilung einer Liste.',
        'search' => '{% search %} — Suchformular mit Treffern.',
        'categories' => '{% categories %} — Kategorien des Blogs.',
        'archive' => '{% archive %} — Archiv nach Monat.',
        'breadcrumbs' => '{% breadcrumbs %} — Brotkrumen der Seite.',
        'languages' => '{% languages %} — Sprachwechsel.',
    ];

    /** @var list<string> Tags, die Markup-Parser, Renderer und Seitenaufbau selbst kennen (nicht in der Registrierung) */
    public const BUILTIN_TAGS = ['each', 'if', 'elseif', 'else', 'end', 'macro', 'set', 'call', 'include', 'region', 'nav', 'part', 'form'];

    /** @var list<string> Eigenschaften eines Feldes */
    public const FIELD_PROPERTIES = ['label', 'help', 'placeholder', 'from', 'pick', 'default', 'options', 'allow', 'min', 'max', 'step', 'types', 'size'];

    /** @var list<string> Eigenschaften einer Bildvariante */
    public const VARIANT_PROPERTIES = ['width', 'height', 'mode', 'sizes', 'densities', 'lazy'];

    /** Die Erklärung eines Schlüsselworts der obersten Ebene, mit Station und Frage der roten Linie (Markdown). */
    public static function keyword(string $word): ?string
    {
        $text = self::KEYWORDS[$word] ?? null;
        if ($text === null) {
            return null;
        }
        $letter = Step::letterFor($word);
        $station = $letter !== null ? sprintf('Station %s · %s', $letter, Step::QUESTIONS[$letter]) : 'Lava';

        return sprintf("**%s** — %s\n\n%s", $word, $station, $text);
    }
}
