/** Die Klassen eines Eingabefelds (Feld, Auswahl, Textbereich) — eine Quelle für alle Formulare. */
export function controlClasses(
    options: { error?: boolean; mono?: boolean; small?: boolean } = {},
): string {
    const base =
        'w-full rounded-md border bg-white text-basalt placeholder:text-ash-dark focus:outline-none focus:ring-1 disabled:bg-paper disabled:text-ash';
    const size = options.small
        ? 'px-2 py-1 text-xs'
        : 'px-2.5 py-2 text-[13px]';
    const font = options.mono ? 'font-mono' : '';
    const state = options.error
        ? 'border-danger ring-2 ring-danger/15 focus:border-danger focus:ring-danger'
        : 'border-ash-dark focus:border-lava-dark focus:ring-lava-dark';

    return [base, size, font, state].filter(Boolean).join(' ');
}

export const labelClasses =
    'mb-1 block text-[9.5px] font-semibold tracking-[.1em] text-basalt-light uppercase';

/**
 * Die Ids für `aria-describedby` eines Felds: zuerst der Fehler, dann die Hilfe (`<id>-error`, `<id>-help`) —
 * so liest ein Screenreader nach dem Label, was falsch ist, und danach, was erwartet wird (WCAG 3.3.1, 1.3.1).
 */
export function describedByIds(
    id: string,
    parts: { error?: boolean; help?: boolean },
): string | undefined {
    const ids = [
        parts.error ? `${id}-error` : null,
        parts.help ? `${id}-help` : null,
    ].filter(Boolean);

    return ids.length > 0 ? ids.join(' ') : undefined;
}
