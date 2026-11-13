<?php

declare(strict_types=1);

namespace Basalto\Core\Business;

use Basalto\Core\Models\Site;

/**
 * Vertrag «Rechnungsstellung» (CR-020): Die Finanzbuchhaltung nimmt Rechnungen anderer Plugins als Entwurf entgegen —
 * das Projektmanagement rechnet so erfasste Zeit ab. Angemeldet mit {@see Connections::provideInvoicing()}.
 */
interface Invoicing
{
    public function enabled(Site $site): bool;

    /** Eine Rechnung als Entwurf anlegen; die Buchhaltung prüft, stellt aus und verbucht. */
    public function draft(Site $site, InvoiceDraft $draft): InvoiceData;

    public function find(Site $site, int $id): ?InvoiceData;

    /**
     * @param  list<int>  $ids
     * @return array<int, InvoiceData>
     */
    public function findMany(Site $site, array $ids): array;
}
