<?php

declare(strict_types=1);

namespace Basalto\Core\Events;

use Basalto\Core\Models\SiteDomain;

/** Domänenereignis (ADR-011, CR-017): Basalto hat eine Unterdomain beim Hoster eingerichtet, sie antwortet über https. */
final class HostnameReady
{
    public function __construct(public readonly SiteDomain $domain) {}
}
