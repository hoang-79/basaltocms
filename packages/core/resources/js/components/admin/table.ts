import type { AdminTableSection } from './types';

/**
 * Tabellen aus Beschreibung (CR-018, TB 99): Breite und Umbruch. Der Browser verteilt die Breite nach dem Inhalt —
 * früher nahm die erste Spalte allen Platz («w-full»), und war sie eine kurze Nummer, brachen alle anderen Spalten Wort
 * für Wort um (CR-020, TB 116).
 */

type ColumnType = AdminTableSection['columns'][number]['type'];

/** Längster Wert ohne Leerzeichen, der noch als «kurz» gilt (Nummern, Codes, Kürzel). */
export const SHORT_TOKEN = 20;

/** Zahlen und Beträge stehen rechtsbündig. */
export function numeric(type: ColumnType): boolean {
    return type === 'number' || type === 'money';
}

/**
 * Bleibt der Wert auf einer Zeile? Immer bei Zahlen, Beträgen, Codes und Ständen; sonst, wenn er kurz ist und kein
 * Leerzeichen hat — eine Nummer wie «2026-0053» bricht sonst am Bindestrich um. Lange Werte ohne Leerzeichen
 * (Adressen, Links) dürfen brechen, damit die Tabelle nicht breiter wird als ihr Platz.
 */
export function keepsTogether(type: ColumnType, text: string): boolean {
    if (numeric(type) || type === 'mono' || type === 'badge') {
        return true;
    }

    return text.length <= SHORT_TOKEN && !/\s/.test(text);
}
