<?php

declare(strict_types=1);

namespace Basalto\Core\Events;

use Basalto\Core\Models\Site;

/** Domänenereignis (ADR-011): eine Site wurde angelegt — Module hängen Seeds, Standardeinstellungen oder Protokoll daran. */
final class SiteCreated
{
    public function __construct(public readonly Site $site) {}
}
