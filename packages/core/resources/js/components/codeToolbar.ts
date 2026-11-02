import {
    redo,
    redoDepth,
    toggleComment,
    undo,
    undoDepth,
    indentLess,
    indentMore,
} from '@codemirror/commands';
import { foldAll, unfoldAll } from '@codemirror/language';
import { nextDiagnostic } from '@codemirror/lint';
import { gotoLine, openSearchPanel } from '@codemirror/search';
import type { EditorView } from '@codemirror/view';
import { t } from '../i18n';
import { icons } from './richtext/toolbar';

/**
 * Die Werkzeugleiste des grossen Code-Editors (TB 97): Werkzeuge als Daten wie beim Text-Editor — Symbol (16-px-
 * Strichpfad), Name, Kürzel (dieselben wie im Editor selbst, siehe LavaEditor.vue). `run` bekommt die Ansicht und den
 * Rest des Editors (Formatieren, Umbruch, Schriftgrösse, Prüfen), den CodeMirror selbst nicht kennt.
 */
export type CodeToolContext = {
    view: EditorView;
    format: () => void;
    toggleWrap: () => void;
    zoom: (step: number) => void;
    check: () => void;
    showKeys: () => void;
    wrap: boolean;
    canFormat: boolean;
    readonly: boolean;
};

export type CodeTool = {
    key: string;
    label: string;
    shortcut?: string;
    icon: string;
    run: (context: CodeToolContext) => void;
    disabled?: (context: CodeToolContext) => boolean;
    active?: (context: CodeToolContext) => boolean;
};

const codeIcons = {
    gotoLine: 'M2 8h8M7.5 5.5 10 8l-2.5 2.5M13.5 3v10',
    format: 'M2 3.5h12M5 6.5h9M5 9.5h9M2 12.5h12M2 6.5l1.5 1.5L2 9.5',
    comment: 'M6.5 3 4.5 13M11.5 3l-2 10',
    foldAll: 'M4 3l4 3.5L12 3M4 9.5l4 3.5 4-3.5',
    unfoldAll: 'M4 6.5 8 3l4 3.5M4 13l4-3.5 4 3.5',
    wrap: 'M2 4h12M2 8h9.5a2.25 2.25 0 0 1 0 4.5H8M9.5 11 8 12.5 9.5 14M2 12.5h3',
    smaller:
        'M7 11.5a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9zM10.3 10.3 14 14M5 7h4',
    larger: 'M7 11.5a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9zM10.3 10.3 14 14M5 7h4M7 5v4',
    check: 'M2.5 8.5l3.5 3.5 7.5-8',
    nextFinding: 'M8 2.5v7M8 12.5v.5M12 9.5l1.5 1.5-1.5 1.5',
    keys: 'M1.5 4h13v8h-13zM4 6.5h.01M6.5 6.5h.01M9 6.5h.01M11.5 6.5h.01M5 9.5h6',
};

const write = (context: CodeToolContext): boolean => context.readonly;

