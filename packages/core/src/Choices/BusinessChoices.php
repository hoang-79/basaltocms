<?php

declare(strict_types=1);

namespace Basalto\Core\Choices;

/**
 * Auswahllisten, die mehrere Geschäfts-Plugins teilen (CR-022). Jedes Plugin meldet sie an und hängt seinen Bereich,
 * sein Modul und seine verwendeten Werte an (`ChoiceList::sharedWith()`, `->used()`).
 */
final class BusinessChoices
{
    public const CONTACT_ROLE = 'business.contact_role';

    /** Funktion einer Kontaktperson — Vorschläge nach Abteilung, eigene Einträge möglich (Wunsch Hoang 15.11.2026). */
    public static function contactRoles(): ChoiceList
    {
        return ChoiceList::text(self::CONTACT_ROLE, static fn (): string => (string) __('Funktion von Kontaktpersonen'), [])
            ->groups(static fn (): array => array_keys(self::roles()))
            ->values(static function (): array {
                $out = [];
                foreach (self::roles() as $group => $roles) {
                    foreach ($roles as $role) {
                        $out[] = ['value' => $role, 'label' => $role, 'group' => $group];
                    }
                }

                return $out;
            });
    }

    /** @return array<string, list<string>> Abteilung → Funktionen, in der Sprache der Oberfläche */
    private static function roles(): array
    {
        return [
            (string) __('Geschäftsleitung') => [(string) __('Inhaber/in'), (string) __('Geschäftsführer/in'), (string) __('CEO'), (string) __('Mitglied der Geschäftsleitung'),
                (string) __('Verwaltungsrat'), (string) __('Assistenz der Geschäftsleitung')],
            (string) __('Finanzen') => [(string) __('Leiter/in Finanzen (CFO)'), (string) __('Buchhaltung'), (string) __('Controlling'), (string) __('Lohnbuchhaltung')],
            (string) __('Einkauf') => [(string) __('Leiter/in Einkauf'), (string) __('Einkäufer/in')],
            (string) __('Verkauf') => [(string) __('Leiter/in Verkauf'), (string) __('Key Account Manager/in'), (string) __('Verkaufsberater/in'), (string) __('Verkaufsinnendienst')],
            (string) __('Marketing und Kommunikation') => [(string) __('Leiter/in Marketing'), (string) __('Kommunikation'), (string) __('Social Media')],
            (string) __('Personal') => [(string) __('Leiter/in Personal (HR)'), (string) __('HR-Fachperson'), (string) __('Rekrutierung')],
            (string) __('Informatik') => [(string) __('Leiter/in Informatik (CIO)'), (string) __('Informatik-Support'), (string) __('Entwickler/in')],
            (string) __('Projekte') => [(string) __('Projektleiter/in'), (string) __('Projektmitarbeiter/in')],
            (string) __('Produktion und Technik') => [(string) __('Betriebsleiter/in'), (string) __('Technische Leitung'), (string) __('Logistik')],
            (string) __('Administration') => [(string) __('Sekretariat'), (string) __('Empfang'), (string) __('Sachbearbeitung')],
            (string) __('Recht und Datenschutz') => [(string) __('Rechtsdienst'), (string) __('Datenschutzberater/in')],
            (string) __('Kundendienst') => [(string) __('Kundendienst'), (string) __('Support')],
        ];
    }
}
