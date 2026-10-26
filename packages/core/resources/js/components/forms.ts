import { locale, t, tc } from '../i18n';
import type {
    FormBuilderData,
    FormFieldInput,
    FormInput,
    FormLocale,
    FormTexts,
    SubmissionStatus,
} from '../types-forms';

/**
 * Reine Hilfen für den Formular-Baukasten und die Eingänge (Bereiche 16 und 29): Eingabe vom Server in eine feste
 * Form bringen (PHP schickt leere Texte als `[]`), Felder anlegen, duplizieren, verschieben, Optionen ↔ Zeilen,
 * Kurzbeschreibung je Feld und Fehler je Pfad.
 */

/** Kennung des Formulars wie im FormBuilder: Kleinbuchstaben, Ziffern, «_», vorne ein Buchstabe, 2–60 Zeichen. */
export const handlePattern = /^[a-z][a-z0-9_]{1,59}$/;

/** Feldname wie im FormBuilder: Schlüssel im Eingang und im CSV. */
export const fieldNamePattern = /^[a-z][a-z0-9_]{0,59}$/;

/** Feldtypen ohne Eingabe — sie tragen keinen Wert im Eingang. */
export const noInputTypes = ['explanation', 'group'];

/** Wo welche Einstellung gilt (wie FormRenderer sie ausgibt). */
export const placeholderTypes = [
    'text',
    'email',
    'number',
    'tel',
    'url',
    'textarea',
];
export const rangeTypes = ['number', 'range'];
export const lengthTypes = ['text', 'textarea'];
export const optionTypes = ['select', 'radio'];

