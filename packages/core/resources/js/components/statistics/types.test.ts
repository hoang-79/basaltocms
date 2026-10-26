import { describe, expect, it } from 'vitest';
import {
    change,
    chartPoints,
    formatDuration,
    formatMetric,
    formatMoney,
    formatMs,
    share,
    trend,
    uiLocale,
} from './types';

describe('Statistik', () => {
    it('schreibt Dauer und Antwortzeit lesbar', () => {
        expect(formatDuration(42)).toBe('42 s');
        expect(formatDuration(120)).toBe('2 min');
        expect(formatDuration(125)).toBe('2 min 5 s');
        expect(formatMs(850)).toBe('850 ms');
        expect(formatMs(1250)).toBe('1.25 s');
    });

    it('formatiert Zahlen wie in der Schweiz', () => {
        expect(uiLocale('de')).toBe('de-CH');
        expect(uiLocale('fr')).toBe('fr-CH');
        expect(uiLocale('en')).toBe('en-GB');
    });

    it('rechnet Balken und Kurve auf die grösste Zahl', () => {
        expect(share(5, [{ count: 10 }, { count: 5 }])).toBe(50);
        expect(share(0, [])).toBe(0);
        expect(chartPoints([0, 10], 100)).toEqual([
            { x: 0, y: 100 },
            { x: 1000, y: 12 },
        ]);
        expect(chartPoints([3], 100)[0].x).toBe(500);
    });

    it('schreibt Beträge in Franken und vergleicht mit dem Zeitraum davor', () => {
        // Intl trennt Währung und Zahl mit einem geschützten Leerzeichen
        const plain = (text: string): string => text.replace(/\u00a0/g, ' ');
        expect(plain(formatMoney(12_950))).toBe('CHF 129.50');
        expect(plain(formatMoney(1_234_500))).toBe("CHF 12'345");
        expect(plain(formatMoney(4_900, 'EUR'))).toBe('EUR 49.00');
        expect(formatMetric(1250, 'number', null)).toBe("1'250");
        expect(formatMetric(42, 'percent', null)).toBe('42 %');
        expect(change(15, 10)).toBe(50);
        expect(change(5, 10)).toBe(-50);
        expect(change(3, 0)).toBe('neu');
        expect(change(0, 0)).toBeNull();
        expect(change(7, null)).toBeNull();
        expect(trend({ value: 12, previous: 10 }, 'neu')).toEqual({
            text: '▲ +20 %',
            tone: 'text-success',
        });
        expect(trend({ value: 8, previous: 10 }, 'neu')).toEqual({
            text: '▼ -20 %',
            tone: 'text-danger',
        });
        expect(trend({ value: 4, previous: 0 }, 'neu')?.text).toBe('neu');
    });
});
