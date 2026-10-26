import { describe, expect, it } from 'vitest';
import { formatPostal } from './address';

const CH = { postal_example: '8001', postal_numeric: true, postal_max: 4 };
const PT = { postal_example: '1000-001', postal_numeric: true, postal_max: 8 };
const SE = { postal_example: '111 22', postal_numeric: true, postal_max: 6 };
const GB = { postal_example: 'SW1A 1AA', postal_numeric: false, postal_max: 8 };

describe('Postleitzahl je Land', () => {
    it('nimmt in Ziffern-Ländern nur Ziffern bis zur Länge des Landes', () => {
        expect(formatPostal('80a01', CH)).toBe('8001');
        expect(formatPostal('800155', CH)).toBe('8001');
        expect(formatPostal('abc', CH)).toBe('');
    });

    it('setzt feste Trennzeichen selbst', () => {
        expect(formatPostal('1000001', PT)).toBe('1000-001');
        expect(formatPostal('1000', PT)).toBe('1000');
        expect(formatPostal('10000', PT)).toBe('1000-0');
        expect(formatPostal('11122', SE)).toBe('111 22');
    });

    it('lässt Buchstaben, wo sie zur PLZ gehören, in Grossschrift', () => {
        expect(formatPostal('sw1a 1aa', GB)).toBe('SW1A 1AA');
        expect(formatPostal('sw1a 1aa!x', GB)).toBe('SW1A 1AA');
    });
});
