<?php

declare(strict_types=1);

namespace Basalto\Core\Business;

use Basalto\Core\Models\Site;

/**
 * Vertrag «Geschäftspartner» (CR-020): Das CRM stellt seine Kunden, Interessenten und Lieferanten anderen Plugins zur
 * Verfügung — Finanzbuchhaltung, Projektmanagement und Kanban-Board lesen sie hier, statt in fremde Tabellen zu greifen.
 * Angemeldet mit {@see Connections::providePartners()}; gefragt über {@see Connections::partners()}, das null liefert,
 * solange kein CRM installiert oder es für die Site aus ist. Änderungen meldet das CRM mit den Ereignissen
 * {@see Events\PartnerCreated}, {@see Events\PartnerUpdated} und {@see Events\PartnerArchived}.
 */
interface PartnerDirectory
{
    /** Ist das CRM für diese Site eingeschaltet? */
    public function enabled(Site $site): bool;

    /**
     * Partner zur Auswahl: nicht archiviert, nach Name, gefiltert nach Name, Nummer, Ort oder E-Mail.
     *
     * @param  bool  $suppliersOnly  nur Lieferanten (Kreditoren)
     * @return list<PartnerData>
     */
    public function search(Site $site, string $term = '', int $limit = 25, bool $suppliersOnly = false): array;

    /** Ein Partner — auch archiviert; null, wenn es ihn auf dieser Site nicht gibt. */
    public function find(Site $site, int $id): ?PartnerData;

    /**
     * @param  list<int>  $ids
     * @return array<int, PartnerData> ID → Partner (fehlende fehlen)
     */
    public function findMany(Site $site, array $ids): array;

    /**
     * Partner mit dieser E-Mail-Adresse (Partner oder Kontaktperson) — etwa für das Kundenkonto.
     *
     * @return list<PartnerData>
     */
    public function withEmail(Site $site, string $email): array;

    /** Adresse des Partners im Backend. */
    public function url(Site $site, int $id): ?string;

    /** Adresse zum Anlegen eines neuen Partners im Backend. */
    public function createUrl(Site $site): ?string;
}
