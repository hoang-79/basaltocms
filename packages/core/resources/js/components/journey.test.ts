import { describe, expect, it } from 'vitest';
import type { JourneyStation } from '../types';
import { cardAlign, nextStation, progress, statusMark } from './journey';

const station = (
    key: string,
    status: JourneyStation['status'],
): JourneyStation => ({
    key,
    question: key,
    term: key,
    explain: '',
    action: '',
    letters: [],
    status,
    findings: [],
    href: null,
    links: [],
});

describe('Fahrplan der Einrichtung', () => {
    it('zählt die Stationen, die stimmen', () => {
        expect(
            progress([
                station('a', 'success'),
                station('b', 'warning'),
                station('c', 'success'),
            ]),
        ).toEqual({ done: 2, total: 3 });
    });

    it('nennt als nächsten Schritt zuerst Fehler, dann Warnungen, dann Wartendes', () => {
        const line = [
            station('a', 'waiting'),
            station('b', 'warning'),
            station('c', 'danger'),
        ];
        expect(nextStation(line)?.key).toBe('c');
        expect(
            nextStation([station('a', 'waiting'), station('b', 'warning')])
                ?.key,
        ).toBe('b');
        expect(nextStation([station('a', 'success')])).toBeNull();
    });

    it('richtet die Karte am Rand nach innen aus', () => {
        expect(cardAlign(0, 9)).toBe('start');
        expect(cardAlign(4, 9)).toBe('center');
        expect(cardAlign(8, 9)).toBe('end');
    });

    it('zeigt den Stand als Zeichen', () => {
        expect(statusMark('success')).toBe('✓');
        expect(statusMark('danger')).toBe('✗');
        expect(statusMark('waiting')).toBe('');
    });
});
