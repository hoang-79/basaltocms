<?php

declare(strict_types=1);

namespace Basalto\Core\Business;

use Basalto\Core\Models\Site;

/**
 * Vertrag «Projekte» (CR-020): Das Projektmanagement zeigt seine Projekte anderen Plugins — das Kanban-Board hängt
 * Karten daran. Angemeldet mit {@see Connections::provideProjects()}; Umbenennungen meldet es mit
 * {@see Events\ProjectUpdated}.
 */
interface ProjectDirectory
{
    public function enabled(Site $site): bool;

    /**
     * Laufende Projekte zur Auswahl, auf Wunsch nur die eines Partners.
     *
     * @return list<ProjectData>
     */
    public function search(Site $site, string $term = '', ?int $partnerId = null, int $limit = 25): array;

    public function find(Site $site, int $id): ?ProjectData;

    /**
     * @param  list<int>  $ids
     * @return array<int, ProjectData>
     */
    public function findMany(Site $site, array $ids): array;

    public function url(Site $site, int $id): ?string;
}
