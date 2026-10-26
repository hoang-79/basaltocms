<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts;

use Basalto\Core\Models\Site;

/**
 * TB 13 Scheibe 2: Ob ein Modul, das das Frontend-Konto nutzt, auf einer Site neue Konten zulässt. Ohne Regel lässt
 * ein Modul sie zu (Lizenzverkauf, Marktplatz: das Konto entsteht mit dem ersten richtigen Code). Die Mitglieder
 * schliessen sie bei «nur auf Einladung» — dann sendet die Anmeldung Codes nur an Adressen mit Konto.
 */
interface RegistrationPolicy
{
    public function opensRegistration(Site $site): bool;
}
