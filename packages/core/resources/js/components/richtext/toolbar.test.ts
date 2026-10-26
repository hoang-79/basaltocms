import { Editor, type JSONContent } from '@tiptap/core';
import { afterEach, beforeEach, describe, expect, test } from 'vitest';
import { richTextExtensions } from './extensions';

/** Je Werkzeug der Toolbar ein Test (M-04): die Editor-Instanz mit denselben Erweiterungen, geprüft wird das JSON. */
let editor: Editor;

/** Das Dokument als untypisiertes JSON — die Tests greifen frei auf Inhalt und Attribute zu. */
const json = (): JSONContent => editor.getJSON() as JSONContent;

beforeEach(() => {
    editor = new Editor({
        extensions: richTextExtensions(),
        content: '<p>Hallo Welt</p>',
    });
});

afterEach(() => editor.destroy());

const first = () => json().content?.[0];

/** Tippt Zeichen für Zeichen wie eine Tastatur — so greifen die Input-Rules (Markdown-Kürzel). */
function typeText(e: Editor, text: string): void {
    for (const char of text) {
        const { view } = e;
        const { from, to } = view.state.selection;
        const handled = view.someProp('handleTextInput', (f) =>
            f(view, from, to, char, () =>
                view.state.tr.insertText(char, from, to),
            ),
        );
        if (!handled) {
            view.dispatch(view.state.tr.insertText(char, from, to));
        }
    }
}
const firstMarks = () => first()?.content?.[0]?.marks?.map((m) => m.type) ?? [];

describe('Marken', () => {
    test.each([
        ['bold', (e: Editor) => e.chain().selectAll().toggleBold().run()],
        ['italic', (e: Editor) => e.chain().selectAll().toggleItalic().run()],
        [
            'underline',
            (e: Editor) => e.chain().selectAll().toggleUnderline().run(),
        ],
        ['strike', (e: Editor) => e.chain().selectAll().toggleStrike().run()],
        ['code', (e: Editor) => e.chain().selectAll().toggleCode().run()],
        [
            'superscript',
            (e: Editor) => e.chain().selectAll().toggleSuperscript().run(),
        ],
        [
            'subscript',
            (e: Editor) => e.chain().selectAll().toggleSubscript().run(),
        ],
    ])('%s', (mark, run) => {
        run(editor);
        expect(firstMarks()).toEqual([mark]);
    });

    test('Link mit Ziel und Titel, dann entfernen', () => {
        editor
            .chain()
            .selectAll()
            .setLink({
                href: 'https://basaltocms.com',
                target: '_blank',
                title: 'Basalto',
            })
            .run();
        const link = first()?.content?.[0]?.marks?.[0];
        expect(link?.type).toBe('link');
        expect(link?.attrs).toMatchObject({
            href: 'https://basaltocms.com',
            target: '_blank',
            title: 'Basalto',
        });
        editor.chain().selectAll().unsetLink().run();
        expect(firstMarks()).toEqual([]);
    });

    test('Format entfernen löscht Marken und Blockformat', () => {
        editor
            .chain()
            .selectAll()
            .toggleBold()
            .toggleHeading({ level: 3 })
            .run();
        editor.chain().selectAll().unsetAllMarks().clearNodes().run();
        expect(first()?.type).toBe('paragraph');
        expect(firstMarks()).toEqual([]);
    });
});

describe('Absatzformat', () => {
    test.each([2, 3, 4] as const)('Überschrift %d', (level) => {
        editor.chain().selectAll().setHeading({ level }).run();
        expect(first()).toMatchObject({ type: 'heading', attrs: { level } });
        editor.chain().selectAll().setParagraph().run();
        expect(first()?.type).toBe('paragraph');
    });

    test('Überschrift 1 gibt es nicht', () => {
        editor
            .chain()
            .selectAll()
            .setHeading({ level: 1 as unknown as 2 })
            .run();
        expect(first()?.type).toBe('paragraph');
    });

    test.each(['left', 'center', 'right', 'justify'])(
        'Textausrichtung %s',
        (align) => {
            editor.chain().selectAll().setTextAlign(align).run();
            expect(first()?.attrs?.textAlign).toBe(align);
        },
    );
});

