import { describe, expect, test } from 'vitest';
import {
    cleanOptions,
    duplicateField,
    errorsUnder,
    fieldNames,
    fieldSummary,
    fileSize,
    linesToOptions,
    moveItem,
    namesValid,
    newField,
    normalizeField,
    normalizeForm,
    optionsToLines,
    submissionStatusTone,
    submissionStatusWord,
    textKey,
    uniqueName,
} from './forms';

describe('Formular-Baukasten', () => {
    test('Eingabe vom Server: leere Texte als [] werden Objekte, die Grundsprache heisst «*»', () => {
        const form = normalizeForm(
            {
                handle: 'kontakt',
                labels: { de: 'Kontakt', en: 'Contact' },
                fields: [
                    {
                        name: 'thema',
                        type: 'radio',
                        labels: [],
                        options: { '*': ['A', 'B'], en: ['A', 'B'] },
                        min: null,
                        size: '5',
                    },
                    {
                        name: 'details',
                        type: 'group',
                        fields: [{ name: 'datei', type: 'file' }],
                    },
                ],
                thanks: [],
                store: true,
                retain: 30,
                redirect: null,
            },
            'de',
        );
        expect(form.labels).toEqual({ '*': 'Kontakt', en: 'Contact' });
        expect(form.thanks).toEqual({});
        expect(form.fields[0]?.labels).toEqual({});
        expect(form.fields[0]?.options['*']).toEqual(['A', 'B']);
        expect(form.fields[0]?.size).toBe('5');
        expect(form.fields[1]?.fields[0]?.name).toBe('datei');
        expect(form.retain).toBe('30');
        expect(form.redirect).toBe('');
        expect(form.store).toBe(true);
        expect(textKey({ code: 'de', default: true })).toBe('*');
        expect(textKey({ code: 'en', default: false })).toBe('en');
    });

    test('Neues Feld bekommt einen freien Namen und die Beschriftung des Typs', () => {
        expect(uniqueName('email', [])).toBe('email');
        expect(uniqueName('email', ['email'])).toBe('email_2');
        expect(uniqueName('email_2', ['email', 'email_2'])).toBe('email_3');
        expect(uniqueName('2 Bad Name!', [])).toBe('bad_name_');
        const field = newField('select', 'Auswahl', ['auswahl']);
        expect(field.name).toBe('auswahl_2');
        expect(field.labels).toEqual({ '*': 'Auswahl' });
        expect(field.options).toEqual({ '*': [] });
        expect(newField('explanation', 'Erklärung', []).text).toEqual({
            '*': '',
        });
    });

    test('Duplizieren setzt die Kopie darunter, mit freien Namen auch in der Gruppe', () => {
        const group = normalizeField({
            name: 'details',
            type: 'group',
            fields: [{ name: 'datei', type: 'file' }],
        });
        const list = [normalizeField({ name: 'name', type: 'text' }), group];
        const next = duplicateField(list, 1);
        expect(next.map((f) => f.name)).toEqual([
            'name',
            'details',
            'details_2',
        ]);
        expect(next[2]?.fields[0]?.name).toBe('datei_2');
        expect(fieldNames(next)).toEqual([
            'name',
            'details',
            'datei',
            'details_2',
            'datei_2',
        ]);
        expect(list).toHaveLength(2);
        expect(duplicateField(list, 9)).toBe(list);
    });

    test('Verschieben', () => {
        expect(moveItem(['a', 'b', 'c'], 0, 2)).toEqual(['b', 'c', 'a']);
        expect(moveItem(['a', 'b', 'c'], 2, 1)).toEqual(['a', 'c', 'b']);
        const list = ['a', 'b'];
        expect(moveItem(list, 0, 5)).toBe(list);
        expect(moveItem(list, -1, 0)).toBe(list);
    });

    test('Optionen ↔ Zeilen: eine je Zeile, Komma bleibt in der Option', () => {
        expect(optionsToLines(['Website', 'Backend, API'])).toBe(
            'Website\nBackend, API',
        );
        expect(linesToOptions('Website\r\nBackend, API\n')).toEqual([
            'Website',
            'Backend, API',
            '',
        ]);
        expect(linesToOptions('')).toEqual([]);
        expect(cleanOptions([' A ', '', 'B'])).toEqual(['A', 'B']);
    });

    test('Kurzbeschreibung je Feld', () => {
        expect(
            fieldSummary(
                normalizeField({
                    name: 'thema',
                    type: 'radio',
                    required: true,
                    options: { '*': ['a', 'b', ''] },
                }),
            ),
        ).toBe('radio · 2 Optionen · Pflicht');
        expect(
            fieldSummary(
                normalizeField({
                    name: 'datei',
                    type: 'file',
                    types: 'pdf, docx',
                    size: 5,
                }),
            ),
        ).toBe('file · PDF, DOCX · bis 5 MB · freiwillig');
        expect(
            fieldSummary(
                normalizeField({ name: 'n', type: 'number', min: 1, max: 9 }),
            ),
        ).toBe('number · 1–9 · freiwillig');
        expect(fieldSummary(normalizeField({ name: 'g', type: 'group' }))).toBe(
            'group · 0 Felder',
        );
    });

    test('Fehler je Pfad und gültige Namen', () => {
        const errors = {
            'fields.3.fields.1.name': 'Name',
            'fields.3': 'Feld',
            'fields.30.name': 'anderes',
            handle: 'Kennung',
        };
        expect(errorsUnder(errors, 'fields.3')).toEqual(['Name', 'Feld']);
        expect(
            namesValid([
                normalizeField({
                    name: 'g',
                    type: 'group',
                    fields: [{ name: 'Gross', type: 'text' }],
                }),
            ]),
        ).toBe(false);
        expect(namesValid([normalizeField({ name: 'ok_1' })])).toBe(true);
    });
});

describe('Eingänge', () => {
    test('Status und Dateigrösse', () => {
        expect(submissionStatusWord('answered')).toBe('beantwortet');
        expect(submissionStatusTone('new')).toBe('lava');
        expect(submissionStatusTone('read')).toBe('grey');
        expect(fileSize(820)).toBe('820 B');
        expect(fileSize(12_400)).toBe('12 KB');
        expect(fileSize(4_404_019)).toBe('4.2 MB');
    });
});
