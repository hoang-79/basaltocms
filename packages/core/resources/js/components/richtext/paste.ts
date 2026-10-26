import { requestError } from '../../http';
import { t } from '../../i18n';
import type { Editor } from '@tiptap/core';
import {
    DOMParser as PmDOMParser,
    type Node as PmNode,
} from '@tiptap/pm/model';
import type { EditorView } from '@tiptap/pm/view';
import { isMarkdown, markdownToHtml } from './markdown';

/** Die Antwort des Einfügen-Uploads (`POST media/paste`). */
export type UploadedMedia = {
    id: number;
    url: string;
    alt: string | null;
    width: number | null;
    height: number | null;
};

/** Lädt ein Bild hoch — als Datei oder als Data-/Fremd-URL mit Name. */
export type Uploader = (
    payload: { file: File } | { data: string; name: string },
) => Promise<UploadedMedia>;

/* ── HTML aus Word, Google Docs und dem Web ── */

const keptAttributes: Record<string, string[]> = {
    a: ['href', 'title', 'target'],
    img: ['src', 'alt', 'title'],
    td: ['colspan', 'rowspan'],
    th: ['colspan', 'rowspan'],
    ol: ['start'],
    li: ['data-type', 'data-checked'],
    ul: ['data-type'],
    code: ['class'],
    input: ['type', 'checked'],
};

function styleOf(element: Element): Record<string, string> {
    const out: Record<string, string> = {};
    (element.getAttribute('style') ?? '').split(';').forEach((part) => {
        const [name, ...rest] = part.split(':');
        if (name && rest.length) {
            out[name.trim().toLowerCase()] = rest
                .join(':')
                .trim()
                .toLowerCase();
        }
    });

    return out;
}

function unwrap(element: Element): void {
    const parent = element.parentNode;
    if (!parent) {
        return;
    }
    while (element.firstChild) {
        parent.insertBefore(element.firstChild, element);
    }
    parent.removeChild(element);
}

/** Ersetzt ein Element durch eines mit anderem Namen; Kinder und `style` (für die Ausrichtung) wandern mit. */
function rename(doc: Document, element: Element, tag: string): void {
    const next = doc.createElement(tag);
    const style = element.getAttribute('style');
    if (style) {
        next.setAttribute('style', style);
    }
    while (element.firstChild) {
        next.appendChild(element.firstChild);
    }
    element.replaceWith(next);
}

function wrapChildren(doc: Document, element: Element, tag: string): void {
    const wrapper = doc.createElement(tag);
    while (element.firstChild) {
        wrapper.appendChild(element.firstChild);
    }
    element.appendChild(wrapper);
}

/** Word-Listenabsätze (`MsoListParagraph`, `mso-list:`) zu echten Listen — Ebene aus `level2`, Art aus dem Marker. */
function wordLists(doc: Document): void {
    const paragraphs = Array.from(doc.body.querySelectorAll('p')).filter(
        (p) =>
            /MsoListParagraph/i.test(p.getAttribute('class') ?? '') ||
            /mso-list\s*:/i.test(p.getAttribute('style') ?? ''),
    );
    let previous: { list: Element; level: number }[] = [];
    paragraphs.forEach((p) => {
        const style = p.getAttribute('style') ?? '';
        const level = Number(/level(\d+)/i.exec(style)?.[1] ?? '1');
        const marker = p.querySelector(
            '[style*="mso-list:Ignore"], [style*="mso-list: Ignore"]',
        );
        let markerText = marker?.textContent?.trim() ?? '';
        marker?.remove();
        if (markerText === '') {
            const match =
                /^\s*([·•o§\-▪■]|\d+[.)]|[a-z][.)]|[ivx]+[.)])\s*/i.exec(
                    p.textContent ?? '',
                );
            if (match) {
                markerText = match[1] ?? '';
                const first = p.firstChild;
                if (first && first.nodeType === 3) {
                    first.textContent = (first.textContent ?? '').replace(
                        /^\s*(?:[·•o§\-▪■]|\d+[.)]|[a-z][.)]|[ivx]+[.)])\s*/i,
                        '',
                    );
                }
            }
        }
        const ordered =
            /^[\da-z]+[.)]$|^[ivx]+[.)]$/i.test(markerText) &&
            !/^[o§·•]$/.test(markerText);
        const type = ordered ? 'ol' : 'ul';
        const sibling = p.previousElementSibling;
        const continues =
            sibling !== null &&
            previous.length > 0 &&
            (sibling === previous[previous.length - 1]?.list ||
                sibling.tagName === 'OL' ||
                sibling.tagName === 'UL');
        if (!continues) {
            previous = [];
        }
        while (
            previous.length &&
            (previous[previous.length - 1]?.level ?? 0) > level
        ) {
            previous.pop();
        }
        let frame = previous[previous.length - 1];
        if (!frame || frame.level < level) {
            const list = doc.createElement(type);
            if (frame) {
                const lastItem = frame.list.lastElementChild;
                (lastItem ?? frame.list).appendChild(list);
            } else {
                p.parentNode?.insertBefore(list, p);
            }
            frame = { list, level };
            previous.push(frame);
        }
        const item = doc.createElement('li');
        const inner = doc.createElement('p');
        while (p.firstChild) {
            inner.appendChild(p.firstChild);
        }
        item.appendChild(inner);
        frame.list.appendChild(item);
        p.remove();
    });
}

