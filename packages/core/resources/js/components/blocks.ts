import { toRaw } from 'vue';
import type {
    BlockValue,
    FieldValue,
    SchemaBlock,
    SchemaField,
} from '../types';
import { tc } from '../i18n';
import { truncate } from '../support';

/** Der Startwert eines Felds: Vorgabe aus Lava, sonst leer — Listen (Repeater, Blöcke, Galerie, Tags) leer. */
export function emptyValue(field: SchemaField): FieldValue {
    if (['repeater', 'blocks', 'gallery', 'tags'].includes(field.type)) {
        return [];
    }
    if (field.default !== undefined && field.default !== null) {
        return field.default as FieldValue;
    }
    if (['checkbox', 'bool'].includes(field.type)) {
        return false;
    }

    return null;
}

/** Ein neuer Block mit den Startwerten seiner Felder. */
export function newBlock(block: SchemaBlock): BlockValue {
    const data: Record<string, FieldValue> = {};
    block.fields.forEach((f) => (data[f.name] = emptyValue(f)));

    return { type: block.name, data };
}

/** Eine tiefe Kopie ohne geteilte Referenzen — fürs Duplizieren. */
export function cloneBlock(block: BlockValue): BlockValue {
    return JSON.parse(JSON.stringify(block)) as BlockValue;
}

function textOf(value: unknown): string {
    if (typeof value === 'string') {
        return value.trim();
    }
    if (value && typeof value === 'object' && 'type' in value) {
        const node = value as { text?: string; content?: unknown[] };
        if (typeof node.text === 'string') {
            return node.text;
        }

        return (node.content ?? []).map(textOf).filter(Boolean).join(' ');
    }

    return '';
}

/** Der Kurzinhalt eines Blocks für die eingeklappte Zeile und die Karten auf Mobil: erster Text, sonst Zahl der Blöcke. */
export function blockSummary(
    block: BlockValue,
    schema: SchemaBlock | undefined,
): string {
    for (const field of schema?.fields ?? []) {
        const value = block.data[field.name];
        if (
            ['text', 'textarea', 'slug', 'email', 'url', 'markdown'].includes(
                field.type,
            ) &&
            typeof value === 'string' &&
            value.trim() !== ''
        ) {
            return truncate(value.trim(), 60);
        }
        if (field.type === 'richtext') {
            const text = textOf(value);
            if (text !== '') {
                return truncate(text, 60);
            }
        }
        if (
            field.type === 'blocks' &&
            Array.isArray(value) &&
            value.length > 0
        ) {
            return tc(':count Block|:count Blöcke', value.length);
        }
        if (
            field.type === 'link' &&
            value &&
            typeof value === 'object' &&
            'label' in value &&
            typeof (value as { label: unknown }).label === 'string' &&
            (value as { label: string }).label !== ''
        ) {
            return truncate((value as { label: string }).label, 60);
        }
    }

    return '—';
}

/** Zählt die Validierungsfehler unter einem Pfad («content.2» trifft «content.2.title» und «content.2.items.0.x»). */
export function errorsUnder(
    errors: Record<string, string>,
    path: string,
): number {
    return Object.keys(errors).filter(
        (k) => k === path || k.startsWith(`${path}.`),
    ).length;
}

const keys = new WeakMap<object, number>();
let nextKey = 1;

/** Überträgt den Schlüssel auf ein neues Objekt — beim Ändern der Felder bleibt der Block derselbe. */
export function adoptKey(from: object, to: object): void {
    keys.set(toRaw(to), keyOf(from));
}

/** Ein stabiler Schlüssel je Blockobjekt (roh, nicht der Proxy) — überlebt Verschieben, anders als der Index. */
export function keyOf(block: object): number {
    const raw = toRaw(block);
    let key = keys.get(raw);
    if (key === undefined) {
        key = nextKey++;
        keys.set(raw, key);
    }

    return key;
}
