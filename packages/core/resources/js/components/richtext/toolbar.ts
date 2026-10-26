import type { Editor } from '@tiptap/core';
import { t } from '../../i18n';

export type PanelKey =
    | 'link'
    | 'table'
    | 'image'
    | 'embed'
    | 'chars'
    | 'search'
    | 'source';

/** Ein Werkzeug der Toolbar: Symbol (16-px-Strichpfad), Name, Kürzel; `run` führt aus, `panel` öffnet einen Dialog. */
export type Tool = {
    key: string;
    label: string;
    shortcut?: string;
    icon: string;
    run?: (editor: Editor) => void;
    active?: (editor: Editor) => boolean;
    disabled?: (editor: Editor) => boolean;
    panel?: PanelKey;
};

/** Eine Reihe der Toolbar: Gruppen aus Werkzeugen oder den Auswahlfeldern Absatzformat und Codesprache. */
export type ToolbarItem = Tool | { key: 'format' } | { key: 'codeLanguage' };

const isMac =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.platform);
const alt = isMac ? '⌥' : 'Alt+';

/** Das Kürzel für den Tooltip: «⌘B» auf dem Mac, «Strg+B» sonst. */
export function shortcut(keys: string): string {
    return keys
        .replace('Mod', isMac ? '⌘' : t('Strg+'))
        .replace('Alt', alt)
        .replace(/\+(?=[^+])/g, isMac ? '' : '+')
        .replace(/⇧\+/g, '⇧');
}

const icons = {
    undo: 'M6 4 2.5 7.5 6 11 M2.5 7.5H10a3.5 3.5 0 0 1 0 7H7',
    redo: 'M10 4l3.5 3.5L10 11 M13.5 7.5H6a3.5 3.5 0 0 0 0 7h3',
    bold: 'M4.5 2.5h4.8a2.6 2.6 0 0 1 0 5.2H4.5zM4.5 7.7h5.6a2.9 2.9 0 0 1 0 5.8H4.5z',
    italic: 'M6.5 2.5h6M3.5 13.5h6M9.5 2.5l-3 11',
    underline: 'M4 2.5v5.5a4 4 0 0 0 8 0V2.5M3 14h10',
    strike: 'M2.5 8h11M11 5c0-1.7-1.5-2.5-3-2.5S5 3.3 5 4.7c0 .9.5 1.5 1.3 2M5 11c0 1.7 1.5 2.5 3 2.5s3-.8 3-2.3c0-.4-.1-.8-.3-1.2',
    code: 'M5.5 4 2 8l3.5 4M10.5 4 14 8l-3.5 4',
    superscript:
        'M2.5 4l6 8M8.5 4l-6 8M11 2.5c.8-.6 2.3-.5 2.5.6.2 1.2-2.5 2-2.5 3h3',
    subscript:
        'M2.5 4l6 8M8.5 4l-6 8M11 9c.8-.6 2.3-.5 2.5.6.2 1.2-2.5 2-2.5 3h3',
    link: 'M6.5 9.5a3 3 0 0 0 4.2 0l2-2a3 3 0 0 0-4.2-4.2l-1 1M9.5 6.5a3 3 0 0 0-4.2 0l-2 2a3 3 0 0 0 4.2 4.2l1-1',
    bulletList: 'M6 4h8M6 8h8M6 12h8M2.5 4h.01M2.5 8h.01M2.5 12h.01',
    orderedList:
        'M6.5 4h8M6.5 8h8M6.5 12h8M2 3h1.3v3M2 6.3h2.3M2 9.5c.7-.6 2.3-.5 2.3.5s-2.3 1.5-2.3 3h2.5',
    taskList:
        'M7 4h7M7 8h7M7 12h7M1.5 3.5l1.2 1.2 2-2.2M1.5 7.5l1.2 1.2 2-2.2M1.5 11.5l1.2 1.2 2-2.2',
    indent: 'M2 3h12M7 6.5h7M7 9.5h7M2 13h12M2 6l2.5 2L2 10',
    outdent: 'M2 3h12M7 6.5h7M7 9.5h7M2 13h12M4.5 6 2 8l2.5 2',
    blockquote:
        'M3 4.5h3.5v3.5H3zM9.5 4.5H13v3.5H9.5zM6.5 8c0 2-1.5 3-3 3.5M13 8c0 2-1.5 3-3 3.5',
    codeBlock: 'M2 2.5h12v11H2zM6 6 4 8l2 2M10 6l2 2-2 2',
    table: 'M2 2.5h12v11H2zM2 6.5h12M2 10.5h12M6 2.5v11M10 2.5v11',
    image: 'M2 3h12v10H2zM2 11l3.5-3.5 3 3 2-2L14 12M10.5 6.5h.01',
    embed: 'M2 3h12v10H2zM6.5 5.5v5l4-2.5z',
    rule: 'M2 8h12',
    chars: 'M4.5 13.5H3c2-1 3-3 3-5.5A2.5 2.5 0 0 1 8 5.5a2.5 2.5 0 0 1 2 2.5c0 2.5 1 4.5 3 5.5h-1.5',
    alignLeft: 'M2 3.5h12M2 6.5h8M2 9.5h12M2 12.5h8',
    alignCenter: 'M2 3.5h12M4 6.5h8M2 9.5h12M4 12.5h8',
    alignRight: 'M2 3.5h12M6 6.5h8M2 9.5h12M6 12.5h8',
    alignJustify: 'M2 3.5h12M2 6.5h12M2 9.5h12M2 12.5h12',
    search: 'M7 11.5a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9zM10.3 10.3 14 14',
    clear: 'M3 3h7l-3 5M5.5 13h3M2.5 14l11-11',
    source: 'M5.5 3 2 8l3.5 5M10.5 3 14 8l-3.5 5M9.5 2 6.5 14',
};