/** Inline-Stile zu Marken: Fett, Kursiv, Unterstrichen, Durchgestrichen, Hoch-/Tiefgestellt. */
function stylesToMarks(doc: Document, element: Element): void {
    const style = styleOf(element);
    const weight = style['font-weight'] ?? '';
    const tags: string[] = [];
    if (
        weight === 'bold' ||
        weight === 'bolder' ||
        /^[5-9]\d\d$/.test(weight)
    ) {
        tags.push('strong');
    }
    if ((style['font-style'] ?? '') === 'italic') {
        tags.push('em');
    }
    const decoration = `${style['text-decoration'] ?? ''} ${style['text-decoration-line'] ?? ''}`;
    if (decoration.includes('underline')) {
        tags.push('u');
    }
    if (decoration.includes('line-through')) {
        tags.push('s');
    }
    const vertical = style['vertical-align'] ?? '';
    if (vertical === 'super') {
        tags.push('sup');
    } else if (vertical === 'sub') {
        tags.push('sub');
    }
    tags.forEach((tag) => wrapChildren(doc, element, tag));
}

/**
 * Räumt eingefügtes HTML so auf, dass nichts verloren geht und nur das Schema ankommt: Word-Listen werden
 * Listen, `h1` wird `h2` (h5/h6 → h4), `b`/`i`/`font-weight` werden Marken, Schriftarten und Farben fallen,
 * Tabellen mit `tbody` bleiben Tabellen, Bilder behalten `src` und `alt`.
 */
export function cleanPastedHtml(html: string): string {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    doc.querySelectorAll(
        'style, script, meta, link, title, xml, input[type="hidden"]',
    ).forEach((n) => n.remove());
    Array.from(doc.getElementsByTagName('o:p')).forEach((n) => n.remove());
    const walker = doc.createTreeWalker(doc.body, 128);
    const comments: Node[] = [];
    while (walker.nextNode()) {
        comments.push(walker.currentNode);
    }
    comments.forEach((c) => c.parentNode?.removeChild(c));

    wordLists(doc);

    Array.from(
        doc.body.querySelectorAll(
            'span, font, b, i, div, p, td, th, h1, h2, h3, h4, h5, h6',
        ),
    ).forEach((element) => {
        if (!element.isConnected) {
            return;
        }
        stylesToMarks(doc, element);
        const tag = element.tagName.toLowerCase();
        if (
            tag === 'b' &&
            (styleOf(element)['font-weight'] ?? '') !== 'normal'
        ) {
            rename(doc, element, 'strong');
        } else if (tag === 'b' || tag === 'span' || tag === 'font') {
            unwrap(element);
        } else if (tag === 'i') {
            rename(doc, element, 'em');
        } else if (tag === 'h1') {
            rename(doc, element, 'h2');
        } else if (tag === 'h5' || tag === 'h6') {
            rename(doc, element, 'h4');
        }
    });

    Array.from(doc.body.querySelectorAll('*')).forEach((element) => {
        const tag = element.tagName.toLowerCase();
        const style = styleOf(element);
        const keep = keptAttributes[tag] ?? [];
        Array.from(element.attributes).forEach((attribute) => {
            if (!keep.includes(attribute.name)) {
                element.removeAttribute(attribute.name);
            }
        });
        const align = style['text-align'];
        if (
            align &&
            ['left', 'center', 'right', 'justify'].includes(align) &&
            ['p', 'h2', 'h3', 'h4'].includes(tag)
        ) {
            element.setAttribute('style', `text-align: ${align}`);
        }
        if (
            tag === 'img' &&
            !/^(https?:|data:|\/)/i.test(element.getAttribute('src') ?? '')
        ) {
            element.remove();
        }
    });

    Array.from(doc.body.querySelectorAll('p')).forEach((p) => {
        const empty = (p.textContent ?? '').replace(/[\s\u00a0]/g, '') === '';
        const images = p.querySelectorAll('img');
        if (empty && images.length > 0 && !p.querySelector('br')) {
            images.forEach((img) => p.parentNode?.insertBefore(img, p));
            p.remove();
        } else if (empty && !p.querySelector('img, br')) {
            p.remove();
        }
    });

    return doc.body.innerHTML;
}

