import { describe, expect, it } from 'vitest';
import { replace, t, tc } from '../i18n';

describe('Übersetzung', () => {
    it('lässt Deutsch als Quelle stehen und ersetzt Platzhalter', () => {
        expect(t('Entwurf gespeichert.')).toBe('Entwurf gespeichert.');
        expect(t('Fassung :number', { number: 3 })).toBe('Fassung 3');
    });

    it('ersetzt längere Platzhalter zuerst', () => {
        expect(replace(':count von :co', { co: 'x', count: 2 })).toBe(
            '2 von x',
        );
    });

    it('wählt Einzahl oder Mehrzahl', () => {
        expect(tc('Ein Entwurf|:count Entwürfe', 1)).toBe('Ein Entwurf');
        expect(tc('Ein Entwurf|:count Entwürfe', 4)).toBe('4 Entwürfe');
        expect(tc(':count Datei', 2)).toBe('2 Datei');
    });
});
