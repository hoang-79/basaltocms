import { describe, expect, it } from 'vitest';
import {
    filterCountries,
    flagOf,
    formatPhone,
    groupDigits,
    limitPhone,
    missingDigits,
    nationalInput,
    parsePhone,
    type PhoneCountry,
} from './phone';

/* Werte wie in `Countries::all()` */
const CH: PhoneCountry = {
    code: 'CH',
    name: 'Schweiz',
    dial: '41',
    min: 9,
    max: 9,
    groups: [2, 3, 2, 2],
    keep_zero: false,
};
const LI: PhoneCountry = {
    code: 'LI',
    name: 'Liechtenstein',
    dial: '423',
    min: 7,
    max: 7,
    groups: [3, 2, 2],
    keep_zero: false,
};
const DE: PhoneCountry = {
    code: 'DE',
    name: 'Deutschland',
    dial: '49',
    min: 6,
    max: 13,
    groups: [3, 4, 4],
    keep_zero: false,
};
const AT: PhoneCountry = {
    code: 'AT',
    name: 'Österreich',
    dial: '43',
    min: 4,
    max: 13,
    groups: [3, 4, 4],
    keep_zero: false,
};
const IT: PhoneCountry = {
    code: 'IT',
    name: 'Italien',
    dial: '39',
    min: 6,
    max: 11,
    groups: [3, 3, 4],
    keep_zero: true,
};
const US: PhoneCountry = {
    code: 'US',
    name: 'Vereinigte Staaten',
    dial: '1',
    min: 10,
    max: 10,
    groups: [3, 3, 4],
    keep_zero: false,
};
const CA: PhoneCountry = {
    code: 'CA',
    name: 'Kanada',
    dial: '1',
    min: 10,
    max: 10,
    groups: [3, 3, 4],
    keep_zero: false,
};

const countries = [CH, LI, DE, AT, IT, US, CA];

describe('Telefonnummer lesen', () => {
    it('liest national im gewählten Land und lässt die führende 0 weg', () => {
        expect(parsePhone('079 123 45 67', CH, countries)).toEqual({
            country: CH,
            national: '791234567',
        });
        expect(parsePhone('(212) 555-0100', US, countries)).toEqual({
            country: US,
            national: '2125550100',
        });
        expect(parsePhone('079.123/45-67', CH, countries)?.national).toBe(
            '791234567',
        );
    });

    it('behält die 0, wo sie zur Nummer gehört', () => {
        expect(parsePhone('06 1234 5678', IT, countries)).toEqual({
            country: IT,
            national: '0612345678',
        });
        expect(parsePhone('+39 06 1234 5678', CH, countries)).toEqual({
            country: IT,
            national: '0612345678',
        });
    });

    it('wählt das Land international über die längste Vorwahl, mit «+» oder «00»', () => {
        expect(parsePhone('+49 30 1234567', CH, countries)).toEqual({
            country: DE,
            national: '301234567',
        });
        expect(parsePhone('0049 30 1234567', CH, countries)?.country).toBe(DE);
        expect(parsePhone('+423 234 56 78', CH, countries)).toEqual({
            country: LI,
            national: '2345678',
        });
        expect(parsePhone('+41 (0)79 123 45 67', DE, countries)).toEqual({
            country: CH,
            national: '791234567',
        });
        expect(parsePhone('+41 79', undefined, countries)?.country).toBe(CH);
    });

    it('bevorzugt bei geteilter Vorwahl das gewählte Land, sonst das erste der Liste', () => {
        expect(parsePhone('+1 613 555 0100', CA, countries)?.country).toBe(CA);
        expect(parsePhone('+1 212 555 0100', US, countries)?.country).toBe(US);
        expect(parsePhone('+1 212 555 0100', CH, countries)?.country).toBe(US);
        expect(parsePhone('+1 212 555 0100', CH, [CH, CA, US])?.country).toBe(
            CA,
        );
    });

    it('prüft die Länge nicht', () => {
        expect(parsePhone('079 12', CH, countries)?.national).toBe('7912');
        expect(parsePhone('079 123 45 67 89', CH, countries)?.national).toBe(
            '79123456789',
        );
    });

    it('gibt null ohne Ziffern, bei fremden Zeichen, unbekannter Vorwahl und national ohne Land', () => {
        expect(parsePhone('', CH, countries)).toBeNull();
        expect(parsePhone('  - ', CH, countries)).toBeNull();
        expect(parsePhone('+', CH, countries)).toBeNull();
        expect(parsePhone('intern 4711', CH, countries)).toBeNull();
        expect(parsePhone('079 123 45 67 #2', CH, countries)).toBeNull();
        expect(parsePhone('41+79', CH, countries)).toBeNull();
        expect(parsePhone('+999 1234567', CH, countries)).toBeNull();
        expect(parsePhone('079 123 45 67', undefined, countries)).toBeNull();
    });
});