/** Die Gruppen der Leiste, von links nach rechts. */
export function codeTools(): CodeTool[][] {
    return [
        [
            {
                key: 'undo',
                label: t('Rückgängig'),
                shortcut: 'Mod+Z',
                icon: icons.undo,
                run: ({ view }) => void undo(view),
                disabled: ({ view, readonly }) =>
                    readonly || undoDepth(view.state) === 0,
            },
            {
                key: 'redo',
                label: t('Wiederholen'),
                shortcut: '⇧+Mod+Z',
                icon: icons.redo,
                run: ({ view }) => void redo(view),
                disabled: ({ view, readonly }) =>
                    readonly || redoDepth(view.state) === 0,
            },
        ],
        [
            {
                key: 'search',
                label: t('Suchen und ersetzen'),
                shortcut: 'Mod+F',
                icon: icons.search,
                run: ({ view }) => void openSearchPanel(view),
            },
            {
                key: 'gotoLine',
                label: t('Gehe zu Zeile'),
                shortcut: 'Mod+Alt+G',
                icon: codeIcons.gotoLine,
                run: ({ view }) => void gotoLine(view),
            },
        ],
        [
            {
                key: 'format',
                label: t('Formatieren'),
                shortcut: '⇧+Alt+F',
                icon: codeIcons.format,
                run: ({ format }) => format(),
                disabled: (context) => write(context) || !context.canFormat,
            },
            {
                key: 'indentMore',
                label: t('Einrücken'),
                shortcut: 'Mod+]',
                icon: icons.indent,
                run: ({ view }) => void indentMore(view),
                disabled: write,
            },
            {
                key: 'indentLess',
                label: t('Ausrücken'),
                shortcut: 'Mod+[',
                icon: icons.outdent,
                run: ({ view }) => void indentLess(view),
                disabled: write,
            },
            {
                key: 'comment',
                label: t('Kommentar ein/aus'),
                shortcut: 'Mod+/',
                icon: codeIcons.comment,
                run: ({ view }) => void toggleComment(view),
                disabled: write,
            },
        ],
        [
            {
                key: 'foldAll',
                label: t('Alles einklappen'),
                shortcut: 'Ctrl+Alt+[',
                icon: codeIcons.foldAll,
                run: ({ view }) => void foldAll(view),
            },
            {
                key: 'unfoldAll',
                label: t('Alles ausklappen'),
                shortcut: 'Ctrl+Alt+]',
                icon: codeIcons.unfoldAll,
                run: ({ view }) => void unfoldAll(view),
            },
            {
                key: 'wrap',
                label: t('Zeilen umbrechen'),
                shortcut: 'Alt+Z',
                icon: codeIcons.wrap,
                run: ({ toggleWrap }) => toggleWrap(),
                active: ({ wrap }) => wrap,
            },
            {
                key: 'smaller',
                label: t('Schrift kleiner'),
                icon: codeIcons.smaller,
                run: ({ zoom }) => zoom(-1),
            },
            {
                key: 'larger',
                label: t('Schrift grösser'),
                icon: codeIcons.larger,
                run: ({ zoom }) => zoom(1),
            },
        ],
        [
            {
                key: 'check',
                label: t('Jetzt prüfen'),
                icon: codeIcons.check,
                run: ({ check }) => check(),
            },
            {
                key: 'nextFinding',
                label: t('Nächster Befund'),
                shortcut: 'F8',
                icon: codeIcons.nextFinding,
                run: ({ view }) => void nextDiagnostic(view),
            },
            {
                key: 'keys',
                label: t('Tastenkürzel'),
                icon: codeIcons.keys,
                run: ({ showKeys }) => showKeys(),
            },
        ],
    ];
}

/** Kürzel, die nicht in der Leiste stehen, für die Übersicht «Tastenkürzel» — `mac`, wo der Mac andere Tasten nimmt. */
export function moreKeys(): {
    label: string;
    shortcut: string;
    mac?: string;
}[] {
    return [
        { label: t('Vorschläge'), shortcut: `Ctrl+${t('Leertaste')}` },
        { label: t('Zur Definition'), shortcut: 'F12' },
        { label: t('Alles auswählen'), shortcut: 'Mod+A' },
        { label: t('Zeile verschieben'), shortcut: 'Alt+↑/↓' },
        { label: t('Zeile kopieren'), shortcut: '⇧+Alt+↑/↓' },
        { label: t('Nächstes Vorkommen dazu wählen'), shortcut: 'Mod+D' },
        {
            label: t('Block einklappen'),
            shortcut: 'Ctrl+⇧+[',
            mac: 'Mod+Alt+[',
        },
        {
            label: t('Block ausklappen'),
            shortcut: 'Ctrl+⇧+]',
            mac: 'Mod+Alt+]',
        },
        { label: t('Editor schliessen'), shortcut: 'Esc' },
    ];
}
