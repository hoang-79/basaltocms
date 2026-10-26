import { Extension } from '@tiptap/core';
import { CodeBlockLowlight } from '@tiptap/extension-code-block-lowlight';
import { TaskItem, TaskList } from '@tiptap/extension-list';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import {
    Table,
    TableCell,
    TableHeader,
    TableRow,
} from '@tiptap/extension-table';
import TextAlign from '@tiptap/extension-text-align';
import { CharacterCount, Placeholder } from '@tiptap/extensions';
import StarterKit from '@tiptap/starter-kit';
import bash from 'highlight.js/lib/languages/bash';
import css from 'highlight.js/lib/languages/css';
import javascript from 'highlight.js/lib/languages/javascript';
import json from 'highlight.js/lib/languages/json';
import php from 'highlight.js/lib/languages/php';
import typescript from 'highlight.js/lib/languages/typescript';
import xml from 'highlight.js/lib/languages/xml';
import { createLowlight } from 'lowlight';
import { t } from '../../i18n';
import { Embed, RichYoutube } from './embed';
import { RichImage } from './image';
import { SearchReplace } from './search';

/** Die Sprachen des Codeblocks (M-04): Text ohne Hervorhebung, dazu die üblichen und Lava (ohne Grammatik). */
export const codeLanguages: { value: string; label: string }[] = [
    {
        value: '',
        get label() {
            return t('Text');
        },
    },
    { value: 'php', label: 'PHP' },
    { value: 'javascript', label: 'JavaScript' },
    { value: 'typescript', label: 'TypeScript' },
    { value: 'xml', label: 'HTML' },
    { value: 'css', label: 'CSS' },
    { value: 'bash', label: 'Bash' },
    { value: 'json', label: 'JSON' },
    { value: 'lava', label: 'Lava' },
];

/** Die Sonderzeichen-Palette: Anführungszeichen, Striche, Zeichen, Pfeile, Haken. */
export const specialCharacters: { char: string; label: string }[] = [
    {
        char: '«',
        get label() {
            return t('Guillemet links');
        },
    },
    {
        char: '»',
        get label() {
            return t('Guillemet rechts');
        },
    },
    {
        char: '‹',
        get label() {
            return t('Einfaches Guillemet links');
        },
    },
    {
        char: '›',
        get label() {
            return t('Einfaches Guillemet rechts');
        },
    },
    {
        char: '„',
        get label() {
            return t('Anführungszeichen unten');
        },
    },
    {
        char: '“',
        get label() {
            return t('Anführungszeichen oben');
        },
    },
    {
        char: '‚',
        get label() {
            return t('Einfaches Anführungszeichen unten');
        },
    },
    {
        char: '‘',
        get label() {
            return t('Einfaches Anführungszeichen oben');
        },
    },
    {
        char: '’',
        get label() {
            return t('Apostroph');
        },
    },
    {
        char: '–',
        get label() {
            return t('Halbgeviertstrich');
        },
    },
    {
        char: '—',
        get label() {
            return t('Geviertstrich');
        },
    },
    {
        char: '…',
        get label() {
            return t('Auslassungspunkte');
        },
    },
    {
        char: '·',
        get label() {
            return t('Mittelpunkt');
        },
    },
    {
        char: '×',
        get label() {
            return t('Malzeichen');
        },
    },
    {
        char: '½',
        get label() {
            return t('Ein halb');
        },
    },
    {
        char: '©',
        get label() {
            return t('Copyright');
        },
    },
    {
        char: '®',
        get label() {
            return t('Registriert');
        },
    },
    {
        char: '™',
        get label() {
            return t('Trademark');
        },
    },
    {
        char: '€',
        get label() {
            return t('Euro');
        },
    },
    {
        char: '£',
        get label() {
            return t('Pfund');
        },
    },
    {
        char: '$',
        get label() {
            return t('Dollar');
        },
    },
    {
        char: '°',
        get label() {
            return t('Grad');
        },
    },
    {
        char: '±',
        get label() {
            return t('Plusminus');
        },
    },
    {
        char: '§',
        get label() {
            return t('Paragraf');
        },
    },
    {
        char: '¶',
        get label() {
            return t('Absatz');
        },
    },
    {
        char: '→',
        get label() {
            return t('Pfeil rechts');
        },
    },
    {
        char: '←',
        get label() {
            return t('Pfeil links');
        },
    },
    {
        char: '↑',
        get label() {
            return t('Pfeil hoch');
        },
    },
    {
        char: '↓',
        get label() {
            return t('Pfeil runter');
        },
    },
    {
        char: '✓',
        get label() {
            return t('Haken');
        },
    },
    {
        char: '✗',
        get label() {
            return t('Kreuz');
        },
    },
    {
        char: ' ',
        get label() {
            return t('Geschütztes Leerzeichen');
        },
    },
];

export type RichTextOptions = {
    placeholder?: string;
    /** ⌘K: öffnet den Link-Dialog der Komponente. */
    onLink?: () => void;
};

/** Nur die Grammatiken der Sprachliste — nicht das ganze highlight.js (Bundle). */
const lowlight = createLowlight({
    php,
    javascript,
    typescript,
    xml,
    css,
    bash,
    json,
});

/**
 * Die Erweiterungen des Fliesstexts — eine Liste für Komponente und Tests: genau die Knoten und Marken,
 * die der Server kennt (StarterKit mit H2–H4, Link mit Ziel und Titel, Aufgabenliste, Tabelle, Bild,
 * YouTube und Einbettung, Hoch-/Tiefgestellt, Textausrichtung, Codeblock mit Sprache, Suchen und Ersetzen).
 */
export function richTextExtensions(options: RichTextOptions = {}) {
    return [
        StarterKit.configure({
            heading: { levels: [2, 3, 4] },
            codeBlock: false,
            link: {
                openOnClick: false,
                autolink: true,
                defaultProtocol: 'https',
                HTMLAttributes: { target: null, rel: null, class: null },
            },
        }),
        CodeBlockLowlight.configure({ lowlight, defaultLanguage: null }),
        TaskList,
        TaskItem.configure({ nested: true }),
        Table.configure({ resizable: false }),
        TableRow,
        TableHeader,
        TableCell,
        RichImage.configure({ allowBase64: true }),
        RichYoutube.configure({ nocookie: true }),
        Embed,
        Subscript,
        Superscript,
        TextAlign.configure({ types: ['heading', 'paragraph'] }),
        CharacterCount,
        Placeholder.configure({
            placeholder: options.placeholder ?? t('Text schreiben …'),
        }),
        SearchReplace,
        Extension.create({
            name: 'basaltoShortcuts',
            addKeyboardShortcuts() {
                return {
                    'Mod-k': () => {
                        options.onLink?.();

                        return true;
                    },
                };
            },
        }),
    ];
}
