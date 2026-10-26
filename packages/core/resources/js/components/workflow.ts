import { t, tc } from '../i18n';
import type { StatusKind } from '../types';
import type { Stage, WorkflowPageRow } from '../types-workflow';

/** Die sechs Stationen der Bahn in ihrer Reihenfolge (Statusmodell § 4.2). */
export const stages: Stage[] = [
    'draft',
    'in_review',
    'approved',
    'scheduled',
    'published',
    'archived',
];

/** Als Getter, damit die Texte erst beim Lesen in der Sprache der Oberfläche entstehen. */
export const stageLabels: Record<Stage, string> = {
    get draft() {
        return t('Entwurf');
    },
    get in_review() {
        return t('In Prüfung');
    },
    get approved() {
        return t('Freigegeben');
    },
    get scheduled() {
        return t('Geplant');
    },
    get published() {
        return t('Veröffentlicht');
    },
    get archived() {
        return t('Archiviert');
    },
};

/** Kurzformen für schmale Bildschirme — die Bahn bricht nicht und scrollt nicht. */
export const stageShort: Record<Stage, string> = {
    get draft() {
        return t('Entw.');
    },
    get in_review() {
        return t('Prüf.');
    },
    get approved() {
        return t('Frei');
    },
    get scheduled() {
        return t('Gepl.');
    },
    get published() {
        return t('Live');
    },
    get archived() {
        return t('Arch.');
    },
};

export type StationState = 'past' | 'current' | 'next';

/** Die Stationen der Bahn mit ihrem Stand: zurückgelegt, aktuell, kommend. */
export function lineStations(
    stage: Stage,
): { stage: Stage; label: string; short: string; state: StationState }[] {
    const current = stages.indexOf(stage);

    return stages.map((s, i) => ({
        stage: s,
        label: stageLabels[s],
        short: stageShort[s],
        state: i < current ? 'past' : i === current ? 'current' : 'next',
    }));
}

/**
 * Zustand und Knopfgewicht einer Aufgabe im Cockpit (Mockup 9): Prüfen und Veröffentlichen Lava und primär,
 * Übersetzung und 404-Treffer Bernstein, Geplant grün, Zurückgewiesen Karmin. Unbekannte Arten (Module) ohne Marke.
 */
export function taskMark(kind: string): {
    status: StatusKind | null;
    primary: boolean;
} {
    switch (kind) {
        case 'review':
        case 'approved':
            return { status: 'lava', primary: true };
        case 'translation':
            return { status: 'warning', primary: false };
        case 'scheduled':
            return { status: 'success', primary: false };
        case 'rejected':
            return { status: 'danger', primary: false };
        case 'not_found':
            return { status: 'warning', primary: false };
    }
    if (/review|prüf/i.test(kind)) {
        return { status: 'lava', primary: true };
    }
    if (/translat|übersetz|stale|veraltet/i.test(kind)) {
        return { status: 'warning', primary: false };
    }
    if (/schedul|geplant/i.test(kind)) {
        return { status: 'success', primary: false };
    }

    return { status: null, primary: false };
}

/* ── Massenaktionen im Seitenbaum ── */

/** Nur Seiten mit Inhalt (Seite, Fehlerseiten) lassen sich einreichen, veröffentlichen und gesammelt löschen. */
export function isSelectable(row: WorkflowPageRow): boolean {
    return Boolean(row.urls.content);
}

/** Schaltet eine Zeile in der Auswahl um. */
export function toggleSelection(selection: number[], id: number): number[] {
    return selection.includes(id)
        ? selection.filter((x) => x !== id)
        : [...selection, id];
}

/** «Alle auswählen» über die sichtbaren, wählbaren Zeilen — sind schon alle gewählt, wird die Auswahl leer. */
export function toggleAll(
    selection: number[],
    rows: WorkflowPageRow[],
): number[] {
    const ids = rows.filter(isSelectable).map((r) => r.id);
    const all = ids.length > 0 && ids.every((id) => selection.includes(id));

    return all
        ? selection.filter((id) => !ids.includes(id))
        : [...new Set([...selection, ...ids])];
}

/** Die Auswahl nach einem Neuladen: Zeilen, die nicht mehr da oder nicht mehr wählbar sind, fallen heraus. */
export function pruneSelection(
    selection: number[],
    rows: WorkflowPageRow[],
): number[] {
    const ids = new Set(rows.filter(isSelectable).map((r) => r.id));

    return selection.filter((id) => ids.has(id));
}

/** «1 Seite» / «3 Seiten». */
export function pagesCount(n: number): string {
    return tc(':count Seite|:count Seiten', n);
}
