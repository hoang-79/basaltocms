import { ref, watch } from 'vue';
import type { Ref } from 'vue';
import { getJson } from '../http';
import { t } from '../i18n';
import type { FieldValue, SchemaField, ThemeImage } from '../types';

/**
 * Geführtes Blockformular (TB 30): welche Felder zuerst stehen, was ein leeres Feld automatisch zeigt und die Linkziele
 * der Site. Reine Regeln ohne Oberfläche — BlockFields zeigt sie an, Vitest prüft sie.
 */

/** Ist ein Feld ausgefüllt? Fliesstext erst mit Text, Link mit Adresse oder Beschriftung, Bild mit Datei. */
export function isFilled(value: FieldValue | undefined): boolean {
    if (value === null || value === undefined || value === false) {
        return false;
    }
    if (typeof value === 'string') {
        return value.trim() !== '';
    }
    if (Array.isArray(value)) {
        return value.length > 0;
    }
    if (typeof value === 'object') {
        const record = value as Record<string, unknown>;
        if (record.type === 'doc') {
            return /"text":"[^"]*\S/.test(JSON.stringify(record));
        }

        return Object.values(record).some((v) =>
            typeof v === 'string'
                ? v.trim() !== ''
                : v !== null && v !== undefined,
        );
    }

    return true;
}

/** Die Art eines Felds für «das Wichtigste»: je Art steht das erste Feld oben (Titel, Text, Bild, Link, Inhalt). */
function kindOf(field: SchemaField): string | null {
    const type = field.type;
    if (field.pick === 'image') {
        return 'image';
    }
    if (['text', 'slug', 'email', 'url'].includes(type)) {
        return 'line';
    }
    if (['textarea', 'richtext', 'markdown'].includes(type)) {
        return 'body';
    }
    if (['image', 'gallery'].includes(type)) {
        return 'image';
    }
    if (['link', 'blocks', 'repeater', 'relation'].includes(type)) {
        return type;
    }

    // Auswahl, Schalter, Zahl, Farbe, Datum, Code … sind Feineinstellungen
    return null;
}

/**
 * Die Felder, die sofort sichtbar sind — der Rest steht unter «Weitere Möglichkeiten». Pflicht, Ausgefülltes und
 * Felder mit Fehler stehen immer oben. Kennzeichnet das Theme Felder mit `advanced`, gilt nur das; sonst steht je Art
 * das erste Feld oben. Blöcke mit bis zu vier Feldern zeigen alles.
 */
export function primaryFields(
    fields: SchemaField[],
    value: Record<string, FieldValue>,
    errors: (name: string) => boolean = () => false,
): Set<string> {
    if (fields.length <= 4) {
        return new Set(fields.map((f) => f.name));
    }
    const explicit = fields.some((f) => f.advanced);
    const seen = new Set<string>();
    const primary = new Set<string>();
    for (const field of fields) {
        const kind = kindOf(field);
        const first = kind !== null && !seen.has(kind);
        if (kind !== null) {
            seen.add(kind);
        }
        if (
            field.required ||
            isFilled(value[field.name]) ||
            errors(field.name) ||
            (explicit ? !field.advanced : first)
        ) {
            primary.add(field.name);
        }
    }
    // Mindestens drei Felder oben, wenn der Block sie hat: der Reihe nach Text und Inhalt, aber keine abgeleiteten
    // («cta_label» gilt erst, wenn jemand vom Titel der Zielseite abweichen will)
    if (!explicit) {
        for (const field of fields) {
            if (primary.size >= 3) {
                break;
            }
            if (kindOf(field) !== null && sourceOf(field, fields) === null) {
                primary.add(field.name);
            }
        }
    }
    if (primary.size === 0 && fields[0]) {
        primary.add(fields[0].name);
    }

    return primary;
}

/** Das Feld, dessen Wert gilt, solange dieses leer ist: `from` aus dem Theme oder «x_label» neben einem Link «x». */
export function sourceOf(
    field: SchemaField,
    fields: SchemaField[],
): { name: string; part: string | null } | null {
    if (field.from) {
        const [name, part] = field.from.split('.');

        return name ? { name, part: part ?? null } : null;
    }
    const match = /^(.+)_label$/.exec(field.name);
    if (
        match?.[1] &&
        fields.some((f) => f.name === match[1] && f.type === 'link')
    ) {
        return { name: match[1], part: 'label' };
    }

    return null;
}

export type LinkTarget = { path: string; title: string };

