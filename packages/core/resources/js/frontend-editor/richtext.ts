/**
 * Frontend-Editor (M-19): Was in einem bearbeitbaren Element der Seite steht, zurück in die Form der Fassungsdaten —
 * Fliesstext als ProseMirror-Dokument (wie der Block-Editor speichert), Text und Textfeld als Klartext.
 *
 * Gelesen wird nur, was der Kern selbst ausgibt (`RichText::toHtml`) und was ein schlichtes `contenteditable`
 * erzeugt: Absätze, Überschriften, Listen, Zitat, Linie, Umbruch; fett, kursiv, unterstrichen, durchgestrichen,
 * Code, hoch/tief, Link. Alles andere fällt weg, Text bleibt Text — der Server säubert danach noch einmal.
 */

export type DocMark = { type: string; attrs?: Record<string, unknown> };
export type DocNode = {
    type: string;
    attrs?: Record<string, unknown>;
    content?: DocNode[];
    text?: string;
    marks?: DocMark[];
};

const MARK_TAGS: Record<string, string> = {
    STRONG: 'bold',
    B: 'bold',
    EM: 'italic',
    I: 'italic',
    U: 'underline',
    S: 'strike',
    STRIKE: 'strike',
    DEL: 'strike',
    CODE: 'code',
    SUB: 'subscript',
    SUP: 'superscript',
};

const BLOCK_TAGS = new Set([
    'P',
    'DIV',
    'H1',
    'H2',
    'H3',
    'H4',
    'H5',
    'H6',
    'UL',
    'OL',
    'LI',
    'BLOCKQUOTE',
    'HR',
    'SECTION',
    'ARTICLE',
]);

/** Inhalte, die nie Text der Seite sind. */
const SKIP_TAGS = new Set([
    'SCRIPT',
    'STYLE',
    'TEMPLATE',
    'NOSCRIPT',
    'IMG',
    'PICTURE',
    'SVG',
    'VIDEO',
    'AUDIO',
    'IFRAME',
    'OBJECT',
    'INPUT',
    'BUTTON',
    'SELECT',
    'TEXTAREA',
]);

const ALIGNS = ['center', 'right', 'justify'];

/** Der Inhalt eines Elements als Fliesstext-Dokument. */
export function domToDoc(root: Node): DocNode {
    return { type: 'doc', content: blocks(Array.from(root.childNodes)) };
}

function blocks(nodes: ChildNode[]): DocNode[] {
    const out: DocNode[] = [];
    let inline: DocNode[] = [];
    const flush = (): void => {
        const paragraph = paragraphOf(inline);
        if (paragraph) {
            out.push(paragraph);
        }
        inline = [];
    };
    for (const node of nodes) {
        if (node.nodeType === Node.ELEMENT_NODE) {
            const el = node as Element;
            if (SKIP_TAGS.has(el.tagName)) {
                continue;
            }
            if (BLOCK_TAGS.has(el.tagName)) {
                flush();
                out.push(...blockOf(el));
                continue;
            }
        }
        inline.push(...inlineOf(node, []));
    }
    flush();

    return out;
}

/** Ein Absatz aus losen Inline-Knoten — nur, wenn er etwas enthält; der Platzhalter-Umbruch am Ende fällt weg. */
function paragraphOf(
    inline: DocNode[],
    attrs?: Record<string, unknown>,
): DocNode | null {
    const content = trimBreaks(inline);
    if (
        !content.some(
            (n) => n.type === 'hardBreak' || (n.text ?? '').trim() !== '',
        )
    ) {
        return null;
    }

    return { type: 'paragraph', ...(attrs ? { attrs } : {}), content };
}

function trimBreaks(inline: DocNode[]): DocNode[] {
    const content = merge(inline);
    while (
        content.length > 0 &&
        content[content.length - 1].type === 'hardBreak'
    ) {
        content.pop();
    }

    return content;
}

function alignOf(el: Element): Record<string, unknown> | undefined {
    for (const align of ALIGNS) {
        if (el.classList.contains(`align-${align}`)) {
            return { textAlign: align };
        }
    }

    return undefined;
}

function hasBlockChild(el: Element): boolean {
    return Array.from(el.children).some((child) =>
        BLOCK_TAGS.has(child.tagName),
    );
}

