import { t } from '../i18n';
import type { SchemaBlock, SchemaField } from '../types';

/**
 * TB 28: Ein Block als Schema — aus seinen Feldern gezeichnet, ohne Handarbeit für jedes Theme und jedes Plugin.
 * Überschrift als dicker Balken, Fliesstext als Zeilen, Bild als Bildfläche, Link als Knopf, Wiederholungen als
 * Karten, Zahlen, Schritte oder aufklappbare Fragen; Blöcke ohne Inhaltsfelder füllen sich «aus Daten».
 * Mit Bild und Text steht das Bild rechts (geteilt), sonst alles untereinander.
 */
export type SketchPart =
    | { kind: 'eyebrow' }
    | { kind: 'heading' }
    | { kind: 'lines'; count: number }
    | { kind: 'buttons'; count: number }
    | { kind: 'image' }
    | { kind: 'gallery' }
    | { kind: 'cards'; count: number; image: boolean }
    | { kind: 'stats'; count: number }
    | { kind: 'steps'; count: number }
    | { kind: 'accordion'; count: number }
    | { kind: 'quote' }
    | { kind: 'form' }
    | { kind: 'list'; count: number }
    | { kind: 'nested' }
    | { kind: 'data' };

export type Sketch = {
    layout: 'stack' | 'split';
    /** Links bzw. oben: Text, Knöpfe */
    main: SketchPart[];
    /** Rechts (nur geteilt): Bild, Karten auf dem Bild */
    side: SketchPart[];
};

const IMAGE_NAMES = /^(image|images|art|photo|picture|bild|cover|logo|visual)$/;
const HEADING_NAMES = /^(title|heading|headline|titel|name)$/;
const EYEBROW_NAMES = /^(eyebrow|badge|kicker|overline|label_top)$/;
const QUOTE_NAMES = /^(quote|zitat|citation)$/;
const QUESTION_NAMES = /^(question|frage|q)$/;
const FIGURE_NAMES = /^(value|number|figure|zahl|count)$/;
const LIST_COUNT_NAMES = /^(per|limit|count|anzahl)$/;
const QUIET_TYPES = [
    'select',
    'checkbox',
    'bool',
    'slug',
    'seo',
    'color',
    'date',
    'datetime',
    'json',
    'code',
    'tags',
    'number',
];

function isImage(field: SchemaField): boolean {
    return (
        field.type === 'image' ||
        (field.type === 'text' && IMAGE_NAMES.test(field.name))
    );
}

/** `art_overlay`, `art_credit`, `cta_label`: Begleiter eines anderen Felds — zeichnen nichts eigenes. */
function isCompanion(field: SchemaField, all: SchemaField[]): boolean {
    return all.some(
        (other) => other !== field && field.name.startsWith(`${other.name}_`),
    );
}

function repeaterPart(field: SchemaField, block: SchemaBlock): SketchPart {
    const inner = field.fields ?? [];
    if (inner.some((f) => QUESTION_NAMES.test(f.name))) {
        return { kind: 'accordion', count: 3 };
    }
    if (inner.some((f) => f.type === 'number' || FIGURE_NAMES.test(f.name))) {
        return { kind: 'stats', count: 3 };
    }
    if (
        /step|schritt/i.test(block.name) ||
        inner.some((f) => /^(step|schritt)$/.test(f.name))
    ) {
        return { kind: 'steps', count: 3 };
    }

    return { kind: 'cards', count: 3, image: inner.some(isImage) };
}

