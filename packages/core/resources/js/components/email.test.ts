import { describe, expect, it } from 'vitest';
import { isEmailAddress } from './email';

// Dieselben Fälle wie tests/Feature/Core/EmailAddressTest.php (Prüfung auf dem Server)
const VALID = [
    'anna@firma.ch',
    'anna.muster+crm@firma.co.uk',
    'info@müller.ch',
    "o'brien@example.ie",
    'a@b.de',
    ' anna@firma.ch ',
];
const INVALID = [
    'anna',
    'anna@',
    '@firma.ch',
    'anna@firma',
    'anna@firma.c',
    'anna@@firma.ch',
    'an na@firma.ch',
    'anna..m@firma.ch',
    '.anna@firma.ch',
    'anna.@firma.ch',
    'anna@firma..ch',
    'anna@-firma.ch',
    'anna@firma-.ch',
    'anna@firma.ch.',
    'anna@firma.123',
];

describe('E-Mail-Adresse im üblichen Format', () => {
    it.each(VALID)('nimmt %s', (address) => {
        expect(isEmailAddress(address)).toBe(true);
    });

    it.each(INVALID)('lehnt %s ab', (address) => {
        expect(isEmailAddress(address)).toBe(false);
    });
});
