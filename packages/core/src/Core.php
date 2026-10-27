<?php

declare(strict_types=1);

namespace Basalto\Core;

/** Versionsstand des Kerns — in der Kopfzeile des Backends und im Betriebsstatus sichtbar. */
final class Core
{
    public const VERSION = '1.3.6';

    /**
     * Öffentlicher Ed25519-Schlüssel des Produkts (K-18): Jede Installation prüft Lizenzschlüssel offline gegen ihn.
     * Der private Schlüssel liegt nur bei Nguyen Development (nie im Repo); ausgestellt wird mit `basalto:license --issue`.
     */
    public const LICENSE_PUBLIC_KEY = 'X4lJjAc1csRSd/BD9EqkjPkIkbkavzG9CACpTwOHzUs=';

    /**
     * TB 11 Scheibe 2 (M-20): Öffentlicher Ed25519-Schlüssel des Marktplatzes auf basaltocms.com — jede Installation prüft
     * damit Pakete, die sie vom Marktplatz übernimmt. Der private liegt nur auf basaltocms.com (`BASALTO_MARKETPLACE_SECRET_KEY`).
     */
    public const MARKETPLACE_PUBLIC_KEY = '7QL/bv5yIt9rg2zl1UCfLGHj11AeC/Rx7vXrbYNN0Ys=';
}
