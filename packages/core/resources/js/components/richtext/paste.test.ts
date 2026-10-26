import { Editor, type JSONContent } from '@tiptap/core';
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { richTextExtensions } from './extensions';
import { inlineMarkdown, isMarkdown, markdownToHtml } from './markdown';
import {
    cleanPastedHtml,
    replaceFileImages,
    rtfImages,
    uploadForeignImages,
    type Uploader,
} from './paste';

let editor: Editor;

/** Das Dokument als untypisiertes JSON — die Tests greifen frei auf Inhalt und Attribute zu. */
const json = (): JSONContent => editor.getJSON() as JSONContent;

beforeEach(() => {
    editor = new Editor({
        extensions: richTextExtensions(),
        content: '<p></p>',
    });
});

afterEach(() => editor.destroy());

/** Fügt HTML wie die Zwischenablage ein: aufräumen, dann über das Schema einlesen. */
function paste(html: string): void {
    editor.commands.setContent(cleanPastedHtml(html));
}

/** Die Blocktypen ohne den leeren Absatz, den der Editor hinter einem letzten Atom (Bild) anhängt. */
const types = () => {
    const nodes = json().content ?? [];
    const last = nodes[nodes.length - 1];
    if (nodes.length > 1 && last?.type === 'paragraph' && !last.content) {
        nodes.pop();
    }

    return nodes.map((n) => n.type);
};

const tinyPng =
    'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

describe('Word', () => {
    const word = `
<html xmlns:o="urn:schemas-microsoft-com:office:office"><head><style>p.MsoNormal{margin:0}</style></head><body>
<p class=MsoNormal><b><span style='font-family:"Calibri",sans-serif;color:#1F4E79'>Fett</span></b> und <i>kursiv</i><o:p></o:p></p>
<h1>Titel</h1>
<p class=MsoListParagraphCxSpFirst style='text-indent:-18.0pt;mso-list:l0 level1 lfo1'><![if !supportLists]><span style='mso-list:Ignore'>·<span style='font:7.0pt "Times New Roman"'>&nbsp;&nbsp;&nbsp;&nbsp; </span></span><![endif]>Erster Punkt</p>
<p class=MsoListParagraphCxSpMiddle style='text-indent:-18.0pt;mso-list:l0 level1 lfo1'><span style='mso-list:Ignore'>·<span style='font:7.0pt "Times New Roman"'>&nbsp; </span></span>Zweiter Punkt</p>
<p class=MsoListParagraphCxSpLast style='margin-left:72.0pt;mso-list:l0 level2 lfo1'><span style='mso-list:Ignore'>o<span style='font:7.0pt "Times New Roman"'>&nbsp; </span></span>Unterpunkt</p>
<p class=MsoNormal>&nbsp;</p>
<p class=MsoListParagraph style='mso-list:l1 level1 lfo2'><span style='mso-list:Ignore'>1.<span style='font:7.0pt "Times New Roman"'>&nbsp; </span></span>Nummer eins</p>
<table class=MsoTableGrid border=1 cellspacing=0 cellpadding=0 style='border-collapse:collapse'>
 <tbody><tr><td width=200 valign=top style='width:150.0pt;border:solid windowtext 1.0pt'><p class=MsoNormal><b>Kopf</b></p></td><td><p class=MsoNormal>Wert</p></td></tr>
 <tr><td><p class=MsoNormal>a</p></td><td><p class=MsoNormal>b</p></td></tr></tbody>
</table>
<p class=MsoNormal><img width=100 height=50 src="${tinyPng}" alt="Diagramm"></p>
<p class=MsoNormal><a href="https://basaltocms.com" title="Basalto">Link</a></p>
</body></html>`;

    test('Fett, Kursiv, Überschrift, Listen (verschachtelt und nummeriert), Tabelle, Bild, Link kommen an', () => {
        paste(word);
        const doc = json();
        expect(types()).toEqual([
            'paragraph',
            'heading',
            'bulletList',
            'orderedList',
            'table',
            'image',
            'paragraph',
        ]);
        expect(doc.content?.[0]?.content?.[0]?.marks?.[0]?.type).toBe('bold');
        expect(doc.content?.[0]?.content?.[2]?.marks?.[0]?.type).toBe('italic');
        expect(doc.content?.[1]?.attrs?.level).toBe(2);
        const list = doc.content?.[2];
        expect(list?.content).toHaveLength(2);
        expect(list?.content?.[1]?.content?.[1]?.type).toBe('bulletList');
        expect(editor.getText()).toContain('Unterpunkt');
        expect(editor.getText()).not.toContain('·');
        const table = doc.content?.[4];
        expect(table?.content).toHaveLength(2);
        expect(
            table?.content?.[0]?.content?.[0]?.content?.[0]?.content?.[0]
                ?.marks?.[0]?.type,
        ).toBe('bold');
        expect(doc.content?.[5]?.attrs).toMatchObject({
            src: tinyPng,
            alt: 'Diagramm',
            mediaId: null,
        });
        const link = doc.content?.[6]?.content?.[0]?.marks?.[0];
        expect(link?.attrs).toMatchObject({
            href: 'https://basaltocms.com',
            title: 'Basalto',
        });
        expect(editor.getHTML()).not.toContain('Calibri');
        expect(editor.getHTML()).not.toContain('MsoNormal');
    });

    test('Bilder aus dem RTF-Teil ersetzen file:///-Quellen', () => {
        const rtf =
            '{\\rtf1\\ansi{\\*\\shppict{\\pict\\pngblip\\picw1\\pich1 89504e470d0a1a0a}}{\\nonshppict{\\pict\\wmetafile8 0102}}}';
        const images = rtfImages(rtf);
        expect(images).toHaveLength(1);
        expect(images[0]).toBe('data:image/png;base64,iVBORw0KGgo=');
        const html = replaceFileImages(
            '<p><img src="file:///C:/Users/x/clip_image001.png"></p><p><img src="file:///y.png"></p>',
            images,
        );
        expect(html).toContain('src="data:image/png;base64,iVBORw0KGgo="');
        expect(html).toContain('src="file:///y.png"');
    });

    test('file:///-Bilder ohne Ersatz fallen weg statt kaputt stehen zu bleiben', () => {
        const html = cleanPastedHtml(
            '<p>Text</p><p><img src="file:///Users/x/bild.png"></p><p><img src="https://example.org/a.png"></p>',
        );
        expect(html).not.toContain('file:');
        expect(html).toContain('https://example.org/a.png');
        expect(html).toContain('Text');
    });
});

