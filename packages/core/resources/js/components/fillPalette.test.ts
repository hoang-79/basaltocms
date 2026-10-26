import { describe, expect, it } from 'vitest';
import type { PartInfo } from '../types';
import { fillOptions, partNameFor, suggestionsFor } from './fillPalette';
import type { Sources } from './layoutEditor';

const part = (name: string, kind: string): PartInfo =>
    ({
        name,
        kind,
        kind_label: kind,
        origin: 'Kern',
        settings: {},
        used_in: [],
        form: { name, kind, settings: {}, markup: null },
        code: '',
        file: '',
        line: 0,
    }) as PartInfo;

const parts = [
    part('main_nav', 'nav'),
    part('lang_switch', 'languages'),
    part('suche', 'search'),
];
const sources: Sources = {
    nav: ['main', 'footer'],
    global: ['footer', 'kontakt'],
    form: [],
    type: [],
    template: [],
    layout: [],
    part: ['main_nav', 'lang_switch', 'suche'],
    locales: ['de'],
};

describe('suggestionsFor', () => {
    const options = fillOptions(parts, sources);

    it('Kopf: Hauptnavigation zuerst, dann Sprachwechsel — ohne, was schon drin ist', () => {
        const keys = suggestionsFor('header', options, parts, []).map(
            (o) => o.key,
        );
        expect(keys[0]).toBe('part main_nav');
        expect(keys).toContain('part lang_switch');
        expect(
            suggestionsFor('header', options, parts, [
                { word: 'part', name: 'main_nav', off: false },
            ]).map((o) => o.key),
        ).not.toContain('part main_nav');
    });

    it('Fuss: die Fusszeile; Bühne: der erste Block; Haupt: die Blöcke der Seite', () => {
        expect(suggestionsFor('footer', options, parts, [])[0]?.key).toBe(
            'global footer',
        );
        expect(suggestionsFor('hero', options, parts, [])[0]?.key).toBe(
            'content.first',
        );
        expect(suggestionsFor('main', options, parts, [])[0]?.key).toBe(
            'content',
        );
        expect(suggestionsFor('xyz', options, parts, [])).toEqual([]);
    });
});

describe('partNameFor', () => {
    it('Kennung aus Quelle und Art, frei im Theme', () => {
        expect(partNameFor('nav', 'main', [])).toBe('main_nav');
        expect(partNameFor('nav', 'main', ['main_nav'])).toBe('main_nav_2');
        expect(partNameFor('members.status', '', [])).toBe('members_status');
    });
});
