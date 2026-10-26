/** Ein TipTap-Dokument, wie es der Editor speichert. */
export type RichDoc = { type: 'doc'; content: Record<string, unknown>[] };

/**
 * Der Wert eines Fliesstextfelds als Dokument. Klartext — ein Feld, das vorher `textarea` war, oder ein Wert aus dem
 * Seed — wird zu Absätzen: Leerzeilen trennen Absätze, einfache Zeilenumbrüche bleiben Umbrüche. Gegenstück zu
 * `RichText::doc()` auf dem Server.
 */
export function toDoc(value: unknown): RichDoc | null {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
        return (value as { type?: unknown }).type === 'doc'
            ? (value as RichDoc)
            : null;
    }
    if (typeof value !== 'string' || value.trim() === '') {
        return null;
    }

    return {
        type: 'doc',
        content: value
            .trim()
            .split(/\r?\n\s*\r?\n/)
            .map((paragraph) => ({
                type: 'paragraph',
                content: paragraph
                    .trim()
                    .split(/\r?\n/)
                    .flatMap((line, i) => [
                        ...(i > 0 ? [{ type: 'hardBreak' }] : []),
                        ...(line !== '' ? [{ type: 'text', text: line }] : []),
                    ]),
            })),
    };
}
