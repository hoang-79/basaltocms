<?php

declare(strict_types=1);

namespace Basalto\Lava\Structure;

/** Eine Eigenschaft «key: wert wert …», optional übersetzt («label.en: …») und mit eigenem Block («index: /blog { … }»). */
final class Property
{
    /** @param list<Value> $values */
    public function __construct(
        public readonly string $key,
        public readonly ?string $lang,
        public readonly array $values,
        public readonly ?Definition $block,
        public readonly int $line,
        public readonly int $column,
    ) {}

    public function first(): ?Value
    {
        return $this->values[0] ?? null;
    }

    public function text(): string
    {
        return implode(', ', array_map(static fn (Value $v): string => $v->text(), $this->values));
    }

    /** @return list<string> Namen aller Verweise (z. B. «allow: hero, text») */
    public function refs(): array
    {
        return array_values(array_map(static fn (Value $v): string => $v->text(),
            array_filter($this->values, static fn (Value $v): bool => $v->type === Value::REF)));
    }
}
