<?php

declare(strict_types=1);

namespace Basalto\Core\Events;

use Basalto\Core\Models\EntryVersion;

/** Domänenereignis (ADR-011): eine Fassung ist live — Cache, Sitemap, Suche und Protokoll hängen daran. */
final class VersionPublished
{
    public function __construct(public readonly EntryVersion $version) {}
}