describe('Listen', () => {
    test('Aufzählung', () => {
        editor.chain().selectAll().toggleBulletList().run();
        expect(first()?.type).toBe('bulletList');
        expect(first()?.content?.[0]?.type).toBe('listItem');
    });

    test('Nummerierung', () => {
        editor.chain().selectAll().toggleOrderedList().run();
        expect(first()).toMatchObject({
            type: 'orderedList',
            attrs: { start: 1 },
        });
    });

    test('Aufgabenliste mit Haken', () => {
        editor.chain().selectAll().toggleTaskList().run();
        expect(first()?.type).toBe('taskList');
        expect(first()?.content?.[0]).toMatchObject({
            type: 'taskItem',
            attrs: { checked: false },
        });
        editor
            .chain()
            .selectAll()
            .updateAttributes('taskItem', { checked: true })
            .run();
        expect(first()?.content?.[0]?.attrs?.checked).toBe(true);
    });

    test('Einzug rein und raus', () => {
        editor.commands.setContent(
            '<ul><li><p>eins</p></li><li><p>zwei</p></li></ul>',
        );
        let pos = 0;
        editor.state.doc.descendants((node, p) => {
            if (node.isText && node.text === 'zwei') {
                pos = p + 1;
            }

            return true;
        });
        editor.commands.setTextSelection(pos);
        expect(editor.commands.sinkListItem('listItem')).toBe(true);
        expect(first()?.content).toHaveLength(1);
        expect(first()?.content?.[0]?.content?.[1]?.type).toBe('bulletList');
        expect(editor.commands.liftListItem('listItem')).toBe(true);
        expect(first()?.content).toHaveLength(2);
    });
});

describe('Blöcke', () => {
    test('Zitat', () => {
        editor.chain().selectAll().toggleBlockquote().run();
        expect(first()?.type).toBe('blockquote');
    });

    test('Codeblock mit Sprache', () => {
        editor.chain().selectAll().setCodeBlock({ language: 'php' }).run();
        expect(first()).toMatchObject({
            type: 'codeBlock',
            attrs: { language: 'php' },
        });
        editor
            .chain()
            .updateAttributes('codeBlock', { language: 'lava' })
            .run();
        expect(first()?.attrs?.language).toBe('lava');
    });

    test('Trennlinie', () => {
        editor.chain().focus('end').setHorizontalRule().run();
        expect(json().content?.map((n) => n.type)).toContain('horizontalRule');
    });

    test('Sonderzeichen einfügen', () => {
        editor.chain().focus('end').insertContent('«»').run();
        expect(editor.getText()).toBe('Hallo Welt«»');
    });

    test('Rückgängig und Wiederholen', () => {
        editor.chain().selectAll().toggleBold().run();
        expect(firstMarks()).toEqual(['bold']);
        editor.commands.undo();
        expect(firstMarks()).toEqual([]);
        editor.commands.redo();
        expect(firstMarks()).toEqual(['bold']);
    });
});

describe('Tabelle', () => {
    test('einfügen 3×3 mit Kopfzeile, Zeile und Spalte hinzufügen, zusammenführen, Kopfzeile umschalten, löschen', () => {
        editor
            .chain()
            .focus('end')
            .insertTable({ rows: 3, cols: 3, withHeaderRow: true })
            .run();
        const table = () => json().content?.find((n) => n.type === 'table');
        expect(table()?.content).toHaveLength(3);
        expect(table()?.content?.[0]?.content?.[0]?.type).toBe('tableHeader');
        expect(table()?.content?.[1]?.content?.[0]?.type).toBe('tableCell');

        editor.commands.addRowAfter();
        expect(table()?.content).toHaveLength(4);
        editor.commands.addColumnBefore();
        expect(table()?.content?.[1]?.content).toHaveLength(4);
        editor.commands.deleteColumn();
        expect(table()?.content?.[1]?.content).toHaveLength(3);
        // der Cursor steht in der Kopfzeile: Zeile löschen entfernt sie
        editor.commands.deleteRow();
        expect(table()?.content).toHaveLength(3);
        expect(table()?.content?.[0]?.content?.[0]?.type).toBe('tableCell');

        editor.commands.toggleHeaderRow();
        expect(table()?.content?.[0]?.content?.[0]?.type).toBe('tableHeader');
        editor.commands.toggleHeaderRow();
        expect(table()?.content?.[0]?.content?.[0]?.type).toBe('tableCell');

        editor.commands.deleteTable();
        expect(table()).toBeUndefined();
    });

    test('Zellen zusammenführen und teilen', () => {
        editor.commands.setContent(
            '<table><tbody><tr><td><p>a</p></td><td><p>b</p></td></tr><tr><td><p>c</p></td><td><p>d</p></td></tr></tbody></table>',
        );
        // Zellenauswahl über die ersten beiden Zellen der ersten Zeile
        const doc = editor.state.doc;
        let firstCell = -1;
        let secondCell = -1;
        doc.descendants((node, pos) => {
            if (node.type.name === 'tableCell') {
                if (firstCell === -1) {
                    firstCell = pos;
                } else if (secondCell === -1) {
                    secondCell = pos;
                }
            }

            return secondCell === -1;
        });
        editor.commands.setCellSelection({
            anchorCell: firstCell,
            headCell: secondCell,
        });
        expect(editor.commands.mergeCells()).toBe(true);
        const row = () => json().content?.[0]?.content?.[0];
        expect(row()?.content).toHaveLength(1);
        expect(row()?.content?.[0]?.attrs?.colspan).toBe(2);
        expect(editor.commands.splitCell()).toBe(true);
        expect(row()?.content).toHaveLength(2);
    });
});

