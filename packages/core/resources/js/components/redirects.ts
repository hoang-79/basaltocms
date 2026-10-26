/**
 * Umleitungen und 404-Protokoll im Seitenbaum (TB 06, M-10): Beschriftungen für Status und Herkunft, der gekürzte
 * Verweis eines 404-Treffers und der Reiter aus der Adresse (`?tab=not_found` aus der Cockpit-Aufgabe).
 */

import { t } from '../i18n';

export type RedirectStatus = 301 | 302 | 410;

export const redirectStatuses: RedirectStatus[] = [301, 302, 410];

/** Beim Aufruf übersetzt — die Sprache der Oberfläche kann wechseln. */
function statusWords(): Record<RedirectStatus, string> {
    return {
        301: t('dauerhaft'),
        302: t('vorübergehend'),
        410: t('entfernt'),
    };
}

/** «dauerhaft», «vorübergehend», «entfernt» — unbekannte Codes bleiben als Zahl stehen. */
export function redirectStatusWord(status: number): string {
    return statusWords()[status as RedirectStatus] ?? String(status);
}

/** Die Auswahl im Formular: «301 dauerhaft» … */
export function redirectStatusOptions(): { value: number; label: string }[] {
    const words = statusWords();

    return redirectStatuses.map((s) => ({
        value: s,
        label: `${s} ${words[s]}`,
    }));
}

/** Ton der Plakette: dauerhaft grün, vorübergehend Bernstein, entfernt grau. */
export function redirectStatusTone(
    status: number,
): 'success' | 'warning' | 'grey' {
    if (status === 301) {
        return 'success';
    }
    if (status === 302) {
        return 'warning';
    }

    return 'grey';
}

function sourceWords(): Record<string, string> {
    return {
        slug_change: t('automatisch'),
        manual: t('von Hand'),
        import: t('Import'),
    };
}

/** Herkunft: «automatisch» (Slug-Wechsel), «von Hand», «Import» — Unbekanntes unverändert. */
export function redirectSourceLabel(source: string): string {
    return sourceWords()[source] ?? source;
}

/** Bei 410 gibt es kein Ziel — das Feld bleibt leer und ausgegraut. */
export function needsTarget(status: number): boolean {
    return status !== 410;
}

/**
 * Der Verweis eines 404-Treffers als kurzer Text: ohne Schema, ohne «www.», höchstens `max` Zeichen mit «…».
 * Er wird nie als Link gezeigt — fremde Adressen öffnet man nicht aus dem Protokoll.
 */
export function shortReferrer(referrer: string | null, max = 48): string {
    if (!referrer) {
        return '';
    }
    const text = referrer
        .trim()
        .replace(/^[a-z][a-z0-9+.-]*:\/\//i, '')
        .replace(/^www\./i, '')
        .replace(/\/$/, '');

    return text.length > max ? `${text.slice(0, max - 1)}…` : text;
}

/** Der Reiter aus `?tab=…` der Adresse, wenn es ihn gibt — sonst der Vorgabe-Reiter. */
export function tabFromUrl(
    url: string,
    keys: string[],
    fallback: string,
): string {
    const query = url.includes('?') ? url.slice(url.indexOf('?') + 1) : '';
    const tab = new URLSearchParams(query.split('#')[0]).get('tab');

    return tab && keys.includes(tab) ? tab : fallback;
}
