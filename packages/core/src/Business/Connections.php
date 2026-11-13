<?php

declare(strict_types=1);

namespace Basalto\Core\Business;

use Basalto\Core\Models\Site;
use Closure;
use Throwable;

/**
 * Verbindungen zwischen Plugins (CR-020): Ein Plugin stellt einen Vertrag bereit — Geschäftspartner (CRM), Projekte
 * (Projektmanagement), Rechnungsstellung (Finanzbuchhaltung), Kartenquellen und Board-Ansicht (Kanban-Board) und
 * verknüpfte Datensätze —, andere fragen
 * hier danach. Fehlt das Plugin oder ist es für die Site aus, liefert die Frage null bzw. nichts: Jedes Plugin läuft
 * allein und schaltet die Verbindung selbst zu, sobald das andere da ist. Kein Plugin greift in die Tabellen eines
 * anderen, und die Reihenfolge, in der Plugins starten, spielt keine Rolle — gefragt wird erst in der Anfrage.
 *
 *     $partners = app(Connections::class)->partners($site); // null ohne CRM
 *     $choices = $partners?->search($site, $term) ?? [];
 *
 * Gefüllt in `boot()` der Plugins, danach nur gelesen.
 */
final class Connections
{
    public const PARTNER = 'partner';

    public const PROJECT = 'project';

    private ?PartnerDirectory $partners = null;

    private ?ProjectDirectory $projects = null;

    private ?Invoicing $invoicing = null;

    private ?BoardViewer $boardViewer = null;

    /** @var array<string, array{label: string, source: CardSource}> */
    private array $cardSources = [];

    /** @var array<string, array<string, Closure(Site, int): iterable<LinkedRecord>>> Art → Schlüssel → Quelle */
    private array $links = [];

    public function providePartners(PartnerDirectory $directory): self
    {
        $this->partners = $directory;

        return $this;
    }

    public function provideProjects(ProjectDirectory $directory): self
    {
        $this->projects = $directory;

        return $this;
    }

    public function provideInvoicing(Invoicing $invoicing): self
    {
        $this->invoicing = $invoicing;

        return $this;
    }

    public function provideBoardViewer(BoardViewer $viewer): self
    {
        $this->boardViewer = $viewer;

        return $this;
    }

    /** Eine Kartenquelle unter `$key` (z. B. «projekte»); `$label` ist der deutsche Text (Schlüssel der Übersetzung). */
    public function provideCardSource(string $key, string $label, CardSource $source): self
    {
        $this->cardSources[$key] = ['label' => $label, 'source' => $source];

        return $this;
    }

    /**
     * Verknüpfte Datensätze zu einem Partner ({@see PARTNER}) oder Projekt ({@see PROJECT}) beisteuern; `$key` nennt
     * die Quelle (meist das Handle des Plugins).
     *
     * @param  Closure(Site, int): iterable<LinkedRecord>  $records
     */
    public function links(string $kind, string $key, Closure $records): self
    {
        $this->links[$kind][$key] = $records;

        return $this;
    }

    /** Das CRM dieser Site — null, wenn keines installiert oder es für die Site aus ist. */
    public function partners(Site $site): ?PartnerDirectory
    {
        return $this->partners !== null && $this->partners->enabled($site) ? $this->partners : null;
    }

    /** Das Projektmanagement dieser Site — null, wenn keines installiert oder es für die Site aus ist. */
    public function projects(Site $site): ?ProjectDirectory
    {
        return $this->projects !== null && $this->projects->enabled($site) ? $this->projects : null;
    }

    /** Die Finanzbuchhaltung dieser Site — null, wenn keine installiert oder sie für die Site aus ist. */
    public function invoicing(Site $site): ?Invoicing
    {
        return $this->invoicing !== null && $this->invoicing->enabled($site) ? $this->invoicing : null;
    }

    public function cardSource(Site $site, string $key): ?CardSource
    {
        $source = $this->cardSources[$key]['source'] ?? null;

        return $source !== null && $source->enabled($site) ? $source : null;
    }

    /**
     * Adresse des Boards zu einer Kartenquelle — null, wenn kein Kanban-Board installiert oder für die Site aus ist
     * oder die Quelle fehlt.
     */
    public function boardUrl(Site $site, string $source, string $key): ?string
    {
        if ($this->boardViewer === null || ! $this->boardViewer->enabled($site) || $this->cardSource($site, $source) === null) {
            return null;
        }

        return $this->boardViewer->url($site, $source, $key);
    }

    /** @return array<string, string> Schlüssel → deutscher Text der eingeschalteten Kartenquellen */
    public function cardSources(Site $site): array
    {
        $out = [];
        foreach ($this->cardSources as $key => $entry) {
            if ($entry['source']->enabled($site)) {
                $out[$key] = $entry['label'];
            }
        }

        return $out;
    }

    /**
     * Alle verknüpften Datensätze zu einem Partner oder Projekt. Scheitert eine Quelle, fehlt nur sie (der Fehler
     * landet im Log) — die Seite des Partners bleibt benutzbar.
     *
     * @return list<LinkedRecord>
     */
    public function linked(Site $site, string $kind, int $id): array
    {
        $out = [];
        foreach ($this->links[$kind] ?? [] as $records) {
            try {
                foreach ($records($site, $id) as $record) {
                    $out[] = $record;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $out;
    }
}