export function blockSketch(block: SchemaBlock): Sketch {
    const fields = block.fields.filter((f) => !isCompanion(f, block.fields));
    const main: SketchPart[] = [];
    const side: SketchPart[] = [];
    const below: SketchPart[] = [];
    let heading = false;
    let buttons = 0;
    let quote = false;

    for (const field of fields) {
        if (isImage(field)) {
            side.push({ kind: 'image' });
        } else if (field.type === 'gallery') {
            side.push({ kind: 'gallery' });
        } else if (field.type === 'repeater') {
            below.push(repeaterPart(field, block));
        } else if (field.type === 'blocks') {
            below.push({ kind: 'nested' });
        } else if (field.type === 'link') {
            buttons++;
        } else if (field.type === 'relation' && field.relation === 'form') {
            below.push({ kind: 'form' });
        } else if (
            field.type === 'number' &&
            LIST_COUNT_NAMES.test(field.name)
        ) {
            below.push({ kind: 'list', count: 4 });
        } else if (QUIET_TYPES.includes(field.type)) {
            continue;
        } else if (EYEBROW_NAMES.test(field.name)) {
            main.push({ kind: 'eyebrow' });
        } else if (
            !heading &&
            field.type === 'text' &&
            (HEADING_NAMES.test(field.name) ||
                (field.required && main.length === 0))
        ) {
            heading = true;
            main.push({ kind: 'heading' });
        } else if (
            !quote &&
            (QUOTE_NAMES.test(field.name) || block.name === 'quote')
        ) {
            quote = true;
            main.push({ kind: 'quote' });
        } else if (['richtext', 'markdown', 'textarea'].includes(field.type)) {
            main.push({ kind: 'lines', count: 3 });
        } else if (field.type === 'text' && !field.name.endsWith('_label')) {
            main.push({ kind: 'lines', count: 1 });
        }
    }
    // Aufeinanderfolgende Zeilen zusammenziehen — höchstens vier, sonst wird das Schema zum Text
    const merged: SketchPart[] = [];
    for (const part of main) {
        const last = merged[merged.length - 1];
        if (part.kind === 'lines' && last?.kind === 'lines') {
            last.count = Math.min(4, last.count + part.count);
        } else {
            merged.push({ ...part });
        }
    }
    if (buttons > 0) {
        merged.push({ kind: 'buttons', count: Math.min(2, buttons) });
    }
    if (merged.length === 0 && side.length === 0 && below.length === 0) {
        return { layout: 'stack', main: [{ kind: 'data' }], side: [] };
    }
    // Mit Bild und Überschrift: geteilt — Wiederholungen liegen dann als Karte auf dem Bild. Ohne Überschrift
    // (Bild mit Bildunterschrift) steht das Bild oben, der Text darunter
    if (side.length > 0 && heading) {
        return {
            layout: 'split',
            main: [...merged, ...below.filter((p) => p.kind === 'form')],
            side: [...side, ...below.filter((p) => p.kind !== 'form')],
        };
    }

    return { layout: 'stack', main: [...side, ...merged, ...below], side: [] };
}

/** Die Gruppe für die Palette: aus dem Theme (`group`), sonst aus dem Schema. */
export function blockGroup(
    block: SchemaBlock & { group?: string | null },
): string {
    if (block.group) {
        return t(block.group);
    }
    const parts = [...blockSketch(block).main, ...blockSketch(block).side];
    const kinds = new Set(parts.map((p) => p.kind));
    if (kinds.has('data') || kinds.has('list')) {
        return t('Aus Daten');
    }
    if (
        ['cards', 'stats', 'steps', 'accordion', 'nested'].some((k) =>
            kinds.has(k as SketchPart['kind']),
        )
    ) {
        return t('Listen und Raster');
    }
    // Knopf ohne Bild: ein Aufruf — mit Bild und Überschrift eine Bühne (unten)
    if (
        kinds.has('form') ||
        (kinds.has('buttons') && !kinds.has('image') && !kinds.has('gallery'))
    ) {
        return t('Aktionen');
    }
    if (kinds.has('image') || kinds.has('gallery')) {
        return kinds.has('heading') && kinds.has('buttons')
            ? t('Einstieg')
            : t('Bilder');
    }

    return t('Text');
}

/** Kurzbeschrieb, wenn das Theme keinen `hint` hat: die Felder in Worten. */
export function blockHint(
    block: SchemaBlock & { hint?: string | null },
): string {
    if (block.hint) {
        return block.hint;
    }
    const labels = block.fields
        .filter(
            (f) =>
                !isCompanion(f, block.fields) && !QUIET_TYPES.includes(f.type),
        )
        .map((f) => f.label);

    return labels.length === 0
        ? t('Füllt sich aus den Daten der Site.')
        : labels.slice(0, 4).join(' · ') + (labels.length > 4 ? ' …' : '');
}