function blockOf(el: Element): DocNode[] {
    const tag = el.tagName;
    const children = Array.from(el.childNodes);
    if (/^H[1-6]$/.test(tag)) {
        const align = alignOf(el);
        const content = trimBreaks(inlineList(children));

        return [
            {
                type: 'heading',
                attrs: { level: Number(tag.slice(1)), ...align },
                ...(content.length > 0 ? { content } : {}),
            },
        ];
    }
    if (tag === 'UL' || tag === 'OL') {
        const items: DocNode[] = Array.from(el.children)
            .filter((child) => child.tagName === 'LI')
            .map((li) => {
                const content = blocks(Array.from(li.childNodes));

                return {
                    type: 'listItem',
                    content:
                        content.length > 0 ? content : [{ type: 'paragraph' }],
                };
            });
        if (items.length === 0) {
            return [];
        }
        const start = Number(el.getAttribute('start') ?? '1');

        return [
            {
                type: tag === 'UL' ? 'bulletList' : 'orderedList',
                ...(tag === 'OL' && Number.isInteger(start) && start > 1
                    ? { attrs: { start } }
                    : {}),
                content: items,
            },
        ];
    }
    if (tag === 'BLOCKQUOTE') {
        const content = blocks(children);

        return [
            {
                type: 'blockquote',
                content: content.length > 0 ? content : [{ type: 'paragraph' }],
            },
        ];
    }
    if (tag === 'HR') {
        return [{ type: 'horizontalRule' }];
    }
    // P, DIV, LI ausserhalb einer Liste, SECTION …: enthält es Blöcke, zählen die; sonst ein Absatz
    if (tag !== 'P' && hasBlockChild(el)) {
        return blocks(children);
    }
    const paragraph = paragraphOf(inlineList(children), alignOf(el));

    return paragraph ? [paragraph] : [];
}

function inlineList(nodes: ChildNode[]): DocNode[] {
    return nodes.flatMap((node) => inlineOf(node, []));
}

function inlineOf(node: Node, marks: DocMark[]): DocNode[] {
    if (node.nodeType === Node.TEXT_NODE) {
        const text = (node.textContent ?? '')
            .replace(/\u00a0/g, ' ')
            .replace(/[\t\n\r ]+/g, ' ');

        return text === ''
            ? []
            : [{ type: 'text', text, ...(marks.length > 0 ? { marks } : {}) }];
    }
    if (node.nodeType !== Node.ELEMENT_NODE) {
        return [];
    }
    const el = node as Element;
    if (SKIP_TAGS.has(el.tagName)) {
        return [];
    }
    if (el.tagName === 'BR') {
        return [{ type: 'hardBreak' }];
    }
    let next = marks;
    const markType = MARK_TAGS[el.tagName];
    if (markType && !marks.some((m) => m.type === markType)) {
        next = [...marks, { type: markType }];
    }
    if (el.tagName === 'A' && !marks.some((m) => m.type === 'link')) {
        const href = (el.getAttribute('href') ?? '').trim();
        if (href !== '') {
            const attrs: Record<string, unknown> = { href };
            if (el.getAttribute('target') === '_blank') {
                attrs.target = '_blank';
            }
            const title = el.getAttribute('title');
            if (title) {
                attrs.title = title;
            }
            next = [...marks, { type: 'link', attrs }];
        }
    }

    return Array.from(el.childNodes).flatMap((child) => inlineOf(child, next));
}

/** Benachbarte Texte mit denselben Marken zusammenlegen. */
function merge(nodes: DocNode[]): DocNode[] {
    const out: DocNode[] = [];
    for (const node of nodes) {
        const last = out[out.length - 1];
        if (
            last &&
            last.type === 'text' &&
            node.type === 'text' &&
            JSON.stringify(last.marks ?? []) ===
                JSON.stringify(node.marks ?? [])
        ) {
            out[out.length - 1] = {
                ...last,
                text: (last.text ?? '') + (node.text ?? ''),
            };
        } else {
            out.push(node);
        }
    }

    return out;
}

/**
 * Klartext eines Elements: Text, Umbruch als Zeilenwechsel, Blockgrenzen als Zeilenwechsel. Einzeilig (`text`) fällt
 * jeder Zeilenwechsel weg; mehrzeilig (`textarea`) bleiben sie — auch die, die das Theme per CSS zusammenzieht.
 */
export function plainText(root: Node, multiline: boolean): string {
    let out = '';
    const walk = (node: Node): void => {
        if (node.nodeType === Node.TEXT_NODE) {
            out += (node.textContent ?? '').replace(/\u00a0/g, ' ');

            return;
        }
        if (node.nodeType !== Node.ELEMENT_NODE) {
            return;
        }
        const el = node as Element;
        if (SKIP_TAGS.has(el.tagName)) {
            return;
        }
        if (el.tagName === 'BR') {
            out += '\n';

            return;
        }
        const block = BLOCK_TAGS.has(el.tagName);
        if (block && out !== '' && !out.endsWith('\n')) {
            out += '\n';
        }
        el.childNodes.forEach(walk);
    };
    root.childNodes.forEach(walk);
    if (!multiline) {
        return out.replace(/\s+/g, ' ').trim();
    }

    return out
        .replace(/\r\n?/g, '\n')
        .replace(/[ \t]+\n/g, '\n')
        .trim();
}
