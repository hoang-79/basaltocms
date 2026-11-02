import {
    ensureSyntaxTree,
    foldable,
    indentUnit,
    syntaxTree,
} from '@codemirror/language';
import { EditorState } from '@codemirror/state';
import type { Extension } from '@codemirror/state';
import { describe, expect, it } from 'vitest';
import {
    detectIndent,
    formatChanges,
    indentFor,
    indentLabel,
    tidyChanges,
} from './codeFormat';
import { kindOf, languageFor } from './codeLanguage';
import type { CodeKind } from './codeLanguage';
import { codeTools } from './codeToolbar';
import { lava } from './lavaLanguage';
import { shortcut } from './richtext/toolbar';

/** TB 97: Formatieren, Sprache je Datei, Hervorhebung von Lava und die Kürzel des grossen Code-Editors. */
async function formatted(text: string, path: string): Promise<string> {
    const extension: Extension = languageFor(path);
    const state = EditorState.create({
        doc: text,
        extensions: [extension, indentUnit.of(indentFor(kindOf(path), text))],
    });
    const result = formatChanges(state, kindOf(path));
    expect(result.error).toBe('');

    return result.changes === null
        ? text
        : state.update({ changes: result.changes }).state.doc.toString();
}

/** Die Knoten der Lava-Hervorhebung mit ihrem Text: [Art, Text]. */
function tokens(text: string): [string, string][] {
    const state = EditorState.create({ doc: text, extensions: [lava] });
    ensureSyntaxTree(state, state.doc.length, 1000);
    const found: [string, string][] = [];
    syntaxTree(state).iterate({
        enter: (node) => {
            if (node.from < node.to && node.name !== 'Document') {
                found.push([
                    node.name,
                    state.doc.sliceString(node.from, node.to),
                ]);
            }
        },
    });

    return found;
}

describe('Sprache je Datei', () => {
    it.each([
        ['theme.lava', 'lava'],
        ['site/types.lava', 'lava'],
        ['assets/theme.css', 'css'],
        ['partials/brotkrumen.lava.html', 'html'],
        ['assets/logo.svg', 'svg'],
        ['theme.json', 'json'],
        ['assets/app.js', 'js'],
        ['README.md', 'text'],
    ] as [string, CodeKind][])('%s → %s', (path, kind) => {
        expect(kindOf(path)).toBe(kind);
    });
});

describe('Hervorhebung von Lava', () => {
    it('nimmt «#» mit Hex-Ziffern als Farbe, sonst als Kommentar (wie der Lexer)', () => {
        const found = tokens(
            'color text: #2b2b2b # Graphit\n# #abc ist hier Kommentar',
        );
        expect(found).toContainEqual(['number', '#2b2b2b']);
        expect(found).toContainEqual(['comment', '# Graphit']);
        expect(found).toContainEqual(['comment', '# #abc ist hier Kommentar']);
    });
});

describe('Einklappen in Lava', () => {
    it('klappt einen Block von «{» bis vor die passende «}» ein, auch mit Kommentar hinter der Klammer', () => {
        const state = EditorState.create({
            doc: 'site x { # Kopf\n  name: "a"\n  nav {\n    item: b\n  }\n}\n',
            extensions: [languageFor('theme.lava')],
        });
        ensureSyntaxTree(state, state.doc.length, 1000);
        const first = state.doc.line(1);
        expect(foldable(state, first.from, first.to)).toEqual({
            from: first.from + 8,
            to: state.doc.line(6).from,
        });
        const inner = state.doc.line(3);
        expect(foldable(state, inner.from, inner.to)).toEqual({
            from: inner.to,
            to: state.doc.line(5).from + 2,
        });
        expect(
            foldable(state, state.doc.line(2).from, state.doc.line(2).to),
        ).toBeNull();
    });
});

describe('Markup in Lava', () => {
    it('beginnt bei ``` mitten in der Zeile und endet beim nächsten ``` (wie der Lexer)', () => {
        const found = tokens(
            'part kopf {\n  markup ```\n    <p>{{ site.name }}</p>\n  ```\n}',
        );
        expect(found.filter(([name]) => name === 'meta')).toHaveLength(2);
        expect(found).toContainEqual(['tagName', '<p>']);
        expect(found).toContainEqual(['variableName', ' site.name ']);
        expect(found).toContainEqual(['keyword', 'part']);
    });
});

