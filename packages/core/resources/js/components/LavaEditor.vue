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
    indentOnInput,
    syntaxHighlighting,
} from '@codemirror/language';
import { forceLinting, linter, lintGutter, lintKeymap } from '@codemirror/lint';
import type { Diagnostic } from '@codemirror/lint';
import { highlightSelectionMatches, searchKeymap } from '@codemirror/search';
import { EditorState } from '@codemirror/state';
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
import {
    completionType,
    plainMarkdown,
    rangeOf,
    severityOf,
} from './lavaEditor';
import type { CompletionItem, Finding } from './lavaEditor';
import { lava } from './lavaLanguage';

/**
 * Der Lava-Editor (L-17, Mockup 17 Bild 3): CodeMirror 6 mit Zeilennummern, Hervorhebung, Suche, und dem
 * Sprachdienst des Servers — Befunde am Text (nach jeder Pause), Vorschläge (Ctrl+Leertaste), Erklärung beim
 * Darüberfahren, F12 springt zur Definition. Geprüft wird das ganze Theme mit den Entwürfen und diesem Puffer.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string;
        path: string;
        languageUrl: string;
        readonly?: boolean;
        label?: string;
    }>(),
    { readonly: false, label: () => t('Lava-Quelltext') },
);
const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    (e: 'findings', value: Finding[]): void;
    (e: 'definition', value: { file: string; line: number }): void;
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

const theme = EditorView.theme({
    '&': {
        fontSize: '13px',
        border: '1px solid var(--color-ash-light)',
        borderRadius: '8px',
        backgroundColor: '#fff',
    },
    '&.cm-focused': { outline: '2px solid var(--color-lava-dark)' },
    '.cm-scroller': {
        fontFamily: 'var(--font-mono)',
        minHeight: '420px',
        maxHeight: '70vh',
    },
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
});

onMounted(() => {
    view = new EditorView({
        parent: host.value!,
        state: EditorState.create({
            doc: props.modelValue,
            extensions: [
                lineNumbers(),
                highlightActiveLineGutter(),
                history(),
                drawSelection(),
                indentOnInput(),
                bracketMatching(),
                closeBrackets(),
                highlightActiveLine(),
                highlightSelectionMatches(),
                syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
                lava,
                lintGutter(),
                linter(lint, { delay: 700 }),
                autocompletion({ override: [complete] }),
                hover,
                keymap.of([
                    {
                        key: 'F12',
                        run: (editor) => (void goToDefinition(editor), true),
                    },
                    ...closeBracketsKeymap,
                    ...defaultKeymap,
                    ...searchKeymap,
                    ...historyKeymap,
                    ...completionKeymap,
                    ...lintKeymap,
                    indentWithTab,
                ]),
                EditorState.readOnly.of(props.readonly),
                EditorView.contentAttributes.of({ 'aria-label': props.label }),
                EditorView.updateListener.of((update) => {
                    if (update.docChanged) {
                        emit('update:modelValue', update.state.doc.toString());
                    }
                }),
                theme,
            ],
        }),
    });
});

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

/** Sofort prüfen (Knopf «Prüfen»). */
function check(): void {
    if (view) {
        forceLinting(view);
    }
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

defineExpose({ check, goToLine });

onBeforeUnmount(() => view?.destroy());
</script>

<template>
    <div ref="host" class="lava-editor" />
</template>
