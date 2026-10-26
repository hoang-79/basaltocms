<script setup lang="ts">
import type { Editor } from '@tiptap/core';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { t } from '../i18n';
import type { FieldValue, MediaOption } from '../types';
import MediaPicker from './MediaPicker.vue';
import { controlClasses } from './control';
import { uploaderFor } from './media';
import { toDoc } from './richtext/doc';
import { embedLabels, parseEmbedUrl } from './richtext/embed';
import {
    codeLanguages,
    richTextExtensions,
    specialCharacters,
} from './richtext/extensions';
import { pasteHandlers } from './richtext/paste';
import {
    applyFormat,
    currentFormat,
    headingOptions,
    row1,
    row2,
    shortcut,
    tableActions,
    type PanelKey,
    type Tool,
    type ToolbarItem,
} from './richtext/toolbar';
import { vDialog } from './a11y';

/**
 * Der Fliesstext-Editor (M-04, E-34): TipTap mit der vollständigen Toolbar in zwei Reihen (sie brechen um,
 * nichts scrollt waagrecht), Dialogen unter der Leiste (Link, Tabelle, Bild aus der Mediathek, Einbettung,
 * Sonderzeichen, Suchen und Ersetzen), Quelltext-Ansicht nur lesend, Zähler — und verlustfreiem Einfügen
 * aus Word, Google Docs, Web und Markdown, Bilder landen über `pasteUrl` in der Mediathek.
 * Der Wert ist das ProseMirror-JSON; der Server kennt genau diese Knoten und Marken.
 */
const props = withDefaults(
    defineProps<{
        modelValue: FieldValue | undefined;
        media?: MediaOption[];
        pasteUrl?: string;
        placeholder?: string;
        /** Name des Eingabefelds für Screenreader (WCAG 4.1.2) — das Label des Felds */
        label?: string;
    }>(),
    {
        media: () => [],
        pasteUrl: '',
        placeholder: '',
        label: '',
    },
);
const emit = defineEmits<{
    (e: 'update:modelValue', value: FieldValue): void;
}>();

const panel = ref<PanelKey | null>(null);
const notice = ref('');
const characters = ref(0);
const words = ref(0);
const hits = ref(0);
const hitIndex = ref(0);
const sourceMode = ref<'json' | 'html'>('json');
const sourceJson = ref('');
const sourceHtml = ref('');
let lastEmitted = '';

const emptyDoc = { type: 'doc', content: [{ type: 'paragraph' }] };
const uploader = uploaderFor(props.pasteUrl);
const paste = pasteHandlers(
    () => editor.value,
    uploader,
    (message) => (notice.value = message),
);

const editor = useEditor({
    content: toDoc(props.modelValue) ?? emptyDoc,
    extensions: richTextExtensions({
        placeholder: props.placeholder || t('Text schreiben …'),
        onLink: () => openPanel('link'),
    }),
    editorProps: {
        attributes: {
            class: 'rte-content min-h-36 px-3 py-2.5 text-[13.5px] leading-relaxed focus:outline-none',
            'aria-multiline': 'true',
            'aria-label': props.label || t('Fliesstext'),
        },
        transformPastedHTML: paste.transformPastedHTML,
        clipboardTextParser: paste.clipboardTextParser,
        handlePaste: paste.handlePaste,
        handleDrop: paste.handleDrop,
    },
    onCreate: ({ editor: e }) => count(e),
    onUpdate: ({ editor: e }) => {
        const json = e.getJSON();
        lastEmitted = JSON.stringify(json);
        emit('update:modelValue', json as FieldValue);
        count(e);
        if (panel.value === 'source') {
            refreshSource(e);
        }
    },
    onTransaction: ({ editor: e }) => {
        hits.value = e.storage.searchReplace.hits.length;
        hitIndex.value = e.storage.searchReplace.index;
    },
});

function count(e: Editor): void {
    characters.value = e.storage.characterCount.characters();
    words.value = e.storage.characterCount.words();
}

watch(
    () => props.modelValue,
    (value) => {
        const next = JSON.stringify(toDoc(value) ?? emptyDoc);
        if (
            editor.value &&
            next !== lastEmitted &&
            next !== JSON.stringify(editor.value.getJSON())
        ) {
            editor.value.commands.setContent(toDoc(value) ?? emptyDoc, {
                emitUpdate: false,
            });
            lastEmitted = next;
            count(editor.value);
        }
    },
);

