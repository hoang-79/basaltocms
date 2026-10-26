import { describe, expect, test } from 'vitest';
import { abilityLabel, expiryText, validationErrors } from './apiTokens';

describe('abilityLabel', () => {
    test('read und write in Worten, Unbekanntes bleibt', () => {
        expect(abilityLabel('read')).toBe('lesen');
        expect(abilityLabel('write')).toBe('schreiben');
        expect(abilityLabel('admin')).toBe('admin');
    });
});

describe('expiryText', () => {
    test('ohne Ablauf, läuft ab, abgelaufen', () => {
        expect(expiryText({ expires: null, expired: false })).toBe(
            'ohne Ablauf',
        );
        expect(expiryText({ expires: '31.12.2026', expired: false })).toBe(
            'läuft ab 31.12.2026',
        );
        expect(expiryText({ expires: '01.10.2026', expired: true })).toBe(
            'abgelaufen 01.10.2026',
        );
    });
});

describe('validationErrors', () => {
    test('erste Meldung je Feld, Listenelemente beim Feld, auch aus Text-JSON', () => {
        const error = {
            response: {
                status: 422,
                data: JSON.stringify({
                    message: 'x',
                    errors: {
                        name: ['Name fehlt.', 'zweite'],
                        'abilities.0': ['Ungültig.'],
                        'abilities.1': ['Auch ungültig.'],
                    },
                }),
            },
        };
        expect(validationErrors(error)).toEqual({
            name: 'Name fehlt.',
            abilities: 'Ungültig.',
        });
    });

    test('andere Status und kaputtes JSON geben nichts', () => {
        expect(
            validationErrors({ response: { status: 500, data: '{}' } }),
        ).toEqual({});
        expect(
            validationErrors({ response: { status: 422, data: 'kein json' } }),
        ).toEqual({});
        expect(validationErrors(new Error('Netz'))).toEqual({});
    });
});
