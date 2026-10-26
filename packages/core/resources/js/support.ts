import { locale, t } from './i18n';
import type { StatusKind } from './types';

/** Die Sprache der Oberfläche als Gebietsschema für `Intl` und `toLocaleString`: de-CH, en-GB, it-CH, fr-CH. */
export function intlLocale(): string {
    const locales: Record<string, string> = {
        de: 'de-CH',
        en: 'en-GB',
        it: 'it-CH',
        fr: 'fr-CH',
    };

    return locales[locale()] ?? 'de-CH';
}

/** Initialen für den Sechseck-Avatar: erster Buchstabe der ersten zwei Wörter («Hoang Nguyen» → «HN»). */
export function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    const letters = parts.slice(0, 2).map((p) => p.charAt(0).toUpperCase());

    return letters.join('') || '?';
}

/** Der Stand einer Station der roten Linie («✓», «✗», «!», «–») als Zustand. */
export function lineStatus(
    status: string,
): 'success' | 'danger' | 'warning' | 'waiting' {
    if (status === '✓') {
        return 'success';
    }
    if (status === '✗') {
        return 'danger';
    }
    if (status === '!') {
        return 'warning';
    }

    return 'waiting';
}

/** Statusmodell einer Fassung → Statuspunkt (Form + Farbe); fehlt eine Fassung, ist der Punkt gestrichelt. */
export function versionStatus(status: string | null | undefined): StatusKind {
    switch (status) {
        case 'published':
            return 'live';
        case 'in_review':
            return 'review';
        case 'approved':
        case 'scheduled':
            return 'scheduled';
        case 'draft':
        case 'archived':
            return 'draft';
        default:
            return 'missing';
    }
}

/** Die Stände einer Fassung in Worten — als Getter, damit jeder Zugriff die Sprache der Oberfläche liest. */
export const versionLabels: Record<string, string> = {
    get draft() {
        return t('Entwurf');
    },
    get in_review() {
        return t('In Prüfung');
    },
    get approved() {
        return t('Freigegeben');
    },
    get scheduled() {
        return t('Geplant');
    },
    get published() {
        return t('Veröffentlicht');
    },
    get archived() {
        return t('Archiviert');
    },
};

/** Herzschlag des Schedulers als Satz mit Folge (K-16: nie still). */
export function heartbeat(age: number | null): {
    text: string;
    tone: 'success' | 'danger';
} {
    if (age === null) {
        return {
            text: t(
                'kein Herzschlag — geplante Veröffentlichungen laufen nicht',
            ),
            tone: 'danger',
        };
    }
    if (age < 60) {
        return { text: t('vor :seconds s', { seconds: age }), tone: 'success' };
    }
    const minutes = Math.round(age / 60);
    if (age > 300) {
        return {
            text: t(
                'vor :minutes Minuten — geplante Veröffentlichungen laufen nicht',
                { minutes },
            ),
            tone: 'danger',
        };
    }

    return { text: t('vor :minutes Minuten', { minutes }), tone: 'success' };
}

/** Ein gespeicherter Wert als Text — Objekte als JSON, Wahrheitswerte als «ein»/«aus». */
export function showValue(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    if (typeof value === 'boolean') {
        return value ? t('ein') : t('aus');
    }
    if (typeof value === 'string' || typeof value === 'number') {
        return String(value);
    }

    return JSON.stringify(value);
}

/** Pfad einer Adresse ohne Abfrage — für den Vergleich mit `page.url`. */
export function pathOf(href: string): string {
    const withoutQuery = href.split('?')[0] ?? href;
    const match = /^[a-z]+:\/\/[^/]+(\/.*)?$/i.exec(withoutQuery);
    if (match) {
        return match[1] || '/';
    }

    return withoutQuery;
}

/** Ob ein Menüeintrag die aktuelle Seite ist — exakt, oder als Elternpfad (ausser die Wurzel «/admin»). */
export function isCurrent(currentUrl: string, href: string): boolean {
    const current = pathOf(currentUrl);
    const target = pathOf(href);
    if (current === target) {
        return true;
    }

    return target.split('/').length > 2 && current.startsWith(`${target}/`);
}

/** Das Symbol eines Seitentyps im Sechseck (Mockup 28): Seite, Ordner, Weiterleitung, Feed, Fehlerseite. */
export function pageTypeIcon(type: string): string {
    if (type === 'folder') {
        return 'folder';
    }
    if (type === 'redirect') {
        return 'redirect';
    }
    if (type === 'feed') {
        return 'feed';
    }
    if (type.startsWith('error_')) {
        return 'error';
    }

    return 'pages';
}

/** Ein Slug wie Laravels `Str::slug`: Umlaute und Akzente verlieren ihre Zeichen, «ß» wird «ss», der Rest Bindestriche. */
export function slugify(text: string): string {
    return text
        .replace(/ß/g, 'ss')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

/** Kürzt einen Text auf `max` Zeichen mit Auslassungspunkten — wie Suchmaschinen ein Ergebnis abschneiden. */
export function truncate(text: string, max: number): string {
    if (text.length <= max) {
        return text;
    }

    return `${text.slice(0, max - 1).trimEnd()}…`;
}

/** Eine Adresse ohne Schema für die Anzeige: «https://example.ch» → «example.ch». */
export function hostOf(url: string): string {
    return url.replace(/^[a-z]+:\/\//i, '');
}

/** «YYYY-MM-DD HH:mm» (PHP) ↔ «YYYY-MM-DDTHH:mm» (`datetime-local`). */
export function toDateTimeLocal(value: string): string {
    return value.replace(' ', 'T');
}

export function fromDateTimeLocal(value: string): string {
    return value.replace('T', ' ');
}

/**
 * Die aktive Station der roten Linie aus der Adresse: die Station, deren `href` die aktuelle Seite ist.
 * Trägt eine Station eine Abfrage (G: `…/layouts?tab=templates`), muss die Adresse sie enthalten — sie gewinnt dann
 * vor der Station ohne Abfrage auf demselben Pfad (C).
 */
export function activeStation(
    line: { letter: string; href?: string | null }[],
    currentUrl: string,
): string | null {
    const query = new URLSearchParams(currentUrl.split('?')[1] ?? '');
    let best: { letter: string; score: number } | null = null;
    for (const station of line) {
        if (!station.href || !isCurrent(currentUrl, station.href)) {
            continue;
        }
        const wanted = new URLSearchParams(station.href.split('?')[1] ?? '');
        let score = 1;
        let matches = true;
        wanted.forEach((value, key) => {
            if (query.get(key) === value) {
                score++;
            } else {
                matches = false;
            }
        });
        if (matches && (!best || score > best.score)) {
            best = { letter: station.letter, score };
        }
    }

    return best?.letter ?? null;
}
