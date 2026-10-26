<?php

declare(strict_types=1);

namespace Basalto\Lava\Runtime;

use Basalto\Lava\Markup\Node;

/** Variablen in Gültigkeitsbereichen, Makros, Grenzen und Registrierung eines Renderlaufs. */
final class Context
{
    /** @var list<array<string, mixed>> */
    private array $scopes;

    /** @var array<string, Node> */
    public array $macros = [];

    /** @var array<string, true> Namen, die «set» im Lauf belegt hat — sie tragen keine Feldmarke mehr */
    private array $assigned = [];

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, array{path: string, kind: string}>  $marks  Feldmarken (Frontend-Editor, M-19): Ausdruck
     *                                                                   («title», «page.title») → Pfad und Art des Feldes; leer für Gäste
     */
    public function __construct(array $variables, public readonly Registry $registry, public readonly Limits $limits, public readonly string $file = 'markup', public readonly array $marks = [])
    {
        $this->scopes = [$variables];
    }

    /** Kommt der Name aus den Variablen des Laufs selbst — nicht aus einer Schleife, einem Makro, einem «include … with» oder «set»? */
    public function isFromBase(string $name): bool
    {
        if (isset($this->assigned[$name])) {
            return false;
        }
        for ($i = count($this->scopes) - 1; $i > 0; $i--) {
            if (array_key_exists($name, $this->scopes[$i])) {
                return false;
            }
        }

        return array_key_exists($name, $this->scopes[0]);
    }

    public function get(string $name): mixed
    {
        for ($i = count($this->scopes) - 1; $i >= 0; $i--) {
            if (array_key_exists($name, $this->scopes[$i])) {
                return $this->scopes[$i][$name];
            }
        }

        return null;
    }

    public function has(string $name): bool
    {
        for ($i = count($this->scopes) - 1; $i >= 0; $i--) {
            if (array_key_exists($name, $this->scopes[$i])) {
                return true;
            }
        }

        return false;
    }

    public function set(string $name, mixed $value): void
    {
        $this->assigned[$name] = true;
        $this->scopes[count($this->scopes) - 1][$name] = $value;
    }

    /** @param  array<string, mixed>  $variables */
    public function push(array $variables = []): void
    {
        $this->scopes[] = $variables;
    }

    public function pop(): void
    {
        array_pop($this->scopes);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge(...$this->scopes);
    }
}