describe('Google Docs', () => {
    test('font-weight:700 wird fett, italic kursiv, Unterstreichung bleibt, Schrift und Farbe fallen', () => {
        paste(
            '<meta charset="utf-8"><b style="font-weight:normal;" id="docs-internal-guid-1"><p dir="ltr" style="line-height:1.38;margin-top:0pt;"><span style="font-size:11pt;font-family:Arial;color:#000000;font-weight:700;">Fett</span><span style="font-weight:400;"> normal </span><span style="font-style:italic;">kursiv</span> <span style="text-decoration:underline;">unter</span> <span style="text-decoration:line-through;">weg</span> <span style="vertical-align:super;">2</span></p><h1 style="text-align:center">Gross</h1></b>',
        );
        const doc = json();
        const p = doc.content?.[0]?.content ?? [];
        expect(p[0]?.marks?.map((m) => m.type)).toEqual(['bold']);
        expect(p[1]?.marks).toBeUndefined();
        expect(p[2]?.marks?.map((m) => m.type)).toEqual(['italic']);
        expect(p[4]?.marks?.map((m) => m.type)).toEqual(['underline']);
        expect(p[6]?.marks?.map((m) => m.type)).toEqual(['strike']);
        expect(p[8]?.marks?.map((m) => m.type)).toEqual(['superscript']);
        expect(doc.content?.[1]).toMatchObject({
            type: 'heading',
            attrs: { level: 2, textAlign: 'center' },
        });
        expect(editor.getHTML()).not.toContain('Arial');
    });
});

describe('Web', () => {
    test('Tabelle mit tbody, Codeblock, Zitat, h5 → h4, span mit Farbe → nur Text', () => {
        paste(
            '<h5>Klein</h5><blockquote><p>Zitat</p></blockquote><pre><code class="language-php">echo 1;</code></pre><table><tbody><tr><th>K</th><td>W</td></tr></tbody></table><p><span style="color:red;font-family:Comic Sans">rot</span></p>',
        );
        expect(types()).toEqual([
            'heading',
            'blockquote',
            'codeBlock',
            'table',
            'paragraph',
        ]);
        expect(json().content?.[0]?.attrs?.level).toBe(4);
        expect(json().content?.[2]?.attrs?.language).toBe('php');
        expect(json().content?.[3]?.content?.[0]?.content?.[0]?.type).toBe(
            'tableHeader',
        );
        expect(json().content?.[4]?.content?.[0]).toEqual({
            type: 'text',
            text: 'rot',
        });
    });
});

