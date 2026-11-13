<?php

declare(strict_types=1);

namespace Basalto\Core\Backend\Schema;

/**
 * Eine Karte eines Boards aus Beschreibung (CR-020): Titel, kurze Angaben, Fälligkeit, verantwortliche Person, Labels
 * und eine Adresse zum Öffnen. Texte kommen übersetzt und formatiert aus dem Plugin.
 */
final class Card
{
    /** @var list<string> */
    private array $meta = [];

    /** @var list<array{text: string, tone: string}> */
    private array $labels = [];

    private ?string $due = null;

    private bool $overdue = false;

    private string $assignee = '';

    private ?string $url = null;

    private function __construct(private readonly string|int $id, private readonly string $title) {}

    public static function make(string|int $id, string $title): self
    {
        return new self($id, $title);
    }

    /** Eine kurze Angabe unter dem Titel (Kunde, Projekt, Schätzung). */
    public function meta(string $text): self
    {
        if (trim($text) !== '') {
            $this->meta[] = $text;
        }

        return $this;
    }

    /** Ein Label mit Ton: `neutral`, `success`, `warning`, `danger` oder `lava`. */
    public function label(string $text, string $tone = 'neutral'): self
    {
        $this->labels[] = ['text' => $text, 'tone' => in_array($tone, ['neutral', 'success', 'warning', 'danger', 'lava'], true) ? $tone : 'neutral'];

        return $this;
    }

    /** Fälligkeit, schon formatiert («14.11.2026»); `$overdue` hebt sie hervor. */
    public function due(?string $date, bool $overdue = false): self
    {
        $this->due = $date;
        $this->overdue = $date !== null && $overdue;

        return $this;
    }

    public function assignee(string $name): self
    {
        $this->assignee = $name;

        return $this;
    }

    public function url(?string $url): self
    {
        $this->url = $url;

        return $this;
    }

    /** @return array{id: string|int, title: string, meta: list<string>, labels: list<array{text: string, tone: string}>, due: string|null, overdue: bool, assignee: string, url: string|null} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'meta' => $this->meta, 'labels' => $this->labels, 'due' => $this->due, 'overdue' => $this->overdue,
            'assignee' => $this->assignee, 'url' => $this->url];
    }
}
