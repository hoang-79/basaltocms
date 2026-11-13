<?php

declare(strict_types=1);

namespace Basalto\Core\Business;

/** Eine Spalte eines Boards aus einem anderen Plugin (CR-020) — z. B. ein Stand der Aufgaben eines Projekts. */
final readonly class CardColumn
{
    /** @param  bool  $done  Karten hier gelten als erledigt */
    public function __construct(public string $key, public string $title, public bool $done = false) {}
}
