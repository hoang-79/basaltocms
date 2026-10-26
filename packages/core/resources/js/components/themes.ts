/**
 * Theme-Bibliothek (TB 22): Namen wie Ordnernamen — Kleinbuchstaben, Ziffern, Bindestrich, höchstens 60 Zeichen.
 * Der Vorschlag aus der Bezeichnung schreibt Umlaute aus (ä → ae), damit «Café Süd» zu «cafe-sued» wird.
 */
export const THEME_NAME = /^[a-z0-9][a-z0-9-]{0,59}$/;

export function isThemeName(name: string): boolean {
    return THEME_NAME.test(name);
}

export function themeSlug(label: string): string {
    return label
        .toLowerCase()
        .replace(/ä/g, 'ae')
        .replace(/ö/g, 'oe')
        .replace(/ü/g, 'ue')
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 60)
        .replace(/-+$/, '');
}

/** Ein freier Name: `base`, sonst `base-2`, `base-3` … */
export function freeThemeName(base: string, taken: string[]): string {
    const root = themeSlug(base) || 'theme';
    let name = root;
    for (let i = 2; taken.includes(name); i++) {
        name = `${root.slice(0, 56)}-${i}`;
    }
    return name;
}

/** Grösse einer Datei lesbar: 512 B, 12 KB, 3,4 MB. */
export function fileSize(bytes: number, language = 'de'): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    const kb = bytes / 1024;
    if (kb < 1024) {
        return `${Math.round(kb)} KB`;
    }
    return `${(kb / 1024).toLocaleString(language === 'en' ? 'en-GB' : `${language}-CH`, { maximumFractionDigits: 1 })} MB`;
}
