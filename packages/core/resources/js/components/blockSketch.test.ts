import { describe, expect, it } from 'vitest';
import type { SchemaBlock, SchemaField } from '../types';
import { blockGroup, blockHint, blockSketch } from './blockSketch';

const f = (
    name: string,
    type: string,
    extra: Partial<SchemaField> = {},
): SchemaField => ({
    name,
    label: name,
    type,
    required: false,
    translatable: false,
    options: [],
    default: null,
    allow: [],
    relation: null,
    fields: [],
    ...extra,
});
const block = (
    name: string,
    fields: SchemaField[],
    extra: Partial<SchemaBlock> = {},
): SchemaBlock => ({ name, label: name, fields, ...extra });

describe('blockSketch', () => {
    it('Bühne: Überschrift, Text und Knöpfe links, Bild rechts — Begleitfelder zeichnen nichts', () => {
        const hero = block('hero', [
            f('title', 'text', { required: true }),
            f('lead', 'richtext'),
            f('image', 'image'),
            f('cta', 'link'),
            f('cta_label', 'text'),
            f('more', 'link'),
        ]);
        expect(blockSketch(hero)).toEqual({
            layout: 'split',
            main: [
                { kind: 'heading' },
                { kind: 'lines', count: 3 },
                { kind: 'buttons', count: 2 },
            ],
            side: [{ kind: 'image' }],
        });
    });

    it('Bild mit Bildunterschrift steht untereinander, auch wenn das Bild ein Text-Feld «art» ist', () => {
        const visual = block('visual', [
            f('art', 'text', { required: true }),
            f('art_overlay', 'text'),
            f('caption', 'text'),
        ]);
        expect(blockSketch(visual)).toEqual({
            layout: 'stack',
            main: [{ kind: 'image' }, { kind: 'lines', count: 1 }],
            side: [],
        });
    });

    it('Wiederholungen nach ihren Feldern: Fragen, Zahlen, Schritte, Karten mit Bild', () => {
        const repeater = (inner: SchemaField[]) =>
            f('items', 'repeater', { fields: inner });
        expect(
            blockSketch(
                block('faq', [
                    repeater([f('question', 'text'), f('answer', 'richtext')]),
                ]),
            ).main,
        ).toEqual([{ kind: 'accordion', count: 3 }]);
        expect(
            blockSketch(
                block('stats', [
                    repeater([f('value', 'text'), f('label', 'text')]),
                ]),
            ).main,
        ).toEqual([{ kind: 'stats', count: 3 }]);
        expect(
            blockSketch(
                block('steps', [
                    f('title', 'text'),
                    repeater([f('title', 'text')]),
                ]),
            ).main.at(-1),
        ).toEqual({ kind: 'steps', count: 3 });
        expect(
            blockSketch(
                block('cards', [
                    repeater([f('image', 'image'), f('title', 'text')]),
                ]),
            ).main,
        ).toEqual([{ kind: 'cards', count: 3, image: true }]);
    });

    it('ohne Inhaltsfelder «aus Daten»; Formular und Liste als eigene Form', () => {
        expect(blockSketch(block('search', []))).toEqual({
            layout: 'stack',
            main: [{ kind: 'data' }],
            side: [],
        });
        expect(
            blockSketch(
                block('form', [f('form', 'relation', { relation: 'form' })]),
            ).main,
        ).toEqual([{ kind: 'form' }]);
        expect(blockSketch(block('posts', [f('per', 'number')])).main).toEqual([
            { kind: 'list', count: 4 },
        ]);
    });

    it('viele Textfelder werden zu höchstens vier Zeilen', () => {
        const sketch = blockSketch(
            block('notes', [
                f('a', 'richtext'),
                f('b', 'richtext'),
                f('c', 'text'),
            ]),
        );
        expect(sketch.main).toEqual([{ kind: 'lines', count: 4 }]);
    });
});

describe('blockGroup und blockHint', () => {
    it('Gruppe und Satz aus dem Theme haben Vorrang', () => {
        const b = block('hero', [f('title', 'text')], {
            group: 'Einstieg',
            hint: 'Oben auf der Seite.',
        });
        expect(blockGroup(b)).toBe('Einstieg');
        expect(blockHint(b)).toBe('Oben auf der Seite.');
    });

    it('ohne Angabe nach den Feldern', () => {
        expect(blockGroup(block('text', [f('body', 'richtext')]))).toBe('Text');
        expect(
            blockGroup(
                block('image', [f('image', 'image'), f('caption', 'text')]),
            ),
        ).toBe('Bilder');
        expect(
            blockGroup(
                block('hero', [
                    f('title', 'text'),
                    f('image', 'image'),
                    f('cta', 'link'),
                ]),
            ),
        ).toBe('Einstieg');
        expect(
            blockGroup(
                block('faq', [
                    f('items', 'repeater', { fields: [f('question', 'text')] }),
                ]),
            ),
        ).toBe('Listen und Raster');
        expect(
            blockGroup(
                block('cta', [
                    f('title', 'text', { required: true }),
                    f('text', 'text'),
                    f('link', 'link'),
                ]),
            ),
        ).toBe('Aktionen');
        expect(blockGroup(block('search', []))).toBe('Aus Daten');
        expect(
            blockHint(
                block('quote', [
                    f('text', 'richtext', { label: 'Zitat' }),
                    f('source', 'text', { label: 'Quelle' }),
                    f('align', 'select'),
                ]),
            ),
        ).toBe('Zitat · Quelle');
    });
});
