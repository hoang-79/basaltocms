import { describe, expect, it } from 'vitest';
import {
    clampHelpWidth,
    closeHelp,
    helpImage,
    helpShortcut,
    helpState,
    helpTarget,
    isTypingTarget,
    openHelp,
    toggleHelp,
} from './help';

/** Webhilfe (TB 20): Tastenkürzel des Hilfe-Panels, Links auf Hilfeseiten und der gemeinsame Zustand. */
function key(
    k: string,
    target: EventTarget | null = document.body,
    extra: Partial<KeyboardEvent> = {},
): Pick<
    KeyboardEvent,
    'key' | 'metaKey' | 'ctrlKey' | 'altKey' | 'defaultPrevented' | 'target'
> {
    return {
        key: k,
        metaKey: false,
        ctrlKey: false,
        altKey: false,
        defaultPrevented: false,
        target,
        ...extra,
    };
}

describe('helpShortcut', () => {
    it('? und F1 schalten die Hilfe, / sucht nur bei offenem Panel', () => {
        expect(helpShortcut(key('?'), false)).toBe('toggle');
        expect(helpShortcut(key('F1'), true)).toBe('toggle');
        expect(helpShortcut(key('/'), false)).toBeNull();
        expect(helpShortcut(key('/'), true)).toBe('search');
        expect(helpShortcut(key('a'), true)).toBeNull();
    });

    it('in Feldern und Editoren bleiben ? und / Zeichen, F1 gilt überall', () => {
        const input = document.createElement('input');
        const editor = document.createElement('div');
        editor.setAttribute('contenteditable', 'true');
        const inside = document.createElement('p');
        editor.append(inside);
        document.body.append(input, editor);
        expect(isTypingTarget(input)).toBe(true);
        expect(isTypingTarget(inside)).toBe(true);
        expect(isTypingTarget(document.body)).toBe(false);
        expect(helpShortcut(key('?', input), false)).toBeNull();
        expect(helpShortcut(key('/', inside), true)).toBeNull();
        expect(helpShortcut(key('F1', input), false)).toBe('toggle');
        input.remove();
        editor.remove();
    });

    it('lässt Tasten mit Modifikator und schon behandelte Tasten in Ruhe', () => {
        expect(
            helpShortcut(key('?', document.body, { metaKey: true }), false),
        ).toBeNull();
        expect(
            helpShortcut(
                key('?', document.body, { defaultPrevented: true }),
                false,
            ),
        ).toBeNull();
        expect(
            helpShortcut(
                key('F1', document.body, { defaultPrevented: true }),
                false,
            ),
        ).toBeNull();
    });
});

describe('helpTarget', () => {
    const origin = 'https://basalto.test';
    const base = 'https://basalto.test/admin/hilfe';

    it('erkennt Hilfeseiten mit Anker', () => {
        expect(
            helpTarget('/admin/hilfe/betrieb-rollen#vorlagen', base, origin),
        ).toEqual({ slug: 'betrieb-rollen', anchor: 'vorlagen' });
        expect(
            helpTarget('https://basalto.test/admin/hilfe', base, origin),
        ).toEqual({ slug: 'index', anchor: null });
        expect(
            helpTarget(
                '/admin/hilfe/betrieb#f%C3%BCr-entwickler',
                base,
                origin,
            ),
        ).toEqual({ slug: 'betrieb', anchor: 'für-entwickler' });
    });

    it('lässt andere Adressen als Link', () => {
        expect(
            helpTarget(
                'https://github.com/hoang-79/basaltocms/blob/main/LICENSE.md',
                base,
                origin,
            ),
        ).toBeNull();
        expect(helpTarget('/admin/hilfe-alt', base, origin)).toBeNull();
        expect(helpTarget('/admin/medien', base, origin)).toBeNull();
        expect(
            helpTarget('/admin/hilfe/panel/medien', base, origin),
        ).toBeNull();
    });
});

describe('helpState', () => {
    it('öffnen, umschalten und schliessen', () => {
        closeHelp();
        const before = helpState.version;
        openHelp('medien', 'alt-text');
        expect(helpState).toMatchObject({
            open: true,
            slug: 'medien',
            anchor: 'alt-text',
            version: before + 1,
        });
        toggleHelp('seiten');
        expect(helpState.open).toBe(false);
        toggleHelp('seiten');
        expect(helpState).toMatchObject({
            open: true,
            slug: 'seiten',
            anchor: null,
        });
        closeHelp();
        expect(helpState.open).toBe(false);
    });
});

describe('Bilder und Breite der Hilfe (TB 29)', () => {
    it('erkennt ein Bild der Hilfe und liest Original, Alt-Text und Unterschrift', () => {
        document.body.innerHTML =
            '<figure class="hilfe-bild"><a href="/_doku/redaktion/bilder/a.webp"><img src="/_doku/redaktion/bilder/a.webp" alt="Der Seitenbaum"></a><figcaption>Seitenbaum mit Ebenen</figcaption></figure><p><a href="/x"><img src="/y.png" alt=""></a></p>';
        const img = document.querySelector('figure img');
        expect(helpImage(img)).toEqual({
            src: '/_doku/redaktion/bilder/a.webp',
            alt: 'Der Seitenbaum',
            caption: 'Seitenbaum mit Ebenen',
        });
        // Ein Bild ausserhalb einer Abbildung der Hilfe bleibt ein gewöhnlicher Link
        expect(helpImage(document.querySelector('p img'))).toBeNull();
        expect(helpImage(null)).toBeNull();
    });

    it('begrenzt die Breite des Panels auf das Erlaubte', () => {
        expect(clampHelpWidth(600, 1440)).toBe(600);
        expect(clampHelpWidth(100, 1440)).toBe(320);
        expect(clampHelpWidth(1400, 1440)).toBe(1200);
        expect(clampHelpWidth(Number.NaN, 1440)).toBe(480);
        // Schmale Fenster: nie unter die kleinste Breite
        expect(clampHelpWidth(500, 400)).toBe(320);
    });
});
