import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    createApp,
    defineComponent,
    h,
    nextTick,
    ref,
    withDirectives,
} from 'vue';
import {
    focusableIn,
    initialFocus,
    nextTabKey,
    trapTab,
    vDialog,
    type DialogOptions,
} from './a11y';
import { describedByIds } from './control';

function dom(html: string): HTMLElement {
    const root = document.createElement('div');
    root.innerHTML = html;
    document.body.append(root);

    return root;
}

function tab(shift = false): KeyboardEvent {
    return new KeyboardEvent('keydown', {
        key: 'Tab',
        shiftKey: shift,
        bubbles: true,
        cancelable: true,
    });
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Fokus im Dialog', () => {
    it('zählt nur, was per Tab erreichbar ist', () => {
        const root = dom(`
            <button id="a">A</button>
            <button disabled>gesperrt</button>
            <a>ohne Ziel</a>
            <a href="#" id="b">B</a>
            <span tabindex="-1">programmatisch</span>
            <div hidden><button>versteckt</button></div>
            <div style="display: none"><input></div>
            <input type="hidden">
            <textarea id="c"></textarea>`);
        expect(focusableIn(root).map((el) => el.id)).toEqual(['a', 'b', 'c']);
    });

    it('beginnt im Editor oder ersten Eingabefeld, sonst beim ersten Bedienelement, sonst beim Dialog', () => {
        expect(
            initialFocus(
                dom(
                    '<button>×</button><input type="checkbox"><input id="feld">',
                ),
            ).id,
        ).toBe('feld');
        expect(
            initialFocus(
                dom(
                    '<select id="format"></select><div contenteditable="true" id="editor"></div>',
                ),
            ).id,
        ).toBe('editor');
        expect(
            initialFocus(dom('<select id="format"></select><input id="feld">'))
                .id,
        ).toBe('feld');
        expect(initialFocus(dom('<button id="x">×</button>')).id).toBe('x');
        const leer = dom('<p>Nur Text</p>');
        expect(initialFocus(leer)).toBe(leer);
    });

    it('hält Tab im Dialog: vom letzten zum ersten und mit Umschalt zurück', () => {
        const root = dom('<button id="a">A</button><button id="b">B</button>');
        const [a, b] = focusableIn(root);
        b?.focus();
        const vor = tab();
        expect(trapTab(vor, root)).toBe(true);
        expect(vor.defaultPrevented).toBe(true);
        expect(document.activeElement).toBe(a);
        expect(trapTab(tab(true), root)).toBe(true);
        expect(document.activeElement).toBe(b);
        // Mittendrin bleibt Tab dem Browser überlassen
        a?.focus();
        expect(trapTab(tab(), root)).toBe(false);
    });

    it('holt den Fokus von aussen zurück in den Dialog', () => {
        const draussen = dom('<button id="draussen">x</button>');
        const root = dom('<button id="a">A</button><button id="b">B</button>');
        draussen.querySelector('button')?.focus();
        trapTab(tab(true), root);
        expect(document.activeElement?.id).toBe('b');
    });
});

describe('v-dialog', () => {
    function mount(options: (close: () => void) => DialogOptions) {
        const open = ref(false);
        const close = vi.fn(() => (open.value = false));
        const host = document.createElement('div');
        document.body.append(host);
        const App = defineComponent(() => () => [
            h(
                'button',
                { id: 'ausloeser', onClick: () => (open.value = true) },
                'Öffnen',
            ),
            open.value
                ? withDirectives(
                      h('div', { role: 'dialog', id: 'dialog' }, [
                          h('button', { id: 'x' }, '×'),
                          h('input', { id: 'feld' }),
                      ]),
                      [[vDialog, options(close)]],
                  )
                : null,
        ]);
        createApp(App).mount(host);

        return { open, close };
    }

    it('fokussiert beim Öffnen das Feld, schliesst mit Escape und gibt den Fokus zurück', async () => {
        const { open, close } = mount((c) => c);
        document.getElementById('ausloeser')?.focus();
        open.value = true;
        await nextTick();
        expect(document.activeElement?.id).toBe('feld');

        document
            .getElementById('feld')
            ?.dispatchEvent(
                new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
            );
        await nextTick();
        expect(close).toHaveBeenCalledOnce();
        expect(document.getElementById('dialog')).toBeNull();
        expect(document.activeElement?.id).toBe('ausloeser');
    });

    it('lässt Tab aus einem nicht modalen Feld hinaus', async () => {
        const { open } = mount((c) => ({ close: c, modal: false }));
        open.value = true;
        await nextTick();
        const ereignis = tab();
        document.getElementById('feld')?.dispatchEvent(ereignis);
        expect(ereignis.defaultPrevented).toBe(false);
    });

    it('nimmt dem Fokus nichts weg, der beim Schliessen schon woanders ist', async () => {
        const { open } = mount((c) => c);
        document.getElementById('ausloeser')?.focus();
        open.value = true;
        await nextTick();
        const anderswo = dom('<textarea id="editor"></textarea>').querySelector(
            'textarea',
        );
        anderswo?.focus();
        open.value = false;
        await nextTick();
        expect(document.activeElement?.id).toBe('editor');
    });
});

describe('Reiter mit Pfeiltasten', () => {
    const keys = ['inhalt', 'seo', 'zugriff'];

    it('wechselt ringsum und springt an den Rand', () => {
        expect(nextTabKey(keys, 'inhalt', 'ArrowRight')).toBe('seo');
        expect(nextTabKey(keys, 'zugriff', 'ArrowRight')).toBe('inhalt');
        expect(nextTabKey(keys, 'inhalt', 'ArrowLeft')).toBe('zugriff');
        expect(nextTabKey(keys, 'seo', 'Home')).toBe('inhalt');
        expect(nextTabKey(keys, 'seo', 'End')).toBe('zugriff');
    });

    it('überlässt andere Tasten der Seite', () => {
        expect(nextTabKey(keys, 'seo', 'Enter')).toBeNull();
        expect(nextTabKey(keys, 'fehlt', 'ArrowRight')).toBeNull();
        expect(nextTabKey([], 'seo', 'ArrowRight')).toBeNull();
    });
});

describe('Fehler und Hilfe am Feld', () => {
    it('verweist zuerst auf den Fehler, dann auf die Hilfe', () => {
        expect(describedByIds('f', { error: true, help: true })).toBe(
            'f-error f-help',
        );
        expect(describedByIds('f', { help: true })).toBe('f-help');
        expect(describedByIds('f', {})).toBeUndefined();
    });
});
