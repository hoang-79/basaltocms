<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { t } from '../i18n';
import Button from './Button.vue';
import { indentFor, indentLabel } from './codeFormat';
import { codeTools, moreKeys } from './codeToolbar';
import type { CodeTool, CodeToolContext } from './codeToolbar';
import { kindLabel, kindOf } from './codeLanguage';
import LavaEditor from './LavaEditor.vue';
import { findingsText } from './lavaEditor';
import type { Finding } from './lavaEditor';
import Modal from './Modal.vue';
import { isMac, shortcut } from './richtext/toolbar';

/**
 * Der grosse Code-Editor (TB 97): ein Dialog fast über den ganzen Bildschirm, der Hintergrund unscharf. Oben die
 * Werkzeugleiste (Rückgängig, Suchen und Ersetzen, Gehe zu Zeile, Formatieren, Ein-/Ausrücken, Kommentar, Ein-/
 * Ausklappen, Umbruch, Schriftgrösse, Prüfen, nächster Befund, Tastenkürzel), unten die Statuszeile mit Position,
 * Sprache, Befunden und dem Stand des Speicherns. Der Text ist derselbe wie auf der Seite (v-model): Sie speichert ihn
 * wie bisher von selbst in den Entwurf.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        path: string;
        modelValue: string;
        languageUrl: string;
        subtitle?: string;
        saveState?: string;
        findings?: Finding[];
        line?: number | null;
    }>(),
    { subtitle: '', saveState: '', findings: () => [], line: null },
);
const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    (e: 'close'): void;
    (e: 'findings', value: Finding[]): void;
    (e: 'definition', value: { file: string; line: number }): void;
}>();

const editor = ref<InstanceType<typeof LavaEditor> | null>(null);
const cursor = ref({ line: 1, column: 1, selected: 0 });
const message = ref('');
const keysOpen = ref(false);
/** Zählt jede Änderung im Editor — die Knöpfe (Rückgängig, Wiederholen) rechnen danach neu. */
const tick = ref(0);

const kind = computed(() => kindOf(props.path));
/** Die Einrückung, die der Editor beim Öffnen aus der Datei abgelesen hat. */
const indent = ref(indentLabel(indentFor(kind.value, props.modelValue)));
const groups = codeTools();
const buttonClass =
    'inline-flex h-8 w-8 items-center justify-center rounded text-basalt-light hover:bg-ash-light hover:text-basalt disabled:cursor-not-allowed disabled:opacity-35 disabled:hover:bg-transparent';

function context(): CodeToolContext | null {
    void tick.value;
    const view = editor.value?.editorView() ?? null;
    if (!view || !editor.value) {
        return null;
    }

    return {
        view,
        format: () => editor.value?.format(),
        toggleWrap: () => editor.value?.toggleWrap(),
        zoom: (step) => editor.value?.zoom(step),
        check: () => editor.value?.check(),
        showKeys: () => (keysOpen.value = !keysOpen.value),
        wrap: editor.value.wrap,
        canFormat: editor.value.canFormat(),
        readonly: false,
    };
}

function title(tool: CodeTool): string {
    return tool.shortcut
        ? `${tool.label} (${shortcut(tool.shortcut)})`
        : tool.label;
}

function press(tool: CodeTool): void {
    const ctx = context();
    if (!ctx) {
        return;
    }
    message.value = '';
    tool.run(ctx);
    if (tool.key !== 'keys') {
        ctx.view.focus();
    }
    tick.value++;
}

const fileFindings = computed(() =>
    props.findings.filter((f) => f.file === props.path),
);
const findingsSummary = computed(() => findingsText(fileFindings.value));

const keyList = computed(() => [
    ...groups
        .flat()
        .filter((tool) => tool.shortcut)
        .map((tool) => ({
            label: tool.label,
            keys: shortcut(tool.shortcut ?? ''),
        })),
    ...moreKeys().map((entry) => ({
        label: entry.label,
        keys: shortcut(isMac && entry.mac ? entry.mac : entry.shortcut),
    })),
]);

