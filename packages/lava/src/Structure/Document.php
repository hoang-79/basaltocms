<?php

declare(strict_types=1);

namespace Basalto\Lava\Structure;

/** Eine geparste Lava-Datei: Versionszeile, Definitionen der obersten Ebene, Einbindungen. */
final class Document
{
    /** @var list<Definition> */
    public array $definitions = [];

    /** @var list<array{file: string, line: int, column: int}> */
    public array $includes = [];

    public ?int $version = null;

    public function __construct(public readonly string $file) {}

    /** @return list<Definition> */
    public function all(string $kind): array
    {
        return array_values(array_filter($this->definitions, static fn (Definition $d): bool => $d->kind === $kind));
    }

    public function find(string $kind, ?string $name = null): ?Definition
    {
        foreach ($this->definitions as $d) {
            if ($d->kind === $kind && ($name === null || $d->name === $name)) {
                return $d;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function names(string $kind): array
    {
        return array_values(array_filter(array_map(static fn (Definition $d): ?string => $d->name, $this->all($kind))));
    }

    /**
     * Definitionen einer eingebundenen Datei übernehmen. Eine benannte Definition, die es mit Art und Name schon gibt,
     * ersetzt die frühere an deren Stelle — so kann die Lava einer Site (am Ende eingebunden) etwa das Menü «main» des
     * Themes ersetzen. Unbenannte Definitionen (`seed`) kommen dazu.
     */
    public function merge(Document $other): void
    {
        foreach ($other->definitions as $definition) {
            if ($definition->name !== null) {
                foreach ($this->definitions as $i => $existing) {
                    if ($existing->kind === $definition->kind && $existing->name === $definition->name) {
                        $this->definitions[$i] = $definition;

                        continue 2;
                    }
                }
            }
            $this->definitions[] = $definition;
        }
    }
}
