<?php

declare(strict_types=1);

namespace Basalto\Core\Forms;

use Basalto\Core\Models\Site;
use Basalto\Core\Theme\LayeredSource;
use Basalto\Core\Theme\MemorySource;
use Basalto\Core\Theme\ThemeLoader;
use Basalto\Lava\Runtime\Registry;
use Basalto\Lava\Structure\Definition;
use Basalto\Lava\Structure\Document;
use Basalto\Lava\Structure\Parser;
use Basalto\Lava\Structure\Printer;
use Basalto\Lava\Structure\Property;
use Basalto\Lava\Structure\Value;
use Basalto\Lava\Theme\Compiler;
use Basalto\Lava\Theme\LavaSource;

/**
 * Der Formular-Baukasten (M-11, Mockup 29): Eingabe → Lava → Probe-Kompilierung → Site-Quelle `forms.lava` (über den
 * Drucker, ADR-005 Nachtrag), und zurück aus Lava in die Eingabe. Theme-Formulare lassen sich lesen, nicht ändern.
 *
 * @phpstan-type Texts array<string, string>
 * @phpstan-type FieldInput array{name: string, type: string, required?: bool, labels?: Texts, placeholder?: Texts, help?: Texts, text?: Texts, options?: array<string, list<string>>, min?: string|int|float|null, max?: string|int|float|null, step?: string|int|float|null, default?: string|null, types?: string|null, size?: string|int|null, fields?: list<mixed>}
 * @phpstan-type FormInput array{handle: string, labels?: Texts, fields: list<FieldInput>, to?: string|null, subject?: string|null, thanks?: Texts, submit?: Texts, consent?: Texts, store?: bool, redirect?: string|null, webhook?: string|null, retain?: int|string|null}
 */
final class FormBuilder
{
    public const PATH = 'forms.lava';

    /** Namen, die der Kern für sich braucht (Token, Honeypot, Zustimmung, Rückweg). */
    private const RESERVED = ['_t', '_consent', '_seite', '_sprache', SpamGuard::HONEYPOT];

    public function __construct(private readonly ThemeLoader $themes, private readonly FormCatalog $catalog) {}

    /**
     * @param  FormInput  $input
     * @return array<string, string> Fehler je Pfad («handle», «fields.2.options», «to» …)
     */
    public function validate(Site $site, array $input, ?string $current = null): array
    {
        $errors = [];
        $handle = $input['handle'];
        if (preg_match('/^[a-z][a-z0-9_]{1,59}$/', $handle) !== 1) {
            $errors['handle'] = __('Kennung aus Kleinbuchstaben, Ziffern und «_», mit einem Buchstaben vorne (z. B. «offerte»).');
        }
        $existing = $this->catalog->find($site, $handle);
        if ($existing !== null && $handle !== $current) {
            $errors['handle'] = $existing->source() === 'theme'
                ? __('«:handle» kommt aus dem Theme (Station H) und kann hier nicht angelegt werden.', ['handle' => $handle])
                : __('Die Site hat schon ein Formular «:handle».', ['handle' => $handle]);
        }
        $errors += $this->validateFields($input['fields'], 'fields', $names);
        if ($names === []) {
            $errors['fields'] = __('Ein Formular braucht mindestens ein Feld mit Eingabe.');
        }
        foreach (preg_split('/[,;\s]+/', trim((string) ($input['to'] ?? ''))) ?: [] as $address) {
            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $errors['to'] = __('«:address» ist keine E-Mail-Adresse.', ['address' => $address]);
            }
        }
        $webhook = trim((string) ($input['webhook'] ?? ''));
        if ($webhook !== '' && (preg_match('#^https://#i', $webhook) !== 1 || filter_var($webhook, FILTER_VALIDATE_URL) === false)) {
            $errors['webhook'] = __('Der Webhook braucht eine https-Adresse.');
        }
        $retain = $input['retain'] ?? null;
        if ($retain !== null && $retain !== '' && (! is_numeric($retain) || (int) $retain < 1 || (int) $retain > 3650)) {
            $errors['retain'] = __('Aufbewahrung in Tagen, 1 bis 3650 — leer gilt die Einstellung der Site.');
        }
        if ($errors !== []) {
            return $errors;
        }
        $document = $this->document($site);
        $this->replaceIn($document, Parser::parse($this->lavaFor($input), LayeredSource::PREFIX.self::PATH)->definitions[0], $current);
        $probe = new LayeredSource($this->themes->sourceFor($site, false)['source'], new MemorySource([self::PATH => (new Printer)->print($document)], 'site'));
        $compiler = new Compiler(app(Registry::class));
        $compiler->compile($probe);
        foreach ($compiler->diagnostics as $d) {
            if ($d->isError() && $d->file === LayeredSource::PREFIX.self::PATH) {
                $errors['fields'] = $d->message.($d->suggestion !== null ? sprintf(' — meinten Sie «%s»?', $d->suggestion) : '');
            }
        }