/** Der Titel der Zielseite einer Adresse, wenn sie eine Seite der Site ist. */
export function targetTitle(
    href: string,
    targets: LinkTarget[],
): string | null {
    const path = href.trim().replace(/[?#].*$/, '');
    if (path === '') {
        return null;
    }

    return targets.find((t) => t.path === path)?.title || null;
}

/**
 * Was ein leeres Feld automatisch zeigt — als Platzhalter «leer = «Kontakt»»: der Wert des Quellfelds, bei einem Link
 * dessen Beschriftung oder der Titel der Zielseite. Null, wenn nichts abzuleiten ist.
 */
export function derivedValue(
    field: SchemaField,
    fields: SchemaField[],
    value: Record<string, FieldValue>,
    targets: LinkTarget[],
): string | null {
    const source = sourceOf(field, fields);
    if (source === null) {
        return null;
    }
    const raw = value[source.name] as unknown;
    const sourceField = fields.find((f) => f.name === source.name);
    if (sourceField?.type === 'link' && raw && typeof raw === 'object') {
        const link = raw as { href?: unknown; label?: unknown };
        const label = typeof link.label === 'string' ? link.label.trim() : '';
        if (label !== '' && (source.part === 'label' || source.part === null)) {
            return label;
        }

        return targetTitle(
            typeof link.href === 'string' ? link.href : '',
            targets,
        );
    }
    if (typeof raw === 'string' && raw.trim() !== '') {
        return raw.trim();
    }
    if (raw && typeof raw === 'object' && source.part) {
        const part = (raw as Record<string, unknown>)[source.part];

        return typeof part === 'string' && part.trim() !== ''
            ? part.trim()
            : null;
    }

    return null;
}

/** Platzhalter eines leeren Felds: abgeleiteter Wert, sonst das Beispiel aus dem Theme. */
export function placeholderFor(
    field: SchemaField,
    fields: SchemaField[],
    value: Record<string, FieldValue>,
    targets: LinkTarget[],
): string {
    const derived = derivedValue(field, fields, value, targets);
    if (derived !== null) {
        return t('leer = «:value»', { value: derived });
    }

    return field.placeholder ?? '';
}

/** Pflichtfelder und wie viele davon ausgefüllt sind — für «Pflichtangaben 1 von 2». */
export function requiredProgress(
    fields: SchemaField[],
    value: Record<string, FieldValue>,
): { done: number; total: number } {
    const required = fields.filter((f) => f.required);

    return {
        done: required.filter((f) => isFilled(value[f.name])).length,
        total: required.length,
    };
}

const targets = ref<LinkTarget[]>([]);
let loaded: string | null = null;

/** Linkziele der Site — einmal je Seite geladen und von allen Feldern geteilt. */
export function useLinkTargets(
    url: string | null | undefined,
): Ref<LinkTarget[]> {
    if (url && loaded !== url) {
        loaded = url;
        getJson<{ targets?: LinkTarget[] }>(url)
            .then((data) => {
                targets.value = data.targets ?? [];
            })
            .catch(() => {
                loaded = null;
            });
    }

    return targets;
}

/**
 * Formular ↔ Vorschau: die Eingabe «blocks.0.title» (Liste `blocks`, Block 0, Feld title) heisst in der Vorschau
 * «content.0.data.title». Nur Felder direkt im Block — verschachtelte zeigen auf ihren Block («content.0.»).
 */
export function markOf(inputId: string, listPath: string): string | null {
    if (!inputId.startsWith(`${listPath}.`)) {
        return null;
    }
    const rest = inputId.slice(listPath.length + 1);
    const match = /^(\d+)\.([a-z0-9_]+)(\.|$)/i.exec(rest);
    if (!match) {
        return null;
    }

    return match[3] === '.'
        ? `content.${match[1]}.`
        : `content.${match[1]}.data.${match[2]}`;
}

/** Umgekehrt: die Marke aus der Vorschau → die Eingabe im Formular. */
export function inputOf(mark: string, listPath: string): string | null {
    const match = /^content\.(\d+)\.data\.([a-z0-9_]+)$/i.exec(mark);

    return match ? `${listPath}.${match[1]}.${match[2]}` : null;
}

const OPEN = 'basalto.vorschau-offen';

/** Ist das Vorschau-Fenster offen? Ab Werk ja; wer es schliesst, findet es geschlossen wieder (je Browser). */
export function usePreviewOpen(): Ref<boolean> {
    const open = ref(window.localStorage.getItem(OPEN) !== '0');
    watch(open, (value) =>
        window.localStorage.setItem(OPEN, value ? '1' : '0'),
    );

    return open;
}

/**
 * Eine Eingabe im Feld «Adresse» eines Links: schon eine Adresse (/…, https://, mailto:, tel:, #) bleibt; ein Titel
 * einer Seite der Site («Kontakt») wird zu ihrer Adresse («/kontakt»). Sonst null — dann ist es keine Adresse.
 */
export function linkAddress(
    input: string,
    targets: LinkTarget[],
): string | null {
    const value = input.trim();
    if (value === '' || /^(\/|#|[a-z][a-z0-9+.-]*:)/i.test(value)) {
        return value;
    }
    const lower = value.toLowerCase();
    const match = targets.find((t) => t.title.trim().toLowerCase() === lower);

    return match ? match.path : null;
}

const themeImages = ref<ThemeImage[]>([]);
let themeImagesLoaded: string | null = null;

/** Bilder unter assets/ des Themes — einmal je Seite geladen, für Vorschau und Auswahl in Bildfeldern. */
export function useThemeImages(
    url: string | null | undefined,
): Ref<ThemeImage[]> {
    if (url && themeImagesLoaded !== url) {
        themeImagesLoaded = url;
        getJson<{ images?: ThemeImage[] }>(url)
            .then((data) => {
                themeImages.value = data.images ?? [];
            })
            .catch(() => {
                themeImagesLoaded = null;
            });
    }

    return themeImages;
}

/** Der Pfad eines Bildfelds ohne führendes «assets/» oder «/» — so steht er in der Liste der Theme-Bilder. */
export function themeImagePath(value: string): string {
    return value
        .trim()
        .replace(/^\/+/, '')
        .replace(/^assets\//, '');
}
