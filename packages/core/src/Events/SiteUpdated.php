<?php

declare(strict_types=1);

namespace Basalto\Core\Events;

use Basalto\Core\Models\Site;

/**
 * Domänenereignis (ADR-011): Stammdaten einer Site geändert — alt und neu als Felder für Protokoll und Cache.
 *
 * @param  array<string, mixed>  $before
 * @param  array<string, mixed>  $after
 */
final class SiteUpdated
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(public readonly Site $site, public readonly array $before, public readonly array $after) {}
}
