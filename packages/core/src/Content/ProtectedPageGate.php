<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

use Basalto\Core\Models\Site;
use Illuminate\Http\Request;

/**
 * TB 13 Scheibe 2 (M-23): Wer geschützte Seiten sehen darf, entscheidet ein Modul (die Mitglieder) — der Kern kennt
 * nur den Schutz. Ohne Modul sieht sie niemand ausser der Backend-Redaktion der Site; der Schutz bleibt also auch
 * bestehen, wenn das Modul aus ist.
 */
interface ProtectedPageGate
{
    /** Ist das Modul auf dieser Site an — kann also überhaupt jemand Mitglied sein und sich anmelden? */
    public function enabled(Site $site): bool;

    /** Wo die Redaktion die Mitglieder verwaltet (Backend), falls es dort eine Seite gibt. */
    public function adminUrl(): ?string;

    /** Darf, wer diese Anfrage stellt, geschützte Seiten der Site sehen? Nur für eingeschaltetes Modul ja. */
    public function allows(Site $site, Request $request): bool;

    /** Was statt des Inhalts erscheint — Anmeldung, Beitritt oder Hinweis, als HTML in der Sprache der Seite. */
    public function denied(Site $site, Request $request, string $locale, string $path): string;

    /** Die Anmeldeseite des Frontend-Kontos (`GET /account/login`): Anmeldung für Gäste, Stand der Mitgliedschaft sonst. */
    public function login(Site $site, Request $request, string $locale, string $path): string;
}
