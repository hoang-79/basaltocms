import type { AdminSection } from './types';

/**
 * Schlüssel eines Abschnitts der Verwaltungsseiten aus Beschreibung (CR-018, TB 101): Ein Formular für einen anderen
 * Datensatz (andere Ziel-Adresse) ist ein neues Formular — sonst behielte Vue beim Wechsel zwischen zwei Seiten aus
 * Beschreibung die Werte der vorigen, und das Autosave schickte sie an den neuen Datensatz. Tabellen behalten ihren
 * Zustand über Blättern und Filtern, aber nicht über die Tabelle hinaus.
 */
export function sectionKey(section: AdminSection, index: number): string {
    if (section.kind === 'form') {
        return `form:${section.method}:${section.url}`;
    }
    if (section.kind === 'table') {
        return `table:${section.key}`;
    }

    return `${section.kind}:${index}`;
}

/**
 * Neue Werte vom Server für dieselbe Adresse (etwa nach «Neu suchen»): übernommen werden nur Felder, die hier seit
 * dem letzten Stand des Servers niemand geändert hat — Getipptes bleibt. `clean`: Kein Feld weicht ab, das Formular
 * entspricht wieder dem Server (Ausgangslage fürs Autosave neu setzen).
 */
export function mergeServerValues(
    current: Record<string, unknown>,
    before: Record<string, unknown>,
    after: Record<string, unknown>,
): { updates: Record<string, unknown>; clean: boolean } {
    const updates: Record<string, unknown> = {};
    let clean = true;
    for (const [name, value] of Object.entries(after)) {
        const mine = JSON.stringify(current[name]);
        if (mine === JSON.stringify(value)) {
            continue;
        }
        if (mine === JSON.stringify(before[name])) {
            updates[name] = JSON.parse(JSON.stringify(value ?? null));
        } else {
            clean = false;
        }
    }

    return { updates, clean };
}
