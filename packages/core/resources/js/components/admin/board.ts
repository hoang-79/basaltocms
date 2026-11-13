import type { AdminBoardColumn } from './types';

/**
 * Boards aus Beschreibung (CR-020): Karten verschieben, ohne auf den Server zu warten, und das WIP-Limit einer Spalte
 * prüfen. Der Server prüft dasselbe noch einmal und hat das letzte Wort.
 */

/** Wo eine Karte gerade steht — null, wenn es sie nicht gibt. */
export function locate(
    columns: AdminBoardColumn[],
    card: string | number,
): { column: string; index: number } | null {
    for (const column of columns) {
        const index = column.cards.findIndex(
            (c) => String(c.id) === String(card),
        );
        if (index >= 0) {
            return { column: column.key, index };
        }
    }

    return null;
}

/**
 * Darf eine Karte aus `from` in die Spalte `to`? Innerhalb derselben Spalte immer, sonst nur, solange das WIP-Limit
 * nicht erreicht ist.
 */
export function allows(
    columns: AdminBoardColumn[],
    from: string,
    to: string,
): boolean {
    if (from === to) {
        return true;
    }
    const target = columns.find((c) => c.key === to);

    return (
        target !== undefined &&
        (target.limit === null || target.cards.length < target.limit)
    );
}

/** Ist das WIP-Limit einer Spalte überschritten (etwa, weil das Limit nachträglich gesenkt wurde)? */
export function overLimit(column: AdminBoardColumn): boolean {
    return column.limit !== null && column.cards.length > column.limit;
}

/**
 * Die Spalten nach dem Verschieben: Karte `card` in Spalte `to` an Stelle `position` (ab 0, auf die Länge begrenzt).
 * Gibt die Spalten unverändert zurück, wenn die Karte fehlt oder das WIP-Limit es nicht erlaubt.
 */
export function moveCard(
    columns: AdminBoardColumn[],
    card: string | number,
    to: string,
    position: number,
): AdminBoardColumn[] {
    const from = locate(columns, card);
    if (
        from === null ||
        !columns.some((c) => c.key === to) ||
        !allows(columns, from.column, to)
    ) {
        return columns;
    }
    const next = columns.map((c) => ({ ...c, cards: [...c.cards] }));
    const source = next.find((c) => c.key === from.column)!;
    const [moved] = source.cards.splice(from.index, 1);
    const target = next.find((c) => c.key === to)!;
    const at = Math.max(0, Math.min(position, target.cards.length));
    target.cards.splice(at, 0, moved);

    return next;
}

/**
 * Die Stelle beim Ablegen: vor der ersten Karte, deren Mitte unterhalb des Zeigers liegt — sonst ans Ende. `middles`
 * sind die senkrechten Mitten der Karten der Spalte (ohne die gezogene), von oben nach unten.
 */
export function dropIndex(middles: number[], pointerY: number): number {
    const index = middles.findIndex((middle) => pointerY < middle);

    return index === -1 ? middles.length : index;
}

/** Nachbarspalte für die Tastatur: −1 links, +1 rechts — null am Rand. */
export function neighbour(
    columns: AdminBoardColumn[],
    key: string,
    step: -1 | 1,
): string | null {
    const index = columns.findIndex((c) => c.key === key);
    const next = columns[index + step];

    return index >= 0 && next !== undefined ? next.key : null;
}
