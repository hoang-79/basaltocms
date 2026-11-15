import { describe, expect, it } from 'vitest';
import { fillFrom, filled, lookupQuery, typedSince } from './lookup';

describe('Nachschlagen und Anlegen ohne Knopf (CR-021)', () => {
    it('fragt erst ab drei Zeichen', () => {
        expect(lookupQuery('  Mu ')).toBeNull();
        expect(lookupQuery(' Mus ')).toBe('Mus');
    });

    it('füllt nur zugeordnete Felder, eine leere Hausnummer leert', () => {
        expect(
            fillFrom(
                {
                    name: 'Muster AG',
                    street_name: 'Bahnhofstrasse',
                    house_number: '',
                    legal_form: 'AG',
                },
                {
                    name: 'name',
                    street_name: 'street',
                    house_number: 'building',
                    uid: 'vat_number',
                },
            ),
        ).toEqual({
            name: 'Muster AG',
            street: 'Bahnhofstrasse',
            building: '',
        });
    });

    it('erkennt ausgefüllte Werte', () => {
        expect(filled('  ')).toBe(false);
        expect(filled('A')).toBe(true);
        expect(filled(null)).toBe(false);
        expect(filled(false)).toBe(true);
    });

    it('übergibt nur, was nach dem Anlegen noch getippt wurde', () => {
        expect(
            typedSince(
                { name: 'Muster AG', city: '', notes: null },
                {
                    name: 'Muster AG',
                    city: 'Winterthur',
                    notes: { type: 'doc' },
                },
            ),
        ).toEqual({ city: 'Winterthur', notes: { type: 'doc' } });
    });
});
