<?php

declare(strict_types=1);

namespace Basalto\Core\Sites;

use Basalto\Core\Models\Site;

/**
 * Die Site der laufenden Anfrage oder Aufgabe (ein Singleton je Anwendung). Ist keine gesetzt, gelten Abfragen
 * installationsweit (Konsole, Wartung); in HTTP-Anfragen setzt sie eine Middleware oder bricht ab (K-02).
 */
final class CurrentSite
{
    private ?Site $site = null;

    public function set(?Site $site): void
    {
        $this->site = $site;
    }

    public function get(): ?Site
    {
        return $this->site;
    }

    public function id(): ?int
    {
        return $this->site?->id;
    }

    public function require(): Site
    {
        return $this->site ?? throw new NoCurrentSiteException('Keine Site für diese Anfrage gesetzt');
    }

    public function forget(): void
    {
        $this->site = null;
    }

    /** Führt eine Closure im Kontext einer Site aus und stellt danach den vorherigen Kontext wieder her. */
    public function within(Site $site, \Closure $callback): mixed
    {
        $previous = $this->site;
        $this->site = $site;
        try {
            return $callback($site);
        } finally {
            $this->site = $previous;
        }
    }
}
