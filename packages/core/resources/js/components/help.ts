import { reactive } from 'vue';

/**
 * Webhilfe (TB 20): Zustand des Hilfe-Panels für das ganze Backend — offen, welche Seite, welcher Anker — und die
 * Tastenkürzel. `?` und F1 öffnen und schliessen das Panel, `/` springt bei offenem Panel in dessen Suche; in
 * Eingabefeldern und Editoren bleiben `?` und `/` Zeichen, F1 gilt überall. Was ein Feld schon behandelt hat
 * (`defaultPrevented`), lässt das Panel in Ruhe.
 */
export const helpState = reactive({
    open: false,
    slug: null as string | null,
    anchor: null as string | null,
    /** zählt jedes Öffnen hoch, damit das Panel auch dieselbe Seite neu lädt */
    version: 0,
});

export function openHelp(slug?: string | null, anchor?: string | null): void {
    helpState.slug = slug ?? null;
    helpState.anchor = anchor ?? null;
    helpState.open = true;
    helpState.version++;
}

export function closeHelp(): void {
    helpState.open = false;
}

export function toggleHelp(slug?: string | null): void {
    if (helpState.open) {
        closeHelp();
    } else {
        openHelp(slug);
    }
}

/** Tippt jemand gerade — Eingabefeld, Auswahl, Rich-Text oder Code-Editor? */
export function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) {
        return true;
    }

    return (
        target.isContentEditable ||
        target.closest('[contenteditable="true"], .cm-editor') !== null
    );
}

export type HelpShortcut = 'toggle' | 'search' | null;

/** Was eine Taste mit dem Panel tun soll; `open` sagt, ob es gerade offen ist. */
export function helpShortcut(
    event: Pick<
        KeyboardEvent,
        'key' | 'metaKey' | 'ctrlKey' | 'altKey' | 'defaultPrevented' | 'target'
    >,
    open: boolean,
): HelpShortcut {
    if (event.defaultPrevented) {
        return null;
    }
    if (event.key === 'F1') {
        return 'toggle';
    }
    if (event.metaKey || event.ctrlKey || event.altKey) {
        return null;
    }
    if (isTypingTarget(event.target)) {
        return null;
    }
    if (event.key === '?') {
        return 'toggle';
    }
    if (event.key === '/' && open) {
        return 'search';
    }

    return null;
}

/**
 * Zeigt ein Link auf eine Hilfeseite? Dann Seite und Anker — das Panel öffnet sie selbst, statt wegzunavigieren.
 * `base` ist die Adresse der Hilfe (`/admin/hilfe`), `origin` die des Backends.
 */
export function helpTarget(
    href: string,
    base: string,
    origin: string,
): { slug: string; anchor: string | null } | null {
    let url: URL;
    let root: URL;
    try {
        url = new URL(href, origin);
        root = new URL(base, origin);
    } catch {
        return null;
    }
    if (url.origin !== root.origin) {
        return null;
    }
    const prefix = root.pathname.replace(/\/$/, '');
    if (url.pathname !== prefix && !url.pathname.startsWith(prefix + '/')) {
        return null;
    }
    const slug = url.pathname.slice(prefix.length + 1) || 'index';
    if (!/^[a-z0-9-]+$/.test(slug)) {
        return null;
    }

    return {
        slug,
        anchor: url.hash ? decodeURIComponent(url.hash.slice(1)) : null,
    };
}

/** Ein Bild der Hilfe, das gross gezeigt werden soll (TB 29). */
export type HelpImage = { src: string; alt: string; caption: string };

/**
 * Das angeklickte Bild einer Hilfeseite — der Server legt jedes Bild als `figure.hilfe-bild` mit Link aufs Original ab.
 * Null, wenn der Klick kein solches Bild traf (dann gilt der Link wie gewohnt).
 */
export function helpImage(target: EventTarget | null): HelpImage | null {
    const element = target instanceof Element ? target : null;
    const link = element?.closest('figure.hilfe-bild a');
    const img = link?.querySelector('img');
    if (!link || !img) {
        return null;
    }

    return {
        src: link.getAttribute('href') || img.getAttribute('src') || '',
        alt: img.getAttribute('alt') ?? '',
        caption:
            link
                .closest('figure')
                ?.querySelector('figcaption')
                ?.textContent?.trim() ?? '',
    };
}

/** Kleinste und grösste Breite des Hilfe-Panels in Pixeln; daneben bleiben mindestens 240 px der Seite sichtbar. */
export const HELP_WIDTH = { min: 320, max: 1600, fallback: 480, keep: 240 };

/** Breite des Panels für ein Fenster der Breite `viewport` — auf das Erlaubte begrenzt und ganzzahlig. */
export function clampHelpWidth(width: number, viewport: number): number {
    const max = Math.max(
        HELP_WIDTH.min,
        Math.min(HELP_WIDTH.max, viewport - HELP_WIDTH.keep),
    );
    const value = Number.isFinite(width)
        ? Math.round(width)
        : HELP_WIDTH.fallback;

    return Math.min(max, Math.max(HELP_WIDTH.min, value));
}
