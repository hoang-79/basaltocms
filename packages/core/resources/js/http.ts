import { http } from '@inertiajs/vue3';
import { t } from './i18n';

/**
 * Einzelne Anfragen neben Inertia-Besuchen (Vorschau, Upload beim Einfügen, Link für Dritte) über den XHR-Client
 * von Inertia v3 — er setzt `X-XSRF-TOKEN` aus dem Cookie und `X-Requested-With` selbst.
 */
export async function postJson<T>(url: string, data: unknown): Promise<T> {
    const response = await http.getClient().request({
        method: 'post',
        url,
        data,
        headers: { Accept: 'application/json' },
    });

    return JSON.parse(response.data) as T;
}

/** Eine GET-Anfrage mit JSON-Antwort (Auswahl im Block-Editor, TB 10 Scheibe 3). */
export async function getJson<T>(url: string): Promise<T> {
    const response = await http.getClient().request({
        method: 'get',
        url,
        headers: { Accept: 'application/json' },
    });

    return JSON.parse(response.data) as T;
}

/** Eine Datei (oder mehrere Felder) als `multipart/form-data`. */
export async function postForm<T>(url: string, form: FormData): Promise<T> {
    const response = await http.getClient().request({
        method: 'post',
        url,
        data: form,
        headers: { Accept: 'application/json' },
    });

    return JSON.parse(response.data) as T;
}

/** Eine Anfrage, deren Antwort HTML ist (die Live-Vorschau). */
export async function postHtml(url: string, data: unknown): Promise<string> {
    const response = await http.getClient().request({
        method: 'post',
        url,
        data,
        headers: { Accept: 'text/html' },
    });

    return response.data;
}

/** Die Fehlermeldung einer gescheiterten Anfrage: Laravels `message`, sonst der Status. */
export function requestError(error: unknown): string {
    const e = error as {
        response?: { status?: number; data?: unknown };
        message?: string;
    };
    const data = e.response?.data;
    if (typeof data === 'string') {
        try {
            const parsed = JSON.parse(data) as { message?: string };
            if (parsed.message) {
                return parsed.message;
            }
        } catch {
            /* kein JSON */
        }
    } else if (data && typeof data === 'object' && 'message' in data) {
        return String((data as { message: unknown }).message);
    }
    if (e.response?.status) {
        return t('Anfrage fehlgeschlagen (:status)', {
            status: e.response.status,
        });
    }

    return e.message ?? t('Anfrage fehlgeschlagen');
}

/** Vorschau-HTML für ein `srcdoc`: ohne `<base>` lösten relative Adressen (Bilder, CSS) gegen `about:srcdoc` auf. */
export function withBase(html: string): string {
    if (/<base\s/i.test(html)) {
        return html;
    }
    const base = `<base href="${window.location.origin}/">`;

    return /<head[^>]*>/i.test(html)
        ? html.replace(/<head[^>]*>/i, (head) => `${head}${base}`)
        : `${base}${html}`;
}
