<?php

declare(strict_types=1);

namespace Basalto\Core\Backend\Schema;

/** Eine Zeile einer Liste (CR-018, TB 99): Zellen je Spalte, auf Wunsch eine Adresse zum Öffnen und eigene Knöpfe. */
final class Row
{
    /** @var array<string, array{text: string, tone?: string, url?: string}> */
    private array $cells = [];

    private ?string $url = null;

    /** @var list<Action> */
    private array $actions = [];

    private function __construct(private readonly string|int $id) {}

    public static function make(string|int $id): self
    {
        return new self($id);
    }

    public function cell(string $key, string|int|float|null $text): self
    {
        $this->cells[$key] = ['text' => $text === null ? '' : (string) $text];

        return $this;
    }

    /** Zustand mit Ton: `neutral`, `success`, `warning`, `danger`, `lava`. */
    public function badge(string $key, string $text, string $tone = 'neutral'): self
    {
        $this->cells[$key] = ['text' => $text, 'tone' => $tone];

        return $this;
    }

    public function link(string $key, string $text, string $url): self
    {
        $this->cells[$key] = ['text' => $text, 'url' => $url];

        return $this;
    }

    /** Adresse, die ein Klick auf die erste Spalte öffnet (meist das Bearbeiten). */
    public function url(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function action(Action $action): self
    {
        $this->actions[] = $action;

        return $this;
    }

    /** @return array{id: string|int, cells: array<string, array{text: string, tone?: string, url?: string}>, url: string|null, actions: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'cells' => $this->cells, 'url' => $this->url, 'actions' => array_map(static fn (Action $a): array => $a->toArray(), $this->actions)];
    }
}
