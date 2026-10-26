import { describe, expect, test } from 'vitest';
import type { TranslationField, TranslationSection } from '../types';
import {
    fillableCount,
    fillEmpty,
    initialValues,
    isBlank,
    progressOf,
    progressText,
    takeOver,
} from './translation';

const field = (
    path: string,
    patch: Partial<TranslationField> = {},
): TranslationField => ({
    path,
    label: path,
    type: 'text',
    translatable: true,
    source: `Quelle ${path}`,
    target: null,
    source_html: null,
    previous: null,
    previous_html: null,
    changed: false,
    ...patch,
});

const doc = (text: string) => ({
    type: 'doc',
    content: [
        {
            type: 'paragraph',
            content: text ? [{ type: 'text', text }] : [],
        },
    ],
});

const sections = (fields: TranslationField[]): TranslationSection[] => [
    { key: 'page', title: 'Seite', subtitle: null, fields },
];

describe('isBlank', () => {
    test('erkennt leere Texte, Listen und Fliesstexte ohne Inhalt', () => {
        expect(isBlank(null)).toBe(true);
        expect(isBlank('  ')).toBe(true);
        expect(isBlank([])).toBe(true);
        expect(isBlank(doc(''))).toBe(true);
        expect(isBlank(doc('Hallo'))).toBe(false);
        expect(isBlank({ type: 'doc', content: [{ type: 'image' }] })).toBe(
            false,
        );
        expect(isBlank('Services')).toBe(false);
        expect(isBlank(0)).toBe(false);
    });
});

describe('Aus Standardsprache übernehmen', () => {
    test('füllt nur leere übersetzbare Felder', () => {
        const fields = [
            field('title', { target: 'Services' }),
            field('intro'),
            field('content.0.data.text', {
                type: 'richtext',
                source: doc('Text'),
                target: doc(''),
            }),
            field('content.0.data.image', { translatable: false, source: 7 }),
        ];
        const values = initialValues(sections(fields));

        expect(Object.keys(values)).toEqual([
            'title',
            'intro',
            'content.0.data.text',
        ]);
        expect(fillableCount(values, fields)).toBe(2);

        const { values: next, filled } = fillEmpty(values, fields);

        expect(filled).toBe(2);
        expect(next.title).toBe('Services');
        expect(next.intro).toBe('Quelle intro');
        expect(next['content.0.data.text']).toEqual(doc('Text'));
        expect(next).not.toHaveProperty('content.0.data.image');
    });

    test('kopiert den Fliesstext, statt ihn mit der Quelle zu teilen', () => {
        const source = doc('Text');
        const fields = [field('body', { type: 'richtext', source })];
        const { values } = fillEmpty({ body: null }, fields);

        expect(values.body).toEqual(source);
        expect(values.body).not.toBe(source);
    });
});

describe('→ übernehmen', () => {
    test('kopiert genau ein Feld und lässt die anderen stehen', () => {
        const fields = [field('title'), field('intro')];
        const values = takeOver({ title: '', intro: '' }, fields[1]!);

        expect(values).toEqual({ title: '', intro: 'Quelle intro' });
    });

    test('lässt geteilte Felder aus', () => {
        const shared = field('image', { translatable: false });
        const values = { title: 'x' };

        expect(takeOver(values, shared)).toBe(values);
    });
});

describe('Zähler', () => {
    test('zählt übersetzt, übersetzbar und geändert — geteilte Felder zählen nicht', () => {
        const fields = [
            field('title', { target: 'Services' }),
            field('lead', { changed: true, target: 'Old' }),
            field('body', { changed: true }),
            field('image', { translatable: false, changed: true }),
        ];
        const values = initialValues(sections(fields));

        expect(progressOf(values, fields)).toEqual({
            translated: 2,
            total: 3,
            changed: 2,
        });

        const filled = takeOver(values, fields[2]!);
        expect(progressOf(filled, fields).translated).toBe(3);
    });

    test('ein Feld ohne Inhalt in der Quelle zählt nicht als übersetzbar', () => {
        const fields = [
            field('title', { target: 'x' }),
            field('intro', { source: '' }),
        ];

        expect(progressOf(initialValues(sections(fields)), fields)).toEqual({
            translated: 1,
            total: 1,
            changed: 0,
        });
    });

    test('formuliert den Satz der Fussleiste', () => {
        expect(
            progressText({ translated: 14, total: 17, changed: 2 }, 'en'),
        ).toBe(
            '14 von 17 Feldern übersetzt · 2 Felder seit der letzten EN-Fassung geändert',
        );
        expect(
            progressText({ translated: 1, total: 1, changed: 0 }, 'it'),
        ).toBe('1 von 1 Feld übersetzt');
    });
});