export const tools: Record<string, Tool> = {
    undo: {
        key: 'undo',
        get label() {
            return t('Rückgängig');
        },
        shortcut: 'Mod+Z',
        icon: icons.undo,
        run: (e) => e.chain().focus().undo().run(),
        disabled: (e) => !e.can().undo(),
    },
    redo: {
        key: 'redo',
        get label() {
            return t('Wiederholen');
        },
        shortcut: '⇧+Mod+Z',
        icon: icons.redo,
        run: (e) => e.chain().focus().redo().run(),
        disabled: (e) => !e.can().redo(),
    },
    bold: {
        key: 'bold',
        get label() {
            return t('Fett');
        },
        shortcut: 'Mod+B',
        icon: icons.bold,
        run: (e) => e.chain().focus().toggleBold().run(),
        active: (e) => e.isActive('bold'),
    },
    italic: {
        key: 'italic',
        get label() {
            return t('Kursiv');
        },
        shortcut: 'Mod+I',
        icon: icons.italic,
        run: (e) => e.chain().focus().toggleItalic().run(),
        active: (e) => e.isActive('italic'),
    },
    underline: {
        key: 'underline',
        get label() {
            return t('Unterstrichen');
        },
        shortcut: 'Mod+U',
        icon: icons.underline,
        run: (e) => e.chain().focus().toggleUnderline().run(),
        active: (e) => e.isActive('underline'),
    },
    strike: {
        key: 'strike',
        get label() {
            return t('Durchgestrichen');
        },
        shortcut: '⇧+Mod+S',
        icon: icons.strike,
        run: (e) => e.chain().focus().toggleStrike().run(),
        active: (e) => e.isActive('strike'),
    },
    code: {
        key: 'code',
        get label() {
            return t('Code');
        },
        shortcut: 'Mod+E',
        icon: icons.code,
        run: (e) => e.chain().focus().toggleCode().run(),
        active: (e) => e.isActive('code'),
    },
    superscript: {
        key: 'superscript',
        get label() {
            return t('Hochgestellt');
        },
        shortcut: 'Mod+.',
        icon: icons.superscript,
        run: (e) => e.chain().focus().toggleSuperscript().run(),
        active: (e) => e.isActive('superscript'),
    },
    subscript: {
        key: 'subscript',
        get label() {
            return t('Tiefgestellt');
        },
        shortcut: 'Mod+,',
        icon: icons.subscript,
        run: (e) => e.chain().focus().toggleSubscript().run(),
        active: (e) => e.isActive('subscript'),
    },
    link: {
        key: 'link',
        get label() {
            return t('Link');
        },
        shortcut: 'Mod+K',
        icon: icons.link,
        panel: 'link',
        active: (e) => e.isActive('link'),
    },
    bulletList: {
        key: 'bulletList',
        get label() {
            return t('Aufzählung');
        },
        shortcut: '⇧+Mod+8',
        icon: icons.bulletList,
        run: (e) => e.chain().focus().toggleBulletList().run(),
        active: (e) => e.isActive('bulletList'),
    },
    orderedList: {
        key: 'orderedList',
        get label() {
            return t('Nummerierung');
        },
        shortcut: '⇧+Mod+7',
        icon: icons.orderedList,
        run: (e) => e.chain().focus().toggleOrderedList().run(),
        active: (e) => e.isActive('orderedList'),
    },
    taskList: {
        key: 'taskList',
        get label() {
            return t('Aufgabenliste');
        },
        shortcut: '⇧+Mod+9',
        icon: icons.taskList,
        run: (e) => e.chain().focus().toggleTaskList().run(),
        active: (e) => e.isActive('taskList'),
    },
    indent: {
        key: 'indent',
        get label() {
            return t('Einzug rein (Liste einrücken)');
        },
        shortcut: 'Tab',
        icon: icons.indent,
        run: (e) =>
            e
                .chain()
                .focus()
                .sinkListItem(e.isActive('taskItem') ? 'taskItem' : 'listItem')
                .run(),
        disabled: (e) =>
            !e
                .can()
                .sinkListItem(e.isActive('taskItem') ? 'taskItem' : 'listItem'),
    },
    outdent: {
        key: 'outdent',
        get label() {
            return t('Einzug raus');
        },
        shortcut: '⇧+Tab',
        icon: icons.outdent,
        run: (e) =>
            e
                .chain()
                .focus()
                .liftListItem(e.isActive('taskItem') ? 'taskItem' : 'listItem')
                .run(),
        disabled: (e) =>
            !e
                .can()
                .liftListItem(e.isActive('taskItem') ? 'taskItem' : 'listItem'),
    },
    blockquote: {
        key: 'blockquote',
        get label() {
            return t('Zitat');
        },
        shortcut: '⇧+Mod+B',
        icon: icons.blockquote,
        run: (e) => e.chain().focus().toggleBlockquote().run(),
        active: (e) => e.isActive('blockquote'),
    },
    codeBlock: {
        key: 'codeBlock',
        get label() {
            return t('Codeblock');
        },
        shortcut: 'Alt+Mod+C',
        icon: icons.codeBlock,
        run: (e) => e.chain().focus().toggleCodeBlock().run(),
        active: (e) => e.isActive('codeBlock'),
    },
    table: {
        key: 'table',
        get label() {
            return t('Tabelle');
        },
        icon: icons.table,
        panel: 'table',
        active: (e) => e.isActive('table'),
    },
    image: {
        key: 'image',
        get label() {
            return t('Bild aus der Mediathek');
        },
        icon: icons.image,
        panel: 'image',
        active: (e) => e.isActive('image'),
    },
    embed: {
        key: 'embed',
        get label() {
            return t('Einbettung (YouTube, Vimeo, Karte)');
        },
        icon: icons.embed,
        panel: 'embed',
        active: (e) => e.isActive('youtube') || e.isActive('embed'),
    },
    rule: {
        key: 'rule',
        get label() {
            return t('Trennlinie');
        },
        icon: icons.rule,
        run: (e) => e.chain().focus().setHorizontalRule().run(),
    },
    chars: {
        key: 'chars',
        get label() {
            return t('Sonderzeichen');
        },
        icon: icons.chars,
        panel: 'chars',
    },
    alignLeft: {
        key: 'alignLeft',
        get label() {
            return t('Linksbündig');
        },
        shortcut: '⇧+Mod+L',
        icon: icons.alignLeft,
        run: (e) => e.chain().focus().setTextAlign('left').run(),
        active: (e) => e.isActive({ textAlign: 'left' }),
    },
    alignCenter: {
        key: 'alignCenter',
        get label() {
            return t('Zentriert');
        },
        shortcut: '⇧+Mod+E',
        icon: icons.alignCenter,
        run: (e) => e.chain().focus().setTextAlign('center').run(),
        active: (e) => e.isActive({ textAlign: 'center' }),
    },
    alignRight: {
        key: 'alignRight',
        get label() {
            return t('Rechtsbündig');
        },
        shortcut: '⇧+Mod+R',
        icon: icons.alignRight,
        run: (e) => e.chain().focus().setTextAlign('right').run(),
        active: (e) => e.isActive({ textAlign: 'right' }),
    },
    alignJustify: {
        key: 'alignJustify',
        get label() {
            return t('Blocksatz');
        },
        shortcut: '⇧+Mod+J',
        icon: icons.alignJustify,
        run: (e) => e.chain().focus().setTextAlign('justify').run(),
        active: (e) => e.isActive({ textAlign: 'justify' }),
    },
    search: {
        key: 'search',
        get label() {
            return t('Suchen und Ersetzen');
        },
        icon: icons.search,
        panel: 'search',
    },
    clear: {
        key: 'clear',
        get label() {
            return t('Format entfernen');
        },
        icon: icons.clear,
        run: (e) => e.chain().focus().unsetAllMarks().clearNodes().run(),
    },
    source: {
        key: 'source',
        get label() {
            return t('Quelltext (JSON und HTML, nur lesen)');
        },
        icon: icons.source,
        panel: 'source',
    },
};

