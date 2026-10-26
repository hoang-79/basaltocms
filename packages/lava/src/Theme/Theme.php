<?php

declare(strict_types=1);

namespace Basalto\Lava\Theme;

use Basalto\Lava\Structure\Definition;
use Basalto\Lava\Structure\Document;
use LogicException;

/**
 * Ein kompiliertes Theme: das zusammengeführte Dokument plus Zugriff auf Markup aus Zaun oder Datei.
 * Serialisierbar für den Baum-Cache (ADR-013): die Quelle wird nicht mitgeschrieben — nach dem Lesen
 * aus dem Cache setzt withSource() sie wieder.
 */
final class Theme
{
    public readonly Document $document;

    public readonly Source $source;

    public function __construct(Document $document, Source $source)
    {
        $this->document = $document;
        $this->source = $source;
    }

    /** Dasselbe Theme mit einer (neuen) Quelle — nach dem Deserialisieren aus dem Cache. */
    public function withSource(Source $source): self
    {
        return new self($this->document, $source);
    }

    public function hasSource(): bool
    {
        return isset($this->source);
    }

    /** @return array{document: Document} */
    public function __serialize(): array
    {
        return ['document' => $this->document];
    }

    /** @param  array{document: Document}  $data */
    public function __unserialize(array $data): void
    {
        $this->document = $data['document'];
    }

    public function site(): ?Definition
    {
        return $this->document->find('site');
    }

    public function layout(string $name): ?Definition
    {
        return $this->document->find('layout', $name);
    }

    public function block(string $name): ?Definition
    {
        return $this->document->find('block', $name);
    }

    public function template(string $name): ?Definition
    {
        return $this->document->find('template', $name);
    }

    public function nav(string $name): ?Definition
    {
        return $this->document->find('nav', $name);
    }

    public function global(string $name): ?Definition
    {
        return $this->document->find('global', $name);
    }

    /**
     * Startinhalte. Bringt die Site eigene Lava-Dateien mit einem weiteren `seed` mit (z. B. die Funktionsschau der Demo,
     * CR-004), kommen deren Seiten und globalen Inhalte nach denen des Themes dazu — zusammen in einer Definition.
     */
    public function seed(): ?Definition
    {
        $seeds = $this->document->all('seed');
        if (count($seeds) <= 1) {
            return $seeds[0] ?? null;
        }
        $first = $seeds[0];
        $combined = new Definition($first->kind, $first->name, $first->typed, $first->modifiers, $first->file, $first->line, $first->column, $first->quotedName);
        $combined->properties = $first->properties;
        // Gleichnamiges (etwa `page home` oder `global footer`) aus einem späteren Seed ersetzt das frühere an dessen
        // Stelle — so überschreibt die Lava einer Site die Startinhalte ihres Themes (CR-004: Startseite der Demo)
        $positions = [];
        $children = [];
        foreach ($seeds as $seed) {
            foreach ($seed->children as $child) {
                $key = $child->name === null ? null : $child->kind.':'.$child->name;
                if ($key !== null && isset($positions[$key])) {
                    $children[$positions[$key]] = $child;

                    continue;
                }
                if ($key !== null) {
                    $positions[$key] = count($children);
                }
                $children[] = $child;
            }
        }
        $combined->children = array_values($children);

        return $combined;
    }

    /** Markup eines Elements: Datei «<ordner>/<name>.lava.html» gewinnt über den Zaun in der Definition. */
    public function markupOf(Definition $def, string $folder, string $name = 'main'): ?string
    {
        $file = sprintf('%s/%s.lava.html', $folder, $def->name);
        if ($name === 'main' && FileSource::isInside($file) && $this->sourceOrFail()->has($file)) {
            return $this->sourceOrFail()->read($file);
        }

        return $def->markup($name);
    }

    /** Ein eingebundener Teil («include "parts/card"») — nur innerhalb der Quelle, nie darüber hinaus (L-16). */
    public function part(string $name): ?string
    {
        $file = $name.'.lava.html';
        if (! FileSource::isInside($file)) {
            return null;
        }

        return $this->sourceOrFail()->has($file) ? $this->sourceOrFail()->read($file) : null;
    }

    private function sourceOrFail(): Source
    {
        if (! isset($this->source)) {
            throw new LogicException('Theme ohne Quelle — nach dem Lesen aus dem Cache withSource() aufrufen');
        }

        return $this->source;
    }
}
