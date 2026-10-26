import { t, tc } from '../i18n';
import type {
    StatusKind,
    TranslationField,
    TranslationProgress,
    TranslationSection,
    TranslationState,
} from '../types';

/** Die Werte der Zielsprache: übersetzbares Feld (`path`) → Wert. */
export type TranslationValues = Record<string, unknown>;

/** Die Stand-Wörter — überall gleich (Reiter, Übersicht, Seitenbaum, Typ-Listen). */
export const translationLabels: Record<TranslationState, string> = {
    get source() {
        return t('Standardsprache');
    },
    get current() {
        return t('aktuell');
    },
    get outdated() {
        return t('veraltet');
    },
    get missing() {
        return t('fehlt');
    },
};

/** Der Stand als Statuspunkt: aktuell grün, veraltet Bernstein, fehlt gestrichelt, Standardsprache ruhig. */
export const translationStatus: Record<TranslationState, StatusKind> = {
    source: 'none',
    current: 'success',
    outdated: 'stale',
    missing: 'missing',
};

/** Knoten im Fliesstext, die ohne Text Inhalt sind. */
const contentNodes = new Set([
    'image',
    'youtube',
    'horizontalRule',
    'hardBreak',
]);

function hasRichContent(node: unknown): boolean {
    if (!node || typeof node !== 'object') {
        return false;
    }
    const n = node as { type?: unknown; text?: unknown; content?: unknown };
    if (typeof n.text === 'string' && n.text.trim() !== '') {
        return true;
    }
    if (typeof n.type === 'string' && contentNodes.has(n.type)) {
        return n.type !== 'hardBreak';
    }

    return Array.isArray(n.content) && n.content.some(hasRichContent);
}

/** Ob ein Wert leer ist: nichts, Leerzeichen, leere Liste, leeres Objekt oder ein Fliesstext ohne Inhalt. */
export function isBlank(value: unknown): boolean {
    if (value === null || value === undefined) {
        return true;
    }
    if (typeof value === 'string') {
        return value.trim() === '';
    }
    if (Array.isArray(value)) {
        return value.length === 0;
    }
    if (typeof value === 'object') {
        const doc = value as { type?: unknown };
        if (doc.type === 'doc') {
            return !hasRichContent(value);
        }

        return Object.keys(value).length === 0;
    }

    return false;
}

/** Eine tiefe Kopie, damit Quelle und Ziel keinen Fliesstext teilen. */
export function copyValue<T>(value: T): T {
    if (value === null || value === undefined || typeof value !== 'object') {
        return value;
    }

    return JSON.parse(JSON.stringify(value)) as T;
}

export function allFields(sections: TranslationSection[]): TranslationField[] {
    return sections.flatMap((s) => s.fields);
}

/** Die Startwerte: je übersetzbares Feld der gespeicherte Wert der Zielsprache. */
export function initialValues(
    sections: TranslationSection[],
): TranslationValues {
    const values: TranslationValues = {};
    allFields(sections)
        .filter((f) => f.translatable)
        .forEach((f) => {
            values[f.path] = copyValue(f.target ?? null);
        });

    return values;
}

/** «→ übernehmen»: genau dieses Feld aus der Standardsprache. Geteilte Felder bleiben, wie sie sind. */
export function takeOver(
    values: TranslationValues,
    field: TranslationField,
): TranslationValues {
    if (!field.translatable) {
        return values;
    }

    return { ...values, [field.path]: copyValue(field.source ?? null) };
}

/** «Aus Standardsprache übernehmen»: füllt alle leeren übersetzbaren Felder — Übersetztes bleibt stehen. */
export function fillEmpty(
    values: TranslationValues,
    fields: TranslationField[],
): { values: TranslationValues; filled: number } {
    const next: TranslationValues = { ...values };
    let filled = 0;
    fields.forEach((f) => {
        if (f.translatable && isBlank(next[f.path]) && !isBlank(f.source)) {
            next[f.path] = copyValue(f.source);
            filled++;
        }
    });

    return { values: next, filled };
}

/** Wie viele leere übersetzbare Felder «Aus Standardsprache übernehmen» füllen würde. */
export function fillableCount(
    values: TranslationValues,
    fields: TranslationField[],
): number {
    return fields.filter(
        (f) => f.translatable && isBlank(values[f.path]) && !isBlank(f.source),
    ).length;
}

/**
 * Der Zähler wie der Server: übersetzbar sind Felder mit Inhalt in der Quelle; übersetzt, wenn das Ziel nicht leer ist;
 * geändert, wenn sich die Quelle seit der übersetzten Fassung geändert hat.
 */
export function progressOf(
    values: TranslationValues,
    fields: TranslationField[],
): TranslationProgress {
    const counted = fields.filter((f) => f.translatable && !isBlank(f.source));

    return {
        translated: counted.filter((f) => !isBlank(values[f.path])).length,
        total: counted.length,
        changed: counted.filter((f) => f.changed).length,
    };
}

/** «14 von 17 Feldern übersetzt · 2 Felder seit der letzten EN-Fassung geändert». */
export function progressText(
    progress: TranslationProgress,
    locale: string,
): string {
    let text = tc(
        ':translated von :count Feld übersetzt|:translated von :count Feldern übersetzt',
        progress.total,
        { translated: progress.translated },
    );
    if (progress.changed > 0) {
        text += ` · ${tc(
            ':count Feld seit der letzten :locale-Fassung geändert|:count Felder seit der letzten :locale-Fassung geändert',
            progress.changed,
            { locale: locale.toUpperCase() },
        )}`;
    }

    return text;
}

/** Ein Wert als lesbarer Text für Quelle und geteilte Felder. */
export function displayValue(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    if (typeof value === 'string' || typeof value === 'number') {
        return String(value);
    }
    if (typeof value === 'boolean') {
        return value ? t('ja') : t('nein');
    }
    if (Array.isArray(value)) {
        return value.map((v) => displayValue(v)).join(', ');
    }

    return JSON.stringify(value);
}