/** Der Schlüssel eines Textes für eine Sprache: die Grundsprache ist «*». */
export function textKey(locale: FormLocale): string {
    return locale.default ? '*' : locale.code;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** Texte je Sprache als Objekt; ein ausdrücklicher Text in der Grundsprache («de») gilt als «*». */
export function normalizeTexts(value: unknown, defaultCode = ''): FormTexts {
    const out: FormTexts = {};
    if (!isRecord(value)) {
        return out;
    }
    for (const [key, text] of Object.entries(value)) {
        if (typeof text === 'string' || typeof text === 'number') {
            out[key] = String(text);
        }
    }
    if (defaultCode && out[defaultCode] !== undefined) {
        if (out['*'] === undefined || out['*'] === '') {
            out['*'] = out[defaultCode] ?? '';
        }
        delete out[defaultCode];
    }

    return out;
}

/** Optionen je Sprache als Objekt mit Listen. */
export function normalizeOptions(
    value: unknown,
    defaultCode = '',
): Record<string, string[]> {
    const out: Record<string, string[]> = {};
    if (!isRecord(value)) {
        return out;
    }
    for (const [key, list] of Object.entries(value)) {
        if (Array.isArray(list)) {
            out[key] = list.map((o) => String(o));
        }
    }
    if (defaultCode && out[defaultCode] && !out['*']) {
        out['*'] = out[defaultCode] ?? [];
        delete out[defaultCode];
    }

    return out;
}

function scalar(value: unknown): string | null {
    return typeof value === 'string' || typeof value === 'number'
        ? value === ''
            ? null
            : String(value)
        : null;
}

export function normalizeField(raw: unknown, defaultCode = ''): FormFieldInput {
    const f = isRecord(raw) ? raw : {};
    const type = typeof f.type === 'string' && f.type !== '' ? f.type : 'text';

    return {
        name: typeof f.name === 'string' ? f.name : '',
        type,
        required: f.required === true,
        labels: normalizeTexts(f.labels, defaultCode),
        placeholder: normalizeTexts(f.placeholder, defaultCode),
        help: normalizeTexts(f.help, defaultCode),
        text: normalizeTexts(f.text, defaultCode),
        options: normalizeOptions(f.options, defaultCode),
        min: scalar(f.min),
        max: scalar(f.max),
        step: scalar(f.step),
        default: scalar(f.default),
        types: scalar(f.types),
        size: scalar(f.size),
        fields:
            type === 'group' && Array.isArray(f.fields)
                ? f.fields.map((c) => ({
                      ...normalizeField(c, defaultCode),
                      fields: [],
                  }))
                : [],
    };
}

/** Die Eingabe vom Server in fester Form — jedes Feld mit allen Schlüsseln, Texte als Objekte. */
export function normalizeForm(raw: unknown, defaultCode = ''): FormInput {
    const f = isRecord(raw) ? raw : {};

    return {
        handle: typeof f.handle === 'string' ? f.handle : '',
        labels: normalizeTexts(f.labels, defaultCode),
        fields: Array.isArray(f.fields)
            ? f.fields.map((x) => normalizeField(x, defaultCode))
            : [],
        to: typeof f.to === 'string' ? f.to : '',
        subject: typeof f.subject === 'string' ? f.subject : '',
        thanks: normalizeTexts(f.thanks, defaultCode),
        submit: normalizeTexts(f.submit, defaultCode),
        consent: normalizeTexts(f.consent, defaultCode),
        store: f.store !== false,
        redirect: typeof f.redirect === 'string' ? f.redirect : '',
        webhook: typeof f.webhook === 'string' ? f.webhook : '',
        retain: scalar(f.retain) ?? '',
    };
}

/** Die Eingabe als Daten des Baukastens: `submit` heisst dort `submit_label` (bei `useForm` ist `submit` die Methode). */
export function toBuilderData(input: FormInput): FormBuilderData {
    const { submit, ...rest } = input;

    return { ...rest, submit_label: submit };
}

/** Zurück in die Form, die der Server liest. */
export function fromBuilderData(data: FormBuilderData): FormInput {
    const { submit_label, ...rest } = data;

    return { ...rest, submit: submit_label };
}

/** Alle Feldnamen, auch in Gruppen. */
export function fieldNames(fields: FormFieldInput[]): string[] {
    return fields.flatMap((f) => [f.name, ...fieldNames(f.fields)]);
}

/** Ein freier Name: «email», sonst «email_2», «email_3» … */
export function uniqueName(base: string, taken: string[]): string {
    const clean =
        base
            .toLowerCase()
            .replace(/[^a-z0-9_]/g, '_')
            .replace(/^[^a-z]+/, '')
            .replace(/_\d+$/, '')
            .slice(0, 50) || 'feld';
    if (!taken.includes(clean)) {
        return clean;
    }
    let n = 2;
    while (taken.includes(`${clean}_${n}`)) {
        n++;
    }

    return `${clean}_${n}`;
}

const baseNames: Record<string, string> = {
    textarea: 'nachricht',
    explanation: 'hinweis',
    group: 'gruppe',
    tel: 'telefon',
    date: 'datum',
    file: 'datei',
    select: 'auswahl',
    checkbox: 'bestaetigung',
    hidden: 'versteckt',
    number: 'zahl',
    range: 'bereich',
};

/** Ein neues Feld eines Typs mit freiem Namen; die Beschriftung in der Grundsprache ist der Name des Typs. */
export function newField(
    type: string,
    typeLabel: string,
    taken: string[],
): FormFieldInput {
    const field = normalizeField({
        type,
        name: uniqueName(baseNames[type] ?? type, taken),
    });
    if (type === 'explanation') {
        field.text = { '*': '' };
    } else {
        field.labels = { '*': typeLabel };
    }
    if (optionTypes.includes(type)) {
        field.options = { '*': [] };
    }

    return field;
}

function copyField(field: FormFieldInput, taken: string[]): FormFieldInput {
    const copy = JSON.parse(JSON.stringify(field)) as FormFieldInput;
    copy.name = uniqueName(field.name, taken);
    taken.push(copy.name);
    copy.fields = field.fields.map((c) => ({
        ...copyField(c, taken),
        fields: [],
    }));

    return copy;
}

/**
 * Ein Feld duplizieren — die Kopie steht direkt darunter, sie und ihre Unterfelder bekommen freie Namen.
 * `taken` sind alle Namen im Formular (auch ausserhalb der Liste, z. B. bei Unterfeldern einer Gruppe).
 */
export function duplicateField(
    list: FormFieldInput[],
    index: number,
    taken: string[] = fieldNames(list),
): FormFieldInput[] {
    const field = list[index];
    if (!field) {
        return list;
    }
    const names = [...taken];
    const next = [...list];
    next.splice(index + 1, 0, copyField(field, names));

    return next;
}

/** Ein Eintrag an eine andere Stelle (Ziehen oder Hoch/Runter); ausserhalb der Liste bleibt alles, wie es ist. */
export function moveItem<T>(list: T[], from: number, to: number): T[] {
    if (
        from === to ||
        from < 0 ||
        to < 0 ||
        from >= list.length ||
        to >= list.length
    ) {
        return list;
    }
    const next = [...list];
    const [item] = next.splice(from, 1);
    if (item === undefined) {
        return list;
    }
    next.splice(to, 0, item);

    return next;
}

/** Optionen als Text — eine je Zeile. */
export function optionsToLines(options: string[] | undefined): string {
    return (options ?? []).join('\n');
}

/**
 * Zeilen zurück in Optionen. Leere Zeilen bleiben stehen, solange getippt wird (sonst verschwände der Zeilenumbruch
 * unter dem Cursor) — der Server wirft leere und trimmt.
 */
export function linesToOptions(text: string): string[] {
    return text === '' ? [] : text.replace(/\r/g, '').split('\n');
}

/** Die Optionen, die zählen: getrimmt, ohne leere. */
export function cleanOptions(options: string[] | undefined): string[] {
    return (options ?? []).map((o) => o.trim()).filter((o) => o !== '');
}

/** Kurzbeschreibung eines Felds in der Liste: «radio · 4 Optionen», «file · PDF, DOCX · bis 5 MB». */
export function fieldSummary(field: FormFieldInput): string {
    const parts: string[] = [field.type];
    const has = (v: string | number | null): boolean => v !== null && v !== '';
    if (optionTypes.includes(field.type)) {
        const n = cleanOptions(field.options['*']).length;
        parts.push(
            n === 0
                ? t('ohne Optionen')
                : tc(':count Option|:count Optionen', n),
        );
    }
    if (rangeTypes.includes(field.type) && (has(field.min) || has(field.max))) {
        parts.push(
            `${has(field.min) ? field.min : '…'}–${has(field.max) ? field.max : '…'}`,
        );
    }
    if (lengthTypes.includes(field.type) && has(field.max)) {
        parts.push(t('max. :max Zeichen', { max: field.max ?? '' }));
    }
    if (field.type === 'file') {
        const types = (field.types ?? '')
            .split(',')
            .map((t) => t.trim().toUpperCase())
            .filter((t) => t !== '');
        parts.push(types.length > 0 ? types.join(', ') : t('PDF und Bilder'));
        parts.push(
            t('bis :size MB', {
                size: has(field.size) ? (field.size ?? '') : 10,
            }),
        );
    }
    if (field.type === 'group') {
        const n = field.fields.length;
        parts.push(tc(':count Feld|:count Felder', n));
    }
    if (field.type === 'hidden' && has(field.default)) {
        parts.push(`= ${field.default}`);
    }
    if (!noInputTypes.includes(field.type)) {
        parts.push(field.required ? t('Pflicht') : t('freiwillig'));
    }

    return parts.join(' · ');
}

/** Die Fehler unter einem Pfad («fields.3» trifft «fields.3.fields.1.name»). */
export function errorsUnder(
    errors: Record<string, string>,
    prefix: string,
): string[] {
    return Object.entries(errors)
        .filter(([k]) => k === prefix || k.startsWith(`${prefix}.`))
        .map(([, v]) => v);
}

/** Sind alle Namen (auch in Gruppen) gültig? Sonst wartet das Speichern. */
export function namesValid(fields: FormFieldInput[]): boolean {
    return fields.every(
        (f) => fieldNamePattern.test(f.name) && namesValid(f.fields),
    );
}

/* ── Eingänge ── */

export function submissionStatusWord(status: string): string {
    const words: Record<SubmissionStatus, string> = {
        new: t('neu'),
        read: t('gelesen'),
        answered: t('beantwortet'),
    };

    return words[status as SubmissionStatus] ?? status;
}

export function submissionStatusTone(
    status: string,
): 'lava' | 'grey' | 'success' {
    return status === 'new'
        ? 'lava'
        : status === 'answered'
          ? 'success'
          : 'grey';
}

/** Zustellung (Mail, Webhook) als Wort. */
export function deliveryWord(status: string | null): string {
    switch (status) {
        case 'sent':
            return t('zugestellt');
        case 'failed':
            return t('nicht zugestellt');
        case 'pending':
            return t('wartet auf den Versand');
        case 'none':
        case null:
            return t('keine');
        default:
            return status;
    }
}

/** Zahlformat der Sprache der Oberfläche: «de-CH», «en-GB», «it-CH», «fr-CH». */
function numberLocale(): string {
    return `${locale()}-${locale() === 'en' ? 'GB' : 'CH'}`;
}

/** Dateigrösse knapp: «820 B», «12 KB», «4.2 MB». */
export function fileSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    return `${(bytes / 1024 / 1024).toLocaleString(numberLocale(), { maximumFractionDigits: 1 })} MB`;
}