describe('Markdown', () => {
    test('erkennt Markdown am Zeilenanfang oder an Fett', () => {
        expect(isMarkdown('# Titel')).toBe(true);
        expect(isMarkdown('- eins\n- zwei')).toBe(true);
        expect(isMarkdown('Ein **fetter** Satz')).toBe(true);
        expect(isMarkdown('Nur Prosa ohne Zeichen.')).toBe(false);
    });

    test('Überschriften, Listen, Aufgaben, Zitat, Codeblock, Trennlinie, Inline', () => {
        const md = [
            '# Titel',
            '',
            'Ein **fetter** und *kursiver* Satz mit `code`, [Link](https://basaltocms.com) und ~~weg~~.',
            '',
            '- eins',
            '- zwei',
            '  - zwei-a',
            '',
            '1. erster',
            '2. zweiter',
            '',
            '- [x] erledigt',
            '- [ ] offen',
            '',
            '> Zitat',
            '',
            '```php',
            'echo 1;',
            '```',
            '',
            '---',
            '',
            '![Bild](https://example.org/b.png)',
        ].join('\n');
        editor.commands.setContent(markdownToHtml(md));
        expect(types()).toEqual([
            'heading',
            'paragraph',
            'bulletList',
            'orderedList',
            'taskList',
            'blockquote',
            'codeBlock',
            'horizontalRule',
            'image',
        ]);
        const doc = json();
        expect(doc.content?.[0]?.attrs?.level).toBe(2);
        const marks = doc.content?.[1]?.content?.map(
            (n) => n.marks?.[0]?.type ?? null,
        );
        expect(marks).toContain('bold');
        expect(marks).toContain('italic');
        expect(marks).toContain('code');
        expect(marks).toContain('link');
        expect(marks).toContain('strike');
        expect(doc.content?.[2]?.content?.[1]?.content?.[1]?.type).toBe(
            'bulletList',
        );
        expect(doc.content?.[3]?.content).toHaveLength(2);
        expect(doc.content?.[4]?.content?.[0]?.attrs?.checked).toBe(true);
        expect(doc.content?.[4]?.content?.[1]?.attrs?.checked).toBe(false);
        expect(doc.content?.[6]?.attrs?.language).toBe('php');
        expect(doc.content?.[8]?.attrs?.alt).toBe('Bild');
    });

    test('Inline-Code bleibt roh', () => {
        expect(inlineMarkdown('a `**b**` c')).toBe('a <code>**b**</code> c');
        expect(inlineMarkdown('<script>')).toBe('&lt;script&gt;');
    });
});

describe('Bild-Upload nach dem Einfügen', () => {
    test('Data-URL und Fremdadresse landen in der Mediathek, eigene Adressen bleiben', async () => {
        editor.commands.setContent(
            `<p>vor</p><img src="${tinyPng}" alt="Eins"><img src="https://example.org/zwei.jpg"><img src="http://localhost:3000/media/5/content.jpg" data-media-id="5">`,
        );
        const upload = vi.fn<Uploader>(async (payload) => ({
            id: 'data' in payload && payload.data.startsWith('data:') ? 11 : 12,
            url: '/media/x/content.jpg',
            alt: null,
            width: 1,
            height: 1,
        }));
        const done = await uploadForeignImages(
            editor,
            upload,
            'http://localhost:3000',
        );
        expect(done).toBe(2);
        expect(upload).toHaveBeenCalledTimes(2);
        expect(upload.mock.calls[1]?.[0]).toEqual({
            data: 'https://example.org/zwei.jpg',
            name: 'zwei.jpg',
        });
        const images = json().content?.filter((n) => n.type === 'image') ?? [];
        expect(images.map((i) => i.attrs?.mediaId)).toEqual([11, 12, 5]);
        expect(images[0]?.attrs?.alt).toBe('Eins');
        expect(images[0]?.attrs?.src).toBe('/media/x/content.jpg');
    });

    test('scheitert der Upload, bleibt ein fremdes Bild als Link, eine Data-URL fällt weg und der Grund wird gemeldet', async () => {
        editor.commands.setContent(
            `<img src="${tinyPng}"><img src="https://example.org/zwei.jpg" alt="Zwei">`,
        );
        const upload = vi.fn<Uploader>(async () => {
            throw new Error('Bilder aus internen Netzen werden nicht geholt.');
        });
        await expect(
            uploadForeignImages(editor, upload, 'http://localhost:3000'),
        ).rejects.toThrow('Bilder aus internen Netzen werden nicht geholt.');
        expect(types()).not.toContain('image');
        const link = json().content?.[0]?.content?.[0];
        expect(link?.text).toBe('Zwei');
        expect(link?.marks?.[0]?.attrs?.href).toBe(
            'https://example.org/zwei.jpg',
        );
    });
});
