<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\SiteScope;
use Basalto\Core\Theme\LayeredSource;
use Basalto\Core\Theme\MemorySource;
use Basalto\Core\Theme\ThemeLoader;
use Basalto\Lava\Diagnostics\Diagnostic;
use Basalto\Lava\Runtime\Registry;
use Basalto\Lava\Structure\Definition;
use Basalto\Lava\Structure\Document;
use Basalto\Lava\Structure\Parser;
use Basalto\Lava\Structure\Printer;
use Basalto\Lava\Theme\Compiler;
use Basalto\Lava\Theme\LavaSource;

/**
 * Inhaltstypen (M-02): aus Lava (gehören dem Theme — sichtbar, nicht änderbar) und aus dem Backend (gehören der Site).
 * Site-eigene Typen sind selbst Lava: Das Formular schreibt über den Drucker in die Site-Quelle `types.lava`
 * (`lava_sources`, `scope = site`), die der Compiler hinter dem Theme einbindet — eine Quelle, ein Editor, eine API.
 *
 * @phpstan-type FieldInput array{name: string, type: string, required?: bool, translatable?: bool, options?: string|null, default?: string|null, relation?: string|null, allow?: list<string>|null, labels?: array<string, string>, help?: string|null}
 * @phpstan-type TypeInput array{kind: string, name: string, labels: array<string, string>, fields: list<FieldInput>, route?: array<string, string>, template?: string|null}
 */
final class ContentTypes
{
    public const PATH = 'types.lava';

    public function __construct(private readonly ThemeLoader $themes, private readonly Registry $registry) {}

    /**
     * Alle Typen und globalen Inhalte der Site mit Herkunft, Feldern, Routen, Anzahl Inhalte und Lava-Code.
     *
     * @return list<array<string, mixed>>
     */
    public function all(Site $site): array
    {
        $theme = $this->themes->forSite($site);
        $printer = new Printer;
        $counts = Entry::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');
        $types = [];
        foreach ($theme->document->definitions as $def) {
            if (! in_array($def->kind, ['type', 'global'], true) || $def->name === null) {
                continue;
            }
            $labels = [];
            $routes = [];
            foreach ($def->properties as $property) {
                if ($property->key === 'label') {
                    $labels[$property->lang ?? '*'] = (string) $property->first()?->text();
                }
                if ($property->key === 'route') {
                    $routes[$property->lang ?? '*'] = (string) $property->first()?->text();
                }
            }
            $types[] = [
                'kind' => $def->kind, 'name' => $def->name, 'label' => $labels['*'] ?? ucfirst($def->name), 'labels' => $labels,
                'source' => str_starts_with($def->file, LayeredSource::PREFIX) ? 'site' : 'theme',
                'is_page_type' => PageType::isPageType($def->name),
                'fields' => self::withTexts(ThemeSchema::fieldsOf($def), $def), 'routes' => $routes, 'template' => $def->value('template'),
                'entries' => (int) ($counts->get($def->name) ?? 0),
                'lava' => $printer->printDefinition($def, 0, true),
                'file' => $def->file, 'line' => $def->line,
            ];
        }
        foreach ($counts as $type => $n) {
            if (! in_array($type, array_column($types, 'name'), true) && ! PageType::isPageType((string) $type)) {
                $types[] = ['kind' => 'type', 'name' => (string) $type, 'label' => (string) $type, 'labels' => [], 'source' => 'missing', 'is_page_type' => false,
                    'fields' => [], 'routes' => [], 'template' => null, 'entries' => (int) $n, 'lava' => '', 'file' => '', 'line' => 0];
            }
        }

        return $types;
    }

