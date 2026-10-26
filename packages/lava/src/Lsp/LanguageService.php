<?php

declare(strict_types=1);

namespace Basalto\Lava\Lsp;

use Basalto\Lava\Diagnostics\Diagnostic;
use Basalto\Lava\Diagnostics\Step;
use Basalto\Lava\Runtime\Registry;
use Basalto\Lava\Structure\Definition;
use Basalto\Lava\Theme\Compiler;
use Basalto\Lava\Theme\Source;
use Basalto\Lava\Theme\Theme;
use Basalto\Lava\Theme\Validator;
use Closure;
use ReflectionFunction;
use ReflectionParameter;
use RuntimeException;

/**
 * Der Sprachdienst für Lava (L-14), unabhängig vom Transport: Befunde, Vervollständigung, Sprung zur Definition und
 * Hover — nur aus einer Quelle (Dateien, Datenbank, mit Editor-Puffern davor) und einer Stelle. Der LSP-Server
 * spricht ihn über stdio an, der Code-Editor im Backend über HTTP (L-17); beide sehen denselben Compiler und
 * dieselbe Registrierung wie «basalto:check».
 *
 * Zeilen und Spalten zählen ab 1, Spalten in Bytes — wie in Diagnostic und Definition. Die Umrechnung in die
 * Zählweise eines Editors (0-basiert, UTF-16) macht die Transportschicht, siehe Columns.
 *
 * @phpstan-type Completion array{label: string, kind: string, detail: string, documentation: string|null}
 * @phpstan-type Location array{file: string, line: int, column: int}
 */
final class LanguageService
{
    /** Art der Vorschläge: Schlüsselwort, Eigenschaft, Feldtyp, Funktion/Filter, Tag, Verweis, fester Wert. */
    public const KEYWORD = 'keyword';

    public const PROPERTY = 'property';

    public const FIELD_TYPE = 'fieldType';

    public const FUNCTION = 'function';

    public const TAG = 'tag';

    public const REFERENCE = 'reference';

    public const VALUE = 'value';

    /** Unterelemente, deren Block Markup-Zäune haben darf. */
    private const WITH_MARKUP = ['layout', 'nav', 'block', 'template'];

    /** @var array<string, string> Art eines Namens → Bezeichnung */
    private const KIND_LABELS = [
        'block' => 'Block', 'part' => 'Teil', 'layout' => 'Layout', 'template' => 'Template', 'type' => 'Typ',
        'nav' => 'Navigation', 'form' => 'Formular', 'global' => 'Globaler Inhalt', 'page' => 'Seite (seed)',
    ];

    public function __construct(private readonly Registry $registry) {}

    public function registry(): Registry
    {
        return $this->registry;
    }

    /**
     * Kompiliert das Theme wie «basalto:check» und ordnet die Befunde ihren Dateien zu.
     *
     * @return array<string, list<Diagnostic>> relativer Pfad → Befunde in der Reihenfolge des Compilers
     */
    public function diagnostics(Source $source, string $entry = 'theme.lava'): array
    {
        $compiler = new Compiler($this->registry);
        try {
            $compiler->compile($source, $entry);
        } catch (RuntimeException $e) {
            return [$entry => [new Diagnostic(Diagnostic::ERROR, $e->getMessage(), $entry, 1, 1)]];
        }
        $byFile = [];
        foreach ($compiler->diagnostics as $diagnostic) {
            $byFile[$diagnostic->file][] = $diagnostic;
        }

        return $byFile;
    }

    /** Die Meldung eines Befunds wie in «basalto:check», ohne den Ort (den zeigt der Editor selbst). */
    public static function message(Diagnostic $diagnostic): string
    {
        $step = $diagnostic->step !== null ? "[{$diagnostic->step}] " : '';
        $suggestion = $diagnostic->suggestion !== null ? " — meinten Sie «{$diagnostic->suggestion}»?" : '';

        return $step.$diagnostic->message.$suggestion;
    }