describe('Formatieren', () => {
    it('rückt Lava nach Klammern ein und lässt Kommentare und Markup stehen', async () => {
        const source = [
            '# Kopf',
            'design {',
            'color text: #2b2b2b   ',
            '      # Farbe der Marke',
            'font body: "IBM Plex Sans" fallback sans',
            '}',
            'part kopf {',
            'markup ```',
            '<header>',
            '    <h1>{{ site.name }}</h1>',
            '</header>',
            '```',
            '}',
            '',
            '',
        ].join('\n');

        expect(await formatted(source, 'theme.lava')).toBe(
            [
                '# Kopf',
                'design {',
                '  color text: #2b2b2b',
                '  # Farbe der Marke',
                '  font body: "IBM Plex Sans" fallback sans',
                '}',
                'part kopf {',
                '  markup ```',
                '<header>',
                '    <h1>{{ site.name }}</h1>',
                '</header>',
                '```',
                '}',
                '',
            ].join('\n'),
        );
    });

    it('liest die Einrückung aus der Datei ab', () => {
        expect(detectIndent('a {\n  b: c;\n}\n')).toBe('  ');
        expect(
            detectIndent('a {\n    b: c;\n    d {\n        e: f;\n    }\n}\n'),
        ).toBe('    ');
        expect(detectIndent('a {\n\tb: c;\n\td: e;\n}\n')).toBe('\t');
        expect(detectIndent('/*\n * Kommentar\n */\n')).toBe('  ');
        expect(detectIndent('')).toBe('  ');
        expect(indentLabel('    ')).toBe('4 Leerzeichen');
        // Lava: immer zwei, auch wenn eingerücktes Markup vier nahelegt
        expect(
            indentFor(
                'lava',
                'part a {\n  markup ```\n<p>\n    <b>x</b>\n</p>\n```\n}',
            ),
        ).toBe('  ');
        expect(indentFor('css', 'a {\n    b: c;\n}')).toBe('    ');
        expect(indentLabel('\t')).toBe('Tabulator');
    });

    it('behält beim Formatieren die Einrückung der Datei (CSS mit vier Leerzeichen)', async () => {
        expect(
            await formatted(
                'a {\n    color: red;\n}\nb {\ncolor: blue;\n}\n',
                'assets/theme.css',
            ),
        ).toBe('a {\n    color: red;\n}\nb {\n    color: blue;\n}\n');
    });

    it('lässt das Innere von Block-Kommentaren stehen', async () => {
        expect(
            await formatted(
                '/*\n * Kanzlei\n *\n */\na {\ncolor: red;\n}\n',
                'assets/theme.css',
            ),
        ).toBe('/*\n * Kanzlei\n *\n */\na {\n  color: red;\n}\n');
    });

    it('räumt HTML-Teile nur auf: Lava-Vorlagen sind kein reines HTML', async () => {
        const source =
            '{% set t = {\n  skip: { de: "Zum Inhalt" },   \n  menu: { de: "Menü" }\n} %}\n<p>\n<b>x</b>\n</p>';
        expect(await formatted(source, 'partials/t.lava.html')).toBe(
            '{% set t = {\n  skip: { de: "Zum Inhalt" },\n  menu: { de: "Menü" }\n} %}\n<p>\n<b>x</b>\n</p>\n',
        );
    });

    it('schreibt JSON mit der Einrückung der Datei', async () => {
        expect(
            await formatted(
                '{\n    "label": "Agora",\n    "tags": ["a"]\n}',
                'theme.json',
            ),
        ).toBe(
            '{\n    "label": "Agora",\n    "tags": [\n        "a"\n    ]\n}\n',
        );
    });

    it('rückt CSS nach dem Parser ein', async () => {
        expect(
            await formatted('a {\ncolor: red;\n }\n', 'assets/theme.css'),
        ).toBe('a {\n  color: red;\n}\n');
    });

    it('schreibt JSON vollständig neu und meldet ungültiges JSON', async () => {
        expect(
            await formatted('{"name":"Feder","tags":["a","b"]}', 'theme.json'),
        ).toBe(
            '{\n  "name": "Feder",\n  "tags": [\n    "a",\n    "b"\n  ]\n}\n',
        );
        const state = EditorState.create({ doc: '{"name": }' });
        const result = formatChanges(state, 'json');
        expect(result.changes).toBeNull();
        expect(result.error).toContain('JSON lässt sich nicht formatieren');
    });

    it('meldet «schon formatiert» als keine Änderung', async () => {
        const state = EditorState.create({
            doc: 'design {\n  color text: #fff\n}\n',
            extensions: [lava],
        });
        expect(formatChanges(state, 'lava').changes).toBeNull();
    });

    it('lässt Markdown und Text in Ruhe', () => {
        const state = EditorState.create({ doc: 'Zeile mit Umbruch  \n' });
        expect(formatChanges(state, 'text').changes).toBeNull();
    });

    it('entfernt Leerzeichen am Zeilenende und setzt genau einen Zeilenumbruch ans Ende', () => {
        const state = EditorState.create({ doc: 'a  \nb\t\n\n\n' });
        const tidy = tidyChanges(state.doc);
        expect(state.update({ changes: tidy }).state.doc.toString()).toBe(
            'a\nb\n',
        );
        const missing = EditorState.create({ doc: 'a' });
        expect(
            missing
                .update({ changes: tidyChanges(missing.doc) })
                .state.doc.toString(),
        ).toBe('a\n');
    });
});

describe('Werkzeugleiste und Kürzel', () => {
    it('zeigt Kürzel je System', () => {
        expect(shortcut('Mod+Z', true)).toBe('⌘Z');
        expect(shortcut('Mod+Z', false)).toBe('Strg+Z');
        expect(shortcut('⇧+Mod+Z', true)).toBe('⇧⌘Z');
        expect(shortcut('⇧+Mod+Z', false)).toBe('⇧+Strg+Z');
        expect(shortcut('Ctrl+Alt+[', true)).toBe('⌃⌥[');
        expect(shortcut('Ctrl+Alt+[', false)).toBe('Strg+Alt+[');
    });

    it('hat jedes Werkzeug einmal, mit Namen und Symbol', () => {
        const tools = codeTools().flat();
        expect(new Set(tools.map((tool) => tool.key)).size).toBe(tools.length);
        expect(tools.map((tool) => tool.key)).toEqual(
            expect.arrayContaining([
                'undo',
                'search',
                'gotoLine',
                'format',
                'comment',
                'foldAll',
                'wrap',
                'check',
                'keys',
            ]),
        );
        for (const tool of tools) {
            expect(tool.label).not.toBe('');
            expect(tool.icon).not.toBe('');
        }
    });
});
