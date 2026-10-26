/**
 * Wort-Diff für die Freigabe (Mockup 13): zwei Texte → Teile «gleich», «gestrichen», «eingefügt».
 * Wörter, Leerraum und Satzzeichen sind je ein Token; dazwischen die längste gemeinsame Teilfolge.
 * Ein Lauf von Änderungen wird zu einem gestrichenen und einem eingefügten Teil zusammengefasst — Leerraum zwischen
 * zwei Änderungen zählt zum Lauf, damit «<del>a b</del><ins>x y</ins>» statt eines Flickenteppichs entsteht.
 */

export type DiffPart = { kind: 'same' | 'del' | 'ins'; text: string };

/** Ab dieser Grösse (Tokens alt × neu) kein Wort-Diff mehr, sondern alt gestrichen und neu eingefügt. */
const MAX_CELLS = 250_000;

const TOKEN = /\s+|[\p{L}\p{N}_]+(?:[’'-][\p{L}\p{N}_]+)*|[^\s]/gu;

/** Zerlegt einen Text in Wörter, Leerraum und einzelne Satzzeichen. */
export function tokenize(text: string): string[] {
    return text.match(TOKEN) ?? [];
}

type Op = { kind: DiffPart['kind']; token: string };

function isSpace(token: string): boolean {
    return /^\s+$/.test(token);
}

/** Die Operationen zwischen zwei Tokenfolgen über die längste gemeinsame Teilfolge. */
function operations(a: string[], b: string[]): Op[] {
    let start = 0;
    while (start < a.length && start < b.length && a[start] === b[start]) {
        start++;
    }
    let endA = a.length;
    let endB = b.length;
    while (endA > start && endB > start && a[endA - 1] === b[endB - 1]) {
        endA--;
        endB--;
    }

    const head: Op[] = a
        .slice(0, start)
        .map((token) => ({ kind: 'same', token }));
    const tail: Op[] = a.slice(endA).map((token) => ({ kind: 'same', token }));
    const midA = a.slice(start, endA);
    const midB = b.slice(start, endB);

    if (midA.length * midB.length > MAX_CELLS) {
        return [
            ...head,
            ...midA.map((token): Op => ({ kind: 'del', token })),
            ...midB.map((token): Op => ({ kind: 'ins', token })),
            ...tail,
        ];
    }

    const n = midA.length;
    const m = midB.length;
    const width = m + 1;
    const lcs = new Uint32Array((n + 1) * width);
    for (let i = n - 1; i >= 0; i--) {
        for (let j = m - 1; j >= 0; j--) {
            lcs[i * width + j] =
                midA[i] === midB[j]
                    ? (lcs[(i + 1) * width + j + 1] ?? 0) + 1
                    : Math.max(
                          lcs[(i + 1) * width + j] ?? 0,
                          lcs[i * width + j + 1] ?? 0,
                      );
        }
    }

    const middle: Op[] = [];
    let i = 0;
    let j = 0;
    while (i < n && j < m) {
        if (midA[i] === midB[j]) {
            middle.push({ kind: 'same', token: midA[i] ?? '' });
            i++;
            j++;
        } else if (
            (lcs[(i + 1) * width + j] ?? 0) >= (lcs[i * width + j + 1] ?? 0)
        ) {
            middle.push({ kind: 'del', token: midA[i] ?? '' });
            i++;
        } else {
            middle.push({ kind: 'ins', token: midB[j] ?? '' });
            j++;
        }
    }
    while (i < n) {
        middle.push({ kind: 'del', token: midA[i++] ?? '' });
    }
    while (j < m) {
        middle.push({ kind: 'ins', token: midB[j++] ?? '' });
    }

    return [...head, ...middle, ...tail];
}

/** Der Wort-Diff zweier Texte als Folge von Teilen; leere Teile fallen weg. */
export function wordDiff(
    oldText: string | null | undefined,
    newText: string | null | undefined,
): DiffPart[] {
    const ops = operations(tokenize(oldText ?? ''), tokenize(newText ?? ''));

    // Leerraum zwischen zwei Änderungen gehört zum Lauf (gestrichen und eingefügt).
    const changed = ops.map((op) => op.kind !== 'same');
    ops.forEach((op, index) => {
        if (
            op.kind === 'same' &&
            isSpace(op.token) &&
            changed[index - 1] === true &&
            changed[index + 1] === true
        ) {
            changed[index] = true;
        }
    });

    const parts: DiffPart[] = [];
    let deleted = '';
    let inserted = '';
    const flush = (): void => {
        if (deleted) {
            parts.push({ kind: 'del', text: deleted });
        }
        if (inserted) {
            parts.push({ kind: 'ins', text: inserted });
        }
        deleted = '';
        inserted = '';
    };

    ops.forEach((op, index) => {
        if (!changed[index]) {
            flush();
            const last = parts[parts.length - 1];
            if (last?.kind === 'same') {
                last.text += op.token;
            } else {
                parts.push({ kind: 'same', text: op.token });
            }

            return;
        }
        if (op.kind === 'same') {
            deleted += op.token;
            inserted += op.token;
        } else if (op.kind === 'del') {
            deleted += op.token;
        } else {
            inserted += op.token;
        }
    });
    flush();

    return parts;
}

/** Die alte Seite (gleich + gestrichen) und die neue Seite (gleich + eingefügt) für die Anzeige nebeneinander. */
export function diffSides(parts: DiffPart[]): {
    old: DiffPart[];
    new: DiffPart[];
} {
    return {
        old: parts.filter((p) => p.kind !== 'ins'),
        new: parts.filter((p) => p.kind !== 'del'),
    };
}
