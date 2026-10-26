import { t } from '../i18n';
import type { ApiTokenRow } from '../types-admin';

/** Fähigkeiten eines API-Tokens (M-17) in Worten. */
export function abilityLabel(ability: string): string {
    return ability === 'read'
        ? t('lesen')
        : ability === 'write'
          ? t('schreiben')
          : ability;
}

/** «läuft ab 31.12.2026», «abgelaufen 01.10.2026» oder «ohne Ablauf». */
export function expiryText(
    row: Pick<ApiTokenRow, 'expires' | 'expired'>,
): string {
    if (!row.expires) {
        return t('ohne Ablauf');
    }

    return row.expired
        ? t('abgelaufen :date', { date: row.expires })
        : t('läuft ab :date', { date: row.expires });
}

/**
 * Feldfehler aus einer 422-Antwort (`{ errors: { feld: [meldung] } }`) — je Feld die erste Meldung; Fehler an
 * Listenelementen (`abilities.0`) gehören zum Feld selbst.
 */
export function validationErrors(error: unknown): Record<string, string> {
    const e = error as { response?: { status?: number; data?: unknown } };
    if (e.response?.status !== 422) {
        return {};
    }
    let data = e.response.data;
    if (typeof data === 'string') {
        try {
            data = JSON.parse(data) as unknown;
        } catch {
            return {};
        }
    }
    const errors =
        data && typeof data === 'object' && 'errors' in data
            ? (data as { errors: Record<string, string[] | string> }).errors
            : {};
    const out: Record<string, string> = {};
    for (const [key, value] of Object.entries(errors)) {
        const field = key.split('.')[0];
        const message = Array.isArray(value) ? value[0] : value;
        if (field && message && !out[field]) {
            out[field] = message;
        }
    }

    return out;
}
