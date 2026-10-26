import { describe, expect, it } from 'vitest';
import type { SchemaField } from '../types';
import {
    derivedValue,
    inputOf,
    isFilled,
    linkAddress,
    markOf,
    placeholderFor,
    primaryFields,
    requiredProgress,
} from './guidedFields';

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

/** Der Hero des Themes «basalto»: 14 Felder, nur der Titel ist Pflicht. */
const hero = [
    f('title', 'text', { required: true }),
    f('accent', 'text'),
    f('suffix', 'text'),
    f('lead', 'richtext'),
    f('command', 'text'),
    f('cta', 'link'),
    f('cta_label', 'text'),
    f('more', 'link'),
    f('more_label', 'text'),
    f('note', 'text'),
    f('art', 'text'),
    f('art_overlay', 'text'),
    f('art_small', 'text'),
    f('art_credit', 'text'),
];

describe('primaryFields', () => {
    it('zeigt zuerst je Art das erste Feld — Titel, Einleitung, Knopf', () => {
        expect([...primaryFields(hero, {})]).toEqual(['title', 'lead', 'cta']);
    });

    it('Ausgefülltes und Felder mit Fehler stehen immer oben', () => {
        const shown = primaryFields(
            hero,
            { note: 'Kostenlos', more: { href: '/preise' } },
            (name) => name === 'art',
        );
        expect(shown.has('note')).toBe(true);
        expect(shown.has('more')).toBe(true);
        expect(shown.has('art')).toBe(true);
        expect(shown.has('accent')).toBe(false);
    });

    it('mit «advanced» im Theme gilt nur das; kleine Blöcke zeigen alles', () => {
        const marked = hero.map((field) =>
            field.name.startsWith('art') || field.name === 'note'
                ? { ...field, advanced: true }
                : field,
        );
        expect(primaryFields(marked, {}).size).toBe(9);
        expect(primaryFields(hero.slice(0, 4), {}).size).toBe(4);
    });

    it('füllt auf drei auf — ohne abgeleitete Felder', () => {
        const cta = [
            f('title', 'text', { required: true }),
            f('text', 'text'),
            f('link', 'link', { required: true }),
            f('link_label', 'text'),
            f('more', 'link'),
            f('center', 'checkbox'),
        ];
        expect([...primaryFields(cta, {})]).toEqual(['title', 'link', 'text']);
    });
});

describe('abgeleitete Werte', () => {
    it('«cta_label» zeigt die Beschriftung des Links, sonst den Titel der Zielseite', () => {
        const targets = [{ path: '/kontakt', title: 'Kontakt' }];
        const label = hero[6] as SchemaField;
        expect(
            placeholderFor(label, hero, { cta: { href: '/kontakt' } }, targets),
        ).toBe('leer = «Kontakt»');
        expect(
            derivedValue(
                label,
                hero,
                { cta: { href: '/kontakt', label: 'Schreiben Sie uns' } },
                targets,
            ),
        ).toBe('Schreiben Sie uns');
        expect(placeholderFor(label, hero, {}, targets)).toBe('');
    });

    it('«from» aus dem Theme, sonst das Beispiel als Platzhalter', () => {
        const fields = [
            f('title', 'text'),
            f('menu', 'text', { from: 'title', placeholder: 'z. B. Über uns' }),
        ];
        const menu = fields[1] as SchemaField;
        expect(placeholderFor(menu, fields, { title: 'Team' }, [])).toBe(
            'leer = «Team»',
        );
        expect(placeholderFor(menu, fields, {}, [])).toBe('z. B. Über uns');
    });
});

describe('Pflichtangaben und Ausgefülltes', () => {
    it('Fliesstext zählt erst mit Text, Link mit Adresse', () => {
        expect(
            isFilled({ type: 'doc', content: [{ type: 'paragraph' }] }),
        ).toBe(false);
        expect(
            isFilled({
                type: 'doc',
                content: [
                    {
                        type: 'paragraph',
                        content: [{ type: 'text', text: 'Hallo' }],
                    },
                ],
            }),
        ).toBe(true);
        expect(isFilled({ href: '', label: '', target: '' })).toBe(false);
        expect(isFilled({ href: '/x', label: '', target: '' })).toBe(true);
        expect(requiredProgress(hero, { title: 'Hallo' })).toEqual({
            done: 1,
            total: 1,
        });
    });
});

describe('Formular ↔ Vorschau', () => {
    it('übersetzt Eingabe und Feldmarke in beide Richtungen', () => {
        expect(markOf('blocks.2.title', 'blocks')).toBe('content.2.data.title');
        expect(markOf('blocks.2.items.0.title', 'blocks')).toBe('content.2.');
        expect(markOf('name', 'blocks')).toBeNull();
        expect(inputOf('content.2.data.title', 'blocks')).toBe(
            'blocks.2.title',
        );
        expect(inputOf('page.title', 'blocks')).toBeNull();
    });
});

describe('linkAddress', () => {
    const targets = [
        { path: '/kontakt', title: 'Kontakt' },
        { path: '/demo', title: 'Demo' },
    ];

    it('macht aus dem Titel einer Seite ihre Adresse, Adressen bleiben', () => {
        expect(linkAddress('Kontakt', targets)).toBe('/kontakt');
        expect(linkAddress(' demo ', targets)).toBe('/demo');
        expect(linkAddress('/preise', targets)).toBe('/preise');
        expect(linkAddress('https://example.org', targets)).toBe(
            'https://example.org',
        );
        expect(linkAddress('mailto:hallo@example.org', targets)).toBe(
            'mailto:hallo@example.org',
        );
        expect(linkAddress('Irgendwas', targets)).toBeNull();
    });
});
