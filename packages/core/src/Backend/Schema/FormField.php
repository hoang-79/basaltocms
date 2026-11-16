<?php

declare(strict_types=1);

namespace Basalto\Core\Backend\Schema;

/**
 * Ein Feld eines Formulars aus Beschreibung (CR-018, TB 99) — gezeichnet mit den Feldern des Kerns. Typen: `text`,
 * `email`, `url`, `number`, `password`, `textarea`, `select`, `checkbox`, `date`, `time`, `datetime`, `color`,
 * `richtext` (Fliesstext mit dem Editor des Kerns, Wert als Dokument), `file` (eine Datei; nur in Formularen ohne
 * Autosave) und `image` (ein Bild aus der Mediathek, CR-019).
 */
final class FormField
{
    /** @var array<string, mixed> */
    private array $options = [];

    private function __construct(public readonly string $name, private readonly string $label, private readonly string $type) {}

    public static function text(string $name, string $label): self
    {
        return new self($name, $label, 'text');
    }

    public static function email(string $name, string $label): self
    {
        return new self($name, $label, 'email');
    }

    public static function url(string $name, string $label): self
    {
        return new self($name, $label, 'url');
    }

    public static function number(string $name, string $label): self
    {
        return new self($name, $label, 'number');
    }

    /** Geheimnisse und Kennungen: im Autosave erst beim Verlassen des Felds gespeichert. */
    public static function password(string $name, string $label): self
    {
        return (new self($name, $label, 'password'))->onLeave();
    }

    public static function textarea(string $name, string $label): self
    {
        return new self($name, $label, 'textarea');
    }

    /** @param  array<string|int, string>  $choices  Wert → Bezeichnung */
    public static function select(string $name, string $label, array $choices): self
    {
        $field = new self($name, $label, 'select');
        $field->options['choices'] = array_map(static fn (string|int $value, string $text): array => ['value' => (string) $value, 'label' => $text], array_keys($choices), array_values($choices));

        return $field;
    }

    /**
     * CR-022: Auswahl aus einer Auswahlliste der Site (`Choices\ChoiceList::select`) — Suche beim Tippen, Gruppen, und
     * «+ … hinzufügen» für alle, die einen Bereich der Liste bearbeiten dürfen. Wert ist der Schlüssel; prüfen mit
     * `Choices::values()`.
     */
    public static function choice(string $name, string $label, string $list): self
    {
        return (new self($name, $label, 'choice'))->set('choice_list', $list);
    }

    public static function checkbox(string $name, string $label): self
    {
        return new self($name, $label, 'checkbox');
    }

    public static function date(string $name, string $label): self
    {
        return new self($name, $label, 'date');
    }

    public static function time(string $name, string $label): self
    {
        return new self($name, $label, 'time');
    }

    public static function datetime(string $name, string $label): self
    {
        return new self($name, $label, 'datetime');
    }

    public static function color(string $name, string $label): self
    {
        return new self($name, $label, 'color');
    }

    public static function richtext(string $name, string $label): self
    {
        return new self($name, $label, 'richtext');
    }

    /**
     * CR-021: Land mit Flagge, Name und Suche (wie im Profil) — Wert ist der ISO-Code mit zwei Buchstaben.
     */
    public static function country(string $name, string $label): self
    {
        return new self($name, $label, 'country');
    }

    /**
     * CR-021: Telefon je Land — nur Ziffern, Länge und Gruppierung des Landes, Flagge mit Vorwahl. Mit `countryFrom('land')`
     * an das Land des Formulars gekoppelt: Die Flagge setzt das Land, ein neues Land die Vorwahl eines leeren Felds.
     * Der Wert kommt international («+41 79 123 45 67»); speichern mit `Countries::parsePhone()` als E.164, prüfen mit
     * `Rules\PhoneNumber`.
     */
    public static function phone(string $name, string $label): self
    {
        return new self($name, $label, 'phone');
    }

    /** CR-021: Postleitzahl im Format des Landes aus `countryFrom('land')` (nur Ziffern, wo das Land es so will). */
    public static function postalCode(string $name, string $label): self
    {
        return (new self($name, $label, 'postal'))->set('on_leave', true);
    }

