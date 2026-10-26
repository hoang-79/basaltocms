import { describe, expect, test } from 'vitest';
import type { RoleArea } from '../types-admin';
import {
    editableLocales,
    languagesText,
    roleChanged,
    visibleGroups,
} from './roles';

const areas: RoleArea[] = [
    { key: 'pages', label: 'Seiten', group: 'content', module: null },
    { key: 'design', label: 'Design', group: 'setup', module: null },
    { key: 'users', label: 'Benutzer', group: 'admin', module: null },
];
const locales = [
    { code: 'de', is_default: true },
    { code: 'en', is_default: false },
    { code: 'fr', is_default: false },
];

describe('visibleGroups', () => {
    test('nur Gruppen mit mindestens «ansehen»', () => {
        expect(visibleGroups({ pages: 2, design: 0, users: 0 }, areas)).toEqual(
            ['content'],
        );
        expect(visibleGroups({ pages: 1, design: 1, users: 4 }, areas)).toEqual(
            ['content', 'setup', 'admin'],
        );
        expect(visibleGroups({}, areas)).toEqual([]);
    });
});

describe('editableLocales und languagesText', () => {
    test('alle, alle ausser Quellsprache, Auswahl', () => {
        expect(editableLocales({ mode: 'all', codes: [] }, locales)).toEqual([
            'de',
            'en',
            'fr',
        ]);
        expect(
            editableLocales({ mode: 'except_default', codes: [] }, locales),
        ).toEqual(['en', 'fr']);
        expect(
            editableLocales({ mode: 'only', codes: ['fr', 'it'] }, locales),
        ).toEqual(['fr']);
        expect(languagesText({ mode: 'all', codes: [] }, locales)).toBe('alle');
        expect(
            languagesText({ mode: 'except_default', codes: [] }, locales),
        ).toBe('alle ausser DE');
        expect(
            languagesText({ mode: 'only', codes: ['fr', 'en'] }, locales),
        ).toBe('EN, FR');
        expect(languagesText({ mode: 'only', codes: [] }, locales)).toBe(
            'keine',
        );
    });
});

describe('roleChanged', () => {
    const base = {
        name: 'Lektorat',
        description: '',
        levels: { pages: 2 },
        languages: { mode: 'only' as const, codes: ['en', 'fr'] },
    };

    test('Reihenfolge der Sprachen und fehlende Bereiche zählen nicht', () => {
        expect(
            roleChanged(base, {
                ...base,
                levels: { pages: 2, design: 0 },
                languages: { mode: 'only', codes: ['fr', 'en'] },
            }),
        ).toBe(false);
        expect(roleChanged(base, { ...base, levels: { pages: 3 } })).toBe(true);
        expect(roleChanged(base, { ...base, name: 'Lektorat ' })).toBe(false);
        expect(
            roleChanged(base, {
                ...base,
                languages: { mode: 'all', codes: [] },
            }),
        ).toBe(true);
    });
});
