<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

use Basalto\Lava\Structure\Definition;
use Basalto\Lava\Theme\PageRenderer;
use Basalto\Lava\Theme\Theme;

/**
 * Was der Editor aus dem Theme wissen muss: Felder je Inhaltstyp, erlaubte Blöcke, Felder je Block
 * (Typ, Pflicht, übersetzbar, Optionen, Vorgabe, Unterfelder bei Repeatern). Dient Formular und Validierung.
 *
 * @phpstan-type Field array{name: string, label: string, help: string|null, placeholder: string|null, from: string|null, pick: string|null, advanced: bool, type: string, required: bool, translatable: bool, options: list<string>, default: mixed, allow: list<string>, relation: string|null, fields: list<mixed>}
 */
final class ThemeSchema
{
    /**
     * @param  array<string, array{name: string, label: string, group: string|null, hint: string|null, fields: list<Field>}>  $blocks
     * @param  array<string, array{name: string, label: string, template: string, routes: array<string, string>, allowed: list<string>, fields: list<Field>}>  $types
     * @param  list<string>  $parts  Lava-Teile des Themes — als Block `@part` in Seiten und Bausteinen (M-28)
     */
    private function __construct(public readonly array $blocks, public readonly array $types, public readonly array $parts = []) {}

    /** Pflichtfelder prüfen? Beim Autosave eines Entwurfs nicht — erst beim Einreichen (TB 05, Autosave). */
    private bool $requireFilled = true;

    /** Dasselbe Schema, aber Pflichtfelder dürfen leer sein (Entwurf speichern); erlaubte Blöcke gelten weiter. */
    public function withoutRequired(): self
    {
        $copy = clone $this;
        $copy->requireFilled = false;

        return $copy;
    }

    public static function fromTheme(Theme $theme): self
    {
        $blocks = [];
        foreach ($theme->document->all('block') as $block) {
            // TB 28: Gruppe und ein Satz für die Palette im Editor (ohne Gruppe ordnet der Editor nach den Feldern)
            $blocks[(string) $block->name] = ['name' => (string) $block->name, 'label' => $block->value('label', app()->getLocale()) ?? ucfirst((string) $block->name),
                'group' => $block->value('group', app()->getLocale()), 'hint' => $block->value('hint', app()->getLocale()), 'fields' => self::fields($block, $theme->document->names('form'))];
        }
        $types = [];
        foreach ($theme->document->all('type') as $type) {
            $allowed = [];
            foreach ($type->childrenOf('field') as $field) {
                if ($field->typedFirst() === 'blocks') {
                    $allowed = $field->property('allow')?->refs() ?? [];
                }
            }
            $routes = [];
            foreach ($type->properties as $property) {
                if ($property->key === 'route' && ($route = $property->first()?->text()) !== null) {
                    $routes[$property->lang ?? '*'] = $route;
                }
            }
            $types[(string) $type->name] = ['name' => (string) $type->name, 'label' => $type->value('label', app()->getLocale()) ?? ucfirst((string) $type->name),
                'template' => $type->value('template') ?? 'default', 'routes' => $routes, 'allowed' => $allowed, 'fields' => self::fields($type, $theme->document->names('form'))];
        }

        return new self($blocks, $types, $theme->document->names('part'));
    }

    /** @return list<Field> */
    public static function fieldsOf(Definition $def): array
    {
        return self::fields($def);
    }

    /**
     * Prüft die Felder eines Typs (ohne Blöcke) gegen das Schema — Pflichtfelder, auch in Repeater-Zeilen.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string> Fehler je Pfad («fields.client»)
     */
    public function validateTypeFields(string $type, array $data): array
    {
        $fields = array_values(array_filter($this->types[$type]['fields'] ?? [], static fn (array $f): bool => $f['type'] !== 'blocks' && $f['name'] !== 'title'));

        return $this->validateFields($fields, $data, 'fields');
    }

    /**
     * @param  list<string>  $forms  Formulare des Themes samt Site-Quelle — die Auswahl für «relation form» (Block «Formular»)
     * @return list<Field>
     */
    private static function fields(Definition $def, array $forms = []): array
    {
        $fields = [];
        $locale = app()->getLocale();
        foreach ($def->childrenOf('field') as $field) {
            $type = $field->typedFirst() ?? 'text';
            $default = $field->value('default');
            // TB 30: Beschriftung, Hilfe und Beispiel in der Sprache der Oberfläche; ohne Beschriftung ein lesbarer Name
            // statt «CTA_LABEL». «from» nennt das Feld, dessen Wert gilt, solange dieses leer ist (z. B. «cta.label»);
            // «advanced» stellt ein Feld unter «Weitere Möglichkeiten».
            $fields[] = [
                'name' => (string) $field->name,
                'label' => $field->value('label', $locale) ?? FieldLabel::humanize((string) $field->name, $locale),
                'help' => $field->value('help', $locale),
                'placeholder' => $field->value('placeholder', $locale),
                'from' => $field->value('from'),
                // TB 30: «pick: image» an einem Text-Feld (Bildadresse) — Auswahl aus der Mediathek, gespeichert als «media:<id>»
                'pick' => $field->value('pick'),
                'advanced' => $field->hasTypedFlag('advanced'),
                'type' => $type,
                'required' => $field->hasTypedFlag('required'),
                'translatable' => $field->hasTypedFlag('translatable'),
                'options' => $type === 'relation' && ($field->typed[1] ?? null)?->text() === 'form' ? $forms : ($field->property('options')?->refs() ?? []),
                'default' => is_numeric($default) ? $default + 0 : $default,
                'allow' => $type === 'blocks' ? ($field->property('allow')?->refs() ?? []) : [],
                'relation' => $type === 'relation' ? ($field->typed[1] ?? null)?->text() : null,
                'fields' => $type === 'repeater' ? self::fields($field, $forms) : [],
            ];
        }

        return $fields;
    }

