/**
 * Sortieren eines freien Menüs (Station D) als flache Liste in Baumreihenfolge: jeder Eintrag trägt seine Tiefe
 * (0 = oberste Ebene); die Eltern ergeben sich aus der Reihenfolge. Ein Eintrag zieht seine Untereinträge immer mit.
 * `maxDepth` ist die Zahl der Ebenen aus Lava (`depth: 2` → Tiefe 0 und 1); 0 heisst ohne Grenze.
 */
export type NavRow = { id: number; depth: number };

export type NavOrder = { id: number; parent_id: number | null }[];

/** Die Eltern aus Tiefe und Reihenfolge — so geht die Reihenfolge an den Server. */
export function toOrder(rows: NavRow[]): NavOrder {
    const stack: number[] = [];

    return rows.map((row) => {
        stack.length = row.depth;
        const parent = row.depth > 0 ? (stack[row.depth - 1] ?? null) : null;
        stack[row.depth] = row.id;

        return { id: row.id, parent_id: parent };
    });
}

/** Das Ende des Teilbaums: Index nach dem letzten Untereintrag von `index`. */
export function subtreeEnd(rows: NavRow[], index: number): number {
    const depth = rows[index]?.depth ?? 0;
    let end = index + 1;
    while (end < rows.length && (rows[end]?.depth ?? 0) > depth) {
        end++;
    }

    return end;
}

/** Wie viele Ebenen ein Teilbaum unter seinem Kopf hat (0 = ohne Untereinträge). */
function subtreeHeight(rows: NavRow[], index: number): number {
    const base = rows[index]?.depth ?? 0;
    const end = subtreeEnd(rows, index);
    let height = 0;
    for (let i = index; i < end; i++) {
        height = Math.max(height, (rows[i]?.depth ?? 0) - base);
    }

    return height;
}

function deepestAllowed(maxDepth: number): number {
    return maxDepth > 0 ? maxDepth - 1 : Number.POSITIVE_INFINITY;
}

function shift<T extends NavRow>(rows: T[], delta: number): T[] {
    return rows.map((r) => ({ ...r, depth: r.depth + delta }));
}

/** Ob «→ tiefer» geht: es gibt ein Geschwister davor, und der Teilbaum bleibt innerhalb der Ebenen. */
export function canIndent<T extends NavRow>(
    rows: T[],
    index: number,
    maxDepth: number,
): boolean {
    const row = rows[index];
    const prev = rows[index - 1];
    if (!row || !prev || prev.depth < row.depth) {
        return false;
    }

    return (
        row.depth + 1 + subtreeHeight(rows, index) <= deepestAllowed(maxDepth)
    );
}

/** «→ tiefer»: der Eintrag wird Untereintrag seines vorigen Geschwisters (als letztes Kind). */
export function indent<T extends NavRow>(
    rows: T[],
    index: number,
    maxDepth: number,
): T[] {
    if (!canIndent(rows, index, maxDepth)) {
        return rows;
    }
    const end = subtreeEnd(rows, index);

    return [
        ...rows.slice(0, index),
        ...shift(rows.slice(index, end), 1),
        ...rows.slice(end),
    ];
}

export function canOutdent<T extends NavRow>(
    rows: T[],
    index: number,
): boolean {
    return (rows[index]?.depth ?? 0) > 0;
}

/**
 * «← höher»: der Eintrag wird Geschwister seines Elterneintrags und steht direkt nach dessen Teilbaum —
 * die Geschwister danach bleiben beim bisherigen Elterneintrag.
 */
export function outdent<T extends NavRow>(rows: T[], index: number): T[] {
    if (!canOutdent(rows, index)) {
        return rows;
    }
    const depth = rows[index]?.depth ?? 0;
    let parent = index - 1;
    while (parent >= 0 && (rows[parent]?.depth ?? 0) >= depth) {
        parent--;
    }
    const end = subtreeEnd(rows, index);
    const block = shift(rows.slice(index, end), -1);
    const rest = [...rows.slice(0, index), ...rows.slice(end)];
    const parentEnd = subtreeEnd(rest, parent);

    return [...rest.slice(0, parentEnd), ...block, ...rest.slice(parentEnd)];
}

/** Der Rest der Liste ohne den Teilbaum ab `from`, mit dem Teilbaum selbst. */
function cut<T extends NavRow>(
    rows: T[],
    from: number,
): { block: T[]; rest: T[] } {
    const end = subtreeEnd(rows, from);

    return {
        block: rows.slice(from, end),
        rest: [...rows.slice(0, from), ...rows.slice(end)],
    };
}

/**
 * Die erlaubten Tiefen beim Ablegen vor `before` (Index in der Liste ohne den gezogenen Teilbaum; = Länge für «ans Ende»):
 * höchstens eine Ebene unter dem Eintrag davor, nicht tiefer als die Ebenen erlauben, und der Eintrag danach darf
 * nicht verwaisen.
 */
export function dropRange<T extends NavRow>(
    rows: T[],
    from: number,
    before: number,
    maxDepth: number,
): { min: number; max: number } | null {
    const { block, rest } = cut(rows, from);
    const head = block[0];
    if (!head) {
        return null;
    }
    const height = Math.max(...block.map((r) => r.depth - head.depth));
    const lastRel = (block[block.length - 1]?.depth ?? head.depth) - head.depth;
    const prev = rest[before - 1];
    const next = rest[before];
    const max = Math.min(
        prev ? prev.depth + 1 : 0,
        deepestAllowed(maxDepth) - height,
    );
    const min = Math.max(0, next ? next.depth - lastRel - 1 : 0);

    return min <= max ? { min, max } : null;
}

/** Den Teilbaum ab `from` vor `before` (Index ohne den Teilbaum) auf Tiefe `depth` ablegen — ungültig ändert nichts. */
export function moveBlock<T extends NavRow>(
    rows: T[],
    from: number,
    before: number,
    depth: number,
    maxDepth: number,
): T[] {
    const range = dropRange(rows, from, before, maxDepth);
    if (!range) {
        return rows;
    }
    const { block, rest } = cut(rows, from);
    const head = block[0];
    if (!head) {
        return rows;
    }
    const target = Math.min(range.max, Math.max(range.min, depth));
    const moved = shift(block, target - head.depth);

    return [...rest.slice(0, before), ...moved, ...rest.slice(before)];
}

/** «↑» / «↓»: mit dem Teilbaum am vorigen oder nächsten Geschwister vorbei. */
export function moveSibling<T extends NavRow>(
    rows: T[],
    index: number,
    direction: -1 | 1,
): T[] {
    const row = rows[index];
    if (!row) {
        return rows;
    }
    if (direction === -1) {
        let prev = index - 1;
        while (prev >= 0 && (rows[prev]?.depth ?? 0) > row.depth) {
            prev--;
        }
        if (prev < 0 || (rows[prev]?.depth ?? 0) !== row.depth) {
            return rows;
        }
        const { block, rest } = cut(rows, index);

        return [...rest.slice(0, prev), ...block, ...rest.slice(prev)];
    }
    const end = subtreeEnd(rows, index);
    const next = rows[end];
    if (!next || next.depth !== row.depth) {
        return rows;
    }
    const nextEnd = subtreeEnd(rows, end);
    const { block, rest } = cut(rows, index);
    const insertAt = nextEnd - block.length;

    return [...rest.slice(0, insertAt), ...block, ...rest.slice(insertAt)];
}

export function canMoveSibling<T extends NavRow>(
    rows: T[],
    index: number,
    direction: -1 | 1,
): boolean {
    return moveSibling(rows, index, direction) !== rows;
}
