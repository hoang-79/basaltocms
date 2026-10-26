import type { JourneyStation } from '../types';

/**
 * Fahrplan der Einrichtung (TB 23): Hilfen für die Leiste — wie viele Stationen stimmen, welche als nächste dran ist
 * und wohin die Kontextkarte einer Station aufklappt, damit sie am Rand nicht abgeschnitten wird.
 */
export function progress(stations: JourneyStation[]): {
    done: number;
    total: number;
} {
    return {
        done: stations.filter((s) => s.status === 'success').length,
        total: stations.length,
    };
}

/** Die erste Station, die noch nicht stimmt — Fehler zuerst, dann Warnung, dann «wartet». */
export function nextStation(stations: JourneyStation[]): JourneyStation | null {
    for (const status of ['danger', 'warning', 'waiting'] as const) {
        const found = stations.find((s) => s.status === status);
        if (found) {
            return found;
        }
    }
    return null;
}

/** Ausrichtung der Karte: an den ersten Stationen links, an den letzten rechts, sonst mittig. */
export function cardAlign(
    index: number,
    count: number,
): 'start' | 'center' | 'end' {
    if (index < 2) {
        return 'start';
    }
    if (index >= count - 2) {
        return 'end';
    }
    return 'center';
}

/** Zeichen für den Stand einer Station. */
export function statusMark(status: JourneyStation['status']): string {
    return { success: '✓', warning: '!', danger: '✗', waiting: '' }[status];
}