onBeforeUnmount(() => editor.value?.destroy());

function isTool(item: ToolbarItem): item is Tool {
    return 'icon' in item;
}

function title(tool: Tool): string {
    return tool.shortcut
        ? `${tool.label} (${shortcut(tool.shortcut)})`
        : tool.label;
}

function press(tool: Tool): void {
    if (!editor.value) {
        return;
    }
    if (tool.panel) {
        openPanel(tool.panel);
    } else {
        tool.run?.(editor.value);
    }
}

/* ── Dialoge ── */

const linkHref = ref('');
const linkTarget = ref('');
const linkTitle = ref('');
const imageId = ref<number | null>(null);
const imageUrl = ref('');
const imageAlt = ref('');
const imageCaption = ref('');
const imageAlign = ref<'left' | 'center' | 'right' | ''>('');
const embedUrl = ref('');
const searchTerm = ref('');
const replaceWith = ref('');
/** Die Auswahl beim Öffnen eines Dialogs — die Eingabefelder nehmen den Fokus, der Editor bekommt sie zurück. */
let savedSelection: { from: number; to: number } | null = null;

function restoreSelection(e: Editor): void {
    if (savedSelection && savedSelection.to <= e.state.doc.content.size) {
        e.commands.setTextSelection(savedSelection);
    }
}

function openPanel(key: PanelKey): void {
    const e = editor.value;
    if (!e) {
        return;
    }
    if (panel.value === key) {
        closePanel();

        return;
    }
    panel.value = key;
    notice.value = '';
    savedSelection = { from: e.state.selection.from, to: e.state.selection.to };
    if (key === 'link') {
        const attrs = e.getAttributes('link');
        linkHref.value = String(attrs.href ?? '');
        linkTarget.value = String(attrs.target ?? '');
        linkTitle.value = String(attrs.title ?? '');
    } else if (key === 'image') {
        const attrs = e.isActive('image') ? e.getAttributes('image') : {};
        imageId.value =
            typeof attrs.mediaId === 'number' ? attrs.mediaId : null;
        imageUrl.value = String(attrs.src ?? '');
        imageAlt.value = String(attrs.alt ?? '');
        imageCaption.value = String(attrs.caption ?? '');
        imageAlign.value = (attrs.align as typeof imageAlign.value) ?? '';
    } else if (key === 'embed') {
        embedUrl.value = String(
            e.getAttributes('youtube').src ??
                e.getAttributes('embed').src ??
                '',
        );
    } else if (key === 'search') {
        if (searchTerm.value) {
            e.commands.setSearchTerm(searchTerm.value);
        }
    } else if (key === 'source') {
        refreshSource(e);
    }
}

function closePanel(): void {
    if (panel.value === 'search') {
        editor.value?.commands.clearSearch();
    }
    panel.value = null;
    editor.value?.commands.focus();
}

function applyLink(): void {
    const e = editor.value;
    if (!e) {
        return;
    }
    const href = linkHref.value.trim();
    if (href === '') {
        removeLink();

        return;
    }
    const attrs = {
        href,
        target: linkTarget.value || null,
        title: linkTitle.value.trim() || null,
    };
    restoreSelection(e);
    if (e.state.selection.empty && !e.isActive('link')) {
        e.chain()
            .focus()
            .insertContent({
                type: 'text',
                text: href,
                marks: [{ type: 'link', attrs }],
            })
            .run();
    } else {
        e.chain().focus().extendMarkRange('link').setLink(attrs).run();
    }
    panel.value = null;
}

function removeLink(): void {
    if (editor.value) {
        restoreSelection(editor.value);
    }
    editor.value?.chain().focus().extendMarkRange('link').unsetLink().run();
    panel.value = null;
}

function pickImage(item: MediaOption): void {
    imageId.value = item.id;
    imageUrl.value = item.url ?? '';
    if (imageAlt.value === '') {
        imageAlt.value = item.name.replace(/\.[^.]+$/, '');
    }
}