    public static function file(string $name, string $label, string $accept = ''): self
    {
        $field = new self($name, $label, 'file');
        $field->options['accept'] = $accept;

        return $field;
    }

    /**
     * Ein Bild aus der Mediathek (CR-019): Auswahl unter den gezeigten Bildern, Wert ist die ID oder null; `$none`
     * beschriftet die Wahl «kein Bild». Hochgeladen wird in der Mediathek.
     *
     * @param  iterable<array{id: int, name: string, url: string}>  $images
     */
    public static function image(string $name, string $label, iterable $images, string $none): self
    {
        $field = new self($name, $label, 'image');
        $field->options['images'] = array_values(is_array($images) ? $images : iterator_to_array($images, false));
        $field->options['none'] = $none;

        return $field;
    }

    public function help(string $help): self
    {
        return $this->set('help', $help);
    }

    public function placeholder(string $placeholder): self
    {
        return $this->set('placeholder', $placeholder);
    }

    public function required(bool $required = true): self
    {
        return $this->set('required', $required);
    }

    public function min(int|float $min): self
    {
        return $this->set('min', $min);
    }

    public function max(int|float $max): self
    {
        return $this->set('max', $max);
    }

    public function step(int|float $step): self
    {
        return $this->set('step', $step);
    }

    public function rows(int $rows): self
    {
        return $this->set('rows', $rows);
    }

    /** Monospace (Kennungen, Codes). */
    public function mono(): self
    {
        return $this->set('mono', true);
    }

    /** Im Autosave erst mit Enter oder beim Verlassen des Felds speichern (Kennungen, Preise, Geheimnisse). */
    public function onLeave(): self
    {
        return $this->set('on_leave', true);
    }

    /** CR-021: Das Feld des Formulars mit dem Land (für Telefon, PLZ und Nachschlagen). */
    public function countryFrom(string $field): self
    {
        return $this->set('country_field', $field);
    }

    /** CR-021: Land, wenn das Formular kein Feld dafür hat (etwa die Kontaktperson im Land ihres Kunden). */
    public function inCountry(string $code): self
    {
        return $this->set('default_country', strtoupper($code));
    }

    /**
     * CR-021: Vorschläge beim Tippen aus einem Nachschlagedienst des Kerns — `company` (Firmenregister) oder `address`
     * (Gebäudeadressen) im Land aus `countryFrom('land')`. Die Auswahl füllt die Felder des Formulars nach `$fill`
     * (Ergebnis → Feld; Ergebnis: name, street — Strasse mit Nummer —, street_name und house_number getrennt, postal_code, city,
     * country, uid, vat_number, legal_form, website).
     * Ist der Dienst für die Site aus, bleibt es ein gewöhnliches Textfeld.
     *
     * @param  'company'|'address'  $kind
     * @param  array<string, string>  $fill
     */
    public function lookup(string $kind, array $fill): self
    {
        return $this->set('lookup', ['kind' => $kind, 'fill' => $fill]);
    }

    /**
     * CR-022: Text mit Vorschlägen aus einer Auswahlliste (`ChoiceList::text`) — Funktion, Abteilung, Ort. Freie Eingabe
     * bleibt möglich; was schon in Einträgen steht, schlägt die Liste von selbst vor. Bringt ein Vorschlag Werte für
     * andere Felder mit (`fill`, etwa die Adresse zum Ort), füllt die Wahl sie aus.
     */
    public function suggest(string $list): self
    {
        return $this->set('choice_list', $list);
    }

    /** CR-022: In einer Auswahl (`choice`) steht zuerst diese Wahl für «keiner» (leerer Wert). */
    public function none(string $label): self
    {
        return $this->set('none', $label);
    }

    /** Halbe Breite: zwei Felder nebeneinander. */
    public function half(): self
    {
        return $this->set('half', true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['name' => $this->name, 'label' => $this->label, 'type' => $this->type] + $this->options;
    }

    private function set(string $key, mixed $value): self
    {
        $this->options[$key] = $value;

        return $this;
    }
}
