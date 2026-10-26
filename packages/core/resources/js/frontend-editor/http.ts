import { t } from '../i18n';
import type { Base, Change, SaveResult } from './saveQueue';
import { xsrfToken } from './saveQueue';
import type { VersionInfo } from './state';

/**
 * Anfragen der Insel mit `fetch` — ohne Inertia und ohne Axios: gleiche Herkunft, Sitzungs-Cookie, `X-XSRF-TOKEN`
 * aus dem Cookie (wie `resources/js/http.ts` über den XHR-Client von Inertia).
 */
export async function post(url: string, body: unknown): Promise<Response> {
    const token = xsrfToken(document.cookie);

    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-XSRF-TOKEN': token } : {}),
        },
        body: JSON.stringify(body),
    });
}

type ErrorBody = { message?: string; errors?: Record<string, string[]> };

/** Speichern in den Entwurf; Fehler als Meldung samt betroffener Felder. */
export async function save(
    url: string,
    base: Base,
    changes: Change[],
): Promise<SaveResult> {
    let response: Response;
    try {
        response = await post(url, { base, changes });
    } catch {
        return {
            ok: false,
            conflict: false,
            message: t(
                'Keine Verbindung — die Änderung ist noch nicht gespeichert.',
            ),
            fields: [],
        };
    }
    const body = (await response.json().catch(() => ({}))) as ErrorBody & {
        version?: VersionInfo;
    };
    if (response.ok && body.version) {
        return {
            ok: true,
            base: { id: body.version.id, stamp: body.version.stamp },
            message: body.message ?? '',
            version: body.version,
        };
    }

    return {
        ok: false,
        conflict: response.status === 409,
        message: errorMessage(response.status, body),
        fields: Object.keys(body.errors ?? {}),
    };
}

/** Die erste Meldung je Feld, sonst Laravels `message`, sonst der Status. */
export function errorMessage(status: number, body: ErrorBody): string {
    const first = Object.values(body.errors ?? {})[0]?.[0];
    if (first) {
        return first;
    }
    if (status === 419) {
        return t('Die Sitzung ist abgelaufen — bitte neu laden und anmelden.');
    }
    if (status === 403) {
        return t('Keine Berechtigung, diese Seite zu bearbeiten.');
    }

    return body.message || t('Speichern fehlgeschlagen (:status).', { status });
}
