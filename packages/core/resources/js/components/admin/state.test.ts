import { describe, expect, it } from 'vitest';
import { mergeServerValues, pageErrors, sectionKey } from './state';
import type { AdminSection } from './types';

const form = (url: string): AdminSection => ({
    kind: 'form',
    title: null,
    url,
    method: 'put',
    autosave: true,
    submit: 'Speichern',
    fields: [],
    values: {},
});

describe('Verwaltungsseiten aus Beschreibung (TB 101)', () => {
    it('gibt einem Formular für einen anderen Datensatz einen neuen Schlüssel', () => {
        expect(sectionKey(form('/admin/karte/1'), 0)).not.toBe(
            sectionKey(form('/admin/karte/2'), 0),
        );
        expect(sectionKey(form('/admin/karte/1'), 0)).toBe(
            sectionKey(form('/admin/karte/1'), 3),
        );
        expect(sectionKey({ kind: 'text', title: null, text: 'x' }, 2)).toBe(
            'text:2',
        );
    });

    it('übernimmt neue Werte vom Server nur in unberührten Feldern', () => {
        const before = { label: 'Bundeshaus', lat: 46.9, lon: 7.4 };
        const after = { label: 'Bundeshaus', lat: 46.946774, lon: 7.444192 };
        expect(mergeServerValues({ ...before }, before, after)).toEqual({
            updates: { lat: 46.946774, lon: 7.444192 },
            clean: true,
        });
        // Getippte Bezeichnung bleibt, die Koordinaten kommen trotzdem
        expect(
            mergeServerValues({ ...before, label: 'Bundes' }, before, after),
        ).toEqual({ updates: { lat: 46.946774, lon: 7.444192 }, clean: false });
        // Dasselbe vom Server wie hier: nichts zu tun
        expect(mergeServerValues({ ...after }, before, after)).toEqual({
            updates: {},
            clean: true,
        });
    });

    it('zeigt oben nur Fehler, die zu keinem Feld gehören (CR-020)', () => {
        const withField: AdminSection = {
            kind: 'form',
            title: null,
            url: '/admin/karte/1',
            method: 'put',
            autosave: true,
            submit: 'Speichern',
            fields: [{ name: 'title', label: 'Titel', type: 'text' }],
            values: {},
        };
        expect(
            pageErrors([withField, { kind: 'text', title: null, text: '' }], {
                title: 'Der Titel fehlt.',
                card: 'Die Spalte ist voll.',
                'ids.0': 'Unbekannt.',
                action: 'Die Spalte ist voll.',
            }),
        ).toEqual(['Die Spalte ist voll.', 'Unbekannt.']);
        expect(pageErrors([], {})).toEqual([]);
    });
});
