<?php

declare(strict_types=1);

namespace Basalto\Core\Events;

use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;

/** Domänenereignis (ADR-011): eine neue Sprachfassung wurde gespeichert (Entwurf). */
final class EntrySaved
{
    public function __construct(public readonly Entry $entry, public readonly EntryVersion $version) {}
}
