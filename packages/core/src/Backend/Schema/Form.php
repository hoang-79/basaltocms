<?php

declare(strict_types=1);

namespace Basalto\Core\Backend\Schema;

/**
 * Ein Formular auf einer Verwaltungsseite (CR-018, TB 99). Mit `autosave()` speichert es nach jeder Pause selbst —
 * für Bestehendes (CR-013); ohne mit einem Knopf, etwa beim Anlegen. Geprüft wird im Controller des Plugins wie immer
 * (`$request->validate`), die Fehler stehen am Feld.
 */
final class Form
{
    private ?string $title = null;

    /** @var list<FormField> */
    private array $fields = [];

    /** @var array<string, mixed> */
    private array $values = [];

    private bool $autosave = false;

    private string $submit = '';

    private function __construct(private readonly string $url, private readonly string $method) {}

    /** `$method`: `post` oder `put`. */
    public static function make(string $url, string $method = 'post'): self
    {
        return new self($url, $method === 'put' ? 'put' : 'post');
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /** @param  list<FormField>  $fields */
    public function fields(array $fields): self
    {
        $this->fields = $fields;

        return $this;
    }

    /** @param  array<string, mixed>  $values  Feldname → Wert */
    public function values(array $values): self
    {
        $this->values = $values;

        return $this;
    }

    public function autosave(bool $autosave = true): self
    {
        $this->autosave = $autosave;

        return $this;
    }

    public function submit(string $label): self
    {
        $this->submit = $label;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $values = [];
        foreach ($this->fields as $field) {
            $values[$field->name] = $this->values[$field->name] ?? null;
        }

        return [
            'kind' => 'form', 'title' => $this->title, 'url' => $this->url, 'method' => $this->method, 'autosave' => $this->autosave,
            'submit' => $this->submit !== '' ? $this->submit : (string) __('Speichern'),
            'fields' => array_map(static fn (FormField $f): array => $f->toArray(), $this->fields), 'values' => $values,
        ];
    }
}
