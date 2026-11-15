import { describe, expect, it } from 'vitest';
import type { BarItem } from './menuBar';
import { barItems, groupItems, hiddenCount, togglePin } from './menuBar';

const item = (
    route: string,
    group: string,
    extra: Partial<BarItem> = {},
): BarItem => ({
    label: route,
    href: '/' + route,
    route,
    mode: 'admin',
    module: null,
    icon: null,
    group,
    ...extra,
});

const items: BarItem[] = [
    item('sites', 'site'),
    item('module', 'site'),
    item('users', 'people'),
    item('roles', 'people'),
    item('privacy', 'people', { count: 2 }),
    item('settings', 'system'),
    item('crm', 'business', { badge: 4 }),
    item('plugin', 'unbekannt'),
];

describe('Leiste mit Panel «Alle» (TB 120)', () => {
    it('zeigt Angeheftetes in Menüreihenfolge und die aktuelle Seite dahinter', () => {
        const pinned = new Set(['settings', 'sites']);
        expect(
            barItems(items, pinned, 'roles').map(
                (b) => `${b.item.route}${b.extra ? '*' : ''}`,
            ),
        ).toEqual(['sites', 'settings', 'roles*']);
        expect(barItems(items, pinned, 'sites')).toHaveLength(2);
    });

    it('ordnet das Panel nach den Gruppen, leere fallen weg, Unbekanntes am Schluss', () => {
        const groups = [
            { key: 'site', label: 'Site' },
            { key: 'people', label: 'Personen' },
            { key: 'monitoring', label: 'Überwachung' },
            { key: 'system', label: 'System' },
        ];
        const grouped = groupItems(items, groups);
        expect(grouped.map((g) => g.key)).toEqual([
            'site',
            'people',
            'system',
            'weitere',
        ]);
        expect(grouped[3]?.items.map((i) => i.route)).toEqual([
            'crm',
            'plugin',
        ]);
    });

    it('zählt, was hinter «Alle» wartet — echte Arbeit nur mit count', () => {
        expect(hiddenCount(items, new Set(['privacy']))).toEqual({
            count: 4,
            waiting: false,
        });
        expect(hiddenCount(items, new Set())).toEqual({
            count: 6,
            waiting: true,
        });
    });

    it('heftet an und löst, die Liste bleibt in Menüreihenfolge', () => {
        expect(togglePin(items, new Set(['settings']), 'sites')).toEqual([
            'sites',
            'settings',
        ]);
        expect(
            togglePin(items, new Set(['sites', 'settings']), 'sites'),
        ).toEqual(['settings']);
    });
});
