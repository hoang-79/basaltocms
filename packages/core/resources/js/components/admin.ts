import { t, tc } from '../i18n';
import type { AdminInvitation, AdminUser, SettingItem } from '../types-admin';

/** Einzahl oder Mehrzahl mit Zahl: «1 Konto», «3 Konten». */
export function counted(n: number, one: string, many: string): string {
    return `${n} ${n === 1 ? one : many}`;
}

/**
 * Ein Wert im Verlauf oder Protokoll als kurzer Text: ein/aus, Zahl mit Einheit, Listen mit Komma,
 * leer als «—», Objekte als knappes JSON.
 */
export function valueText(value: unknown, unit: string | null = null): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    if (typeof value === 'boolean') {
        return value ? t('ein') : t('aus');
    }
    if (typeof value === 'number') {
        return unit ? `${value} ${unit}` : String(value);
    }
    if (typeof value === 'string') {
        return value;
    }
    if (Array.isArray(value)) {
        return value.length === 0
            ? '—'
            : value.map((v) => valueText(v)).join(', ');
    }

    return JSON.stringify(value);
}

/** «alt → neu» für eine Änderung (Mockup 18/3: «aus → ein», «— → ein», «DE → DE, EN»). */
export function changeText(
    old: unknown,
    next: unknown,
    unit: string | null = null,
): string {
    return `${valueText(old, unit)} → ${valueText(next, unit)}`;
}

/** Führungssatz der Benutzerliste: «5 Konten · 2 Einladungen (1 abgelaufen)». */
export function usersLead(
    users: Pick<AdminUser, 'deactivated'>[],
    invitations: Pick<AdminInvitation, 'expired'>[],
): string {
    const parts = [tc(':count Konto|:count Konten', users.length)];
    const deactivated = users.filter((u) => u.deactivated).length;
    if (deactivated > 0) {
        parts[0] += ` (${tc(':count deaktiviert|:count deaktiviert', deactivated)})`;
    }
    if (invitations.length > 0) {
        const expired = invitations.filter((i) => i.expired).length;
        parts.push(
            tc(':count Einladung|:count Einladungen', invitations.length) +
                (expired > 0
                    ? ` (${tc(':count abgelaufen|:count abgelaufen', expired)})`
                    : ''),
        );
    }

    return parts.join(' · ');
}

/** Was eine Rolle darf — der Erklärsatz unter der Rollenwahl (Mockup 18/2); Getter lesen die Sprache der Oberfläche. */
export const roleHelp: Record<string, string> = {
    get owner() {
        return t(
            'Darf alles, auch Benutzer, Rollen und Lizenz; jede Site braucht mindestens einen.',
        );
    },
    get administrator() {
        return t('Richtet die Site ein, verwaltet Benutzer und Einstellungen.');
    },
    get editor() {
        return t('Schreibt und reicht ein, pflegt Medien.');
    },
    get approver() {
        return t('Prüft, gibt frei, veröffentlicht.');
    },
    get translator() {
        return t('Nur Sprachfassungen.');
    },
};

/**
 * Die Eingabe einer Einstellung als Wert für `PUT {key, value}`: Wahrheitswert bleibt, Zahl aus dem Feld
 * (leer oder ungültig → null, damit der Server «value» als Fehler meldet).
 */
export function parseSettingInput(
    type: SettingItem['type'],
    raw: string | boolean | number | null,
): boolean | number | null {
    if (type === 'bool') {
        return raw === true || raw === 'true' || raw === '1';
    }
    if (type === 'int') {
        if (raw === null || raw === '' || typeof raw === 'boolean') {
            return null;
        }
        const n = Number(raw);

        return Number.isInteger(n) ? n : null;
    }

    return null;
}

/** Der Titel eines Protokoll-Objekts ohne Fassung und Sprache: «Leistungen · Nr. 4 (DE)» → «Leistungen». */
export function subjectTitle(subject: string): string {
    return subject.replace(/ · Nr\. \d+.*$/, '').replace(/ \([A-Z]{2}\)$/, '');
}