function group(...keys: string[]): ToolbarItem[] {
    return keys.map((key): ToolbarItem => {
        if (key === 'format') {
            return { key: 'format' };
        }
        if (key === 'codeLanguage') {
            return { key: 'codeLanguage' };
        }

        return tools[key] as Tool;
    });
}

/** Reihe 1: Verlauf · Absatzformat · Marken · Link · Listen und Einzug · Zitat und Codeblock mit Sprache. */
export const row1: ToolbarItem[][] = [
    group('undo', 'redo'),
    group('format'),
    group(
        'bold',
        'italic',
        'underline',
        'strike',
        'code',
        'superscript',
        'subscript',
    ),
    group('link'),
    group('bulletList', 'orderedList', 'taskList', 'indent', 'outdent'),
    group('blockquote', 'codeBlock', 'codeLanguage'),
];

/** Reihe 2: Tabelle · Bild · Einbettung · Trennlinie · Sonderzeichen · Ausrichtung · Suchen · Format entfernen · Quelltext. */
export const row2: ToolbarItem[][] = [
    group('table'),
    group('image', 'embed'),
    group('rule', 'chars'),
    group('alignLeft', 'alignCenter', 'alignRight', 'alignJustify'),
    group('search'),
    group('clear'),
    group('source'),
];

