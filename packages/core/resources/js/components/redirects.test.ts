import { describe, expect, test } from 'vitest';
import {
    needsTarget,
    redirectSourceLabel,
    redirectStatusOptions,
    redirectStatusTone,
    redirectStatusWord,
    shortReferrer,
    tabFromUrl,
} from './redirects';

describe('Umleitungen', () => {
    test('Status als Wort, Auswahl und Ton', () => {
        expect(redirectStatusWord(301)).toBe('dauerhaft');
        expect(redirectStatusWord(302)).toBe('vorübergehend');
        expect(redirectStatusWord(410)).toBe('entfernt');
        expect(redirectStatusWord(307)).toBe('307');
        expect(redirectStatusOptions().map((o) => o.label)).toEqual([
            '301 dauerhaft',
            '302 vorübergehend',
            '410 entfernt',
        ]);
        expect(redirectStatusTone(301)).toBe('success');
        expect(redirectStatusTone(302)).toBe('warning');
        expect(redirectStatusTone(410)).toBe('grey');
    });

    test('Herkunft', () => {
        expect(redirectSourceLabel('slug_change')).toBe('automatisch');
        expect(redirectSourceLabel('manual')).toBe('von Hand');
        expect(redirectSourceLabel('import')).toBe('Import');
        expect(redirectSourceLabel('modul')).toBe('modul');
    });

    test('nur 410 kommt ohne Ziel aus', () => {
        expect(needsTarget(301)).toBe(true);
        expect(needsTarget(302)).toBe(true);
        expect(needsTarget(410)).toBe(false);
    });
});

describe('404-Protokoll', () => {
    test('Verweis gekürzt, ohne Schema und www', () => {
        expect(shortReferrer(null)).toBe('');
        expect(shortReferrer('https://www.google.ch/')).toBe('google.ch');
        expect(shortReferrer('http://example.com/a/b')).toBe('example.com/a/b');
        const long = `https://example.com/${'x'.repeat(80)}`;
        const short = shortReferrer(long, 30);
        expect(short).toHaveLength(30);
        expect(short.endsWith('…')).toBe(true);
    });

    test('Reiter aus der Adresse', () => {
        const keys = ['tree', 'redirects', 'not_found', 'trash'];
        expect(tabFromUrl('/admin/pages?tab=not_found', keys, 'tree')).toBe(
            'not_found',
        );
        expect(
            tabFromUrl('/admin/pages?q=a&tab=redirects#x', keys, 'tree'),
        ).toBe('redirects');
        expect(tabFromUrl('/admin/pages?tab=gibts-nicht', keys, 'tree')).toBe(
            'tree',
        );
        expect(tabFromUrl('/admin/pages', keys, 'tree')).toBe('tree');
    });
});