describe('Ziffern gruppieren', () => {
    it('gruppiert nach dem Land, auch unvollständig', () => {
        expect(groupDigits('791234567', [2, 3, 2, 2])).toBe('79 123 45 67');
        expect(groupDigits('7912', [2, 3, 2, 2])).toBe('79 12');
        expect(groupDigits('7', [2, 3, 2, 2])).toBe('7');
        expect(groupDigits('', [2, 3, 2, 2])).toBe('');
    });

    it('hängt überzählige Ziffern an die letzte Gruppe und lässt ohne Gruppen alles, wie es ist', () => {
        expect(groupDigits('79123456789', [2, 3, 2, 2])).toBe('79 123 45 6789');
        expect(groupDigits('791234567', [])).toBe('791234567');
    });
});

describe('Telefonnummer anzeigen', () => {
    it('setzt Vorwahl und Gruppen, sobald eine Nummer erkennbar ist', () => {
        expect(formatPhone('0791234567', CH, countries)).toBe(
            '+41 79 123 45 67',
        );
        expect(formatPhone('0791', CH, countries)).toBe('+41 79 1');
        expect(formatPhone('+4930 1234 5678', CH, countries)).toBe(
            '+49 301 2345 678',
        );
        expect(formatPhone('06 1234 5678', IT, countries)).toBe(
            '+39 061 234 5678',
        );
        expect(formatPhone('+41', CH, countries)).toBe('+41');
    });

    it('lässt alles andere unverändert — auch die getippte 0', () => {
        expect(formatPhone('', CH, countries)).toBe('');
        expect(formatPhone('0', CH, countries)).toBe('0');
        expect(formatPhone('Sekretariat', CH, countries)).toBe('Sekretariat');
        expect(formatPhone('+999 12', CH, countries)).toBe('+999 12');
        expect(formatPhone('079 123', undefined, countries)).toBe('079 123');
    });
});

describe('Länge begrenzen und fehlende Ziffern', () => {
    it('schneidet nach der letzten erlaubten Ziffer ab', () => {
        expect(limitPhone('079 123 45 678', CH, countries)).toBe(
            '079 123 45 67',
        );
        expect(limitPhone('079 123 45 67 ', CH, countries)).toBe(
            '079 123 45 67',
        );
        expect(limitPhone('+41 79 123 45 6789', CH, countries)).toBe(
            '+41 79 123 45 67',
        );
        expect(limitPhone('0041 (0)79 123 45 6789', CH, countries)).toBe(
            '0041 (0)79 123 45 67',
        );
        expect(limitPhone('+423 234 56 789', CH, countries)).toBe(
            '+423 234 56 78',
        );
    });

    it('lässt kürzere und nicht erkennbare Eingaben stehen', () => {
        expect(limitPhone('079 123', CH, countries)).toBe('079 123');
        expect(limitPhone('intern 4711 0000 0000', CH, countries)).toBe(
            'intern 4711 0000 0000',
        );
        expect(limitPhone('+999 1234567890123', CH, countries)).toBe(
            '+999 1234567890123',
        );
    });

    it('zählt, was bis zur kürzesten Länge fehlt', () => {
        expect(missingDigits('079 123', CH, countries)).toBe(4);
        expect(missingDigits('+41 79 123 45 67', CH, countries)).toBe(0);
        expect(missingDigits('079 123 45 67 89', CH, countries)).toBe(0);
        expect(missingDigits('+49 30', CH, countries)).toBe(4);
        expect(missingDigits('', CH, countries)).toBeNull();
        expect(missingDigits('Sekretariat', CH, countries)).toBeNull();
    });
});