watch(
    () => props.open,
    async (open) => {
        if (!open) {
            keysOpen.value = false;
            message.value = '';

            return;
        }
        indent.value = indentLabel(indentFor(kind.value, props.modelValue));
        await nextTick();
        if (props.line !== null) {
            editor.value?.goToLine(props.line);
        }
        tick.value++;
    },
);

function onCursor(value: {
    line: number;
    column: number;
    selected: number;
}): void {
    cursor.value = value;
    tick.value++;
}
</script>

<template>
    <Modal
        :open="open"
        :title="path"
        :subtitle="subtitle"
        full
        @close="emit('close')"
    >
        <div
            class="flex flex-wrap items-center gap-0.5 border-b border-ash-light bg-paper px-2 py-1"
            role="toolbar"
            :aria-label="$t('Code-Werkzeuge')"
        >
            <template v-for="(group, g) in groups" :key="g">
                <span
                    v-if="g > 0"
                    class="mx-1 h-5 w-px bg-ash-light"
                    aria-hidden="true"
                />
                <button
                    v-for="tool in group"
                    :key="tool.key"
                    type="button"
                    :class="[
                        buttonClass,
                        (context() && tool.active?.(context()!)) ||
                        (tool.key === 'keys' && keysOpen)
                            ? 'bg-basalt text-paper hover:bg-basalt hover:text-paper'
                            : '',
                    ]"
                    :title="title(tool)"
                    :aria-label="title(tool)"
                    :aria-pressed="
                        tool.active && context()
                            ? tool.active(context()!)
                            : tool.key === 'keys'
                              ? keysOpen
                              : undefined
                    "
                    :disabled="
                        context() === null ||
                        (tool.disabled?.(context()!) ?? false)
                    "
                    @mousedown.prevent
                    @click="press(tool)"
                >
                    <svg
                        viewBox="0 0 16 16"
                        class="h-4 w-4"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path :d="tool.icon" />
                    </svg>
                </button>
            </template>
            <span
                class="ml-auto pr-1 text-[12px] text-basalt-light"
                role="status"
                aria-live="polite"
                >{{ message }}</span
            >
        </div>

        <div
            v-if="keysOpen"
            class="border-b border-ash-light px-4 py-3"
            role="region"
            :aria-label="$t('Tastenkürzel')"
        >
            <dl
                class="grid gap-x-6 gap-y-1 text-[12.5px] sm:grid-cols-2 lg:grid-cols-3"
            >
                <div
                    v-for="entry in keyList"
                    :key="entry.label"
                    class="flex justify-between gap-3"
                >
                    <dt>{{ entry.label }}</dt>
                    <dd class="font-mono text-basalt-light">
                        {{ entry.keys }}
                    </dd>
                </div>
            </dl>
        </div>

        <div class="min-h-0 flex-1">
            <LavaEditor
                v-if="open"
                ref="editor"
                :model-value="modelValue"
                :path="path"
                :language-url="languageUrl"
                :label="$t('Quelltext :path', { path })"
                size="large"
                @update:model-value="emit('update:modelValue', $event)"
                @findings="emit('findings', $event)"
                @definition="emit('definition', $event)"
                @cursor="onCursor"
                @message="message = $event"
            />
        </div>

        <div
            class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-ash-light bg-paper px-4 py-1.5 text-[12px] text-basalt-light"
        >
            <span>{{
                cursor.selected > 0
                    ? $t('Zeile :line, Spalte :column (:selected ausgewählt)', {
                          line: cursor.line,
                          column: cursor.column,
                          selected: cursor.selected,
                      })
                    : $t('Zeile :line, Spalte :column', {
                          line: cursor.line,
                          column: cursor.column,
                      })
            }}</span>
            <span>{{ kindLabel(kind) }}</span>
            <span>{{ indent }}</span>
            <span
                :class="
                    fileFindings.length > 0 ? 'text-danger font-semibold' : ''
                "
                >{{ findingsSummary }}</span
            >
            <span class="ml-auto" role="status" aria-live="polite">{{
                saveState
            }}</span>
            <Button size="sm" @click="emit('close')">{{ $t('Fertig') }}</Button>
        </div>
    </Modal>
</template>
