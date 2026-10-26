import { describe, expect, it, vi } from 'vitest';
import { enhanceCode, enhanceLines, splitLines } from './code';

const block = (column: string, code: string): HTMLElement => {
    document.body.innerHTML = `<figure class="forum-code" data-insel="code"><figcaption class="forum-code__kopf"><span class="forum-code__titel">TWIG</span></figcaption><div class="forum-code__rahmen">${column}<pre><code data-sprache="plaintext">${code}</code></pre></div></figure>`;

    return document.querySelector('code') as HTMLElement;
};

describe('splitLines', () => {
    it('legt jede Zeile in ein eigenes Element und öffnet Hervorhebungen neu', () => {
        const code = document.createElement('code');
        code.innerHTML =
            '<span class="hljs-comment">/* eins\nzwei */</span> <b>x</b>\ndrei\n';
        expect(splitLines(code)).toBe(3);
        expect(Array.from(code.children).map((line) => line.innerHTML)).toEqual(
            [
                '<span class="hljs-comment">/* eins</span>',
                '<span class="hljs-comment">zwei */</span> <b>x</b>',
                'drei',
            ],
        );
        expect(code.children[0].className).toBe('forum-code__zeile');
    });

    it('behält leere Zeilen in der Mitte', () => {
        const code = document.createElement('code');
        code.textContent = 'a\n\nb';
        expect(splitLines(code)).toBe(3);
        expect(code.children[1].textContent).toBe('');
    });
});

describe('enhanceLines', () => {
    it('hängt die Nummern an die Zeilen und bietet «Mehr», solange Zeilen abgeschnitten sind', () => {
        const code = block(
            '<span class="forum-code__zeilen" aria-hidden="true">9\n10</span>',
            'SQLSTATE[HY000]: lange Zeile\nkurz',
        );
        const frame = document.querySelector(
            '.forum-code__rahmen',
        ) as HTMLElement;
        Object.defineProperty(frame, 'clientWidth', { value: 200 });
        const width = { value: 640 };
        Object.defineProperty(frame, 'scrollWidth', {
            get: () => width.value,
        });
        let check = (): void => {};
        enhanceLines(code, 'de', (_target, run) => {
            check = run;
            run();
        });

        const figure = document.querySelector('figure') as HTMLElement;
        expect(figure.dataset.zeilen).toBe('2');
        expect(figure.classList.contains('forum-code--nummern')).toBe(true);
        expect(code.style.counterReset).toBe('zeile 8');
        expect(figure.style.getPropertyValue('--ziffern')).toBe('2');

        const more = figure.querySelector(
            '.forum-code__mehr',
        ) as HTMLButtonElement;
        expect(more.hidden).toBe(false);
        expect(more.textContent).toBe('Mehr');
        expect(more.getAttribute('aria-controls')).toBe(frame.id);
        more.click();
        expect(figure.classList.contains('forum-code--ganz')).toBe(true);
        expect([more.textContent, more.getAttribute('aria-expanded')]).toEqual([
            'Weniger',
            'true',
        ]);
        // umgebrochen passt alles — der Knopf bleibt, damit man zurück kann
        width.value = 200;
        check();
        expect(more.hidden).toBe(false);
        more.click();
        check();
        expect(more.hidden).toBe(true);

        // ein zweites Mal ändert nichts
        enhanceLines(code, 'de', () => {});
        expect(figure.querySelectorAll('.forum-code__mehr')).toHaveLength(1);
    });

    it('ohne Nummernspalte keine Nummern, Texte in der Sprache der Seite', () => {
        const code = block('', 'ein Satz');
        enhanceLines(code, 'fr', (_target, run) => run());
        const figure = document.querySelector('figure') as HTMLElement;
        expect(figure.classList.contains('forum-code--nummern')).toBe(false);
        expect(figure.querySelector('.forum-code__mehr')?.textContent).toBe(
            'Plus',
        );
    });
});

describe('enhanceCode', () => {
    it('kopiert den Code mit seinen Zeilenumbrüchen, auch nach dem Legen der Zeilen', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText },
            configurable: true,
        });
        block(
            '<span class="forum-code__zeilen" aria-hidden="true">1\n2</span>',
            'eins\nzwei',
        );
        await enhanceCode(document);
        expect(document.querySelectorAll('.forum-code__zeile')).toHaveLength(2);
        (
            document.querySelector('.forum-code__kopieren') as HTMLButtonElement
        ).click();
        expect(writeText).toHaveBeenCalledWith('eins\nzwei');
        const caption = document.querySelector('figcaption') as HTMLElement;
        expect(
            Array.from(caption.querySelectorAll('button')).map(
                (b) => b.className,
            ),
        ).toEqual(['forum-code__mehr', 'forum-code__kopieren']);
    });
});