/* ── Bilder aus RTF (Word legt eingebettete Bilder als Hex in `text/rtf` ab, das HTML zeigt nur `file:///`) ── */

/** Die Bilder eines RTF-Texts als Data-URLs in Dokumentreihenfolge (PNG und JPEG). */
export function rtfImages(rtf: string): string[] {
    const images: string[] = [];
    const pattern = /\{\\pict([\s\S]*?)\}/g;
    let match: RegExpExecArray | null = pattern.exec(rtf);
    while (match) {
        const body = match[1] ?? '';
        const before = rtf.slice(Math.max(0, match.index - 24), match.index);
        if (!/\\nonshppict/.test(before)) {
            const mime = /\\pngblip/.test(body)
                ? 'image/png'
                : /\\jpegblip/.test(body)
                  ? 'image/jpeg'
                  : null;
            const hex = body
                .replace(/\\[a-z]+-?\d*\s?/g, '')
                .replace(/[^0-9a-fA-F]/g, '');
            if (mime && hex.length > 0 && hex.length % 2 === 0) {
                let binary = '';
                for (let i = 0; i < hex.length; i += 2) {
                    binary += String.fromCharCode(
                        parseInt(hex.slice(i, i + 2), 16),
                    );
                }
                images.push(`data:${mime};base64,${btoa(binary)}`);
            }
        }
        match = pattern.exec(rtf);
    }

    return images;
}

/** Ersetzt `file:///`-Bilder im HTML der Reihe nach durch die Bilder aus dem RTF-Teil. */
export function replaceFileImages(html: string, dataUrls: string[]): string {
    if (dataUrls.length === 0) {
        return html;
    }
    let i = 0;

    return html.replace(
        /(<img\b[^>]*\bsrc=["'])file:[^"']*(["'])/gi,
        (whole, start: string, end: string) => {
            const data = dataUrls[i++];

            return data ? `${start}${data}${end}` : whole;
        },
    );
}

/* ── Fremde Bilder nach dem Einfügen in die Mediathek laden ── */

function isForeign(src: string, origin: string): boolean {
    if (src.startsWith('data:')) {
        return true;
    }
    if (/^https?:\/\//i.test(src)) {
        return !src.startsWith(`${origin}/`);
    }

    return false;
}

function nameOf(src: string, index: number): string {
    if (src.startsWith('data:')) {
        const ext =
            /^data:image\/(png|jpe?g|gif|webp)/i.exec(src)?.[1] ?? 'png';

        return `eingefuegt-${Date.now()}-${index}.${ext.replace('jpeg', 'jpg')}`;
    }
    const last = src.split('?')[0]?.split('/').pop() ?? '';

    return last !== '' ? last : `eingefuegt-${Date.now()}-${index}.png`;
}

function findImage(
    doc: PmNode,
    src: string,
): { node: PmNode; pos: number } | null {
    let found: { node: PmNode; pos: number } | null = null;
    doc.descendants((node, pos) => {
        if (found) {
            return false;
        }
        if (node.type.name === 'image' && node.attrs.src === src) {
            found = { node, pos };

            return false;
        }

        return true;
    });

    return found;
}

