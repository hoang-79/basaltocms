<?php

declare(strict_types=1);

namespace Basalto\Core\Backend\Schema;

/** Eine Kennzahl oben auf einer Verwaltungsseite (CR-018, TB 99): Wert, Bezeichnung, auf Wunsch Hinweis und Ton. */
final class Stat
{
    private ?string $hint = null;

    private string $tone = 'neutral';

    private function __construct(private readonly string $label, private readonly string $value) {}

    public static function make(string $label, string|int|float $value): self
    {
        return new self($label, (string) $value);
    }

    public function hint(string $hint): self
    {
        $this->hint = $hint;

        return $this;
    }

    /** `neutral`, `success`, `warning` oder `danger`. */
    public function tone(string $tone): self
    {
        $this->tone = in_array($tone, ['neutral', 'success', 'warning', 'danger'], true) ? $tone : 'neutral';

        return $this;
    }

    /** @return array{label: string, value: string, hint: string|null, tone: string} */
    public function toArray(): array
    {
        return ['label' => $this->label, 'value' => $this->value, 'hint' => $this->hint, 'tone' => $this->tone];
    }
}