export const headingOptions: { value: string; label: string }[] = [
    {
        value: 'paragraph',
        get label() {
            return t('Absatz');
        },
    },
    {
        value: '2',
        get label() {
            return t('Überschrift 2');
        },
    },
    {
        value: '3',
        get label() {
            return t('Überschrift 3');
        },
    },
    {
        value: '4',
        get label() {
            return t('Überschrift 4');
        },
    },
];

/** Das aktuelle Absatzformat für das Auswahlfeld. */
export function currentFormat(editor: Editor): string {
    for (const level of [2, 3, 4] as const) {
        if (editor.isActive('heading', { level })) {
            return String(level);
        }
    }

    return 'paragraph';
}

export function applyFormat(editor: Editor, value: string): void {
    if (value === 'paragraph') {
        editor.chain().focus().setParagraph().run();
    } else {
        editor
            .chain()
            .focus()
            .setHeading({ level: Number(value) as 2 | 3 | 4 })
            .run();
    }
}

/** Die Tabellenbefehle des Menüs — je mit Prüfung, ob er gerade möglich ist. */
export const tableActions: {
    key: string;
    label: string;
    run: (e: Editor) => void;
    can: (e: Editor) => boolean;
}[] = [
    {
        key: 'insert',
        get label() {
            return t('Tabelle einfügen (3 × 3 mit Kopfzeile)');
        },
        run: (e) =>
            e
                .chain()
                .focus()
                .insertTable({ rows: 3, cols: 3, withHeaderRow: true })
                .run(),
        can: (e) => !e.isActive('table'),
    },
    {
        key: 'rowBefore',
        get label() {
            return t('Zeile davor');
        },
        run: (e) => e.chain().focus().addRowBefore().run(),
        can: (e) => e.can().addRowBefore(),
    },
    {
        key: 'rowAfter',
        get label() {
            return t('Zeile danach');
        },
        run: (e) => e.chain().focus().addRowAfter().run(),
        can: (e) => e.can().addRowAfter(),
    },
    {
        key: 'colBefore',
        get label() {
            return t('Spalte davor');
        },
        run: (e) => e.chain().focus().addColumnBefore().run(),
        can: (e) => e.can().addColumnBefore(),
    },
    {
        key: 'colAfter',
        get label() {
            return t('Spalte danach');
        },
        run: (e) => e.chain().focus().addColumnAfter().run(),
        can: (e) => e.can().addColumnAfter(),
    },
    {
        key: 'deleteRow',
        get label() {
            return t('Zeile löschen');
        },
        run: (e) => e.chain().focus().deleteRow().run(),
        can: (e) => e.can().deleteRow(),
    },
    {
        key: 'deleteColumn',
        get label() {
            return t('Spalte löschen');
        },
        run: (e) => e.chain().focus().deleteColumn().run(),
        can: (e) => e.can().deleteColumn(),
    },
    {
        key: 'merge',
        get label() {
            return t('Zellen zusammenführen');
        },
        run: (e) => e.chain().focus().mergeCells().run(),
        can: (e) => e.can().mergeCells(),
    },
    {
        key: 'split',
        get label() {
            return t('Zelle teilen');
        },
        run: (e) => e.chain().focus().splitCell().run(),
        can: (e) => e.can().splitCell(),
    },
    {
        key: 'header',
        get label() {
            return t('Kopfzeile umschalten');
        },
        run: (e) => e.chain().focus().toggleHeaderRow().run(),
        can: (e) => e.can().toggleHeaderRow(),
    },
    {
        key: 'delete',
        get label() {
            return t('Tabelle löschen');
        },
        run: (e) => e.chain().focus().deleteTable().run(),
        can: (e) => e.can().deleteTable(),
    },
];
