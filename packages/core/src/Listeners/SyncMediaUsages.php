<?php

declare(strict_types=1);

namespace Basalto\Core\Listeners;

use Basalto\Core\Events\EntrySaved;
use Basalto\Core\Media\MediaUsages;

/** Führt den Verwendungsnachweis der Mediathek (K-08) bei jedem Speichern einer Fassung nach. */
final class SyncMediaUsages
{
    public function __construct(private readonly MediaUsages $usages) {}

    public function handle(EntrySaved $event): void
    {
        $this->usages->sync($event->entry, $event->version);
    }
}
