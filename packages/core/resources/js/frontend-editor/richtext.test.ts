import { describe, expect, it } from 'vitest';
import { domToDoc, plainText } from './richtext';

function el(html: string): HTMLElement {
    const div = document.createElement('div');
    div.innerHTML = html;

    return div;
}

describe('Frontend-Editor: Fliesstext von der Seite', () => {
    it('liest die Ausgabe des Kerns zurück — Absätze, Marken, Link, Listen, Ausrichtung', () => {
        const doc = domToDoc(
            el(
                '<p class="align-center">Wir <strong>bauen</strong> <em>Laravel</em> mit <a href="https://basaltocms.com" rel="noopener noreferrer" target="_blank">Basalto</a>.</p>' +
                    '<h3>Titel</h3><ol start="3"><li><p>Drei</p></li></ol><ul><li>lose</li></ul><blockquote><p>Zitat</p></blockquote><hr>',
            ),
        );
        expect(doc).toEqual({
            type: 'doc',
            content: [
                {
                    type: 'paragraph',
                    attrs: { textAlign: 'center' },
                    content: [
                        { type: 'text', text: 'Wir ' },
                        {
                            type: 'text',
                            text: 'bauen',
                            marks: [{ type: 'bold' }],
                        },
                        { type: 'text', text: ' ' },
                        {
                            type: 'text',
                            text: 'Laravel',
                            marks: [{ type: 'italic' }],
                        },
                        { type: 'text', text: ' mit ' },
                        {
                            type: 'text',
                            text: 'Basalto',
                            marks: [
                                {
                                    type: 'link',
                                    attrs: {
                                        href: 'https://basaltocms.com',
                                        target: '_blank',
                                    },
                                },
                            ],
                        },
                        { type: 'text', text: '.' },
                    ],
                },
                {
                    type: 'heading',
                    attrs: { level: 3 },
                    content: [{ type: 'text', text: 'Titel' }],
                },
                {
                    type: 'orderedList',
                    attrs: { start: 3 },
                    content: [
                        {
                            type: 'listItem',
                            content: [
                                {
                                    type: 'paragraph',
                                    content: [{ type: 'text', text: 'Drei' }],
                                },
                            ],
                        },
                    ],
                },
                {
                    type: 'bulletList',
                    content: [
                        {
                            type: 'listItem',
                            content: [
                                {
                                    type: 'paragraph',
                                    content: [{ type: 'text', text: 'lose' }],
                                },
                            ],
                        },
                    ],
                },
                {
                    type: 'blockquote',
                    content: [
                        {
                            type: 'paragraph',
                            content: [{ type: 'text', text: 'Zitat' }],
                        },
                    ],
                },
                { type: 'horizontalRule' },
            ],
        });
    });

    it('nimmt, was contenteditable erzeugt: <b>, <div>-Zeilen, Umbrüche, Platzhalter-<br> und geschützte Leerzeichen', () => {
        expect(
            domToDoc(
                el(
                    '<p>Eins<br>zwei<br></p><div><b>fett</b>&nbsp;danach</div><div><br></div>lose',
                ),
            ),
        ).toEqual({
            type: 'doc',
            content: [
                {
                    type: 'paragraph',
                    content: [
                        { type: 'text', text: 'Eins' },
                        { type: 'hardBreak' },
                        { type: 'text', text: 'zwei' },
                    ],
                },
                {
                    type: 'paragraph',
                    content: [
                        {
                            type: 'text',
                            text: 'fett',
                            marks: [{ type: 'bold' }],
                        },
                        { type: 'text', text: ' danach' },
                    ],
                },
                {
                    type: 'paragraph',
                    content: [{ type: 'text', text: 'lose' }],
                },
            ],
        });
    });

    it('lässt Fremdes weg und behält den Text', () => {
        expect(
            domToDoc(
                el(
                    '<p><span style="color:red">rot</span><img src="x.png"><script>alert(1)</script><font>alt</font></p><table><tr><td>Zelle</td></tr></table>',
                ),
            ),
        ).toEqual({
            type: 'doc',
            content: [
                {
                    type: 'paragraph',
                    content: [{ type: 'text', text: 'rotalt' }],
                },
                {
                    type: 'paragraph',
                    content: [{ type: 'text', text: 'Zelle' }],
                },
            ],
        });
    });
});

describe('Frontend-Editor: Klartext', () => {
    it('macht Text einzeilig und lässt Zeilen im Textfeld stehen', () => {
        const node = el('Erste  Zeile<br>zweite\nZeile<div>dritte</div>');
        expect(plainText(node, false)).toBe('Erste Zeile zweite Zeile dritte');
        expect(plainText(node, true)).toBe(
            'Erste  Zeile\nzweite\nZeile\ndritte',
        );
        expect(plainText(el('  A&nbsp;&amp;&nbsp;B  '), false)).toBe('A & B');
    });
});
