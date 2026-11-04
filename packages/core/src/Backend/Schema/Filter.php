<?php

declare(strict_types=1);

namespace Basalto\Core\Backend\Schema;

/**
 * Ein Filter einer Liste (CR-018, TB 99): Knöpfe für die Werte eines Query-Parameters, auf Wunsch mit Zahl. Gewählt
 * wird über die Adresse — der Controller des Plugins liest den Parameter und filtert selbst.
 */
final class Filter
{
    /** @var list<array{value: string, label: string, count: int|null}> */
    private array $options = [];

    private function __construct(private readonly string $param, private readonly string $label, private readonly string $value) {}

    /** @param  string|null  $value  der gewählte Wert (leer: «Alle») */
    public static function make(string $param, string $label, ?string $value): self
    {
        return new self($param, $label, (string) $value);
    }

    public function option(string $value, string $label, ?int $count = null): self
    {
        $this->options[] = ['value' => $value, 'label' => $label, 'count' => $count];

        return $this;
    }

    /** @return array{param: string, label: string, value: string, options: list<array{value: string, label: string, count: int|null}>} */
    public function toArray(): array
    {
        return ['param' => $this->param, 'label' => $this->label, 'value' => $this->value, 'options' => $this->options];
    }
}
