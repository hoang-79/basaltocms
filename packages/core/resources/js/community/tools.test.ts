import { describe, expect, it, vi } from 'vitest';
import { menuOf } from './toolbar';
import { normalizeHref, tableGrid } from './tools';

describe('normalizeHref', () => {
    it('nimmt Web, Mail, Pfade und Anker und ergänzt https:// bei Domains', () => {
        expect(normalizeHref('https://basaltocms.com')).toBe(
            'https://basaltocms.com',
        );
        expect(normalizeHref('mailto:hallo@example.ch')).toBe(
            'mailto:hallo@example.ch',
        );
        expect(normalizeHref('/community/thema/12')).toBe(
            '/community/thema/12',
        );
        expect(normalizeHref('#beitrag-4')).toBe('#beitrag-4');
        expect(normalizeHref('basaltocms.com/doku')).toBe(
            'https://basaltocms.com/doku',
        );
    });

    it('weist Skripte, Protokoll-relative Adressen und Leeres ab', () => {
        expect(normalizeHref('javascript:alert(1)')).toBeNull();
        expect(normalizeHref('//evil.example')).toBeNull();
        expect(normalizeHref('   ')).toBeNull();
        expect(normalizeHref('kein link')).toBeNull();
    });
});

describe('menuOf', () => {
    it('bewegt den Fokus mit den Pfeiltasten und meldet den Zustand', async () => {
        const run = vi.fn();
        const close = vi.fn();
        const menu = menuOf(
            [
                {
                    key: 'a',
                    icon: 'list',
                    label: 'Aufzählung',
                    run,
                    active: () => true,
                },
                {
                    key: 'b',
                    icon: 'ordered',
                    label: 'Nummeriert',
                    run: vi.fn(),
                    active: () => false,
                },
                {
                    key: 'c',
                    icon: 'indent',
                    label: 'Einzug',
                    run: vi.fn(),
                    enabled: () => false,
                },
            ],
            close,
            { label: 'Listen', horizontal: true },
        );
        document.body.append(menu);
        await Promise.resolve();
        const items = Array.from(menu.querySelectorAll('button'));
        expect(items.map((i) => i.getAttribute('aria-checked'))).toEqual([
            'true',
            'false',
            null,
        ]);
        expect(items[2]?.disabled).toBe(true);
        expect(document.activeElement).toBe(items[0]);
        menu.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }),
        );
        expect(document.activeElement).toBe(items[1]);
        // Deaktiviertes überspringen — es geht zurück zum ersten
        menu.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }),
        );
        expect(document.activeElement).toBe(items[0]);
        items[0]?.click();
        expect(close).toHaveBeenCalledWith(false);
        expect(run).toHaveBeenCalledOnce();
        menu.remove();
    });
});

describe('tableGrid', () => {
    it('wählt die Grösse mit Pfeiltasten und fügt mit Enter ein', () => {
        const insert = vi.fn();
        const grid = tableGrid((_key, fallback) => fallback, insert, {
            rows: 4,
            cols: 5,
        });
        const field = grid.querySelector<HTMLElement>('.insel-raster__felder');
        const key = (k: string): void => {
            field?.dispatchEvent(
                new KeyboardEvent('keydown', { key: k, bubbles: true }),
            );
        };
        expect(grid.querySelector('.insel-raster__groesse')?.textContent).toBe(
            '3 × 3',
        );
        key('ArrowRight');
        key('ArrowRight');
        key('ArrowRight');
        key('ArrowDown');
        key('ArrowDown');
        expect(grid.querySelector('.insel-raster__groesse')?.textContent).toBe(
            '4 × 5',
        );
        expect(field?.getAttribute('aria-valuetext')).toBe(
            '4 Zeilen, 5 Spalten',
        );
        key('Enter');
        expect(insert).toHaveBeenCalledWith(4, 5);
    });
});
