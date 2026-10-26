import { describe, expect, it } from 'vitest';
import {
    collapseMarkup,
    cropRect,
    describeVariant,
    dissolveAt,
    fillLava,
    handleFrom,
    isValidName,
    moveItem,
    partSummary,
    placedRegions,
    referenceBlock,
    scheme,
    schemeText,
    settingInput,
    snippetSummary,
} from './layoutEditor';
import type { FillItem, Sources } from './layoutEditor';

const sources: Sources = {
    nav: ['main', 'footer'],
    global: ['footer'],
    form: ['contact'],
    type: ['page', 'post'],
    template: ['default', 'legal'],
    layout: ['standard'],
    part: ['main_nav'],
    locales: ['en'],
};
const part = (name: string, off = false): FillItem => ({
    word: 'part',
    name,
    off,
});
const region = (
    name: string,
    where: 'before' | 'after' | null = null,
    target: string | null = null,
) => ({
    name,
    where,
    target,
    optional: false,
    fill: [] as FillItem[],
});

describe('Füllung und Reihenfolge', () => {
    it('schreibt die Füllung wie im Theme, «off» hinter dem Teil', () => {
        expect(
            fillLava([
                part('main_nav'),
                part('newsletter', true),
                { word: 'content', name: null, off: false },
            ]),
        ).toBe('part main_nav, part newsletter off, content');
    });

    it('verschiebt nur innerhalb der Liste', () => {
        expect(moveItem(['a', 'b', 'c'], 2, -1)).toEqual(['a', 'c', 'b']);
        expect(moveItem(['a', 'b'], 0, -1)).toEqual(['a', 'b']);
    });

    it('setzt Regionen mit «before/after» neben ihr Ziel und baut das Schema', () => {
        const regions = [
            region('header'),
            region('main'),
            region('aside'),
            region('footer'),
            region('hero', 'before', 'main'),
            region('banner', 'after', 'header'),
        ];
        expect(placedRegions(regions).map((r) => r.name)).toEqual([
            'header',
            'banner',
            'hero',
            'main',
            'aside',
            'footer',
        ]);
        const s = scheme(regions);
        expect(s.top.map((r) => r.name)).toEqual(['header', 'banner', 'hero']);
        expect(s.main?.name).toBe('main');
        expect(s.aside?.name).toBe('aside');
        expect(s.bottom.map((r) => r.name)).toEqual(['footer']);
    });

    it('zeigt im Schema keine ausgeschalteten Teile', () => {
        expect(
            schemeText({
                name: 'footer',
                fill: [part('legal_nav'), part('newsletter', true)],
            }),
        ).toBe('footer · legal_nav');
    });
});

describe('Teile', () => {
    it('wählt die Eingabe je Einstellung aus dem Theme', () => {
        expect(settingInput('nav', 'source', sources)).toEqual({
            type: 'select',
            options: ['main', 'footer'],
        });
        expect(settingInput('list', 'source', sources)).toEqual({
            type: 'select',
            options: ['page', 'post'],
        });
        expect(settingInput('nav', 'depth', sources)).toEqual({
            type: 'number',
        });
        expect(settingInput('nav', 'only', sources)).toEqual({
            type: 'list',
            options: ['default', 'legal'],
        });
        expect(settingInput('comments.list', 'moderation', sources)).toEqual({
            type: 'text',
        });
    });

    it('fasst eine Instanz kurz zusammen und prüft Namen', () => {
        expect(
            partSummary({
                name: 'main_nav',
                kind: 'nav',
                settings: {
                    source: 'main',
                    depth: '2',
                    label: 'Hauptnavigation',
                },
                markup: null,
            }),
        ).toBe('main · Tiefe 2 · Hauptnavigation');
        expect(isValidName('kopf_nav')).toBe(true);
        expect(isValidName('Kopf-Nav')).toBe(false);
    });
});

describe('Bildvarianten', () => {
    it('schneidet «exakt» auf das Seitenverhältnis um den Fokuspunkt, am Rand begrenzt', () => {
        const r = cropRect(
            { width: 2000, height: 1000 },
            { width: 1000, height: 1000, mode: 'exact' },
            { x: 0.9, y: 0.5 },
        );
        expect(r.w).toBeCloseTo(0.5);
        expect(r.h).toBe(1);
        expect(r.x).toBeCloseTo(0.5);
        expect(
            cropRect(
                { width: 2000, height: 1000 },
                { width: 800, height: null, mode: 'scale' },
                { x: 0.5, y: 0.5 },
            ),
        ).toEqual({ x: 0, y: 0, w: 1, h: 1 });
    });

    it('beschreibt eine Variante', () => {
        expect(
            describeVariant({
                name: 'hero',
                width: 1920,
                height: 1080,
                mode: 'exact',
                densities: [1, 2],
                sizes: null,
                lazy: false,
            }),
        ).toBe('1920 × 1080 · exakt · 1×, 2×');
    });
});

describe('Bausteine im Editor', () => {
    const schema = [
        { name: 'text', label: 'Text', fields: [] },
        { name: 'cta', label: 'Aufruf', fields: [] },
    ];
    const trio = {
        handle: 'trio',
        name: 'Trio',
        blocks: [
            { type: 'text', data: { body: 'a' } },
            { type: 'cta', data: {} },
            { type: '@snippet', data: { snippet: 'x' } },
        ],
    };

    it('fasst die Blöcke zusammen', () => {
        expect(snippetSummary(trio.blocks.slice(0, 2), schema)).toBe(
            'Text · Aufruf',
        );
        expect(
            snippetSummary([referenceBlock('part', 'main_nav')], schema),
        ).toBe('Teil main_nav');
    });

    it('löst einen Verweis zu Kopien auf — ohne verschachtelte Bausteine', () => {
        const blocks = [
            { type: 'text', data: {} },
            referenceBlock('snippet', 'trio'),
        ];
        const out = dissolveAt(blocks, 1, trio);
        expect(out.map((b) => b.type)).toEqual(['text', 'text', 'cta']);
        expect(out[1]).not.toBe(trio.blocks[0]);
        expect(dissolveAt(blocks, 0, trio)).toBe(blocks);
    });

    it('bildet die Kennung aus dem Namen', () => {
        expect(handleFrom('Leistungs-Trio für Zürich')).toBe(
            'leistungs-trio-fuer-zuerich',
        );
    });
});

describe('Code-Ansicht', () => {
    it('klappt Markup-Zäune ein und lässt die Struktur stehen', () => {
        const code =
            'layout standard {\n  region main\n  markup ```\n    <main>\n    </main>\n  ```\n}';
        expect(collapseMarkup(code)).toEqual({
            code: 'layout standard {\n  region main\n  markup ``` … 2 Zeilen Markup (Lava-Editor) … ```\n}',
            collapsed: true,
        });
        expect(collapseMarkup('part x: languages').collapsed).toBe(false);
    });
});
