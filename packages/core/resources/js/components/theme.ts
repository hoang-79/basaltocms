/**
 * Darstellung des Backends (TB 21): hell, dunkel oder wie das System. Gesetzt als `data-theme="dark"` am `<html>`; die
 * Farben stellt backend.css über Variablen um. Der Server setzt den Wert schon im HTML (kein Aufblitzen); hier folgt die
 * Seite einem Wechsel im Profil ohne Neuladen und bei «system» dem Wechsel des Betriebssystems.
 */
export type ThemePreference = 'light' | 'dark' | 'system';

let media: MediaQueryList | null = null;
let current: ThemePreference = 'light';

function systemDark(): boolean {
    return typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function'
        ? window.matchMedia('(prefers-color-scheme: dark)').matches
        : false;
}

/** Ob die Oberfläche bei dieser Wahl dunkel ist. */
export function isDark(
    preference: ThemePreference,
    prefersDark = systemDark(),
): boolean {
    return preference === 'dark' || (preference === 'system' && prefersDark);
}

function onSystemChange(): void {
    if (current === 'system') {
        render();
    }
}

function render(): void {
    const root = document.documentElement;
    root.dataset.themePref = current;
    if (isDark(current)) {
        root.dataset.theme = 'dark';
    } else {
        delete root.dataset.theme;
    }
}

/** Wahl anwenden (bei jedem Seitenwechsel mit den geteilten Props). */
export function applyTheme(preference: string | null | undefined): void {
    current =
        preference === 'dark' || preference === 'system' ? preference : 'light';
    if (
        media === null &&
        typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function'
    ) {
        media = window.matchMedia('(prefers-color-scheme: dark)');
        media.addEventListener?.('change', onSystemChange);
    }
    render();
}
