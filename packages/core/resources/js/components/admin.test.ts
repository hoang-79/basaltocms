import { describe, expect, test } from 'vitest';
import { initials } from '../support';
import {
    changeText,
    counted,
    parseSettingInput,
    subjectTitle,
    usersLead,
    valueText,
} from './admin';

describe('valueText', () => {
    test('Wahrheitswerte, Zahlen mit Einheit, Listen und Leeres', () => {
        expect(valueText(true)).toBe('ein');
        expect(valueText(false)).toBe('aus');
        expect(valueText(72, 'Stunden')).toBe('72 Stunden');
        expect(valueText(0)).toBe('0');
        expect(valueText(['DE', 'EN'])).toBe('DE, EN');
        expect(valueText([])).toBe('—');
        expect(valueText(null)).toBe('—');
        expect(valueText('')).toBe('—');
        expect(valueText({ a: 1 })).toBe('{"a":1}');
    });
});

describe('changeText', () => {
    test('alt → neu wie im Mockup', () => {
        expect(changeText(false, true)).toBe('aus → ein');
        expect(changeText(null, true)).toBe('— → ein');
        expect(changeText(['DE'], ['DE', 'EN'])).toBe('DE → DE, EN');
        expect(changeText(30, 90, 'Tage')).toBe('30 Tage → 90 Tage');
    });
});

describe('usersLead', () => {
    test('Konten und Einladungen mit abgelaufenen', () => {
        const users = [{ deactivated: false }, { deactivated: false }];
        expect(usersLead(users, [])).toBe('2 Konten');
        expect(usersLead([{ deactivated: false }], [{ expired: false }])).toBe(
            '1 Konto · 1 Einladung',
        );
        expect(
            usersLead(
                [...users, { deactivated: true }],
                [{ expired: true }, { expired: false }],
            ),
        ).toBe('3 Konten (1 deaktiviert) · 2 Einladungen (1 abgelaufen)');
    });

    test('counted wählt Einzahl und Mehrzahl', () => {
        expect(counted(1, 'Eintrag', 'Einträge')).toBe('1 Eintrag');
        expect(counted(0, 'Eintrag', 'Einträge')).toBe('0 Einträge');
    });
});

describe('parseSettingInput', () => {
    test('bool bleibt Wahrheitswert, Zahl aus dem Feld, ungültig wird null', () => {
        expect(parseSettingInput('bool', true)).toBe(true);
        expect(parseSettingInput('bool', false)).toBe(false);
        expect(parseSettingInput('int', '72')).toBe(72);
        expect(parseSettingInput('int', '')).toBeNull();
        expect(parseSettingInput('int', '1.5')).toBeNull();
        expect(parseSettingInput('link', 'x')).toBeNull();
    });
});

describe('initials', () => {
    test('zwei Buchstaben fürs Sechseck', () => {
        expect(initials('Marc Keller')).toBe('MK');
        expect(initials('Giulia Maria Rossi')).toBe('GM');
        expect(initials('  ')).toBe('?');
    });
});

describe('subjectTitle', () => {
    test('ohne Fassungsnummer und Sprache', () => {
        expect(subjectTitle('Leistungen · Nr. 4 (DE)')).toBe('Leistungen');
        expect(subjectTitle('Datenschutz (IT)')).toBe('Datenschutz');
        expect(subjectTitle('logo.png')).toBe('logo.png');
    });
});
