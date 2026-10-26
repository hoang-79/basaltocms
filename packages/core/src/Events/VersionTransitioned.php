<?php

declare(strict_types=1);

namespace Basalto\Core\Events;

use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Models\EntryVersion;

/** Domänenereignis (ADR-011): eine Fassung hat ihren Zustand gewechselt (M-08) — Protokoll, Cockpit und Benachrichtigungen hängen daran. */
final class VersionTransitioned
{
    public function __construct(public readonly EntryVersion $version, public readonly EntryStatus $from, public readonly EntryStatus $to, public readonly ?string $comment = null) {}
}
