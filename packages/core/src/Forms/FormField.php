<?php

declare(strict_types=1);

namespace Basalto\Core\Forms;

use Basalto\Lava\Structure\Definition;
use Basalto\Lava\Structure\Value;

/**
 * Ein Feld eines Formulars aus Lava (M-11): `field email: email required { label: "E-Mail"  label.en: "Email" }`.
 * Beschriftung, Platzhalter, Hilfe und Optionen je Sprache mit Rückfall auf die Grundsprache.
 */
final class FormField
{
    /** @param  list<FormField>  $children  nur bei «group» */
    private function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $required,
        private readonly Definition $definition,
        public readonly array $children,
    ) {}

    public static function fromDefinition(Definition $field): self
    {
        $children = $field->typedFirst() === 'group' ? array_map(self::fromDefinition(...), $field->childrenOf('field')) : [];

        return new self((string) $field->name, (string) $field->typedFirst(), $field->hasTypedFlag('required'), $field, $children);
    }

    public function label(string $lang): string
    {
        return $this->definition->value('label', $lang) ?? ucfirst(str_replace('_', ' ', $this->name));
    }

    public function text(string $key, string $lang): ?string
    {
        return $this->definition->value($key, $lang);
    }

    public function number(string $key): ?float
    {
        $value = $this->definition->value($key);

        return is_numeric($value) ? (float) $value : null;
    }

    /** @return list<string> */
    public function options(string $lang): array
    {
        $property = $this->definition->property('options', $lang) ?? $this->definition->property('options');

        return $property === null ? [] : array_map(static fn (Value $v): string => $v->text(), $property->values);
    }

    /**
     * Dateitypen für «file» (Endungen), leer = PDF und Bilder.
     *
     * @return list<string>
     */
    public function fileTypes(): array
    {
        $property = $this->definition->property('types');
        $types = $property === null ? [] : array_map(static fn (Value $v): string => strtolower(ltrim($v->text(), '.')), $property->values);

        return $types === [] ? ['pdf', 'jpg', 'jpeg', 'png', 'webp'] : $types;
    }

    /** Hat das Feld eine Eingabe (nicht Erklärung, nicht Gruppe)? */
    public function isInput(): bool
    {
        return ! in_array($this->type, ['explanation', 'group'], true);
    }

    /** @return list<FormField> alle Eingabefelder, Gruppen aufgelöst */
    public function inputs(): array
    {
        return $this->type === 'group' ? array_merge(...array_map(static fn (FormField $c): array => $c->inputs(), $this->children)) : ($this->isInput() ? [$this] : []);
    }
}