    /**
     * Vorschläge an einer Stelle — Schlüsselwörter, Eigenschaften, Feldtypen, Filter, Funktionen, Tags und Namen
     * aus dem Theme, je nachdem, wo der Cursor steht. Gefiltert wird im Editor.
     *
     * @return list<Completion>
     */
    public function completions(Source $source, string $path, int $line, int $column, string $entry = 'theme.lava'): array
    {
        $cursor = Cursor::at($path, $source->read($path), $line, $column);

        return match ($cursor->slot) {
            Cursor::KEYWORD => $this->keywordCompletions($line),
            Cursor::ITEM => $this->itemCompletions($cursor, $source, $entry),
            Cursor::FIELD_TYPE => $this->fieldTypeCompletions($cursor->inForm()),
            Cursor::PART_KIND => array_map(static fn (string $kind, array $spec): array => self::item($kind, self::VALUE, sprintf('Teil · %s (%s)', $spec['label'], $spec['origin'])),
                array_keys($this->registry->parts()), array_values($this->registry->parts())),
            Cursor::NAMES => $this->nameCompletions($cursor, $source, $entry),
            Cursor::TAG => $this->tagCompletions(),
            Cursor::FILTER, Cursor::FUNCTION => array_map(fn (string $name): array => self::item($name, self::FUNCTION, $this->signature($name) ?? 'Funktion', Vocabulary::FUNCTIONS[$name] ?? null),
                $this->registry->functionNames()),
            default => [],
        };
    }

    /**
     * Wo ist der Name unter dem Cursor definiert? Datei, Zeile und Spalte aus dem kompilierten Dokument.
     *
     * @return Location|null
     */
    public function definition(Source $source, string $path, int $line, int $column, string $entry = 'theme.lava'): ?array
    {
        $text = $source->read($path);
        $word = Cursor::wordAt($text, $line, $column);
        if ($word === null) {
            return null;
        }
        $cursor = Cursor::at($path, $text, $line, $word['column']);
        if (in_array($cursor->slot, [Cursor::KEYWORD, Cursor::FIELD_TYPE, Cursor::PART_KIND, Cursor::TAG, Cursor::FILTER], true)) {
            return null;
        }
        $kinds = match (true) {
            in_array($cursor->slot, [Cursor::NAMES, Cursor::HEADING], true) && $cursor->kinds !== [] => $cursor->kinds,
            $cursor->slot === Cursor::ITEM && ($cursor->parent()['kind'] ?? null) === 'content' => ['block'],
            default => ThemeIndex::KINDS,
        };
        $found = $this->index($source, $entry)->lookup($word['word'], $kinds);

        return $found === null ? null : ['file' => $found['entry']['file'], 'line' => $found['entry']['line'], 'column' => $found['entry']['column']];
    }

    /** Kurze Erklärung (Markdown) zum Wort unter dem Cursor — Schlüsselwort, Feldtyp, Filter, Funktion, Tag oder Name. */
    public function hover(Source $source, string $path, int $line, int $column, string $entry = 'theme.lava'): ?string
    {
        $text = $source->read($path);
        $word = Cursor::wordAt($text, $line, $column);
        if ($word === null) {
            return null;
        }
        $name = $word['word'];
        $cursor = Cursor::at($path, $text, $line, $word['column']);

        switch ($cursor->slot) {
            case Cursor::KEYWORD:
                return Vocabulary::keyword($name);
            case Cursor::FIELD_TYPE:
                return $this->fieldTypeHover($name, $cursor->inForm());
            case Cursor::PART_KIND:
                $spec = $this->registry->parts()[$name] ?? null;

                return $spec === null ? null : sprintf("**%s** — Art eines Teils: %s (%s)\n\nEinstellungen: %s", $name, $spec['label'], $spec['origin'], $spec['settings'] === [] ? 'keine' : implode(', ', $spec['settings']));
            case Cursor::TAG:
                return $this->tagHover($name);
            case Cursor::FILTER:
            case Cursor::FUNCTION:
                return $this->functionHover($name, $cursor->slot === Cursor::FILTER);
            case Cursor::ITEM:
                if (isset(Vocabulary::ELEMENTS[$name]) && ($cursor->parent()['kind'] ?? null) !== 'content') {
                    return sprintf('**%s** — %s', $name, Vocabulary::ELEMENTS[$name]);
                }
                break;
        }
        $kinds = in_array($cursor->slot, [Cursor::NAMES, Cursor::HEADING], true) && $cursor->kinds !== [] ? $cursor->kinds : ThemeIndex::KINDS;
        if ($cursor->slot === Cursor::ITEM && ($cursor->parent()['kind'] ?? null) === 'content') {
            $kinds = ['block'];
        }
        if (in_array($cursor->slot, [Cursor::NAMES, Cursor::HEADING, Cursor::ITEM], true)) {
            return $this->nameHover($this->index($source, $entry), $name, $kinds);
        }

        return null;
    }

