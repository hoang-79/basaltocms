import { describe, expect, test } from 'vitest';
import { activeStation } from '../support';
import {
    canIndent,
    canMoveSibling,
    dropRange,
    indent,
    moveBlock,
    moveSibling,
    outdent,
    toOrder,
    type NavRow,
} from './navigation';

/** [id, Tiefe] → Zeilen */
const rows = (...list: [number, number][]): NavRow[] =>
    list.map(([id, depth]) => ({ id, depth }));

const shape = (list: NavRow[]): [number, number][] =>
    list.map((r) => [r.id, r.depth]);

describe('toOrder', () => {
    test('leitet die Eltern aus Tiefe und Reihenfolge ab', () => {
        expect(toOrder(rows([1, 0], [2, 1], [3, 2], [4, 1], [5, 0]))).toEqual([
            { id: 1, parent_id: null },
            { id: 2, parent_id: 1 },
            { id: 3, parent_id: 2 },
            { id: 4, parent_id: 1 },
            { id: 5, parent_id: null },
        ]);
    });
});

describe('tiefer und höher', () => {
    test('tiefer macht den Eintrag zum Untereintrag des vorigen Geschwisters, mit Teilbaum', () => {
        const list = rows([1, 0], [2, 0], [3, 1], [4, 0]);

        expect(shape(indent(list, 1, 3))).toEqual([
            [1, 0],
            [2, 1],
            [3, 2],
            [4, 0],
        ]);
    });

    test('tiefer respektiert die Ebenen aus Lava und braucht ein Geschwister davor', () => {
        const list = rows([1, 0], [2, 0], [3, 1]);

        expect(canIndent(list, 0, 2)).toBe(false);
        expect(canIndent(list, 2, 2)).toBe(false);
        expect(canIndent(list, 1, 2)).toBe(false);
        expect(canIndent(list, 1, 3)).toBe(true);
        expect(canIndent(list, 1, 0)).toBe(true);
        expect(indent(list, 1, 2)).toBe(list);
    });

    test('höher setzt den Eintrag hinter den Teilbaum seines Elterneintrags', () => {
        const list = rows([1, 0], [2, 1], [3, 2], [4, 1], [5, 0]);

        expect(shape(outdent(list, 1))).toEqual([
            [1, 0],
            [4, 1],
            [2, 0],
            [3, 1],
            [5, 0],
        ]);
        expect(toOrder(outdent(list, 1))[1]).toEqual({ id: 4, parent_id: 1 });
        expect(outdent(list, 0)).toBe(list);
    });
});

describe('Ziehen', () => {
    test('legt den Teilbaum vor einem Eintrag ab und hält die Tiefe in den Grenzen', () => {
        const list = rows([1, 0], [2, 0], [3, 1], [4, 0]);

        // 2 mit 3 ans Ende, Wunsch Tiefe 5 → höchstens unter 4 (Tiefe 1), Teilbaum bis 2
        expect(shape(moveBlock(list, 1, 2, 5, 3))).toEqual([
            [1, 0],
            [4, 0],
            [2, 1],
            [3, 2],
        ]);
        // dieselbe Bewegung mit zwei Ebenen: der Teilbaum passt nur auf Tiefe 0
        expect(shape(moveBlock(list, 1, 2, 5, 2))).toEqual([
            [1, 0],
            [4, 0],
            [2, 0],
            [3, 1],
        ]);
    });

    test('lässt den Eintrag danach nicht verwaisen', () => {
        const list = rows([1, 0], [2, 0], [3, 1]);

        // 1 vor 3: 3 hat Tiefe 1 → 1 muss mindestens Tiefe 0 haben, höchstens unter 2
        expect(dropRange(list, 0, 1, 0)).toEqual({ min: 0, max: 1 });
        expect(shape(moveBlock(list, 0, 1, 0, 0))).toEqual([
            [2, 0],
            [1, 0],
            [3, 1],
        ]);
    });

    test('ganz oben ist nur Tiefe 0 erlaubt', () => {
        const list = rows([1, 0], [2, 0]);

        expect(dropRange(list, 1, 0, 3)).toEqual({ min: 0, max: 0 });
        expect(shape(moveBlock(list, 1, 0, 2, 3))).toEqual([
            [2, 0],
            [1, 0],
        ]);
    });
});

describe('nach oben und unten', () => {
    test('springt mit dem Teilbaum über das Geschwister', () => {
        const list = rows([1, 0], [2, 1], [3, 0], [4, 0]);

        expect(shape(moveSibling(list, 2, -1))).toEqual([
            [3, 0],
            [1, 0],
            [2, 1],
            [4, 0],
        ]);
        expect(shape(moveSibling(list, 0, 1))).toEqual([
            [3, 0],
            [1, 0],
            [2, 1],
            [4, 0],
        ]);
        expect(canMoveSibling(list, 1, -1)).toBe(false);
        expect(canMoveSibling(list, 3, 1)).toBe(false);
    });
});

describe('aktive Station', () => {
    const line = [
        { letter: 'C', href: '/admin/einrichtung/layouts' },
        { letter: 'D', href: '/admin/einrichtung/navigation' },
        { letter: 'G', href: '/admin/einrichtung/layouts?tab=templates' },
        { letter: 'H', href: null },
    ];

    test('folgt der Adresse, die Abfrage entscheidet zwischen C und G', () => {
        expect(
            activeStation(line, '/admin/einrichtung/navigation?nav=main'),
        ).toBe('D');
        expect(activeStation(line, '/admin/einrichtung/layouts')).toBe('C');
        expect(
            activeStation(line, '/admin/einrichtung/layouts?tab=parts'),
        ).toBe('C');
        expect(
            activeStation(line, '/admin/einrichtung/layouts?tab=templates'),
        ).toBe('G');
        expect(activeStation(line, '/admin/pages')).toBeNull();
    });
});
