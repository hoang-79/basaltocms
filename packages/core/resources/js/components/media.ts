import { reactive } from 'vue';
import { postForm, postJson, requestError } from '../http';
import { locale, t, tc } from '../i18n';
import { intlLocale } from '../support';
import type { MediaOption, MediaRow, MediaUploadResult } from '../types';
import type { UploadedMedia, Uploader } from './richtext/paste';

/** Was in dieser Sitzung über den Einfügen-Upload in die Mediathek kam — alle Auswahlfelder sehen es sofort. */
export const uploadedMedia = reactive<MediaOption[]>([]);

function remember(item: UploadedMedia, name: string): MediaOption {
    const option: MediaOption = {
        id: item.id,
        name: item.alt ?? name,
        url: item.url,
        mime: 'image/*',
        width: item.width,
        height: item.height,
    };
    if (!uploadedMedia.some((m) => m.id === option.id)) {
        uploadedMedia.unshift(option);
    }

    return option;
}

/** Der Uploader für `POST media/paste`: Datei als `multipart/form-data`, Data-/Fremd-URL als JSON. */
export function uploaderFor(pasteUrl: string): Uploader {
    return async (payload) => {
        if ('file' in payload) {
            const form = new FormData();
            form.append('file', payload.file);
            const uploaded = await postForm<UploadedMedia>(pasteUrl, form);
            remember(uploaded, payload.file.name);

            return uploaded;
        }
        const uploaded = await postJson<UploadedMedia>(pasteUrl, {
            data: payload.data,
            name: payload.name,
        });
        remember(uploaded, payload.name);

        return uploaded;
    };
}

/** Ob ein Eintrag der Mediathek ein Bild ist — die Liste des Servers trägt bei Bildern eine Vorschau-URL. */
export function isImageOption(item: MediaOption): boolean {
    return item.mime ? item.mime.startsWith('image/') : item.url !== null;
}

/* ── Mehrfach-Upload der Mediathek (Mockup 14): ein Aufruf je Datei, Stand je Datei ── */

/** Welche Dateien die Mediathek annimmt und wie gross sie sein dürfen — dieselben Grenzen wie der Server. */
export const uploadAccept = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/gif',
    'image/svg+xml',
    'application/pdf',
] as const;

export const uploadMaxBytes = 10 * 1024 * 1024;

export type UploadState = 'waiting' | 'uploading' | 'done' | 'rejected';

/** Eine Datei im laufenden Upload: ihr Stand, der Grund einer Abweisung und die Zeile des Servers nach Erfolg. */
export type UploadEntry = {
    name: string;
    size: number;
    state: UploadState;
    error: string | null;
    media: MediaRow | null;
};

/** Prüft eine Datei vor dem Senden: Typ und Grösse — die Abweisung nennt die Datei beim Namen und hält die anderen nicht auf. */
export function rejectReason(file: File): string | null {
    if (!(uploadAccept as readonly string[]).includes(file.type)) {
        const kind =
            file.name.split('.').pop()?.toUpperCase() ?? t('Dieser Typ');

        return t(
            ':kind wird nicht unterstützt (JPG, PNG, WebP, GIF, SVG, PDF). Die anderen Dateien laufen weiter.',
            { kind },
        );
    }
    if (file.size > uploadMaxBytes) {
        return t(
            'Grösser als 10 MB (:size). Die anderen Dateien laufen weiter.',
            { size: formatBytes(file.size) },
        );
    }

    return null;
}

/** Legt je Datei einen Eintrag an; was die Vorprüfung abweist, steht sofort als abgewiesen da. */
export function createUploadEntries(files: File[]): UploadEntry[] {
    return files.map((file) => {
        const reason = rejectReason(file);

        return {
            name: file.name,
            size: file.size,
            state: reason ? 'rejected' : 'waiting',
            error: reason,
            media: null,
        };
    });
}

/**
 * Sendet die Dateien nacheinander, jede einzeln als `files[]` mit `folder_id`, und schreibt den Stand in die Einträge.
 * Ein Fehler des Servers (422, Netz) wird zur Abweisung dieser einen Datei — der Lauf geht weiter.
 */
export async function runUpload(
    url: string,
    files: File[],
    entries: UploadEntry[],
    folderId: number | null,
    onFile?: (entry: UploadEntry) => void,
): Promise<void> {
    for (const [index, file] of files.entries()) {
        const entry = entries[index];
        if (!entry || entry.state !== 'waiting') {
            continue;
        }
        entry.state = 'uploading';
        const form = new FormData();
        form.append('files[]', file);
        if (folderId !== null) {
            form.append('folder_id', String(folderId));
        }
        try {
            const result = await postForm<MediaUploadResult>(url, form);
            const answer =
                result.files?.find((f) => f.name === file.name) ??
                result.files?.[0];
            if (answer?.ok) {
                entry.state = 'done';
                entry.media = answer.media ?? null;
            } else {
                entry.state = 'rejected';
                entry.error = answer?.error ?? t('Vom Server abgewiesen.');
            }
        } catch (e) {
            entry.state = 'rejected';
            entry.error = requestError(e);
        }
        onFile?.(entry);
    }
}

/** Der Zähler über dem Upload: «2 von 3 fertig · 1 abgewiesen». */
export function uploadCounter(entries: UploadEntry[]): string {
    const done = entries.filter((e) => e.state === 'done').length;
    const rejected = entries.filter((e) => e.state === 'rejected').length;
    const counter = t(':done von :total fertig', {
        done,
        total: entries.length,
    });

    return rejected > 0
        ? `${counter} · ${tc(':count abgewiesen|:count abgewiesen', rejected)}`
        : counter;
}

export function uploadRunning(entries: UploadEntry[]): boolean {
    return entries.some(
        (e) => e.state === 'waiting' || e.state === 'uploading',
    );
}

/** Bytes lesbar: «1,8 MB», «96 kB» — auf Englisch mit Dezimalpunkt. */
export function formatBytes(bytes: number): string {
    const decimal = locale() === 'en' ? '.' : ',';
    if (bytes >= 1024 * 1024 * 1024) {
        return `${(bytes / 1024 / 1024 / 1024).toFixed(1).replace('.', decimal)} GB`;
    }
    if (bytes >= 1024 * 1024) {
        return `${(bytes / 1024 / 1024).toFixed(1).replace('.', decimal)} MB`;
    }

    return bytes <= 0 ? '0 kB' : `${Math.max(1, Math.round(bytes / 1024))} kB`;
}

/** Die Art einer Datei als Kürzel: «JPG», «PNG», «SVG», «PDF» — aus dem MIME-Typ, sonst aus der Endung. */
export function kindOf(mime: string, name: string): string {
    const known: Record<string, string> = {
        'image/jpeg': 'JPG',
        'image/png': 'PNG',
        'image/webp': 'WebP',
        'image/gif': 'GIF',
        'image/svg+xml': 'SVG',
        'application/pdf': 'PDF',
    };

    return (
        known[mime] ??
        (name.includes('.')
            ? (name.split('.').pop() ?? '').toUpperCase()
            : t('Datei'))
    );
}

/** Ein Zeitpunkt als Datum «12.10.2026» — im Format der Sprache der Oberfläche. */
export function formatDate(iso: string): string {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return iso;
    }

    return date.toLocaleDateString(intlLocale(), {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}