    /**
     * Die Namen des Themes: aus dem kompilierten Dokument, sonst Datei für Datei.
     */
    public function index(Source $source, string $entry = 'theme.lava'): ThemeIndex
    {
        $compiler = new Compiler($this->registry);
        try {
            $theme = $compiler->compile($source, $entry);
        } catch (RuntimeException) {
            $theme = null;
        }
        if ($theme instanceof Theme) {
            return ThemeIndex::fromTheme($theme);
        }
        $files = [];
        foreach ($source->paths() as $path) {
            if (str_ends_with($path, '.lava')) {
                $files[$path] = $source->read($path);
            }
        }

        return ThemeIndex::fromFiles($files);
    }

    /**
     * Die Signatur einer registrierten Funktion oder eines Filters aus ihrer Closure, z. B.
     * «truncate(v: mixed, n: int = 100, end: string = "…"): string».
     */
    public function signature(string $name): ?string
    {
        $closure = $this->registry->functionClosure($name);
        if ($closure === null) {
            return null;
        }
        $reflection = new ReflectionFunction($closure);
        $parameters = array_map(static function (ReflectionParameter $p): string {
            $type = $p->getType();
            $text = $p->getName().($type !== null ? ': '.$type : '');
            if ($p->isDefaultValueAvailable()) {
                $default = $p->getDefaultValue();
                $text .= ' = '.(is_string($default) ? '"'.$default.'"' : (string) json_encode($default, JSON_UNESCAPED_UNICODE));
            }

            return $text;
        }, $reflection->getParameters());
        $return = $reflection->getReturnType();

        return sprintf('%s(%s)%s', $name, implode(', ', $parameters), $return !== null ? ': '.$return : '');
    }

    /** @return list<Completion> */
    private function keywordCompletions(int $line): array
    {
        $items = [];
        foreach (array_keys(Vocabulary::KEYWORDS) as $keyword) {
            if ($keyword === 'lava' && $line > 3) {
                continue;
            }
            $items[] = self::item($keyword, self::KEYWORD, self::keywordDetail($keyword), Vocabulary::keyword($keyword));
        }

        return $items;
    }

    /** @return list<Completion> */
    private function itemCompletions(Cursor $cursor, Source $source, string $entry): array
    {
        $parent = $cursor->parent();
        if ($parent === null) {
            return [];
        }
        $grandparent = $cursor->grandparent();
        $kind = $parent['kind'];
        $properties = [];
        $children = [];
        if ($grandparent === null && $kind === 'part') {
            $properties = $this->registry->parts()[(string) $parent['typed']]['settings'] ?? [];
        } elseif ($grandparent === null && isset(Validator::SHAPE[$kind])) {
            $properties = array_merge(Validator::SHAPE[$kind]['properties'], $this->registry->properties($kind));
            $children = Validator::SHAPE[$kind]['children'];
            if (in_array($kind, self::WITH_MARKUP, true)) {
                $children[] = 'markup';
            }
            if ($kind === 'seed') {
                $children = array_merge($children, $this->index($source, $entry)->names('type'));
            }
        } elseif ($kind === 'content') {
            return array_map(fn (string $block): array => self::item($block, self::REFERENCE, 'Block'), $this->index($source, $entry)->names('block'));
        } elseif ($kind === 'field') {
            $properties = Vocabulary::FIELD_PROPERTIES;
            if (in_array($parent['typed'], ['group', 'repeater'], true)) {
                $children = ['field'];
            }
        } elseif ($kind === 'images') {
            $children = ['variant'];
        } elseif ($kind === 'variant') {
            $properties = Vocabulary::VARIANT_PROPERTIES;
        } elseif (($grandparent['kind'] ?? null) === 'seed') {
            $index = $this->index($source, $entry);
            $type = $kind === 'page' ? 'page' : $kind;
            $fields = array_map(static fn (Definition $f): string => (string) $f->name, $index->definition('type', $type)?->childrenOf('field') ?? []);
            $properties = array_values(array_unique(array_merge($kind === 'page' ? ['title', 'slug', 'template'] : [], $fields)));
            $children = $kind === 'page' ? ['content'] : [];
        }
        $items = array_map(static fn (string $p): array => self::item($p, self::PROPERTY, sprintf('Eigenschaft von «%s»', $kind)), $properties);
        foreach ($children as $child) {
            $items[] = self::item($child, self::KEYWORD, sprintf('Unterelement von «%s»', $kind), isset(Vocabulary::ELEMENTS[$child]) ? Vocabulary::ELEMENTS[$child] : null);
        }

        return $items;
    }

