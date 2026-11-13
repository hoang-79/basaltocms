<?php

declare(strict_types=1);

namespace Basalto\Core\Business;

use DateTimeInterface;

/** Eine Karte eines Boards aus einem anderen Plugin (CR-020) — z. B. eine Aufgabe eines Projekts. */
final readonly class CardData
{
    /**
     * @param  string  $column  Schlüssel der {@see CardColumn}
     * @param  list<string>  $meta  kurze Angaben unter dem Titel («CR-003», «4 h geschätzt»)
     * @param  list<array{text: string, tone: string}>  $labels
     */
    public function __construct(
        public string $id,
        public string $column,
        public string $title,
        public int $position = 0,
        public ?string $url = null,
        public array $meta = [],
        public ?DateTimeInterface $dueOn = null,
        public string $assignee = '',
        public array $labels = [],
    ) {}
}
