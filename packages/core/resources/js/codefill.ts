/**
 * Ziffern in Felder verteilen (TB 72) — gemeinsam für das Backend (`CodeInput.vue`) und die Website (`codefelder.ts`).
 * Eine getippte Ziffer landet im Feld `index`, mehrere (eingefügt oder vom Browser ausgefüllt) ab dort; ein ganzer Code
 * ersetzt immer alles. Liefert die neuen Ziffern, das nächste freie Feld und ob der Code vollständig ist — oder null,
 * wenn keine Ziffer dabei war.
 */
export function fillDigits(
    digits: string[],
    index: number,
    raw: string,
): { digits: string[]; next: number; complete: boolean } | null {
    const clean = raw.replace(/\D/g, '');
    if (clean === '') {
        return null;
    }
    const length = digits.length;
    const start = clean.length >= length ? 0 : index;
    const out = digits.slice();
    let next = start;
    for (const c of clean.slice(0, length - start)) {
        out[next++] = c;
    }
    const complete = out.every((c) => c !== '');
    const free = out.findIndex((c, i) => i >= next && c === '');

    return {
        digits: out,
        next: complete
            ? length - 1
            : free === -1
              ? Math.min(next, length - 1)
              : free,
        complete,
    };
}

/** Ein im gefüllten Feld getipptes Zeichen ohne Markierung kommt als «37» — neu ist das zweite. */
export function typedDigit(current: string, typed: string): string {
    return typed.length === 2 && current !== '' && typed.startsWith(current)
        ? typed.slice(1)
        : typed;
}
