import { describe, expect, it } from 'vitest';
import { enhanceSearchPage, enhanceSuggest, safeExcerpt } from './search';

const tick = (ms = 20): Promise<void> => new Promise((r) => setTimeout(r, ms));

describe('Live-Suche im Forum', () => {
    it('übernimmt im Auszug nur <mark>, alles andere wird Text', () => {
        const holder = document.createElement('p');
        holder.append(
            safeExcerpt(
                'Wie baue ich <mark>Lava</mark>? <img src=x onerror="alert(1)"><b>fett</b>',
            ),
        );
        expect(holder.querySelector('mark')?.textContent).toBe('Lava');
        expect(holder.querySelector('img, b, script')).toBeNull();
        expect(holder.textContent).toBe('Wie baue ich Lava? fett');
    });

    it('schlägt nach einer Pause Themen vor, mit Tastatur bedienbar', async () => {
        document.documentElement.lang = 'de';
        document.body.innerHTML =
            '<form action="/community/suche"><input id="s" name="q" data-suche="vorschlag" data-suche-url="/forum/suche-live" data-suche-titel="Vorschläge" data-suche-alle="Alle :count Treffer anzeigen" data-suche-leer="Keine Treffer"></form>';
        const input = document.getElementById('s') as HTMLInputElement;
        let asked = '';
        const fake = (async (url: string) => {
            asked = url;
            return {
                ok: true,
                json: async () => ({
                    total: 7,
                    items: [
                        {
                            title: 'Lava-Schleifen',
                            url: '/community/thema/2-lava',
                            excerpt: 'Schleife über <mark>Lava</mark>',
                        },
                        {
                            title: 'Lava im Theme',
                            url: '/community/beitrag/9',
                            excerpt: '<mark>Lava</mark> im Theme',
                            meta: 'Nina Derungs · Beitrag 3 · 25. April 2026',
                        },
                    ],
                }),
            };
        }) as unknown as typeof fetch;
        enhanceSuggest(input, fake, 0);
        expect(input.getAttribute('role')).toBe('combobox');

        input.value = 'Lava';
        input.dispatchEvent(new Event('input'));
        await tick();
        expect(asked).toBe('/forum/suche-live?q=Lava&sprache=de');
        const list = document.getElementById('s-vorschlaege') as HTMLElement;
        expect(list.hidden).toBe(false);
        expect(input.getAttribute('aria-expanded')).toBe('true');
        // im Bild: höchstens 26rem, nie breiter als der Bildschirm minus Rand
        expect(parseFloat(list.style.width)).toBe(
            Math.min(416, window.innerWidth - 16),
        );
        const options = list.querySelectorAll('[role="option"]');
        expect(options).toHaveLength(3);
        expect(options[0].getAttribute('href')).toBe('/community/thema/2-lava');
        expect(options[0].querySelector('small')).toBeNull();
        expect(options[1].querySelector('small')?.textContent).toBe(
            'Nina Derungs · Beitrag 3 · 25. April 2026',
        );
        expect(options[2].textContent).toBe('Alle 7 Treffer anzeigen');
        expect(options[2].getAttribute('href')).toContain(
            '/community/suche?q=Lava',
        );

        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown' }));
        expect(input.getAttribute('aria-activedescendant')).toBe(
            's-vorschlaege-0',
        );
        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp' }));
        expect(input.getAttribute('aria-activedescendant')).toBe(
            's-vorschlaege-alle',
        );
        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(list.hidden).toBe(true);
        expect(input.hasAttribute('aria-activedescendant')).toBe(false);

        // Unter zwei Zeichen fragt die Insel nicht und leert die Liste
        asked = '';
        input.value = 'L';
        input.dispatchEvent(new Event('input'));
        await tick();
        expect(asked).toBe('');
        expect(list.querySelectorAll('[role="option"]')).toHaveLength(0);
    });

    it('sagt, wenn nichts gefunden wurde', async () => {
        document.body.innerHTML =
            '<form action="/community/suche"><input id="s" name="q" data-suche="vorschlag" data-suche-url="/forum/suche-live" data-suche-alle="Alle :count Treffer" data-suche-leer="Keine Treffer — zur ausführlichen Suche"></form>';
        const input = document.getElementById('s') as HTMLInputElement;
        const fake = (async () => ({
            ok: true,
            json: async () => ({ total: 0, items: [] }),
        })) as unknown as typeof fetch;
        enhanceSuggest(input, fake, 0);
        input.value = 'xyz';
        input.dispatchEvent(new Event('input'));
        await tick();
        const options = document.querySelectorAll(
            '#s-vorschlaege [role="option"]',
        );
        expect(options).toHaveLength(1);
        expect(options[0].textContent).toBe(
            'Keine Treffer — zur ausführlichen Suche',
        );
    });

    it('erneuert auf der Suchseite die Treffer beim Tippen', async () => {
        document.body.innerHTML =
            '<form action="/community/suche" data-suche="seite"><input name="q" value=""></form><div id="forum-suche-ergebnis"></div>';
        const form = document.querySelector('form') as HTMLFormElement;
        const input = form.querySelector('input') as HTMLInputElement;
        let asked = '';
        const fake = (async (url: string) => {
            asked = url;
            return {
                ok: true,
                text: async () =>
                    '<html><body><div id="forum-suche-ergebnis"><p class="forum-note" role="status">2 Themen gefunden</p><ol class="forum-faelle"><li>A</li><li>B</li></ol></div></body></html>',
            };
        }) as unknown as typeof fetch;
        enhanceSearchPage(form, fake, 0);
        input.value = 'La';
        input.dispatchEvent(new Event('input'));
        await tick();
        expect(asked).toContain('/community/suche?q=La');
        const target = document.getElementById(
            'forum-suche-ergebnis',
        ) as HTMLElement;
        expect(target.querySelector('[role="status"]')?.textContent).toBe(
            '2 Themen gefunden',
        );
        expect(target.querySelectorAll('li')).toHaveLength(2);
        expect(window.location.search).toBe('?q=La');

        // Ein einzelnes Zeichen fragt nicht — die Treffer bleiben stehen
        asked = '';
        input.value = 'L';
        input.dispatchEvent(new Event('input'));
        await tick();
        expect(asked).toBe('');
        expect(target.querySelectorAll('li')).toHaveLength(2);
    });
});
