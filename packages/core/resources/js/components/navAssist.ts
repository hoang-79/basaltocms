import type { NavigationItem, NavigationPage } from '../types';

/**
 * TB 34: Menüs geführt — ein Feld für Seite oder Adresse, Seiten, die im Menü noch fehlen, und was eine Eingabe ist.
 * Reine Regeln ohne Oberfläche, Vitest prüft sie.
 */

/** Sieht die Eingabe wie eine Adresse aus (/pfad, https://…, mailto:, tel:, #anker)? */
export function looksLikeAddress(input: string): boolean {
    return /^(\/|#|https?:\/\/|mailto:|tel:|www\.)/i.test(input.trim());
}

/** Eine eingetippte Adresse in die Form, die das Menü speichert: «www.x.ch» → «https://www.x.ch». */
export function normalizeAddress(input: string): string {
    const value = input.trim();

    return /^www\./i.test(value) ? `https://${value}` : value;
}

/** Ist die Adresse eine Seite der Site? Dann besser die Seite verknüpfen — sie folgt Umbenennungen. */
export function pageAt(
    address: string,
    pages: NavigationPage[],
): NavigationPage | null {
    const path =
        address
            .trim()
            .replace(/[?#].*$/, '')
            .replace(/\/+$/, '') || '/';

    return (
        pages.find(
            (p) =>
                (p.path ?? '').replace(/\/+$/, '') === path ||
                (path === '/' && p.path === '/'),
        ) ?? null
    );
}

/** Seiten zur Eingabe: Titel oder Adresse enthält sie; Treffer am Anfang zuerst, höchstens `limit`. */
export function matchingPages(
    input: string,
    pages: NavigationPage[],
    limit = 8,
): NavigationPage[] {
    const q = input.trim().toLocaleLowerCase();
    if (q === '') {
        return pages.slice(0, limit);
    }
    const scored = pages
        .map((p) => {
            const title = p.title.toLocaleLowerCase();
            const path = (p.path ?? '').toLocaleLowerCase();
            const score = title.startsWith(q)
                ? 0
                : title.includes(q)
                  ? 1
                  : path.includes(q)
                    ? 2
                    : -1;

            return { p, score };
        })
        .filter((x) => x.score >= 0)
        .sort((a, b) => a.score - b.score || a.p.depth - b.p.depth);

    return scored.slice(0, limit).map((x) => x.p);
}

/** Veröffentlichte Seiten, die im Menü noch fehlen — oberste Ebene zuerst, höchstens `limit`. */
export function missingPages(
    pages: NavigationPage[],
    items: NavigationItem[],
    limit = 6,
): NavigationPage[] {
    const used = new Set(
        items
            .map((i) => i.target.entry_id)
            .filter((id): id is number => id !== null),
    );

    return pages
        .filter((p) => p.live && !used.has(p.id))
        .sort((a, b) => a.depth - b.depth)
        .slice(0, limit);
}
