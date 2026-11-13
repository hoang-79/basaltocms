<?php

declare(strict_types=1);

namespace Basalto\Core\Business\Events;

use Basalto\Core\Business\ProjectData;
use Illuminate\Foundation\Events\Dispatchable;

/** CR-020: Ein Projekt hat sich geändert (Name, Nummer, Kunde, Stand) — Karten mit einer Kopie des Namens gleichen ab. */
final class ProjectUpdated
{
    use Dispatchable;

    public function __construct(public readonly int $siteId, public readonly ProjectData $project) {}
}
