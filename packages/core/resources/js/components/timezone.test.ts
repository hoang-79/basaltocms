import { describe, expect, it } from 'vitest';
import {
    buildOptions,
    cityOf,
    formatOffset,
    groupByRegion,
    offsetMinutes,
    searchTimezones,
} from './timezone';

const summer = new Date('2026-07-01T12:00:00Z');
const winter = new Date('2026-01-15T12:00:00Z');
const zones = [
    'Europe/Zurich',
    'Europe/Vienna',
    'Europe/Berlin',
    'Asia/Ho_Chi_Minh',
    'Asia/Kolkata',
    'America/New_York',
    'Pacific/Auckland',
    'UTC',
];

describe('Zeitzonen für Menschen', () => {
    it('rechnet den Abstand zu UTC mit Sommerzeit', () => {
        expect(offsetMinutes('Europe/Zurich', summer)).toBe(120);
        expect(offsetMinutes('Europe/Zurich', winter)).toBe(60);
        expect(offsetMinutes('Asia/Kolkata', summer)).toBe(330);
        expect(offsetMinutes('America/New_York', winter)).toBe(-300);
    });

    it('schreibt Abstände lesbar', () => {
        expect(formatOffset(120)).toBe('UTC+2');
        expect(formatOffset(330)).toBe('UTC+5:30');
        expect(formatOffset(-300)).toBe('UTC−5');
        expect(formatOffset(0)).toBe('UTC±0');
    });

    it('nennt Städte deutsch, wo es üblich ist', () => {
        expect(cityOf('Europe/Zurich')).toBe('Zürich');
        expect(cityOf('Europe/Vienna')).toBe('Wien');
        expect(cityOf('America/Los_Angeles')).toBe('Los Angeles');
    });

    it('findet nach Stadt, Land, Region und Abstand', () => {
        const options = buildOptions(zones, summer);
        const ids = (q: string) => searchTimezones(options, q).map((o) => o.id);
        expect(ids('zur')).toEqual(['Europe/Zurich']);
        expect(ids('Zürich')).toEqual(['Europe/Zurich']);
        expect(ids('wien')).toEqual(['Europe/Vienna']);
        expect(ids('vietnam')).toEqual(['Asia/Ho_Chi_Minh']);
        expect(ids('hanoi')).toEqual(['Asia/Ho_Chi_Minh']);
        expect(ids('deutschland')).toEqual(['Europe/Berlin']);
        expect(ids('+7')).toEqual(['Asia/Ho_Chi_Minh']);
        expect(ids('utc+5:30')).toEqual(['Asia/Kolkata']);
        expect(ids('+2').sort()).toEqual([
            'Europe/Berlin',
            'Europe/Vienna',
            'Europe/Zurich',
        ]);
        expect(ids('-4')).toEqual(['America/New_York']);
    });

    it('gruppiert nach Region in fester Reihenfolge', () => {
        const groups = groupByRegion(buildOptions(zones, summer));
        expect(groups.map((g) => g.region)).toEqual([
            'Europa',
            'Asien',
            'Amerika',
            'Pazifik',
            'Weitere',
        ]);
        expect(groups[0]?.items.map((o) => o.city)).toEqual([
            'Berlin',
            'Wien',
            'Zürich',
        ]);
    });
});
