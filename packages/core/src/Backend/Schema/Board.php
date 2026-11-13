<?php

declare(strict_types=1);

namespace Basalto\Core\Backend\Schema;

/**
 * Ein Board nach der Kanban-Methode auf einer Verwaltungsseite aus Beschreibung (CR-020): Spalten mit Karten, auf
 * Wunsch mit WIP-Limit (höchstens so viele Karten gleichzeitig). Die Seite des Kerns verschiebt Karten mit Ziehen und
 * Ablegen oder über die Tastatur und schickt `card`, `column` und `position` (ab 0) als POST an `$moveUrl`; der
 * Controller des Plugins prüft und speichert. Mit `add()` gibt es unter jeder Spalte «Karte hinzufügen» (POST mit
 * `column` und `title`). Die Spalten brechen auf schmalen Bildschirmen um, statt waagrecht zu rollen.
 *
 * ```php
 * $page->board(Board::make(route('…move'))->add(route('…store'), $addLabel)
 *     ->column('doing', $title, [Card::make(7, 'Logo')->due('14.11.2026')], limit: 3));
 * ```
 */
final class Board
{
    private ?string $title = null;

    /** @var list<array{key: string, title: string, limit: int|null, done: bool, cards: list<array<string, mixed>>}> */
    private array $columns = [];

    /** @var array{url: string, label: string}|null */
    private ?array $add = null;

    /** @var array{title: string, text: string|null} */
    private array $empty = ['title' => '', 'text' => null];

    private function __construct(private readonly string $moveUrl, private readonly string $key) {}

    /** `$key` unterscheidet mehrere Boards auf einer Seite. */
    public static function make(string $moveUrl, string $key = 'board'): self
    {
        return new self($moveUrl, $key);
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Eine Spalte mit ihren Karten in der Reihenfolge von oben nach unten.
     *
     * @param  list<Card>  $cards
     * @param  int|null  $limit  WIP-Limit — null oder 0: ohne
     * @param  bool  $done  Karten hier gelten als erledigt (die Fälligkeit wird nicht mehr hervorgehoben)
     */
    public function column(string $key, string $title, array $cards = [], ?int $limit = null, bool $done = false): self
    {
        $this->columns[] = ['key' => $key, 'title' => $title, 'limit' => $limit !== null && $limit > 0 ? $limit : null, 'done' => $done,
            'cards' => array_map(static fn (Card $card): array => $card->toArray(), $cards)];

        return $this;
    }

    /** «Karte hinzufügen» unter jeder Spalte: POST an `$url` mit `column` und `title`. */
    public function add(string $url, string $label): self
    {
        $this->add = ['url' => $url, 'label' => $label];

        return $this;
    }

    /** Was das Board ohne Spalten sagt. */
    public function empty(string $title, ?string $text = null): self
    {
        $this->empty = ['title' => $title, 'text' => $text];

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['kind' => 'board', 'key' => $this->key, 'title' => $this->title, 'move_url' => $this->moveUrl, 'add' => $this->add, 'columns' => $this->columns, 'empty' => $this->empty];
    }

    /**
     * Darf eine Karte aus `$from` in die Spalte `$to` mit `$count` Karten und dem WIP-Limit `$limit`? Innerhalb
     * derselben Spalte immer; sonst nur unter dem Limit. Für die Controller der Plugins.
     */
    public static function allows(?int $limit, int $count, string $from, string $to): bool
    {
        return $from === $to || $limit === null || $limit <= 0 || $count < $limit;
    }
}
