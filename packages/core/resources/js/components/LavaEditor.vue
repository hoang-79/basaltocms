<script setup lang="ts">
import {
    autocompletion,
    closeBrackets,
    closeBracketsKeymap,
    completionKeymap,
} from '@codemirror/autocomplete';
import type {
    CompletionContext,
    CompletionResult,
} from '@codemirror/autocomplete';
import {
    defaultKeymap,
    history,
    historyKeymap,
    indentWithTab,
} from '@codemirror/commands';
import {
    bracketMatching,
    defaultHighlightStyle,
    foldGutter,
    foldKeymap,
    indentOnInput,
    indentUnit,
    syntaxHighlighting,
} from '@codemirror/language';
import { forceLinting, linter, lintGutter, lintKeymap } from '@codemirror/lint';
import type { Diagnostic } from '@codemirror/lint';
import { highlightSelectionMatches, searchKeymap } from '@codemirror/search';
import { Compartment, EditorState } from '@codemirror/state';
import type { Extension } from '@codemirror/state';
import {
    drawSelection,
    EditorView,
    highlightActiveLine,
    highlightActiveLineGutter,
    hoverTooltip,
    keymap,
    lineNumbers,
} from '@codemirror/view';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { postJson } from '../http';
import { t } from '../i18n';
import { formatChanges, formattable, indentFor } from './codeFormat';
import { kindOf, languageFor } from './codeLanguage';
import {
    completionType,
    plainMarkdown,
    rangeOf,
    severityOf,
} from './lavaEditor';
import type { CompletionItem, Finding } from './lavaEditor';

/**
 * Der Lava-Editor (L-17, Mockup 17 Bild 3): CodeMirror 6 mit Zeilennummern, Hervorhebung, Suche, und dem
 * Sprachdienst des Servers — Befunde am Text (nach jeder Pause), Vorschläge (Ctrl+Leertaste), Erklärung beim
 * Darüberfahren, F12 springt zur Definition. Geprüft wird das ganze Theme mit den Entwürfen und diesem Puffer.
 *
 * TB 97: Sprache nach Dateiendung (Lava, CSS, HTML/SVG, JSON, JavaScript), Einklappen, Formatieren (⇧⌥F), Umbruch
 * (⌥Z) und Schriftgrösse. `size="large"` füllt den grossen Editor im Dialog; `preview` zeigt den Text nur — ein Klick
 * oder Enter meldet `open` mit der Zeile (der Theme-Editor öffnet dann den grossen Editor).
 */
const props = withDefaults(
    defineProps<{
        modelValue: string;
        path: string;
        languageUrl: string;
        readonly?: boolean;
        label?: string;
        size?: 'normal' | 'large';
        preview?: boolean;
    }>(),
    {
        readonly: false,
        label: () => t('Lava-Quelltext'),
        size: 'normal',
        preview: false,
    },
);
const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    (e: 'findings', value: Finding[]): void;
    (e: 'definition', value: { file: string; line: number }): void;
    (e: 'open', line: number): void;
    (
        e: 'cursor',
        value: { line: number; column: number; selected: number },
    ): void;
    (e: 'message', value: string): void;
}>();

const host = ref<HTMLDivElement | null>(null);
let view: EditorView | null = null;

async function ask<T>(payload: Record<string, unknown>): Promise<T> {
    return postJson<T>(props.languageUrl, {
        path: props.path,
        lava_source: view?.state.doc.toString() ?? props.modelValue,
        ...payload,
    });
}

function position(pos: number): { line: number; character: number } {
    const line = view!.state.doc.lineAt(pos);

    return { line: line.number, character: pos - line.from };
}

async function lint(editor: EditorView): Promise<Diagnostic[]> {
    let findings: Finding[] = [];
    try {
        findings = (
            await ask<{ findings: Finding[] }>({ action: 'diagnostics' })
        ).findings;
    } catch {
        return [];
    }
    emit('findings', findings);
    const text = editor.state.doc.toString();

    return findings
        .filter((f) => f.file === props.path)
        .map((f) => ({
            ...rangeOf(text, f.line, f.character),
            severity: severityOf(f.severity),
            message: f.message,
            source: `${f.file}:${f.line}:${f.column}`,
        }));
}

async function complete(
    context: CompletionContext,
): Promise<CompletionResult | null> {
    const word = context.matchBefore(/[\w-]*/);
    if (!context.explicit && (!word || word.from === word.to)) {
        return null;
    }
    const at = position(context.pos);
    let items: CompletionItem[] = [];
    try {
        items = (
            await ask<{ items: CompletionItem[] }>({
                action: 'completions',
                ...at,
            })
        ).items;
    } catch {
        return null;
    }

    return {
        from: word?.from ?? context.pos,
        options: items.map((item) => ({
            label: item.label,
            type: completionType(item.kind),
            detail: item.detail,
            info: item.documentation
                ? plainMarkdown(item.documentation)
                : undefined,
        })),
        validFor: /^[\w-]*$/,
    };
}

