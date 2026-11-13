<?php

declare(strict_types=1);

namespace Basalto\Core\Business;

use Basalto\Core\Models\Site;

/**
 * Vertrag «Kartenquelle» (CR-020): Ein Plugin zeigt Datensätze als Karten auf einem Board — das Projektmanagement die
 * Aufgaben eines Projekts. Das Kanban-Board zeichnet sie mit Ziehen und Ablegen und WIP-Limits; verschoben wird über
 * {@see move()}, die Daten bleiben beim Plugin. Angemeldet mit {@see Connections::provideCardSource()}.
 */
interface CardSource
{
    public function enabled(Site $site): bool;

    /** Titel des Boards zu `$key` (z. B. der ID eines Projekts) — null, wenn es das nicht gibt. */
    public function title(Site $site, string $key): ?string;

    /** @return list<CardColumn> */
    public function columns(Site $site, string $key): array;

    /** @return list<CardData> */
    public function cards(Site $site, string $key): array;

    /** Karte `$card` in Spalte `$column` an Stelle `$position` (ab 0) — false, wenn das nicht geht. */
    public function move(Site $site, string $key, string $card, string $column, int $position): bool;

    /** Zurück zur Quelle (z. B. zur Seite des Projekts). */
    public function url(Site $site, string $key): ?string;
}
