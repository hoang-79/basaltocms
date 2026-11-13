import { describe, expect, it } from 'vitest';
import {
    allows,
    dropIndex,
    locate,
    moveCard,
    neighbour,
    overLimit,
} from './board';
import type { AdminBoardColumn, AdminCard } from './types';

const card = (id: number): AdminCard => ({
    id,
    title: `Karte ${id}`,
    meta: [],
    labels: [],
    due: null,
    overdue: false,
    assignee: '',
    url: null,
});

const board = (): AdminBoardColumn[] => [
    {
        key: 'todo',
        title: 'Offen',
        limit: null,
        done: false,
        cards: [card(1), card(2), card(3)],
    },
    {
        key: 'doing',
        title: 'In Arbeit',
        limit: 2,
        done: false,
        cards: [card(4)],
    },
    { key: 'done', title: 'Erledigt', limit: null, done: true, cards: [] },
];

const ids = (columns: AdminBoardColumn[], key: string): (string | number)[] =>
    columns.find((c) => c.key === key)!.cards.map((c) => c.id);

describe('Boards aus Beschreibung (CR-020)', () => {
    it('verschiebt eine Karte in eine andere Spalte an die gewählte Stelle', () => {
        const next = moveCard(board(), 2, 'doing', 0);
        expect(ids(next, 'todo')).toEqual([1, 3]);
        expect(ids(next, 'doing')).toEqual([2, 4]);
        // Das Original bleibt unverändert (Vue sieht einen neuen Stand)
        expect(ids(board(), 'todo')).toEqual([1, 2, 3]);
    });

    it('ordnet innerhalb einer Spalte um und begrenzt die Stelle auf die Länge', () => {
        expect(ids(moveCard(board(), 1, 'todo', 2), 'todo')).toEqual([2, 3, 1]);
        expect(ids(moveCard(board(), 1, 'todo', 99), 'todo')).toEqual([
            2, 3, 1,
        ]);
        expect(ids(moveCard(board(), 3, 'todo', -5), 'todo')).toEqual([
            3, 1, 2,
        ]);
    });

    it('hält das WIP-Limit ein: in eine volle Spalte geht nichts mehr', () => {
        const full = moveCard(board(), 1, 'doing', 1);
        expect(ids(full, 'doing')).toEqual([4, 1]);
        expect(allows(full, 'todo', 'doing')).toBe(false);
        expect(moveCard(full, 2, 'doing', 0)).toBe(full);
        // Umordnen in der vollen Spalte geht weiter, ebenso hinaus
        expect(ids(moveCard(full, 1, 'doing', 0), 'doing')).toEqual([1, 4]);
        expect(ids(moveCard(full, 4, 'done', 0), 'done')).toEqual([4]);
    });

    it('meldet überschrittene Limits, unbekannte Karten und Spalten bleiben ohne Wirkung', () => {
        const columns = board();
        columns[1].limit = 1;
        columns[1].cards.push(card(5));
        expect(overLimit(columns[1])).toBe(true);
        expect(overLimit(columns[0])).toBe(false);
        expect(moveCard(columns, 99, 'done', 0)).toBe(columns);
        expect(moveCard(columns, 1, 'nirgends', 0)).toBe(columns);
        expect(locate(columns, '5')).toEqual({ column: 'doing', index: 1 });
    });

    it('findet die Stelle beim Ablegen und die Nachbarspalte für die Tastatur', () => {
        expect(dropIndex([10, 50, 90], 5)).toBe(0);
        expect(dropIndex([10, 50, 90], 60)).toBe(2);
        expect(dropIndex([10, 50, 90], 200)).toBe(3);
        expect(dropIndex([], 10)).toBe(0);
        expect(neighbour(board(), 'todo', 1)).toBe('doing');
        expect(neighbour(board(), 'todo', -1)).toBeNull();
        expect(neighbour(board(), 'done', 1)).toBeNull();
    });
});