const hover = hoverTooltip(async (_editor, pos) => {
    let markdown: string | null = null;
    try {
        markdown = (
            await ask<{ markdown: string | null }>({
                action: 'hover',
                ...position(pos),
            })
        ).markdown;
    } catch {
        return null;
    }
    if (!markdown) {
        return null;
    }

    return {
        pos,
        create: () => {
            const dom = document.createElement('div');
            dom.className = 'lava-hover';
            dom.textContent = plainMarkdown(markdown);

            return { dom };
        },
    };
});

async function goToDefinition(editor: EditorView): Promise<boolean> {
    const at = position(editor.state.selection.main.head);
    try {
        const { location } = await ask<{
            location: { file: string; line: number; column: number } | null;
        }>({ action: 'definition', ...at });
        if (!location) {
            return true;
        }
        if (location.file === props.path) {
            const line = editor.state.doc.line(
                Math.min(location.line, editor.state.doc.lines),
            );
            editor.dispatch({
                selection: { anchor: line.from },
                scrollIntoView: true,
            });
        } else {
            emit('definition', { file: location.file, line: location.line });
        }
    } catch {
        /* nichts gefunden */
    }

    return true;
}

const FONT_SIZES = [11, 12, 13, 14, 15, 16, 18, 20];
const fontSize = ref(props.size === 'large' ? 14 : 13);
const wrap = ref(false);
const language = new Compartment();
const indentation = new Compartment();
const looks = new Compartment();
const wrapping = new Compartment();

/** Aussehen: Rahmen, Schrift, Höhe — im grossen Editor füllt er die ganze Fläche. */
function theme(): Extension {
    const large = props.size === 'large';

    return EditorView.theme({
        '&': {
            fontSize: `${fontSize.value}px`,
            border: large ? 'none' : '1px solid var(--color-ash-light)',
            borderRadius: large ? '0' : '8px',
            backgroundColor: '#fff',
            height: large ? '100%' : 'auto',
        },
        '&.cm-focused': {
            outline: large ? 'none' : '2px solid var(--color-lava-dark)',
        },
        '.cm-scroller': {
            fontFamily: 'var(--font-mono)',
            minHeight: large ? '0' : '420px',
            maxHeight: large ? 'none' : '70vh',
            cursor: props.preview ? 'pointer' : 'auto',
        },
        '.cm-content': { cursor: props.preview ? 'pointer' : 'text' },
        '.cm-gutters': {
            backgroundColor: 'var(--color-paper)',
            color: 'var(--color-basalt-light)',
            borderRight: '1px solid var(--color-ash-light)',
        },
        '.lava-hover': {
            maxWidth: '420px',
            padding: '6px 8px',
            whiteSpace: 'pre-wrap',
            fontSize: '12.5px',
        },
        '.cm-panels': { fontSize: '13px' },
    });
}

/** Die Texte von CodeMirror (Suche, Befunde, Einklappen) in der Sprache der Oberfläche. */
function phrases(): Extension {
    return EditorState.phrases.of({
        Find: t('Suchen'),
        Replace: t('Ersetzen'),
        next: t('weiter'),
        previous: t('zurück'),
        all: t('alle'),
        'match case': t('Gross/klein'),
        regexp: t('Regulärer Ausdruck'),
        'by word': t('ganzes Wort'),
        replace: t('ersetzen'),
        'replace all': t('alle ersetzen'),
        close: t('Schliessen'),
        'current match': t('aktueller Treffer'),
        'on line': t('in Zeile'),
        'replaced match on line $': t('Treffer in Zeile $ ersetzt'),
        'replaced $ matches': t('$ Treffer ersetzt'),
        'Go to line': t('Gehe zu Zeile'),
        go: t('los'),
        Diagnostics: t('Befunde'),
        'No diagnostics': t('Keine Befunde'),
        'Fold line': t('Einklappen'),
        'Unfold line': t('Ausklappen'),
        'Folded lines': t('Eingeklappte Zeilen'),
        'folded code': t('eingeklappter Code'),
        unfold: t('ausklappen'),
        to: t('bis'),
        Completions: t('Vorschläge'),
        'Selection deleted': t('Auswahl gelöscht'),
        'Control character': t('Steuerzeichen'),
    });
}

/** Die ganze Datei formatieren — ein Schritt, den «Rückgängig» wieder aufhebt. */
function format(): void {
    if (!view || props.readonly || props.preview) {
        return;
    }
    const result = formatChanges(view.state, kindOf(props.path));
    if (result.error) {
        emit('message', result.error);

        return;
    }
    if (result.changes === null) {
        emit('message', t('Schon formatiert.'));

        return;
    }
    view.dispatch({
        changes: result.changes,
        userEvent: 'format',
        scrollIntoView: true,
    });
    emit('message', t('Formatiert.'));
}

function toggleWrap(): void {
    wrap.value = !wrap.value;
    view?.dispatch({
        effects: wrapping.reconfigure(
            wrap.value ? EditorView.lineWrapping : [],
        ),
    });
}

