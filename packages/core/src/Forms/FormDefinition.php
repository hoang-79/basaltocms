<?php

declare(strict_types=1);

namespace Basalto\Core\Forms;

use Basalto\Core\Theme\LayeredSource;
use Basalto\Lava\Structure\Definition;
use Basalto\Lava\Structure\Value;

/**
 * Ein Formular aus Lava (M-11) — aus dem Theme (`form contact`, Station H) oder aus dem Baukasten (Site-Quelle
 * `forms.lava`). Aktionen: Mail an `to`, Speichern (`store`, Standard an), Weiterleitung (`redirect: -> seite`),
 * Webhook (`webhook: https://…`, Geheimnis in der Datenbank). Das Zustimmungsfeld fügt der Kern immer an (N-05).
 */
final class FormDefinition
{
    /** @param  list<FormField>  $fields */
    private function __construct(public readonly string $handle, public readonly Definition $definition, public readonly array $fields) {}

    public static function fromDefinition(Definition $form): self
    {
        return new self((string) $form->name, $form, array_map(FormField::fromDefinition(...), $form->childrenOf('field')));
    }

    /** «theme» oder «site» (Baukasten) — Theme-Formulare sind im Backend nur zu lesen. */
    public function source(): string
    {
        return str_starts_with($this->definition->file, LayeredSource::PREFIX) ? 'site' : 'theme';
    }

    public function label(string $lang): string
    {
        return $this->definition->value('label', $lang) ?? ucfirst($this->handle);
    }

    public function text(string $key, string $lang): ?string
    {
        return $this->definition->value($key, $lang);
    }

    /** @return list<string> */
    public function recipients(): array
    {
        $property = $this->definition->property('to');
        $out = [];
        foreach ($property === null ? [] : $property->values as $value) {
            foreach (preg_split('/[,;\s]+/', $value->text()) ?: [] as $address) {
                if (filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                    $out[] = strtolower($address);
                }
            }
        }

        return array_values(array_unique($out));
    }

    public function stores(): bool
    {
        return ! in_array($this->definition->value('store'), ['false', 'off', 'aus', 'no'], true);
    }

    /** Ziel nach dem Senden: Pfad, Adresse oder Seitenname aus dem Seed (`redirect: -> danke`). */
    public function redirectTarget(): ?string
    {
        $property = $this->definition->property('redirect');
        if ($property === null) {
            return null;
        }
        $values = array_values(array_filter($property->values, static fn (Value $v): bool => $v->type !== Value::ARROW));

        return isset($values[0]) ? $values[0]->text() : null;
    }

    public function webhookUrl(): ?string
    {
        $url = $this->definition->value('webhook');

        return is_string($url) && preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }

    public function retainDays(int $default): int
    {
        $value = $this->definition->value('retain');

        return is_numeric(rtrim((string) $value, 'd')) ? max(1, (int) rtrim((string) $value, 'd')) : $default;
    }

    /** @return list<FormField> */
    public function inputs(): array
    {
        return array_merge([], ...array_map(static fn (FormField $f): array => $f->inputs(), $this->fields));
    }

    /** Zeile in der Lava-Quelle — für «Theme · form contact · theme.lava:171». */
    public function location(): string
    {
        return $this->definition->file.':'.$this->definition->line;
    }
}
