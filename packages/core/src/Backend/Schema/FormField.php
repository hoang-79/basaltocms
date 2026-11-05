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
