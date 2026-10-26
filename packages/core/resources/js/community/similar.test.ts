import { describe, expect, it } from 'vitest';
import { enhanceSimilar, renderSimilar } from './similar';

describe('Ähnliche Themen', () => {
    it('zeigt nichts ohne Treffer und eine Liste mit Treffern', () => {
        expect(renderSimilar('Ähnlich:', [])).toEqual([]);
        const [head, list] = renderSimilar('Ähnlich:', [
            {
                title: 'Theme bauen',
                url: '/community/thema/1-theme',
                replies: 2,
                done: true,
            },
        ]);
        expect(head?.textContent).toBe('Ähnlich:');
        expect(list?.querySelector('a')?.getAttribute('href')).toBe(
            '/community/thema/1-theme',
        );
        expect(list?.textContent).toContain('✓');
    });

    it('fragt nach einer Pause und füllt den Kasten', async () => {
        document.body.innerHTML =
            '<input id="t" data-insel="aehnlich" data-insel-url="/forum/aehnlich" data-insel-basis="/community" data-insel-text="Ähnlich:"><div id="forum-aehnlich" hidden></div>';
        const input = document.getElementById('t') as HTMLInputElement;
        let asked = '';
        const fake = (async (url: string) => {
            asked = url;
            return {
                ok: true,
                json: async () => [
                    {
                        title: 'Lava-Schleifen',
                        url: '/community/thema/2-lava',
                        replies: 0,
                        done: false,
                    },
                ],
            };
        }) as unknown as typeof fetch;
        enhanceSimilar(input, fake);
        input.value = 'Lava Schleife';
        input.dispatchEvent(new Event('input'));
        await new Promise((r) => setTimeout(r, 400));
        expect(asked).toContain('titel=Lava+Schleife');
        expect(document.getElementById('forum-aehnlich')?.hidden).toBe(false);
        expect(
            document.getElementById('forum-aehnlich')?.textContent,
        ).toContain('Lava-Schleifen');
    });
});
