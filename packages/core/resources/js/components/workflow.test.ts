import { describe, expect, test } from 'vitest';
import type { WorkflowPageRow } from '../types-workflow';
import {
    isSelectable,
    lineStations,
    pruneSelection,
    taskMark,
    toggleAll,
    toggleSelection,
} from './workflow';

const row = (id: number, content: string | null): WorkflowPageRow => ({
    id,
    title: `Seite ${id}`,
    type: content ? 'page' : 'folder',
    type_label: content ? 'Seite' : 'Ordner',
    depth: 0,
    parent_id: null,
    position: id,
    has_children: false,
    path: null,
    status: {},
    hint: null,
    target: null,
    is_home: false,
    match: true,
    visible: true,
    changed: null,
    urls: {
        settings: `/admin/pages/${id}`,
        content,
        preview: null,
        move: '',
        delete: '',
        new_child: null,
    },
});

describe('Bahn', () => {
    test('zurückgelegt, aktuell, kommend', () => {
        expect(lineStations('approved').map((s) => s.state)).toEqual([
            'past',
            'past',
            'current',
            'next',
            'next',
            'next',
        ]);
    });
});

describe('Aufgaben im Cockpit', () => {
    test('Rand und Knopf je Art', () => {
        expect(taskMark('review')).toEqual({ status: 'lava', primary: true });
        expect(taskMark('translation').status).toBe('warning');
        expect(taskMark('scheduled').status).toBe('success');
        expect(taskMark('rejected').status).toBe('danger');
        expect(taskMark('not_found')).toEqual({
            status: 'warning',
            primary: false,
        });
        expect(taskMark('forms').status).toBeNull();
    });
});

describe('Massenaktionen', () => {
    const rows = [row(1, '/admin/entries/1/de'), row(2, null), row(3, '/x')];

    test('nur Seiten mit Inhalt sind wählbar', () => {
        expect(rows.filter(isSelectable).map((r) => r.id)).toEqual([1, 3]);
    });

    test('einzeln umschalten', () => {
        expect(toggleSelection([], 1)).toEqual([1]);
        expect(toggleSelection([1, 3], 1)).toEqual([3]);
    });

    test('alle auswählen und wieder abwählen', () => {
        expect(toggleAll([], rows)).toEqual([1, 3]);
        expect(toggleAll([1, 3], rows)).toEqual([]);
        expect(toggleAll([3], rows)).toEqual([3, 1]);
    });

    test('verschwundene Zeilen fallen aus der Auswahl', () => {
        expect(pruneSelection([1, 2, 3, 9], rows)).toEqual([1, 3]);
    });
});