function applyImage(): void {
    const e = editor.value;
    if (!e || imageId.value === null) {
        return;
    }
    const attrs = {
        src: imageUrl.value,
        alt: imageAlt.value.trim() || null,
        mediaId: imageId.value,
        caption: imageCaption.value.trim() || null,
        align: imageAlign.value || null,
    };
    restoreSelection(e);
    if (e.isActive('image')) {
        e.chain().focus().updateAttributes('image', attrs).run();
    } else {
        e.chain().focus().insertContent({ type: 'image', attrs }).run();
    }
    panel.value = null;
}

const embedTarget = computed(() => parseEmbedUrl(embedUrl.value));

function applyEmbed(): void {
    const e = editor.value;
    const target = embedTarget.value;
    if (!e || !target) {
        return;
    }
    restoreSelection(e);
    if (target.kind === 'youtube') {
        e.chain().focus().setYoutubeVideo({ src: target.src }).run();
    } else {
        e.chain()
            .focus()
            .setEmbed({ provider: target.provider, src: target.src })
            .run();
    }
    panel.value = null;
}

function insertChar(char: string): void {
    editor.value?.chain().focus().insertContent(char).run();
}

watch(searchTerm, (term) => {
    if (panel.value === 'search') {
        editor.value?.commands.setSearchTerm(term);
    }
});

function refreshSource(e: Editor): void {
    sourceJson.value = JSON.stringify(e.getJSON(), null, 2);
    sourceHtml.value = e.getHTML().replace(/></g, '>\n<');
}

const codeLanguage = computed(() =>
    String(editor.value?.getAttributes('codeBlock').language ?? ''),
);

function setCodeLanguage(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    editor.value
        ?.chain()
        .focus()
        .updateAttributes('codeBlock', { language: value || null })
        .run();
}

const buttonClass =
    'inline-flex h-[26px] w-[26px] items-center justify-center rounded text-basalt-light hover:bg-ash-light hover:text-basalt disabled:cursor-not-allowed disabled:opacity-35 disabled:hover:bg-transparent';
const panelButton =
    'rounded-md border border-ash bg-white px-2.5 py-1 text-xs font-semibold text-basalt hover:bg-paper disabled:text-ash disabled:hover:bg-white';
const primaryButton =
    'rounded-md border border-transparent bg-lava-dark px-2.5 py-1 text-xs font-semibold text-white hover:brightness-90 disabled:bg-ash-light disabled:text-ash';
</script>

