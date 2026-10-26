<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Hub;

/**
 * Ein Bereich des Kundenkontos (TB 48): Module steuern ihn bei — Lizenzen, Installationen, Käufe im Marktplatz,
 * Community, Sites in der Cloud. Das Konto fragt jeden Bereich nach seinem Stand (Wabe, «Als Nächstes», «Zuletzt»)
 * und gibt dann seinen Abschnitt aus. Registriert über {@see AccountAreas::add()}; je Anfrage frisch erzeugt, darf
 * also zwischen `summary()` und `render()` Daten halten.
 */
interface AccountArea
{
    /** Kennung und Anker (`#konto-<key>`): Kleinbuchstaben, ohne Leerzeichen. */
    public function key(): string;

    /** Reihenfolge — kleinere zuerst (Sites 10, Lizenzen 20, Installationen 30, Marktplatz 40, Community 50, Rechnungen 60, Profil 90). */
    public function position(): int;

    /** Für Gäste: was der Bereich bringt — null, wenn er auf dieser Site nichts zu bieten hat. */
    public function pitch(AccountPage $page): ?AreaPitch;

    /** Angemeldet: der Stand des Bereichs — null blendet ihn ganz aus. */
    public function summary(AccountPage $page): ?AreaSummary;

    /** Angemeldet: der Inhalt des Abschnitts (Rahmen, Überschrift und Anker setzt das Konto). */
    public function render(AccountPage $page, AreaSummary $summary): string;
}