describe('Medien', () => {
    test('Bild aus der Mediathek mit Beschriftung und Ausrichtung', () => {
        editor
            .chain()
            .focus('end')
            .setImage({ src: '/media/1/content.jpg', alt: 'Cockpit' })
            .updateAttributes('image', {
                mediaId: 1,
                caption: 'Das Cockpit',
                align: 'right',
            })
            .run();
        const image = json().content?.find((n) => n.type === 'image');
        expect(image?.attrs).toMatchObject({
            src: '/media/1/content.jpg',
            alt: 'Cockpit',
            mediaId: 1,
            caption: 'Das Cockpit',
            align: 'right',
        });
        expect(editor.getHTML()).toContain(
            '<figcaption>Das Cockpit</figcaption>',
        );
    });

    test('Einbettung YouTube, Vimeo und Karte', () => {
        editor
            .chain()
            .focus('end')
            .setYoutubeVideo({
                src: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            })
            .run();
        editor
            .chain()
            .focus('end')
            .setEmbed({ provider: 'vimeo', src: 'https://vimeo.com/123' })
            .run();
        editor
            .chain()
            .focus('end')
            .setEmbed({
                provider: 'map',
                src: 'https://www.google.com/maps/place/Zuerich',
            })
            .run();
        const types = json().content?.map((n) => [
            n.type,
            n.attrs?.provider ?? null,
        ]);
        expect(types).toContainEqual(['youtube', null]);
        expect(types).toContainEqual(['embed', 'vimeo']);
        expect(types).toContainEqual(['embed', 'map']);
        expect(editor.getHTML()).toContain('wird erst nach Zustimmung geladen');
        expect(editor.getHTML()).not.toContain('<iframe');
    });
});

describe('Suchen und Ersetzen', () => {
    test('findet, springt weiter, ersetzt einen und alle', () => {
        editor.commands.setContent(
            '<p>Lava fliesst. <strong>Lava</strong> kühlt.</p><p>lava bleibt.</p>',
        );
        editor.commands.setSearchTerm('lava');
        const storage = editor.storage.searchReplace;
        expect(storage.hits).toHaveLength(3);
        expect(storage.index).toBe(0);
        editor.commands.nextMatch();
        expect(storage.index).toBe(1);
        expect(
            editor.state.doc.textBetween(
                editor.state.selection.from,
                editor.state.selection.to,
            ),
        ).toBe('Lava');
        editor.commands.replaceMatch('Basalt');
        expect(editor.getText()).toContain('Basalt kühlt');
        expect(storage.hits).toHaveLength(2);
        editor.commands.replaceAllMatches('Stein');
        expect(editor.getText()).toBe(
            'Stein fliesst. Basalt kühlt.\n\nStein bleibt.',
        );
        expect(storage.hits).toHaveLength(0);
        editor.commands.clearSearch();
        expect(storage.term).toBe('');
    });
});

describe('Zähler und Quelltext', () => {
    test('Zeichen und Wörter', () => {
        expect(editor.storage.characterCount.characters()).toBe(10);
        expect(editor.storage.characterCount.words()).toBe(2);
    });

    test('JSON und HTML lesen', () => {
        expect(editor.getHTML()).toBe('<p>Hallo Welt</p>');
        expect(JSON.stringify(json())).toContain('"type":"paragraph"');
    });
});

describe('Markdown-Kürzel (Input-Rules)', () => {
    test.each([
        ['## ', 'heading', { level: 2 }],
        ['### ', 'heading', { level: 3 }],
        ['- ', 'bulletList', null],
        ['1. ', 'orderedList', null],
        ['> ', 'blockquote', null],
        ['[ ] ', 'taskList', null],
    ] as const)('«%s» → %s', (shortcut, type, attrs) => {
        editor.commands.setContent('<p></p>');
        editor.commands.focus('start');
        typeText(editor, shortcut);
        const found = first();
        expect(
            found?.type === type ||
                json().content?.some((n) => n.type === type),
        ).toBe(true);
        if (attrs) {
            expect(found?.attrs).toMatchObject(attrs);
        }
    });
});
