import { describe, expect, test } from 'vitest';
import { diffSides, tokenize, wordDiff } from './diff';

describe('tokenize', () => {
    test('trennt Wörter, Leerraum und Satzzeichen', () => {
        expect(tokenize('Hallo, Welt.')).toEqual([
            'Hallo',
            ',',
            ' ',
            'Welt',
            '.',
        ]);
    });

    test('hält Umlaute und Bindestriche im Wort', () => {
        expect(tokenize('Vier-Augen-Prinzip für Grüne')).toEqual([
            'Vier-Augen-Prinzip',
            ' ',
            'für',
            ' ',
            'Grüne',
        ]);
    });
});

describe('wordDiff', () => {
    test('eingefügte Wörter', () => {
        expect(
            wordDiff(
                'Software bauen, Produkte betreiben.',
                'Software bauen, Produkte betreiben — und nie still.',
            ),
        ).toEqual([
            { kind: 'same', text: 'Software bauen, Produkte betreiben' },
            { kind: 'ins', text: ' — und nie still' },
            { kind: 'same', text: '.' },
        ]);
    });

    test('gestrichene Wörter', () => {
        expect(wordDiff('ein sehr guter Text', 'ein guter Text')).toEqual([
            { kind: 'same', text: 'ein ' },
            { kind: 'del', text: 'sehr ' },
            { kind: 'same', text: 'guter Text' },
        ]);
    });

    test('ersetzte Wörter werden ein Lauf aus gestrichen und eingefügt', () => {
        expect(
            wordDiff(
                'der Betrieb, der nie schweigt.',
                'der Betrieb, der still ist.',
            ),
        ).toEqual([
            { kind: 'same', text: 'der Betrieb, der ' },
            { kind: 'del', text: 'nie schweigt' },
            { kind: 'ins', text: 'still ist' },
            { kind: 'same', text: '.' },
        ]);
    });

    test('unveränderter Text bleibt ein Teil', () => {
        expect(wordDiff('Leistungen', 'Leistungen')).toEqual([
            { kind: 'same', text: 'Leistungen' },
        ]);
    });

    test('leere Seiten', () => {
        expect(wordDiff('', '')).toEqual([]);
        expect(wordDiff(null, 'neu da')).toEqual([
            { kind: 'ins', text: 'neu da' },
        ]);
        expect(wordDiff('weg damit', undefined)).toEqual([
            { kind: 'del', text: 'weg damit' },
        ]);
    });

    test('Umlaute zählen als Wortzeichen', () => {
        expect(wordDiff('Grüsse aus Zürich', 'Grüsse aus Bümpliz')).toEqual([
            { kind: 'same', text: 'Grüsse aus ' },
            { kind: 'del', text: 'Zürich' },
            { kind: 'ins', text: 'Bümpliz' },
        ]);
    });

    test('alt und neu lassen sich aus den Teilen wieder zusammensetzen', () => {
        const before =
            'Anwendungen auf Laravel 12, Inertia und Vue — mit Tests.';
        const after =
            'Anwendungen auf Laravel 13 und Vue — mit Tests, Pipeline.';
        const sides = diffSides(wordDiff(before, after));
        expect(sides.old.map((p) => p.text).join('')).toBe(before);
        expect(sides.new.map((p) => p.text).join('')).toBe(after);
    });
});
