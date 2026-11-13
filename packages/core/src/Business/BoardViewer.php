<?php

declare(strict_types=1);

namespace Basalto\Core\Business;

use Basalto\Core\Models\Site;

/**
 * Vertrag «Board-Ansicht» (CR-020): Das Kanban-Board zeichnet Kartenquellen anderer Plugins ({@see CardSource}) und
 * sagt hier, unter welcher Adresse. Das Projektmanagement fragt über {@see Connections::boardUrl()} — ohne
 * Kanban-Board gibt es keine Adresse, und das Projekt zeigt seine Aufgaben als Liste.
 */
interface BoardViewer
{
    public function enabled(Site $site): bool;

    /** Adresse des Boards zur Kartenquelle `$source` mit dem Schlüssel `$key` im Backend. */
    public function url(Site $site, string $source, string $key): string;
}
