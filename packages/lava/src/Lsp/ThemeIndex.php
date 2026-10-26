<?php

declare(strict_types=1);

namespace Basalto\Lava\Lsp;

use Basalto\Lava\Diagnostics\LavaSyntaxException;
use Basalto\Lava\Structure\Definition;
use Basalto\Lava\Structure\Parser;
use Basalto\Lava\Theme\Theme;
use RuntimeException;

/**
 * Die Namen eines Themes mit Datei und Zeile: Blöcke, Teile, Layouts, Templates, Typen, Navigationen, Formulare,
 * Globale und die Seiten aus «seed». Normalerweise aus dem kompilierten Dokument; scheitert das Kompilieren (der
 * Editor ist mitten in einer Änderung), wird jede .lava-Datei einzeln gelesen — notfalls nur die Köpfe der Definitionen.
 *
 * @phpstan-type Entry array{file: string, line: int, column: int, label: string|null}
 */
final class ThemeIndex
{
    /** @var list<string> Arten mit Namen, auf die verwiesen werden kann */
    public const KINDS = ['block', 'part', 'layout', 'template', 'type', 'nav', 'form', 'global', 'page'];

    /** @var array<string, array<string, Entry>> Art → Name → Ort */
    private array $entries = [];

    private function __construct(public readonly ?Theme $theme) {}

    public static function fromTheme(Theme $theme): self
    {
        $index = new self($theme);
        $index->addAll($theme->document->definitions);

        return $index;
    }

    /**
     * Notbehelf ohne kompiliertes Theme: jede .lava-Datei der Quelle für sich.
     *
     * @param  array<string, string>  $files  relativer Pfad → Inhalt
     */
    public static function fromFiles(array $files): self
    {
        $index = new self(null);
        foreach ($files as $path => $text) {
            try {
                $index->addAll(Parser::parse($text, $path)->definitions);
            } catch (LavaSyntaxException|RuntimeException) {
                $index->scanHeads($path, $text);
            }
        }

        return $index;
    }

    /** @return list<string> */
    public function names(string $kind): array
    {
        return array_map('strval', array_keys($this->entries[$kind] ?? []));
    }

    /** @return Entry|null */
    public function find(string $kind, string $name): ?array
    {
        return $this->entries[$kind][$name] ?? null;
    }

    /**
     * Die erste Art, unter der ein Name vorkommt — in der Reihenfolge der Arten.
     *
     * @param  list<string>  $kinds
     * @return array{kind: string, entry: Entry}|null
     */
    public function lookup(string $name, array $kinds = self::KINDS): ?array
    {
        foreach ($kinds as $kind) {
            if (($entry = $this->find($kind, $name)) !== null) {
                return ['kind' => $kind, 'entry' => $entry];
            }
        }

        return null;
    }

    /** Die Definition eines Namens im kompilierten Theme (für Hover: Felder, Beschriftung). */
    public function definition(string $kind, string $name): ?Definition
    {
        if ($this->theme === null) {
            return null;
        }
        if ($kind === 'page') {
            return $this->theme->seed()?->child('page', $name);
        }

        return $this->theme->document->find($kind, $name);
    }

    /** @param  list<Definition>  $definitions */
    private function addAll(array $definitions): void
    {
        foreach ($definitions as $def) {
            if ($def->kind === 'seed') {
                foreach ($def->childrenOf('page') as $page) {
                    $this->add('page', $page);
                }

                continue;
            }
            if (in_array($def->kind, self::KINDS, true)) {
                $this->add($def->kind, $def);
            }
        }
    }

    private function add(string $kind, Definition $def): void
    {
        if ($def->name === null || isset($this->entries[$kind][$def->name])) {
            return;
        }
        $this->entries[$kind][$def->name] = ['file' => $def->file, 'line' => $def->line, 'column' => $def->column, 'label' => $def->value('label') ?? $def->value('title')];
    }

    /** Liest nur die Köpfe «wort name» am Zeilenanfang und die Seiten «page name» — für Dateien mit Syntaxfehler. */
    private function scanHeads(string $path, string $text): void
    {
        foreach (explode("\n", $text) as $i => $line) {
            if (preg_match('/^([a-z]+)\s+([\w\-]+)/u', $line, $m) && in_array($m[1], self::KINDS, true) && $m[1] !== 'page') {
                $this->entries[$m[1]][$m[2]] ??= ['file' => $path, 'line' => $i + 1, 'column' => 1, 'label' => null];
            } elseif (preg_match('/^(\s+)page\s+([\w\-]+)/u', $line, $m)) {
                $this->entries['page'][$m[2]] ??= ['file' => $path, 'line' => $i + 1, 'column' => strlen($m[1]) + 1, 'label' => null];
            }
        }
    }
}
