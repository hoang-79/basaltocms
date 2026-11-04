<?php

declare(strict_types=1);

namespace Basalto\Core\Backend\Schema;

/**
 * Eine Spalte einer Liste (CR-018, TB 99). Der Typ bestimmt die Darstellung — die Werte formatiert das Plugin selbst:
 * `text`, `mono` (Kennungen), `number` und `money` (rechtsbündig, Ziffern gleich breit), `date`, `badge` (Zustand mit
 * Ton aus der Zelle), `link` (Adresse aus der Zelle).
 */
final class Column
{
    private function __construct(public readonly string $key, private readonly string $label, private readonly string $type) {}

    public static function text(string $key, string $label): self
    {
        return new self($key, $label, 'text');
    }

    public static function mono(string $key, string $label): self
    {
        return new self($key, $label, 'mono');
    }

    public static function number(string $key, string $label): self
    {
        return new self($key, $label, 'number');
    }

    public static function money(string $key, string $label): self
    {
        return new self($key, $label, 'money');
    }

    public static function date(string $key, string $label): self
    {
        return new self($key, $label, 'date');
    }

    public static function badge(string $key, string $label): self
    {
        return new self($key, $label, 'badge');
    }

    public static function link(string $key, string $label): self
    {
        return new self($key, $label, 'link');
    }

    /** @return array{key: string, label: string, type: string} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'type' => $this->type];
    }
}