    /**
     * Die Felder mit ihren Texten, wie sie in Lava stehen (TB 32): `labels` je Sprache («*» = Grundsprache) und `help`
     * der Grundsprache — das Formular schreibt sie so zurück.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private static function withTexts(array $fields, Definition $def): array
    {
        $texts = [];
        foreach ($def->childrenOf('field') as $field) {
            $labels = [];
            foreach ($field->properties as $property) {
                if ($property->key === 'label') {
                    $labels[$property->lang ?? '*'] = (string) $property->first()?->text();
                }
            }
            $texts[(string) $field->name] = ['labels' => $labels, 'help_raw' => $field->value('help')];
        }

        return array_map(static fn (array $f): array => $f + ($texts[$f['name']] ?? ['labels' => [], 'help_raw' => null]), $fields);
    }

    /** @return array<string, mixed>|null */
    public function find(Site $site, string $name): ?array
    {
        foreach ($this->all($site) as $type) {
            if ($type['name'] === $name) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Prüft eine Definition, bevor sie geschrieben wird: Form, Kollision mit dem Theme, dann eine Probe-Kompilierung
     * des ganzen Themes mit der neuen Site-Quelle — Befunde aus `site/types.lava` werden Fehler je Feld.
     *
     * @param  TypeInput  $input
     * @return array<string, string> Fehler je Pfad («name», «fields.2.type» …)
     */
    public function validate(Site $site, array $input, ?string $current = null): array
    {
        $errors = [];
        $existing = $this->find($site, $input['name']);
        if ($existing !== null && $existing['source'] === 'theme' && $input['name'] !== $current) {
            $errors['name'] = __('«:name» kommt aus dem Theme (Station E in theme.lava) und kann hier nicht angelegt werden.', ['name' => $input['name']]);
        } elseif ($existing !== null && $input['name'] !== $current && $existing['source'] === 'site') {
            $errors['name'] = __('Die Site hat schon einen Typ «:name».', ['name' => $input['name']]);
        }
        if (PageType::isPageType($input['name']) && $input['name'] !== 'page') {
            $errors['name'] = __('«:name» ist ein Seitentyp des Baums, kein Inhaltstyp.', ['name' => $input['name']]);
        }
        $names = [];
        foreach ($input['fields'] as $i => $field) {
            if (in_array($field['name'], $names, true)) {
                $errors["fields.$i.name"] = __('Feld «:name» gibt es schon.', ['name' => $field['name']]);
            }
            $names[] = $field['name'];
            if (! $this->registry->hasFieldType($field['type'])) {
                $errors["fields.$i.type"] = __('Feldtyp «:type» ist nicht registriert.', ['type' => $field['type']]);
            }
        }
        if ($input['kind'] === 'type' && ! in_array('title', $names, true)) {
            $errors['fields'] = __('Ein Inhaltstyp braucht ein Feld «title» (Text) — es trägt den Titel in Listen und im Editor.');
        }
        if ($errors !== []) {
            return $errors;
        }
        $document = $this->document($site);
        $this->replaceIn($document, Parser::parse($this->lavaFor($input), LayeredSource::PREFIX.self::PATH)->definitions[0], $current);
        $probe = new LayeredSource($this->themes->sourceFor($site, false)['source'], new MemorySource([self::PATH => (new Printer)->print($document)], 'site'));
        $compiler = new Compiler($this->registry);
        $compiler->compile($probe);
        foreach ($compiler->diagnostics as $d) {
            if ($d->isError() && str_starts_with($d->file, LayeredSource::PREFIX)) {
                $errors[$this->pathForDiagnostic($d, $input)] = $d->message.($d->suggestion !== null ? sprintf(' — meinten Sie «%s»?', $d->suggestion) : '');
            }
        }

        return $errors;
    }

    /** @param  TypeInput  $input */
    public function save(Site $site, array $input, ?string $current = null): void
    {
        $document = $this->document($site);
        $this->replaceIn($document, Parser::parse($this->lavaFor($input), LayeredSource::PREFIX.self::PATH)->definitions[0], $current);
        $this->write($site, $document);
        // Umbenennen nimmt die Inhalte mit — sonst stünden sie unter einem Typ, den es nicht mehr gibt
        $name = $input['name'];
        if ($current !== null && $name !== '' && $current !== $name) {
            Entry::query()->withoutGlobalScopes()->withTrashed()->where('site_id', $site->id)->where('type', $current)->update(['type' => $name]);
        }
    }

    public function delete(Site $site, string $name): void
    {
        $document = $this->document($site);
        $document->definitions = array_values(array_filter($document->definitions, static fn (Definition $d): bool => $d->name !== $name));
        $this->write($site, $document);
    }

    /**
     * Der Lava-Text einer Definition aus dem Formular — das, was der Drucker nachher normalisiert.
     *
     * @param  TypeInput  $input
     */
    public function lavaFor(array $input): string
    {
        $lines = [sprintf('%s %s {', $input['kind'], $input['name'])];
        foreach ($input['labels'] as $lang => $label) {
            if (trim($label) !== '') {
                $lines[] = sprintf('  label%s: %s', $lang === '*' ? '' : '.'.$lang, Printer::quote(trim($label)));
            }
        }
        foreach ($input['fields'] as $field) {
            $head = sprintf('  field %s: %s', $field['name'], $field['type']);
            if ($field['type'] === 'relation' && ($field['relation'] ?? '') !== '') {
                $head .= ' '.$field['relation'];
            }
            if ($field['required'] ?? false) {
                $head .= ' required';
            }
            if ($field['translatable'] ?? false) {
                $head .= ' translatable';
            }
            $inner = [];
            // TB 32: Beschriftung je Sprache und Hilfe — was die Redaktion im Editor über und unter dem Feld sieht
            foreach ($field['labels'] ?? [] as $lang => $text) {
                if (trim($text) !== '') {
                    $inner[] = sprintf('label%s: %s', $lang === '*' ? '' : '.'.$lang, Printer::quote(trim($text)));
                }
            }
            $help = trim((string) ($field['help'] ?? ''));
            if ($help !== '') {
                $inner[] = 'help: '.Printer::quote($help);
            }
            $allow = array_values(array_filter(array_map('trim', $field['allow'] ?? []), static fn (string $v): bool => $v !== ''));
            if ($field['type'] === 'blocks' && $allow !== []) {
                $inner[] = 'allow: '.implode(', ', $allow);
            }
            $options = array_values(array_filter(array_map('trim', explode(',', (string) ($field['options'] ?? ''))), static fn (string $v): bool => $v !== ''));
            if ($field['type'] === 'select' && $options !== []) {
                $inner[] = 'options: '.implode(', ', $options);
            }
            if (($field['default'] ?? '') !== '' && $field['default'] !== null) {
                $inner[] = 'default: '.(is_numeric($field['default']) ? $field['default'] : Printer::quote((string) $field['default']));
            }
            $lines[] = $inner === [] ? $head : $head.' { '.implode('  ', $inner).' }';
        }
        foreach ($input['route'] ?? [] as $lang => $route) {
            if (trim($route) !== '') {
                $lines[] = sprintf('  route%s: %s', $lang === '*' ? '' : '.'.$lang, trim($route));
            }
        }
        if (($input['template'] ?? '') !== '' && $input['template'] !== null) {
            $lines[] = '  template: '.$input['template'];
        }
        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }

    private function replaceIn(Document $document, Definition $new, ?string $current): void
    {
        $document->definitions = array_values(array_filter($document->definitions, static fn (Definition $d): bool => $d->name !== $new->name && ($current === null || $d->name !== $current)));
        $document->definitions[] = $new;
    }

    /** Die Site-Quelle als Dokument — leer mit Versionszeile, wenn es sie noch nicht gibt. */
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

    /** @param  TypeInput  $input */
    private function pathForDiagnostic(Diagnostic $d, array $input): string
    {
        foreach ($input['fields'] as $i => $field) {
            if (str_contains($d->message, sprintf('«%s»', $field['name']))) {
                return "fields.$i.type";
            }
        }
        if (str_contains($d->message, 'Template')) {
            return 'template';
        }

        return 'name';
    }
}
