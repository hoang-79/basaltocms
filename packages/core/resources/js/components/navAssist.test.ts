import { describe, expect, it } from 'vitest';
import type { NavigationItem, NavigationPage } from '../types';
import {
    looksLikeAddress,
    matchingPages,
    missingPages,
    normalizeAddress,
    pageAt,
} from './navAssist';

const page = (
    id: number,
    title: string,
    path: string,
    depth = 0,
    live = true,
): NavigationPage => ({ id, title, path, depth, live });
const pages = [
    page(1, 'Start', '/'),
    page(2, 'Kontakt', '/kontakt'),
    page(3, 'Über uns', '/ueber-uns'),
    page(4, 'Team', '/ueber-uns/team', 1),
    page(5, 'Entwurf', '/entwurf', 0, false),
];
const item = (entryId: number | null): NavigationItem =>
    ({
        id: 1,
        parent_id: null,
        depth: 0,
        label: {},
        label_effective: '',
        label_fallback: true,
        target: {
            kind: entryId === null ? 'url' : 'entry',
            entry_id: entryId,
            url: null,
            title: null,
            path: null,
        },
        visible: true,
        new_window: false,
        live: true,
        hint: null,
        urls: null,
    }) as unknown as NavigationItem;

describe('navAssist', () => {
    it('erkennt Adressen und ergänzt www', () => {
        expect(looksLikeAddress('/blog')).toBe(true);
        expect(looksLikeAddress('www.example.ch')).toBe(true);
        expect(looksLikeAddress('Kontakt')).toBe(false);
        expect(normalizeAddress('www.example.ch')).toBe(
            'https://www.example.ch',
        );
    });

    it('findet die Seite zu einer Adresse und Seiten zum Titel', () => {
        expect(pageAt('/kontakt/', pages)?.id).toBe(2);
        expect(pageAt('https://example.org', pages)).toBeNull();
        expect(matchingPages('ko', pages).map((p) => p.id)).toEqual([2]);
        expect(matchingPages('uns', pages).map((p) => p.id)).toEqual([3, 4]);
    });

    it('nennt veröffentlichte Seiten, die im Menü fehlen — oberste Ebene zuerst', () => {
        expect(missingPages(pages, [item(2)]).map((p) => p.id)).toEqual([
            1, 3, 4,
        ]);
    });
});