/**
 * Lädt alle Bilder ohne `mediaId`, deren Quelle eine Data-URL oder fremd ist, in die Mediathek und ersetzt
 * `src` und `mediaId`. Scheitert der Upload, bleibt ein fremdes Bild als Link stehen, eine Data-URL fällt weg.
 */
export async function uploadForeignImages(
    editor: Editor,
    upload: Uploader,
    origin = typeof window === 'undefined' ? '' : window.location.origin,
): Promise<number> {
    const sources: string[] = [];
    editor.state.doc.descendants((node) => {
        if (
            node.type.name === 'image' &&
            !node.attrs.mediaId &&
            typeof node.attrs.src === 'string' &&
            isForeign(node.attrs.src, origin) &&
            !sources.includes(node.attrs.src)
        ) {
            sources.push(node.attrs.src);
        }

        return true;
    });
    let done = 0;
    let failure = '';
    for (const [index, src] of sources.entries()) {
        let uploaded: UploadedMedia | null = null;
        try {
            uploaded = await upload({ data: src, name: nameOf(src, index) });
        } catch (error) {
            uploaded = null;
            failure ||= requestError(error);
        }
        let hit = findImage(editor.state.doc, src);
        while (hit) {
            const { node, pos } = hit;
            const tr = editor.state.tr;
            if (uploaded) {
                tr.setNodeMarkup(pos, undefined, {
                    ...node.attrs,
                    src: uploaded.url,
                    mediaId: uploaded.id,
                    alt: node.attrs.alt || uploaded.alt || null,
                });
                done += 1;
            } else if (src.startsWith('data:')) {
                tr.delete(pos, pos + node.nodeSize);
            } else {
                const schema = editor.state.schema;
                const link = schema.marks.link
                    ? [schema.marks.link.create({ href: src })]
                    : [];
                const text = schema.text(
                    String(node.attrs.alt || 'Bild'),
                    link,
                );
                tr.replaceWith(
                    pos,
                    pos + node.nodeSize,
                    schema.nodes.paragraph?.create(null, text) ?? text,
                );
            }
            editor.view.dispatch(tr);
            hit = findImage(editor.state.doc, src);
        }
    }
    if (failure !== '') {
        throw new Error(failure);
    }

    return done;
}

/* ── Die Editor-Props fürs Einfügen und Ablegen ── */

export type PasteHandlers = {
    transformPastedHTML: (html: string) => string;
    clipboardTextParser: NonNullable<
        EditorView['props']['clipboardTextParser']
    >;
    handlePaste: (view: EditorView, event: ClipboardEvent) => boolean;
    handleDrop: (view: EditorView, event: DragEvent) => boolean;
};

/** Eine Datei als Data-URL — damit gehen Bilder aus der Zwischenablage denselben Weg wie die aus Word. */
function dataUrlOf(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () =>
            typeof reader.result === 'string'
                ? resolve(reader.result)
                : reject(new Error('unlesbar'));
        reader.onerror = () => reject(reader.error ?? new Error('unlesbar'));
        reader.readAsDataURL(file);
    });
}

async function insertFiles(
    editor: Editor,
    files: File[],
    upload: Uploader,
    pos?: number,
): Promise<void> {
    for (const file of files) {
        try {
            const uploaded = await upload({ file });
            const node = {
                type: 'image',
                attrs: {
                    src: uploaded.url,
                    mediaId: uploaded.id,
                    alt: uploaded.alt ?? file.name.replace(/\.[^.]+$/, ''),
                },
            };
            if (pos !== undefined) {
                editor.chain().focus().insertContentAt(pos, node).run();
            } else {
                editor.chain().focus().insertContent(node).run();
            }
        } catch {
            /* der Aufrufer meldet den Fehler über onError */
        }
    }
}

/**
 * Verlustfreies Einfügen (M-04): Bilddateien aus der Zwischenablage werden hochgeladen; HTML wird aufgeräumt
 * und Bilder darin (Data-URL, Fremdadresse, Word-RTF) landen in der Mediathek; reines Markdown wird zu Knoten.
 */