/** Schrift einen Schritt kleiner (−1) oder grösser (+1). */
function zoom(step: number): void {
    const index = FONT_SIZES.indexOf(fontSize.value);
    const next =
        FONT_SIZES[
            Math.min(FONT_SIZES.length - 1, Math.max(0, index + step))
        ] ?? fontSize.value;
    fontSize.value = next;
    view?.dispatch({ effects: looks.reconfigure(theme()) });
}

/** Im Vorschaumodus: Klick oder Enter öffnet den grossen Editor an dieser Zeile. */
const opener = EditorView.domEventHandlers({
    mousedown: (event, editor) => {
        const pos = editor.posAtCoords({ x: event.clientX, y: event.clientY });
        emit('open', pos === null ? 1 : editor.state.doc.lineAt(pos).number);

        return true;
    },
    keydown: (event, editor) => {
        if (event.key !== 'Enter') {
            return false;
        }
        event.preventDefault();
        emit(
            'open',
            editor.state.doc.lineAt(editor.state.selection.main.head).number,
        );

        return true;
    },
});

function editing(): Extension[] {
    if (props.preview) {
        return [opener];
    }

    return [
        lintGutter(),
        linter(lint, { delay: 700 }),
        autocompletion({ override: [complete] }),
        hover,
        keymap.of([
            {
                key: 'F12',
                run: (editor) => (void goToDefinition(editor), true),
            },
            { key: 'Shift-Alt-f', run: () => (format(), true) },
            { key: 'Alt-z', run: () => (toggleWrap(), true) },
            ...closeBracketsKeymap,
            ...defaultKeymap,
            ...searchKeymap,
            ...historyKeymap,
            ...foldKeymap,
            ...completionKeymap,
            ...lintKeymap,
            indentWithTab,
        ]),
    ];
}

/** Sprache und Einrückung der Datei setzen (beim Wechsel der Datei). */
function applyLanguage(): void {
    view?.dispatch({
        effects: [
            language.reconfigure(languageFor(props.path)),
            indentation.reconfigure(
                indentUnit.of(
                    indentFor(kindOf(props.path), view.state.doc.toString()),
                ),
            ),
        ],
    });
}

onMounted(() => {
    view = new EditorView({
        parent: host.value!,
        state: EditorState.create({
            doc: props.modelValue,
            extensions: [
                lineNumbers(),
                foldGutter(),
                highlightActiveLineGutter(),
                history(),
                drawSelection(),
                indentOnInput(),
                bracketMatching(),
                closeBrackets(),
                highlightActiveLine(),
                highlightSelectionMatches(),
                syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
                language.of(languageFor(props.path)),
                indentation.of(
                    indentUnit.of(
                        indentFor(kindOf(props.path), props.modelValue),
                    ),
                ),
                ...editing(),
                phrases(),
                EditorState.readOnly.of(props.readonly || props.preview),
                EditorView.contentAttributes.of({
                    'aria-label': props.preview
                        ? `${props.label} — ${t('Enter öffnet den Editor')}`
                        : props.label,
                }),
                EditorView.updateListener.of((update) => {
                    if (update.docChanged) {
                        emit('update:modelValue', update.state.doc.toString());
                    }
                    if (update.docChanged || update.selectionSet) {
                        const head = update.state.selection.main;
                        const line = update.state.doc.lineAt(head.head);
                        emit('cursor', {
                            line: line.number,
                            column: head.head - line.from + 1,
                            selected: head.to - head.from,
                        });
                    }
                }),
                wrapping.of([]),
                looks.of(theme()),
            ],
        }),
    });
});

watch(
    () => props.path,
    () => applyLanguage(),
);

watch(
    () => props.modelValue,
    (value) => {
        if (view && value !== view.state.doc.toString()) {
            view.dispatch({
                changes: { from: 0, to: view.state.doc.length, insert: value },
            });
        }
    },
);

/** Sofort prüfen (Knopf «Prüfen»). Die Vorschau hat keinen Linter: Sie fragt den Sprachdienst direkt. */
function check(): void {
    if (!view) {
        return;
    }
    if (props.preview) {
        void lint(view);

        return;
    }
    forceLinting(view);
}

/** Zu einer Zeile springen (Befundliste, Definition in einer anderen Datei). */
function goToLine(line: number): void {
    if (!view) {
        return;
    }
    const target = view.state.doc.line(
        Math.min(Math.max(line, 1), view.state.doc.lines),
    );
    view.dispatch({ selection: { anchor: target.from }, scrollIntoView: true });
    view.focus();
}

/** Die Ansicht für die Werkzeugleiste des grossen Editors. */
function editorView(): EditorView | null {
    return view;
}

defineExpose({
    check,
    goToLine,
    format,
    toggleWrap,
    zoom,
    editorView,
    wrap,
    fontSize,
    canFormat: () => formattable(kindOf(props.path)),
});

onBeforeUnmount(() => view?.destroy());
</script>

<template>
    <div
        ref="host"
        class="lava-editor"
        :class="size === 'large' ? 'h-full min-h-0' : ''"
    />
</template>