describe('Flaggen', () => {
    it('baut die Flagge aus dem Ländercode, auch klein geschrieben', () => {
        expect(flagOf('CH')).toBe('🇨🇭');
        expect(flagOf('de')).toBe('🇩🇪');
    });

    it('gibt für alles andere einen leeren Text', () => {
        expect(flagOf('')).toBe('');
        expect(flagOf('C')).toBe('');
        expect(flagOf('CHE')).toBe('');
        expect(flagOf('C1')).toBe('');
        expect(flagOf('Ä1')).toBe('');
    });
});

describe('Länder suchen', () => {
    it('zeigt ohne Suche alle Länder in ihrer Reihenfolge', () => {
        expect(filterCountries(countries, '  ')).toEqual(countries);
    });

    it('findet nach Name ohne Rücksicht auf Gross- und Kleinschreibung und Akzente', () => {
        expect(filterCountries(countries, 'schw')).toEqual([CH]);
        expect(filterCountries(countries, 'osterr')).toEqual([AT]);
        expect(filterCountries(countries, ' ÖSTERR ')).toEqual([AT]);
    });

    it('findet nach dem Anfang des ISO-Codes', () => {
        expect(filterCountries(countries, 'ca')).toEqual([CA]);
        expect(filterCountries(countries, 'US')).toEqual([US]);
    });

    it('findet nach der Vorwahl mit oder ohne «+» und «00», in der Reihenfolge der Liste', () => {
        expect(filterCountries(countries, '+41')).toEqual([CH]);
        expect(filterCountries(countries, '41')).toEqual([CH]);
        expect(filterCountries(countries, '0041')).toEqual([CH]);
        expect(filterCountries(countries, '+4')).toEqual([CH, LI, DE, AT]);
        expect(filterCountries(countries, '1')).toEqual([US, CA]);
        expect(filterCountries(countries, '+7')).toEqual([]);
    });
});

describe('Feld hinter der festen Vorwahl', () => {
    it('behält nur Ziffern, ohne führende 0, gekürzt und gruppiert', () => {
        expect(nationalInput('079 123 45 67', CH, countries)).toEqual({
            country: CH,
            national: '791234567',
            grouped: '79 123 45 67',
        });
        expect(nationalInput('79a12', CH, countries)).toEqual({
            country: CH,
            national: '7912',
            grouped: '79 12',
        });
        expect(nationalInput('79 123 45 678', CH, countries).national).toBe(
            '791234567',
        );
        expect(nationalInput('0', CH, countries).grouped).toBe('');
        expect(nationalInput('06 1234', IT, countries).grouped).toBe('061 234');
    });

    it('stellt bei «+» oder «00» das Land um, wenn eine Vorwahl passt', () => {
        expect(nationalInput('+49 30 1234567', CH, countries)).toEqual({
            country: DE,
            national: '301234567',
            grouped: '301 2345 67',
        });
        expect(nationalInput('0041 79 123 45 67', DE, countries)).toEqual({
            country: CH,
            national: '791234567',
            grouped: '79 123 45 67',
        });
        expect(nationalInput('+1 613 555 0100', CA, countries).country).toBe(
            CA,
        );
        expect(nationalInput('+49', CH, countries)).toEqual({
            country: DE,
            national: '',
            grouped: '',
        });
    });

    it('bleibt ohne passende Vorwahl im gewählten Land', () => {
        expect(nationalInput('+999 12', CH, countries)).toEqual({
            country: CH,
            national: '99912',
            grouped: '99 912',
        });
        expect(nationalInput('+', CH, countries)).toEqual({
            country: CH,
            national: '',
            grouped: '',
        });
    });

    it('gibt ohne Land die Ziffern ungruppiert zurück', () => {
        expect(nationalInput('079 123', undefined, countries)).toEqual({
            country: undefined,
            national: '079123',
            grouped: '079123',
        });
    });
});