        return $errors;
    }

    /** @param  FormInput  $input */
    public function save(Site $site, array $input, ?string $current = null): void
    {
        $document = $this->document($site);
        $this->replaceIn($document, Parser::parse($this->lavaFor($input), LayeredSource::PREFIX.self::PATH)->definitions[0], $current);
        $this->write($site, $document);
    }

    public function delete(Site $site, string $handle): void
    {
        $document = $this->document($site);
        $document->definitions = array_values(array_filter($document->definitions, static fn (Definition $d): bool => $d->name !== $handle));
        $this->write($site, $document);
    }

    /**
     * Der Lava-Text eines Formulars aus dem Baukasten — der Drucker normalisiert ihn beim Schreiben.
     *
     * @param  FormInput  $input
     */
    public function lavaFor(array $input): string
    {
        $lines = [sprintf('form %s {', $input['handle'])];
        self::texts($lines, '  ', 'label', $input['labels'] ?? []);
        foreach ($input['fields'] as $field) {
            self::field($lines, '  ', $field);
        }
        $to = array_values(array_filter(preg_split('/[,;\s]+/', trim((string) ($input['to'] ?? ''))) ?: [], static fn (string $a): bool => $a !== ''));
        if ($to !== []) {
            $lines[] = '  to: '.implode(', ', $to);
        }
        if (trim((string) ($input['subject'] ?? '')) !== '') {
            $lines[] = '  subject: '.Printer::quote(trim((string) ($input['subject'] ?? '')));
        }
        self::texts($lines, '  ', 'thanks', $input['thanks'] ?? []);
        self::texts($lines, '  ', 'submit', $input['submit'] ?? []);
        self::texts($lines, '  ', 'consent', $input['consent'] ?? []);
        if (($input['store'] ?? true) === false) {
            $lines[] = '  store: false';
        }
        $redirect = trim((string) ($input['redirect'] ?? ''));
        if ($redirect !== '') {
            $lines[] = '  redirect: -> '.(str_starts_with($redirect, '/') ? $redirect : preg_replace('/[^a-z0-9_-]/', '', strtolower($redirect)));
        }
        if (trim((string) ($input['webhook'] ?? '')) !== '') {
            $lines[] = '  webhook: '.Printer::quote(trim((string) ($input['webhook'] ?? '')));
        }
        if (($input['retain'] ?? null) !== null && $input['retain'] !== '') {
            $lines[] = '  retain: '.(int) $input['retain'];
        }
        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }

    /**
     * Ein Formular aus Lava als Eingabe für den Baukasten (auch Theme-Formulare — dort nur zum Lesen).
     *
     * @return FormInput
     */
    public function inputFor(FormDefinition $form): array
    {
        $def = $form->definition;
        $redirect = $form->redirectTarget();

        return [
            'handle' => $form->handle, 'labels' => self::textsOf($def, 'label'),
            'fields' => array_map(self::fieldInput(...), $def->childrenOf('field')),
            'to' => implode(', ', $form->recipients()), 'subject' => (string) ($def->value('subject') ?? ''),
            'thanks' => self::textsOf($def, 'thanks'), 'submit' => self::textsOf($def, 'submit'), 'consent' => self::textsOf($def, 'consent'),
            'store' => $form->stores(), 'redirect' => $redirect, 'webhook' => $form->webhookUrl(),
            'retain' => is_numeric(rtrim((string) $def->value('retain'), 'd')) ? (int) rtrim((string) $def->value('retain'), 'd') : null,
        ];
    }

    /** Lava-Code eines Formulars, wie ihn der Drucker schreibt (Code-Ansicht im Baukasten). */
    public function lavaOf(FormDefinition $form): string
    {
        return (new Printer)->printDefinition($form->definition, 0, true);
    }

    /**
     * @param  list<mixed>  $fields
     * @param  list<string>  $names  gesammelte Namen der Eingabefelder (auch in Gruppen)
     * @return array<string, string>
     */
    private function validateFields(array $fields, string $path, ?array &$names): array
    {
        $names ??= [];
        $errors = [];
        foreach ($fields as $i => $field) {
            if (! is_array($field)) {
                continue;
            }
            $name = (string) ($field['name'] ?? '');
            $type = (string) ($field['type'] ?? '');
            if (preg_match('/^[a-z][a-z0-9_]{0,59}$/', $name) !== 1 || in_array($name, self::RESERVED, true)) {
                $errors["$path.$i.name"] = __('Name aus Kleinbuchstaben, Ziffern und «_», mit einem Buchstaben vorne — er ist der Schlüssel im Eingang und im CSV.');
            } elseif (in_array($name, $names, true)) {
                $errors["$path.$i.name"] = __('Feld «:name» gibt es schon.', ['name' => $name]);
            }
            if (! in_array($type, Registry::FORM_FIELD_TYPES, true)) {
                $errors["$path.$i.type"] = __('Feldtyp «:type» gibt es im Formular nicht.', ['type' => $type]);
            }
            if (in_array($type, ['select', 'radio'], true) && array_filter((array) (($field['options'] ?? [])['*'] ?? [])) === []) {
                $errors["$path.$i.options"] = __('Auswahl und Radio brauchen Optionen (eine je Zeile).');
            }
            if ($type === 'group') {
                $errors += $this->validateFields(is_array($field['fields'] ?? null) ? array_values($field['fields']) : [], "$path.$i.fields", $names);
            } elseif (! in_array($type, ['explanation'], true)) {
                $names[] = $name;
            }
        }

        return $errors;
    }

    /**
     * @param  list<string>  $lines
     * @param  array<mixed>  $field
     */
    private static function field(array &$lines, string $indent, array $field): void
    {
        $type = (string) ($field['type'] ?? 'text');
        $lines[] = sprintf('%sfield %s: %s%s {', $indent, (string) ($field['name'] ?? ''), $type, ($field['required'] ?? false) && ! in_array($type, ['explanation', 'group'], true) ? ' required' : '');
        $inner = $indent.'  ';
        self::texts($lines, $inner, 'label', (array) ($field['labels'] ?? []));
        self::texts($lines, $inner, 'placeholder', (array) ($field['placeholder'] ?? []));
        self::texts($lines, $inner, 'help', (array) ($field['help'] ?? []));
        self::texts($lines, $inner, 'text', (array) ($field['text'] ?? []));
        foreach ((array) ($field['options'] ?? []) as $lang => $options) {
            $options = array_values(array_filter(array_map(static fn (mixed $o): string => trim((string) $o), (array) $options), static fn (string $o): bool => $o !== ''));
            if ($options !== []) {
                $lines[] = sprintf('%soptions%s: %s', $inner, $lang === '*' ? '' : '.'.$lang, implode(', ', array_map(Printer::quote(...), $options)));
            }
        }
        foreach (['min', 'max', 'step', 'size'] as $key) {
            if (isset($field[$key]) && is_numeric($field[$key])) {
                $lines[] = sprintf('%s%s: %s', $inner, $key, $field[$key] + 0);
            }
        }
        if (($field['default'] ?? '') !== '' && $field['default'] !== null) {
            $lines[] = $inner.'default: '.Printer::quote((string) $field['default']);
        }
        $types = array_values(array_filter(array_map(static fn (string $t): string => strtolower(trim($t, " .\t")), explode(',', (string) ($field['types'] ?? ''))), static fn (string $t): bool => preg_match('/^[a-z0-9]{1,10}$/', $t) === 1));
        if ($type === 'file' && $types !== []) {
            $lines[] = $inner.'types: '.implode(', ', $types);
        }
        if ($type === 'group') {
            foreach (is_array($field['fields'] ?? null) ? $field['fields'] : [] as $child) {
                if (is_array($child)) {
                    self::field($lines, $inner, $child);
                }
            }
        }
        $lines[] = $indent.'}';
    }

    /**
     * @param  list<string>  $lines
     * @param  array<mixed>  $texts  Sprache («*» = Grundsprache) → Text
     */
    private static function texts(array &$lines, string $indent, string $key, array $texts): void
    {
        foreach ($texts as $lang => $text) {
            if (trim((string) $text) !== '' && ($lang === '*' || preg_match('/^[a-z]{2}$/', (string) $lang) === 1)) {
                $lines[] = sprintf('%s%s%s: %s', $indent, $key, $lang === '*' ? '' : '.'.$lang, Printer::quote(trim((string) $text)));
            }
        }
    }

    /** @return array<string, string> */
    private static function textsOf(Definition $def, string $key): array
    {
        $out = [];
        foreach ($def->properties as $property) {
            if ($property->key === $key) {
                $out[$property->lang ?? '*'] = $property->text();
            }
        }

        return $out;
    }

    /** @return FieldInput */
    private static function fieldInput(Definition $field): array
    {
        $options = [];
        foreach ($field->properties as $property) {
            if ($property->key === 'options') {
                $options[$property->lang ?? '*'] = array_map(static fn (Value $v): string => $v->text(), $property->values);
            }
        }
        $number = static fn (string $key): ?string => is_numeric($field->value($key)) ? (string) $field->value($key) : null;
        $types = $field->property('types');

        return [
            'name' => (string) $field->name, 'type' => (string) $field->typedFirst(), 'required' => $field->hasTypedFlag('required'),
            'labels' => self::textsOf($field, 'label'), 'placeholder' => self::textsOf($field, 'placeholder'), 'help' => self::textsOf($field, 'help'), 'text' => self::textsOf($field, 'text'),
            'options' => $options, 'min' => $number('min'), 'max' => $number('max'), 'step' => $number('step'), 'size' => $number('size'),
            'default' => $field->value('default'), 'types' => $types instanceof Property ? implode(', ', array_map(static fn (Value $v): string => $v->text(), $types->values)) : null,
            'fields' => $field->typedFirst() === 'group' ? array_map(self::fieldInput(...), $field->childrenOf('field')) : [],
        ];
    }

    private function replaceIn(Document $document, Definition $new, ?string $current): void
    {
        $document->definitions = array_values(array_filter($document->definitions, static fn (Definition $d): bool => $d->name !== $new->name && ($current === null || $d->name !== $current)));
        $document->definitions[] = $new;
    }

    private function document(Site $site): Document
    {
        $row = LavaSource::query()->where('site_id', $site->id)->where('scope', LavaSource::SCOPE_SITE)->where('path', self::PATH)->first();
        if ($row === null) {
            $document = new Document(LayeredSource::PREFIX.self::PATH);
            $document->version = 1;

            return $document;
        }

        return Parser::parse($row->source, LayeredSource::PREFIX.self::PATH);
    }

    private function write(Site $site, Document $document): void
    {
        $query = LavaSource::query()->where('site_id', $site->id)->where('scope', LavaSource::SCOPE_SITE)->where('path', self::PATH);
        if ($document->definitions === []) {
            $query->delete();
        } else {
            LavaSource::query()->updateOrCreate(['site_id' => $site->id, 'scope' => LavaSource::SCOPE_SITE, 'path' => self::PATH], ['source' => (new Printer)->print($document)]);
        }
        $this->themes->forget();
    }
}
