import { describe, expect, it } from 'vitest';
import { keepsTogether, numeric } from './table';

describe('Tabellen aus Beschreibung', () => {
    it('hält Zahlen, Beträge, Codes und Stände immer auf einer Zeile', () => {
        expect(keepsTogether('money', 'CHF 1’165.00')).toBe(true);
        expect(keepsTogether('number', '2087')).toBe(true);
        expect(keepsTogether('mono', '000000000000000000202600205')).toBe(true);
        expect(keepsTogether('badge', 'Nicht zugeordnet')).toBe(true);
    });

    it('hält kurze Werte ohne Leerzeichen zusammen — eine Nummer bricht nicht am Bindestrich', () => {
        expect(keepsTogether('text', '2026-0053')).toBe(true);
        expect(keepsTogether('link', '/docs')).toBe(true);
    });

    it('lässt Text mit Leerzeichen und lange Werte umbrechen', () => {
        expect(
            keepsTogether('text', 'Mitgliederbeitrag 2026 · Aktivmitglied'),
        ).toBe(false);
        expect(
            keepsTogether('text', 'heidi.fischer.107@testdaten.example'),
        ).toBe(false);
        expect(keepsTogether('date', '04.11.2026, 18:22')).toBe(false);
    });

    it('stellt nur Zahlen und Beträge rechtsbündig', () => {
        expect(numeric('money')).toBe(true);
        expect(numeric('number')).toBe(true);
        expect(numeric('mono')).toBe(false);
    });
});
