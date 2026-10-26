import { describe, expect, test } from 'vitest';
import type { AlarmInfo } from './monitoring';
import { alarmState, shortClass } from './monitoring';

const alarm = (threshold: number, current: number): AlarmInfo => ({
    threshold,
    minutes: 5,
    pause: 60,
    current,
    last: null,
    last_count: null,
    recipients: 1,
    settings_url: '/admin/settings?group=operations',
});

describe('Betrieb › Kennzahlen (B-09)', () => {
    test('kürzt Klassennamen auf den letzten Teil', () => {
        expect(shortClass('Illuminate\\Database\\QueryException')).toBe(
            'QueryException',
        );
        expect(shortClass('RuntimeException')).toBe('RuntimeException');
    });

    test('meldet den Alarm ab der Schwelle, mit Schwelle 0 als aus', () => {
        expect(alarmState(alarm(0, 99))).toBe('off');
        expect(alarmState(alarm(3, 2))).toBe('quiet');
        expect(alarmState(alarm(3, 3))).toBe('reached');
    });
});