export function pasteHandlers(
    getEditor: () => Editor | undefined,
    upload: Uploader,
    onError?: (message: string) => void,
): PasteHandlers {
    const afterPaste = (): void => {
        const editor = getEditor();
        if (!editor) {
            return;
        }
        window.setTimeout(() => {
            uploadForeignImages(editor, upload).catch((error: Error) =>
                onError?.(
                    t('Ein Bild konnte nicht übernommen werden: :message', {
                        message: error.message,
                    }),
                ),
            );
        }, 0);
    };

    return {
        transformPastedHTML: cleanPastedHtml,
        clipboardTextParser: (text, $context, plain, view) => {
            const source =
                !plain && isMarkdown(text) ? markdownToHtml(text) : null;
            const dom = document.createElement('div');
            if (source) {
                dom.innerHTML = source;
            } else {
                text.split(/\r?\n/).forEach((line) => {
                    const p = document.createElement('p');
                    p.textContent = line;
                    dom.appendChild(p);
                });
            }

            return PmDOMParser.fromSchema(view.state.schema).parseSlice(dom, {
                preserveWhitespace: true,
                context: $context,
            });
        },
        handlePaste: (_view, event) => {
            const editor = getEditor();
            const data = event.clipboardData;
            if (!editor || !data) {
                return false;
            }
            const html = data.getData('text/html');
            const files = Array.from(data.files).filter((f) =>
                f.type.startsWith('image/'),
            );
            const textOnly = html
                .replace(/<[^>]+>/g, '')
                .replace(/&nbsp;|\s/g, '');
            if (files.length > 0 && (html === '' || textOnly === '')) {
                event.preventDefault();
                insertFiles(editor, files, upload).catch(() =>
                    onError?.(t('Ein Bild konnte nicht hochgeladen werden.')),
                );

                return true;
            }
            if (files.length > 0 && !/<img\b/i.test(html)) {
                // Text mit Bildern, deren HTML die Bilder gar nicht nennt: Text wie gewohnt, Bilder danach
                window.setTimeout(() => {
                    insertFiles(editor, files, upload).catch(() =>
                        onError?.(
                            t('Ein Bild konnte nicht hochgeladen werden.'),
                        ),
                    );
                }, 0);

                return false;
            }
            if (/<img\b/i.test(html)) {
                const rtf = data.getData('text/rtf');
                const fromRtf = rtf ? rtfImages(rtf) : [];
                if (
                    fromRtf.length === 0 &&
                    files.length > 0 &&
                    /<img\b[^>]*\bsrc=["']file:/i.test(html)
                ) {
                    // Die Apps des Macs legen die Bilder als Dateien neben das HTML, das nur auf file:/// zeigt
                    event.preventDefault();
                    Promise.all(files.map(dataUrlOf))
                        .then((urls) => {
                            editor
                                .chain()
                                .focus()
                                .insertContent(
                                    cleanPastedHtml(
                                        replaceFileImages(html, urls),
                                    ),
                                )
                                .run();
                            afterPaste();
                        })
                        .catch(() =>
                            onError?.(
                                t('Die Bilder liessen sich nicht lesen.'),
                            ),
                        );

                    return true;
                }
                const withImages =
                    fromRtf.length > 0
                        ? replaceFileImages(html, fromRtf)
                        : html;
                if (withImages !== html) {
                    event.preventDefault();
                    editor
                        .chain()
                        .focus()
                        .insertContent(cleanPastedHtml(withImages))
                        .run();
                    afterPaste();

                    return true;
                }
                afterPaste();
            }

            return false;
        },
        handleDrop: (view, event) => {
            const editor = getEditor();
            const files = Array.from(event.dataTransfer?.files ?? []).filter(
                (f) => f.type.startsWith('image/'),
            );
            if (!editor || files.length === 0) {
                return false;
            }
            event.preventDefault();
            const pos = view.posAtCoords({
                left: event.clientX,
                top: event.clientY,
            })?.pos;
            insertFiles(editor, files, upload, pos).catch(() =>
                onError?.(t('Ein Bild konnte nicht hochgeladen werden.')),
            );

            return true;
        },
    };
}
