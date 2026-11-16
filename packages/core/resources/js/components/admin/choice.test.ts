import { describe, expect, it } from 'vitest';
import {
    canAdd,
    filterChoices,
    groupChoices,
    type AdminChoice,
} from './choice';

const roles: AdminChoice[] = [
    { value: 'Inhaber/in', label: 'Inhaber/in', group: 'Geschäftsleitung' },
    { value: 'Buchhaltung', label: 'Buchhaltung', group: 'Finanzen' },
    {
        value: 'Geschäftsführer/in',
        label: 'Geschäftsführer/in',
        group: 'Geschäftsleitung',
    },
    { value: 'Hauswart', label: 'Hauswart', group: null },
];

describe('Auswahllisten (CR-022)', () => {
    it('sucht ohne Akzente in Bezeichnung und Gruppe', () => {
        expect(filterChoices(roles, 'geschaftsf').map((o) => o.value)).toEqual([
            'Geschäftsführer/in',
        ]);
        expect(filterChoices(roles, 'finanz').map((o) => o.value)).toEqual([
            'Buchhaltung',
        ]);
        expect(filterChoices(roles, '  ')).toHaveLength(4);
    });

    it('ordnet nach Gruppe, Werte ohne Gruppe zuletzt, Index über alle Gruppen', () => {
        const groups = groupChoices(roles);
        expect(groups.map((g) => g.group)).toEqual([
            'Geschäftsleitung',
            'Finanzen',
            null,
        ]);
        expect(groups[0]?.items.map((i) => [i.option.value, i.index])).toEqual([
            ['Inhaber/in', 0],
            ['Geschäftsführer/in', 1],
        ]);
        expect(groups[2]?.items[0]?.index).toBe(3);
    });

    it('bietet Hinzufügen nur für einen neuen Text an', () => {
        expect(canAdd(roles, 'buchhaltung ')).toBe(false);
        expect(canAdd(roles, 'Projektleiterin Bau')).toBe(true);
        expect(canAdd(roles, '')).toBe(false);
    });
});
