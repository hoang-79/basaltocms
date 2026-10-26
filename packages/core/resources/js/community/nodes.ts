import { Mark, Node, mergeAttributes } from '@tiptap/core';
import Blockquote from '@tiptap/extension-blockquote';
import CodeBlock from '@tiptap/extension-code-block';
import Image from '@tiptap/extension-image';
import { languageLabel } from './code';

/**
 * CR-006 (TB 37): Knoten der Community für die Editor-Insel — dieselben Namen und Attribute, die der Server säubert
 * (`CommunityRichText`): Zitat mit Verfasser, Spoiler, Einbettung, Erwähnung, Bild aus einem Anhang.
 */

/** Zitat mit `author` und `post` — im Editor mit Kopfzeile über ein data-Attribut. */
export const ForumQuote = Blockquote.extend({
    addAttributes() {
        return {
            author: {
                default: null,
                parseHTML: (el) => el.getAttribute('data-author'),
                renderHTML: (attrs: { author?: string | null }) =>
                    attrs.author ? { 'data-author': attrs.author } : {},
            },
            post: {
                default: null,
                parseHTML: (el) => {
                    const value = el.getAttribute('data-post');

                    return value ? Number(value) : null;
                },
                renderHTML: (attrs: { post?: number | null }) =>
                    attrs.post ? { 'data-post': String(attrs.post) } : {},
            },
        };
    },
});

/** Spoiler: aufklappbarer Block mit Titel. */
export const Spoiler = Node.create({
    name: 'spoiler',
    group: 'block',
    content: 'block+',
    defining: true,
    addAttributes() {
        return { title: { default: null } };
    },
    parseHTML() {
        return [{ tag: 'div[data-spoiler]' }];
    },
    renderHTML({ node, HTMLAttributes }) {
        return [
            'div',
            mergeAttributes(HTMLAttributes, {
                'data-spoiler': '',
                class: 'insel-spoiler',
                'data-title': (node.attrs.title as string | null) ?? '',
            }),
            0,
        ];
    },
});

/** Einbettung (YouTube, Vimeo) — im Editor nur ein Platzhalter, nichts lädt von aussen. */
export const Embed = Node.create({
    name: 'embed',
    group: 'block',
    atom: true,
    addAttributes() {
        return { provider: { default: 'youtube' }, src: { default: '' } };
    },
    parseHTML() {
        return [{ tag: 'div[data-embed]' }];
    },
    renderHTML({ node }) {
        return [
            'div',
            { 'data-embed': '', class: 'insel-embed' },
            `▶ ${String(node.attrs.provider)}: ${String(node.attrs.src)}`,
        ];
    },
});

/** Erwähnung (@Name) — die Kennung zählt, der Name erscheint beim Anzeigen frisch aus dem Profil. */
export const Mention = Node.create({
    name: 'mention',
    group: 'inline',
    inline: true,
    atom: true,
    selectable: false,
    addAttributes() {
        // TB 52: `group` — eine ganze Besuchergruppe (`@Moderation`)
        return {
            id: { default: null },
            label: { default: '' },
            group: { default: false },
        };
    },
    parseHTML() {
        return [{ tag: 'span[data-mention]' }];
    },
    renderHTML({ node }) {
        return [
            'span',
            {
                'data-mention': String(node.attrs.id),
                class: node.attrs.group
                    ? 'insel-mention insel-mention--gruppe'
                    : 'insel-mention',
            },
            `@${String(node.attrs.label)}`,
        ];
    },
    renderText({ node }) {
        return `@${String(node.attrs.label)}`;
    },
});

/** Bild aus einem Anhang — mit der Kennung, damit der Server ihn dem Beitrag zuordnet. */
export const AttachmentImage = Image.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            attachment: { default: null },
        };
    },
});

/** Farben des Markierstifts — dieselben wie `CommunityRichText::HIGHLIGHTS`. */
export const HIGHLIGHT_COLORS = [
    'gelb',
    'gruen',
    'blau',
    'rot',
    'violett',
] as const;

/** TB 49: Markierstift in fünf Farben — als Klasse, nie als freie Farbe. */
export const Highlight = Mark.create({
    name: 'highlight',
    addAttributes() {
        return {
            color: {
                default: 'gelb',
                parseHTML: (el) => {
                    const color = el.getAttribute('data-farbe');

                    return HIGHLIGHT_COLORS.includes(
                        color as (typeof HIGHLIGHT_COLORS)[number],
                    )
                        ? color
                        : 'gelb';
                },
                renderHTML: (attrs: { color?: string }) => ({
                    'data-farbe': attrs.color ?? 'gelb',
                    class: `insel-markiert insel-markiert--${attrs.color ?? 'gelb'}`,
                }),
            },
        };
    },
    parseHTML() {
        return [{ tag: 'mark' }];
    },
    renderHTML({ HTMLAttributes }) {
        return ['mark', HTMLAttributes, 0];
    },
});

/**
 * TB 49: Codeblock mit Sprache, Dateiname und erster Zeilennummer. Im Editor zeigt der Kopf Dateiname und Sprache
 * (`data-kopf`); die Hervorhebung kommt erst beim Lesen.
 */
export const ForumCodeBlock = CodeBlock.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            filename: {
                default: null,
                parseHTML: (el) => el.getAttribute('data-datei'),
                renderHTML: () => ({}),
            },
            lineStart: {
                default: null,
                parseHTML: (el) => {
                    const value = Number(el.getAttribute('data-ab'));

                    return value > 1 ? value : null;
                },
                renderHTML: () => ({}),
            },
        };
    },
    renderHTML({ node, HTMLAttributes }) {
        const attrs = node.attrs as {
            language?: string | null;
            filename?: string | null;
            lineStart?: number | null;
        };
        const head = [
            attrs.filename,
            languageLabel(attrs.language),
            attrs.lineStart ? `#${attrs.lineStart}` : null,
        ]
            .filter(Boolean)
            .join(' · ');

        return [
            'pre',
            mergeAttributes(HTMLAttributes, {
                'data-kopf': head || 'Code',
                ...(attrs.filename ? { 'data-datei': attrs.filename } : {}),
                ...(attrs.lineStart
                    ? { 'data-ab': String(attrs.lineStart) }
                    : {}),
            }),
            [
                'code',
                { class: attrs.language ? `language-${attrs.language}` : null },
                0,
            ],
        ];
    },
});
