/**
 * Nachschlagen und Anlegen ohne Knopf in Formularen der Verwaltungsseiten (CR-021) — die Teile ohne Oberfläche.
 */
export type LookupSuggestion = {
    label: string;
    detail: string;
    values: Record<string, string>;
};

/** Die Anfrage an den Dienst: getrimmt, ab drei Zeichen — sonst keine. */
export function lookupQuery(value: string): string | null {
    const query = value.trim();

    return query.length >= 3 ? query : null;
}

/**
 * Die Werte einer Auswahl als Felder des Formulars — nach der Zuordnung des Felds (Ergebnis → Feld); Ergebnisse ohne
 * Feld fallen weg. Der Server lässt leere Werte weg — ausser der Hausnummer neben einer Strasse: Sie leert das Feld.
 */
export function fillFrom(
    values: Record<string, string>,
    fill: Record<string, string>,
): Record<string, string> {
    const out: Record<string, string> = {};
    for (const [key, field] of Object.entries(fill)) {
        const value = values[key];
        if (typeof value === 'string') {
            out[field] = value;
        }
    }

    return out;
}

/** Ob ein Wert als «ausgefüllt» gilt — für das Anlegen ohne Knopf. */
export function filled(value: unknown): boolean {
    return typeof value === 'string' ? value.trim() !== '' : value != null;
}

/**
 * Was nach dem Absenden des Anlegens noch getippt wurde: Felder, deren Wert sich seit dem gesendeten Stand geändert
 * hat — sie gehen ins Formular des neuen Eintrags und speichern sich dort selbst.
 */
export function typedSince(
    sent: Record<string, unknown>,
    latest: Record<string, unknown>,
): Record<string, unknown> {
    const out: Record<string, unknown> = {};
    for (const [key, value] of Object.entries(latest)) {
        if (JSON.stringify(value) !== JSON.stringify(sent[key])) {
            out[key] = value;
        }
    }

    return out;
}

/** Übergabe vom Formular «Neu» an das Formular des angelegten Eintrags (eine Navigation dazwischen). */
export const handoff: {
    from: string | null;
    sent: Record<string, unknown>;
    latest: Record<string, unknown>;
    focus: string | null;
} = { from: null, sent: {}, latest: {}, focus: null };
