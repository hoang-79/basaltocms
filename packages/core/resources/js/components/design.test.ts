import { describe, expect, it } from 'vitest';
import { contrast, formatRatio, isHex } from './design';

describe('Kontrast nach WCAG 2.2', () => {
    it('schwarz auf weiss ist 21:1, gleiche Farben 1:1', () => {
        expect(contrast('#000000', '#ffffff')).toBeCloseTo(21, 5);
        expect(contrast('#3f5c6e', '#3f5c6e')).toBeCloseTo(1, 5);
    });

    it('kennt die Grenzfälle um 4,5:1 und die Kurzform', () => {
        expect(contrast('#767676', '#ffffff')).toBeGreaterThanOrEqual(4.5);
        expect(contrast('#777777', '#ffffff')).toBeLessThan(4.5);
        expect(contrast('#fff', '#000')).toBeCloseTo(21, 5);
    });

    it('schneidet beim Anzeigen ab statt zu runden', () => {
        expect(formatRatio(4.499)).toBe('4,49:1');
        expect(formatRatio(21)).toBe('21,00:1');
    });

    it('nimmt nur Farbwerte', () => {
        expect(isHex('#abc')).toBe(true);
        expect(isHex('#a1b2c3')).toBe(true);
        expect(isHex('red')).toBe(false);
        expect(contrast('red', '#fff')).toBeNull();
    });
});
