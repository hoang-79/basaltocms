import { describe, expect, it } from 'vitest';
import { renderSummary } from './reactions';

describe('renderSummary', () => {
    it('zeigt nur Reaktionen mit Zählung, in fester Reihenfolge', () => {
        const chips = renderSummary(
            { love: 2, like: 1, sad: 0 },
            { like: '👍', love: '❤️', sad: '😢' },
            { like: 'Gefällt mir', love: 'Super', sad: 'Traurig' },
        );
        expect(chips.map((c) => c.textContent)).toEqual(['👍 1', '❤️ 2']);
        expect(chips[1]?.title).toBe('Super');
    });

    it('hebt die eigene Reaktion hervor', () => {
        const chips = renderSummary(
            { love: 2, like: 1 },
            { like: '👍', love: '❤️' },
            { like: 'Gefällt mir', love: 'Super' },
            'love',
        );
        expect(chips.map((c) => c.className)).toEqual([
            'forum-reaktion',
            'forum-reaktion ist-meine',
        ]);
    });
});