    /** @return list<Completion> */
    private function fieldTypeCompletions(bool $inForm): array
    {
        $types = $inForm ? Registry::FORM_FIELD_TYPES : $this->registry->fieldTypes();

        return array_map(static fn (string $type): array => self::item($type, self::FIELD_TYPE, $inForm ? 'Formular-Feldtyp' : (str_contains($type, '.') ? 'Feldtyp aus Modul' : 'Feldtyp'), Vocabulary::FIELD_TYPES[$type] ?? null), $types);
    }

    /** @return list<Completion> */
    private function nameCompletions(Cursor $cursor, Source $source, string $entry): array
    {
        $items = array_map(static fn (string $value): array => self::item($value, self::VALUE, 'Wert'), $cursor->values);
        if ($cursor->kinds === []) {
            return $items;
        }
        $index = $this->index($source, $entry);
        foreach ($cursor->kinds as $kind) {
            foreach ($index->names($kind) as $name) {
                $entryOf = $index->find($kind, $name);
                $items[] = self::item($name, self::REFERENCE, self::KIND_LABELS[$kind].($entryOf !== null && $entryOf['label'] !== null ? ' · '.$entryOf['label'] : ''));
            }
        }

        return $items;
    }

    /** @return list<Completion> */
    private function tagCompletions(): array
    {
        $names = array_values(array_unique(array_merge(Vocabulary::BUILTIN_TAGS, $this->registry->tagNames())));

        return array_map(static fn (string $tag): array => self::item($tag, self::TAG, 'Tag', Vocabulary::TAGS[$tag] ?? null), $names);
    }

    private function fieldTypeHover(string $type, bool $inForm): ?string
    {
        $known = $inForm ? in_array($type, Registry::FORM_FIELD_TYPES, true) : $this->registry->hasFieldType($type);
        if (! $known) {
            return null;
        }
        $text = Vocabulary::FIELD_TYPES[$type] ?? sprintf('Feldtyp aus dem Modul «%s».', explode('.', $type)[0]);

        return sprintf("**%s** — %s\n\n%s", $type, $inForm ? 'Formular-Feldtyp' : 'Feldtyp', $text);
    }

    private function tagHover(string $tag): ?string
    {
        if (isset(Vocabulary::TAGS[$tag])) {
            return sprintf("**%s** — Tag\n\n%s", $tag, Vocabulary::TAGS[$tag]);
        }

        return $this->registry->hasTag($tag) ? sprintf('**%s** — Tag aus dem Modul «%s»', $tag, explode('.', $tag)[0]) : null;
    }

    private function functionHover(string $name, bool $asFilter): ?string
    {
        $signature = $this->signature($name);
        if ($signature === null) {
            return null;
        }
        $text = sprintf("```\n%s\n```", $signature);
        $about = Vocabulary::FUNCTIONS[$name] ?? (str_contains($name, '.') ? sprintf('Aus dem Modul «%s».', explode('.', $name)[0]) : null);
        if ($about !== null) {
            $text .= "\n\n".$about;
        }

        return $text."\n\n".($asFilter
            ? 'Als Filter bekommt die Funktion den Wert links von «|» als erstes Argument.'
            : sprintf('Auch als Filter: «{{ wert | %s }}».', $name));
    }

    /** @param  list<string>  $kinds */
    private function nameHover(ThemeIndex $index, string $name, array $kinds): ?string
    {
        $found = $index->lookup($name, $kinds);
        if ($found === null) {
            return null;
        }
        ['kind' => $kind, 'entry' => $entry] = $found;
        $text = sprintf('**%s %s**%s', $kind === 'page' ? 'page' : $kind, $name, $entry['label'] !== null ? ' — '.$entry['label'] : '');
        $definition = $index->definition($kind, $name);
        $fields = $definition !== null ? array_map(static fn (Definition $f): string => sprintf('%s (%s)', $f->name, $f->typedFirst() ?? '?'), $definition->childrenOf('field')) : [];
        if ($fields !== []) {
            $text .= "\n\nFelder: ".implode(', ', $fields);
        }

        return $text.sprintf("\n\n%s · %s, Zeile %d", self::KIND_LABELS[$kind], $entry['file'], $entry['line']);
    }

    private static function keywordDetail(string $keyword): string
    {
        $letter = Step::letterFor($keyword);

        return $letter !== null ? sprintf('Station %s', $letter) : 'Lava';
    }

    /** @return Completion */
    private static function item(string $label, string $kind, string $detail, ?string $documentation = null): array
    {
        return ['label' => $label, 'kind' => $kind, 'detail' => $detail, 'documentation' => $documentation];
    }
}