<template>
    <div
        class="rte overflow-hidden rounded-md border border-ash bg-white focus-within:border-lava-dark focus-within:ring-2 focus-within:ring-lava/20"
    >
        <div v-if="editor" class="border-b border-ash-light bg-paper">
            <div
                v-for="(row, r) in [row1, row2]"
                :key="r"
                class="flex flex-wrap items-center gap-y-0.5 px-1.5 py-1"
                :class="{ 'border-t border-dashed border-ash-light': r === 1 }"
                role="toolbar"
                :aria-label="
                    r === 0
                        ? $t('Textwerkzeuge, Reihe 1')
                        : $t('Textwerkzeuge, Reihe 2')
                "
            >
                <template v-for="(group, g) in row" :key="g">
                    <span
                        v-if="g > 0"
                        class="mx-1 h-4 w-px bg-ash-light"
                        aria-hidden="true"
                    />
                    <template v-for="item in group" :key="item.key">
                        <select
                            v-if="item.key === 'format'"
                            :value="currentFormat(editor)"
                            class="h-[26px] rounded border border-ash-light bg-white px-1 text-[11.5px] text-basalt"
                            :aria-label="$t('Absatzformat')"
                            :title="
                                $t(
                                    'Absatzformat (⌥⌘0 Absatz, ⌥⌘2–4 Überschrift)',
                                )
                            "
                            @change="
                                applyFormat(
                                    editor,
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option
                                v-for="o in headingOptions"
                                :key="o.value"
                                :value="o.value"
                            >
                                {{ o.label }}
                            </option>
                        </select>
                        <select
                            v-else-if="item.key === 'codeLanguage'"
                            v-show="editor.isActive('codeBlock')"
                            :value="codeLanguage"
                            class="ml-0.5 h-[26px] rounded border border-ash-light bg-white px-1 font-mono text-[11px] text-basalt"
                            :aria-label="$t('Sprache des Codeblocks')"
                            :title="$t('Sprache des Codeblocks')"
                            @change="setCodeLanguage"
                        >
                            <option
                                v-for="l in codeLanguages"
                                :key="l.value"
                                :value="l.value"
                            >
                                {{ l.label }}
                            </option>
                        </select>
                        <button
                            v-else-if="isTool(item)"
                            type="button"
                            :class="[
                                buttonClass,
                                (item.active?.(editor) ?? false) ||
                                panel === item.panel
                                    ? 'bg-basalt text-paper hover:bg-basalt hover:text-paper'
                                    : '',
                            ]"
                            :title="title(item)"
                            :aria-label="title(item)"
                            :aria-pressed="
                                item.active ? item.active(editor) : undefined
                            "
                            :aria-expanded="
                                item.panel ? panel === item.panel : undefined
                            "
                            :disabled="item.disabled?.(editor) ?? false"
                            @mousedown.prevent
                            @click="press(item)"
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
                                <path :d="item.icon" />
                            </svg>
                        </button>
                    </template>
                </template>
            </div>

            <!-- Dialoge unter der Leiste -->
            <div
                v-if="panel === 'link'"
                class="border-t border-ash-light px-3 py-2.5"
                v-dialog="{ close: closePanel, modal: false }"
                role="dialog"
                :aria-label="$t('Link')"
            >
                <div class="grid gap-2 md:grid-cols-[1fr_auto_1fr]">
                    <input
                        v-model="linkHref"
                        type="text"
                        :class="controlClasses({ small: true, mono: true })"
                        :placeholder="$t('https://…, mailto:, / oder #')"
                        :aria-label="$t('Adresse')"
                        @keydown.enter.prevent="applyLink"
                    />
                    <select
                        v-model="linkTarget"
                        :class="controlClasses({ small: true })"
                        :aria-label="$t('Ziel')"
                    >
                        <option value="">{{ $t('gleiches Fenster') }}</option>
                        <option value="_blank">
                            {{ $t('neues Fenster') }}
                        </option>
                    </select>
                    <input
                        v-model="linkTitle"
                        type="text"
                        :class="controlClasses({ small: true })"
                        :placeholder="$t('Titel (Tooltip, freiwillig)')"
                        :aria-label="$t('Titel')"
                        @keydown.enter.prevent="applyLink"
                    />
                </div>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <button
                        type="button"
                        :class="primaryButton"
                        @click="applyLink"
                    >
                        {{ $t('Anwenden') }}
                    </button>
                    <button
                        v-if="editor.isActive('link')"
                        type="button"
                        :class="panelButton"
                        @click="removeLink"
                    >
                        {{ $t('Link entfernen') }}
                    </button>
                    <button
                        type="button"
                        :class="panelButton"
                        @click="closePanel"
                    >
                        {{ $t('Abbrechen') }}
                    </button>
                </div>
            </div>

            <div
                v-else-if="panel === 'table'"
                class="flex flex-wrap gap-1.5 border-t border-ash-light px-3 py-2.5"
                role="menu"
                :aria-label="$t('Tabelle')"
            >
                <button
                    v-for="a in tableActions"
                    :key="a.key"
                    type="button"
                    role="menuitem"
                    :class="panelButton"
                    :disabled="!a.can(editor)"
                    @click="
                        a.run(editor);
                        a.key === 'insert' && closePanel();
                    "
                >
                    {{ a.label }}
                </button>
                <button
                    type="button"
                    :class="[panelButton, 'ml-auto']"
                    @click="closePanel"
                >
                    {{ $t('Schliessen') }}
                </button>
            </div>

            <div
                v-else-if="panel === 'image'"
                class="border-t border-ash-light px-3 py-2.5"
                v-dialog="{ close: closePanel, modal: false }"
                role="dialog"
                :aria-label="$t('Bild aus der Mediathek')"
            >
                <MediaPicker
                    :media="media"
                    :paste-url="pasteUrl"
                    :model-value="imageId"
                    @pick="pickImage"
                />
                <div class="mt-2 grid gap-2 md:grid-cols-[1fr_1fr_auto]">
                    <input
                        v-model="imageAlt"
                        type="text"
                        :class="controlClasses({ small: true })"
                        :placeholder="$t('Alt-Text (beschreibt das Bild)')"
                        :aria-label="$t('Alt-Text')"
                    />
                    <input
                        v-model="imageCaption"
                        type="text"
                        :class="controlClasses({ small: true })"
                        :placeholder="$t('Beschriftung (freiwillig)')"
                        :aria-label="$t('Beschriftung')"
                    />
                    <select
                        v-model="imageAlign"
                        :class="controlClasses({ small: true })"
                        :aria-label="$t('Ausrichtung')"
                    >
                        <option value="">
                            {{ $t('Ausrichtung: Fliesstext') }}
                        </option>
                        <option value="left">{{ $t('links') }}</option>
                        <option value="center">{{ $t('mitte') }}</option>
                        <option value="right">{{ $t('rechts') }}</option>
                    </select>
                </div>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <button
                        type="button"
                        :class="primaryButton"
                        :disabled="imageId === null"
                        @click="applyImage"
                    >
                        {{
                            editor.isActive('image')
                                ? $t('Anwenden')
                                : $t('Einfügen')
                        }}
                    </button>
                    <button
                        type="button"
                        :class="panelButton"
                        @click="closePanel"
                    >
                        {{ $t('Abbrechen') }}
                    </button>
                </div>
            </div>

            <div
                v-else-if="panel === 'embed'"
                class="border-t border-ash-light px-3 py-2.5"
                v-dialog="{ close: closePanel, modal: false }"
                role="dialog"
                :aria-label="$t('Einbettung')"
            >
                <input
                    v-model="embedUrl"
                    type="url"
                    :class="controlClasses({ small: true, mono: true })"
                    :placeholder="
                        $t(
                            'Adresse von YouTube, Vimeo oder einer Karte (Google Maps, OpenStreetMap)',
                        )
                    "
                    :aria-label="$t('Adresse der Einbettung')"
                    @keydown.enter.prevent="applyEmbed"
                />
                <p class="mt-1 text-[11.5px] text-basalt-light">
                    {{
                        embedTarget
                            ? $t(
                                  ':provider erkannt — die Einbettung wird erst nach Zustimmung der Besucherin geladen.',
                                  {
                                      provider:
                                          embedTarget.kind === 'youtube'
                                              ? 'YouTube'
                                              : embedLabels[
                                                    embedTarget.provider
                                                ],
                                  },
                              )
                            : embedUrl.trim() !== ''
                              ? $t(
                                    'Adresse nicht erkannt (YouTube, Vimeo, Google Maps, OpenStreetMap) — die Einbettung wird erst nach Zustimmung der Besucherin geladen.',
                                )
                              : $t(
                                    'die Einbettung wird erst nach Zustimmung der Besucherin geladen.',
                                )
                    }}
                </p>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <button
                        type="button"
                        :class="primaryButton"
                        :disabled="!embedTarget"
                        @click="applyEmbed"
                    >
                        {{ $t('Einfügen') }}
                    </button>
                    <button
                        type="button"
                        :class="panelButton"
                        @click="closePanel"
                    >
                        {{ $t('Abbrechen') }}
                    </button>
                </div>
            </div>

            <div
                v-else-if="panel === 'chars'"
                class="flex flex-wrap gap-1 border-t border-ash-light px-3 py-2.5"
                v-dialog="{ close: closePanel, modal: false }"
                role="dialog"
                :aria-label="$t('Sonderzeichen')"
            >
                <button
                    v-for="c in specialCharacters"
                    :key="c.label"
                    type="button"
                    class="h-7 min-w-7 rounded border border-ash-light bg-white px-1 text-[13px] hover:border-ash hover:bg-paper"
                    :title="c.label"
                    :aria-label="c.label"
                    @mousedown.prevent
                    @click="insertChar(c.char)"
                >
                    {{ c.char === ' ' ? '⎵' : c.char }}
                </button>
                <button
                    type="button"
                    :class="[panelButton, 'ml-auto']"
                    @click="closePanel"
                >
                    {{ $t('Schliessen') }}
                </button>
            </div>

            <div
                v-else-if="panel === 'search'"
                class="border-t border-ash-light px-3 py-2.5"
                role="search"
                :aria-label="$t('Suchen und Ersetzen')"
            >
                <div class="grid gap-2 md:grid-cols-2">
                    <input
                        v-model="searchTerm"
                        type="search"
                        :class="controlClasses({ small: true })"
                        :placeholder="$t('Suchbegriff')"
                        :aria-label="$t('Suchbegriff')"
                        @keydown.enter.prevent="editor.commands.nextMatch()"
                    />
                    <input
                        v-model="replaceWith"
                        type="text"
                        :class="controlClasses({ small: true })"
                        :placeholder="$t('Ersetzen durch')"
                        :aria-label="$t('Ersetzen durch')"
                    />
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                    <button
                        type="button"
                        :class="panelButton"
                        :disabled="hits === 0"
                        @click="editor.commands.nextMatch()"
                    >
                        {{ $t('Weiter') }}
                    </button>
                    <button
                        type="button"
                        :class="panelButton"
                        :disabled="hits === 0"
                        @click="editor.commands.replaceMatch(replaceWith)"
                    >
                        {{ $t('Ersetzen') }}
                    </button>
                    <button
                        type="button"
                        :class="panelButton"
                        :disabled="hits === 0"
                        @click="editor.commands.replaceAllMatches(replaceWith)"
                    >
                        {{ $t('Alle ersetzen') }}
                    </button>
                    <span class="font-mono text-[10.5px] text-basalt-light">{{
                        searchTerm
                            ? hits === 0
                                ? $t('kein Treffer')
                                : `${hitIndex + 1} / ${hits}`
                            : ''
                    }}</span>
                    <button
                        type="button"
                        :class="[panelButton, 'ml-auto']"
                        @click="closePanel"
                    >
                        {{ $t('Schliessen') }}
                    </button>
                </div>
            </div>

            <div
                v-else-if="panel === 'source'"
                class="border-t border-ash-light px-3 py-2.5"
            >
                <div class="flex flex-wrap items-center gap-1.5">
                    <button
                        type="button"
                        :class="[
                            panelButton,
                            sourceMode === 'json'
                                ? 'bg-basalt text-paper hover:bg-basalt'
                                : '',
                        ]"
                        @click="sourceMode = 'json'"
                    >
                        JSON
                    </button>
                    <button
                        type="button"
                        :class="[
                            panelButton,
                            sourceMode === 'html'
                                ? 'bg-basalt text-paper hover:bg-basalt'
                                : '',
                        ]"
                        @click="sourceMode = 'html'"
                    >
                        HTML
                    </button>
                    <span class="text-[11.5px] text-basalt-light">{{
                        $t('nur lesend — gespeichert wird das JSON')
                    }}</span>
                    <button
                        type="button"
                        :class="[primaryButton, 'ml-auto']"
                        @click="closePanel"
                    >
                        {{ $t('Zurück zum Editor') }}
                    </button>
                </div>
            </div>
        </div>

        <pre
            v-if="panel === 'source'"
            class="scroll-y max-h-96 bg-basalt px-3 py-2.5 font-mono text-[11.5px] leading-snug whitespace-pre-wrap text-paper"
            style="--scroll-bg: #23262b"
        ><code>{{ sourceMode === 'json' ? sourceJson : sourceHtml }}</code></pre>
        <EditorContent v-else :editor="editor" />

        <div
            class="flex flex-wrap items-center gap-x-3 border-t border-ash-light bg-paper px-3 py-1 text-[10.5px] text-basalt-light"
        >
            <span class="font-mono"
                >{{ $t(':count Zeichen', { count: characters }) }} ·
                {{ $tc(':count Wort|:count Wörter', words) }}</span
            >
            <span class="hidden md:inline"
                >{{ $t('Markdown-Kürzel:') }} <code>##</code>
                {{ $t('Überschrift') }} · <code>-</code> {{ $t('Liste') }} ·
                <code>**{{ $t('fett') }}**</code> · <code>&gt;</code>
                {{ $t('Zitat') }} · <code>```</code> {{ $t('Code') }} ·
                <code>---</code> {{ $t('Linie') }}</span
            >
            <span
                v-if="notice"
                class="text-danger font-semibold"
                role="alert"
                >{{ notice }}</span
            >
        </div>
    </div>
</template>