    /** @return list<string> */
    public function allowedBlocks(string $type): array
    {
        return $this->types[$type]['allowed'] ?? array_keys($this->blocks);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['blocks' => array_values($this->blocks), 'types' => array_values($this->types)];
    }

    /**
     * Prüft eine Blockliste gegen das Schema: erlaubter Typ, Pflichtfelder (auch in Repeater-Zeilen).
     *
     * @param  list<mixed>  $blocks
     * @return array<string, string> Fehler je Pfad («content.0.title»)
     */
    public function validate(string $type, array $blocks): array
    {
        $allowed = $this->allowedBlocks($type);

        return $this->validateList($allowed, $blocks, 'content', $allowed !== []);
    }

    /**
     * Die Blöcke eines Bausteins (M-28): jeder Block des Themes und Lava-Teile, aber kein Baustein im Baustein.
     *
     * @param  list<mixed>  $blocks
     * @return array<string, string> Fehler je Pfad («blocks.0.title»)
     */
    public function validateSnippet(array $blocks): array
    {
        return $this->validateList(array_keys($this->blocks), $blocks, 'blocks', false);
    }

    /**
     * Verweise sind erlaubt, wo überhaupt Blöcke stehen dürfen: Bausteine (`@snippet`, ausser im Baustein selbst) und
     * Lava-Teile des Themes (`@part`) — TB 10 Scheibe 3, M-28.
     *
     * @param  list<string>  $allowed
     * @param  list<mixed>  $blocks
     * @return array<string, string>
     */
    private function validateList(array $allowed, array $blocks, string $prefix, bool $references): array
    {
        $errors = [];
        foreach ($blocks as $i => $block) {
            $name = is_array($block) ? (string) ($block['type'] ?? '') : '';
            $data = is_array($block) && is_array($block['data'] ?? null) ? $block['data'] : [];
            if ($name === Snippets::TYPE && $references) {
                if (! is_string($data['snippet'] ?? null) || preg_match(Snippets::HANDLE, $data['snippet']) !== 1) {
                    $errors["$prefix.$i.type"] = __('Baustein ohne gültige Kennung');
                }

                continue;
            }
            if ($name === PageRenderer::PART_BLOCK && $allowed !== []) {
                if (! is_string($data['part'] ?? null) || ! in_array($data['part'], $this->parts, true)) {
                    $errors["$prefix.$i.type"] = __('Lava-Teil «:part» gibt es im Theme nicht', ['part' => is_string($data['part'] ?? null) ? $data['part'] : '']);
                }

                continue;
            }
            if (! isset($this->blocks[$name]) || ! in_array($name, $allowed, true)) {
                $errors["$prefix.$i.type"] = __('Block «:name» ist hier nicht erlaubt', ['name' => $name]);

                continue;
            }
            $errors += $this->validateFields($this->blocks[$name]['fields'], $data, "$prefix.$i");
        }

        return $errors;
    }

    /**
     * Verschachtelte Blöcke (Feldtyp `blocks` in einem Block, M-04): nur Typen aus der `allow`-Liste des Feldes, Felder rekursiv.
     *
     * @param  list<string>  $allowed
     * @param  array<mixed>  $blocks
     * @return array<string, string>
     */
    private function validateNested(array $allowed, array $blocks, string $prefix): array
    {
        $errors = [];
        foreach (array_values($blocks) as $i => $block) {
            $name = is_array($block) ? (string) ($block['type'] ?? '') : '';
            if (! isset($this->blocks[$name]) || ($allowed !== [] && ! in_array($name, $allowed, true))) {
                $errors["$prefix.$i.type"] = __('Block «:name» ist hier nicht erlaubt', ['name' => $name]);

                continue;
            }
            $data = is_array($block) && is_array($block['data'] ?? null) ? $block['data'] : [];
            $errors += $this->validateFields($this->blocks[$name]['fields'], $data, "$prefix.$i");
        }

        return $errors;
    }

    /**
     * @param  list<Field>  $fields
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function validateFields(array $fields, array $data, string $prefix): array
    {
        $errors = [];
        foreach ($fields as $field) {
            $value = $data[$field['name']] ?? null;
            $path = $prefix.'.'.$field['name'];
            if ($this->requireFilled && $field['required'] && self::isEmpty($field['type'], $value)) {
                $errors[$path] = __('«:field» ist ein Pflichtfeld', ['field' => $field['label']]);
            }
            if ($field['type'] === 'repeater' && is_array($value)) {
                foreach (array_values($value) as $r => $row) {
                    $errors += $this->validateFields($field['fields'], is_array($row) ? $row : [], "$path.$r");
                }
            }
            if ($field['type'] === 'blocks' && is_array($value)) {
                $errors += $this->validateNested($field['allow'], $value, $path);
            }
        }

        return $errors;
    }

    private static function isEmpty(string $type, mixed $value): bool
    {
        if ($type === 'richtext') {
            return RichText::toText($value) === '';
        }
        if ($type === 'link') {
            return ! is_array($value) || trim((string) ($value['href'] ?? '')) === '';
        }

        return $value === null || $value === '' || $value === [];
    }
}
