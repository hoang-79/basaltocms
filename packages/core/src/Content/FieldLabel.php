<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

/**
 * Lesbare Beschriftung für ein Feld ohne `label` im Theme (TB 30): aus dem Namen statt «CTA_LABEL» — «cta_label» wird
 * «Knopf · Beschriftung», «art_overlay» «Bild · Grafik darüber». Bekannte Wörter aus Themes kommen aus einer Liste,
 * unbekannte bleiben, wie sie sind (erster Buchstabe gross). Deutsch und Englisch; Italienisch und Französisch nehmen
 * die englischen Wörter, bis ein Theme eigene Beschriftungen setzt (`label.it`, `label.fr`).
 */
final class FieldLabel
{
    /** @var array<string, array{0: string, 1: string}> Wort im Feldnamen → [Deutsch, Englisch] */
    private const WORDS = [
        'title' => ['Titel', 'Title'], 'text' => ['Text', 'Text'], 'items' => ['Einträge', 'Items'], 'intro' => ['Einleitung', 'Introduction'],
        'link' => ['Link', 'Link'], 'art' => ['Bild', 'Image'], 'slug' => ['Kurzname', 'Slug'], 'overlay' => ['Grafik darüber', 'Overlay graphic'],
        'more' => ['Zweiter Link', 'Second link'], 'lead' => ['Einleitung', 'Lead'], 'cta' => ['Knopf', 'Button'], 'content' => ['Inhalt', 'Content'],
        'credit' => ['Bildnachweis', 'Image credit'], 'name' => ['Name', 'Name'], 'label' => ['Beschriftung', 'Label'], 'eyebrow' => ['Dachzeile', 'Eyebrow'],
        'image' => ['Bild', 'Image'], 'body' => ['Text', 'Text'], 'source' => ['Quelle', 'Source'], 'seo' => ['Suchmaschinen', 'Search engines'],
        'per' => ['Anzahl je Seite', 'Per page'], 'message' => ['Nachricht', 'Message'], 'form' => ['Formular', 'Form'], 'entry' => ['Eintrag', 'Entry'],
        'page' => ['Seite', 'Page'], 'email' => ['E-Mail', 'Email'], 'cover' => ['Titelbild', 'Cover image'], 'claim' => ['Slogan', 'Claim'],
        'caption' => ['Bildunterschrift', 'Caption'], 'address' => ['Adresse', 'Address'], 'width' => ['Breite', 'Width'], 'value' => ['Wert', 'Value'],
        'terms' => ['Bedingungen', 'Terms'], 'tags' => ['Schlagwörter', 'Tags'], 'summary' => ['Zusammenfassung', 'Summary'], 'subtitle' => ['Untertitel', 'Subtitle'],
        'role' => ['Rolle', 'Role'], 'question' => ['Frage', 'Question'], 'answer' => ['Antwort', 'Answer'], 'note' => ['Hinweis', 'Note'],
        'lines' => ['Zeilen', 'Lines'], 'icon' => ['Symbol', 'Icon'], 'date' => ['Datum', 'Date'], 'category' => ['Kategorie', 'Category'],
        'buy' => ['Kauf', 'Purchase'], 'button' => ['Knopf', 'Button'], 'author' => ['Autor', 'Author'], 'account' => ['Konto', 'Account'],
        'year' => ['Jahr', 'Year'], 'topic' => ['Thema', 'Topic'], 'tone' => ['Farbton', 'Tone'], 'suffix' => ['Nachsatz', 'Suffix'],
        'style' => ['Stil', 'Style'], 'state' => ['Zustand', 'State'], 'sections' => ['Abschnitte', 'Sections'], 'section' => ['Abschnitt', 'Section'],
        'left' => ['Linke Spalte', 'Left column'], 'right' => ['Rechte Spalte', 'Right column'], 'replies' => ['Antworten', 'Replies'],
        'position' => ['Position', 'Position'], 'photo' => ['Foto', 'Photo'], 'panel' => ['Seitenkasten', 'Side panel'], 'meta' => ['Angaben', 'Details'],
        'order' => ['Reihenfolge', 'Order'], 'notify' => ['Benachrichtigen', 'Notify'], 'moderation' => ['Moderation', 'Moderation'], 'links' => ['Links', 'Links'],
        'language' => ['Sprache', 'Language'], 'key' => ['Schlüssel', 'Key'], 'free' => ['Kostenlos', 'Free'], 'command' => ['Befehl', 'Command'],
        'columns' => ['Spalten', 'Columns'], 'code' => ['Code', 'Code'], 'closed' => ['Geschlossen', 'Closed'], 'client' => ['Kunde', 'Client'],
        'center' => ['Zentriert', 'Centred'], 'catalog' => ['Katalog', 'Catalogue'], 'blocks' => ['Blöcke', 'Blocks'], 'badge' => ['Abzeichen', 'Badge'],
        'small' => ['klein', 'small'], 'accent' => ['Akzent', 'Accent'], 'quote' => ['Zitat', 'Quote'], 'url' => ['Adresse', 'Address'],
        'alt' => ['Alt-Text', 'Alt text'], 'description' => ['Beschreibung', 'Description'], 'heading' => ['Überschrift', 'Heading'],
        'headline' => ['Überschrift', 'Headline'], 'background' => ['Hintergrund', 'Background'], 'color' => ['Farbe', 'Colour'], 'size' => ['Grösse', 'Size'],
        'count' => ['Anzahl', 'Count'], 'limit' => ['Anzahl', 'Limit'], 'phone' => ['Telefon', 'Phone'], 'video' => ['Video', 'Video'],
        'gallery' => ['Galerie', 'Gallery'], 'logo' => ['Logo', 'Logo'], 'price' => ['Preis', 'Price'], 'features' => ['Merkmale', 'Features'],
        'steps' => ['Schritte', 'Steps'], 'stats' => ['Kennzahlen', 'Figures'], 'ok' => ['OK', 'OK'],
    ];

    /** Hinteres Wort, das ein anderes näher bestimmt: «cta_label» = Beschriftung des Knopfs → «Knopf · Beschriftung». */
    private const SUFFIXES = ['label', 'small', 'overlay', 'credit', 'page', 'title', 'note', 'items', 'meta', 'url', 'alt'];

    public static function humanize(string $name, ?string $locale = null): string
    {
        $index = str_starts_with($locale ?? app()->getLocale(), 'de') ? 0 : 1;
        $parts = array_values(array_filter(explode('_', strtolower($name)), static fn (string $p): bool => $p !== ''));
        if ($parts === []) {
            return $name;
        }
        $word = static fn (string $part): string => self::WORDS[$part][$index] ?? $part;
        $last = $parts[count($parts) - 1];
        if (count($parts) > 1 && in_array($last, self::SUFFIXES, true)) {
            $suffix = $last === 'credit' ? ['Nachweis', 'credit'][$index] : $word($last);

            return self::upper(implode(' ', array_map($word, array_slice($parts, 0, -1)))).' · '.$suffix;
        }

        return self::upper(implode(' ', array_map($word, $parts)));
    }

    private static function upper(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
