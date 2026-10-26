<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { computed, onMounted, ref } from 'vue';
import { t } from '../i18n';
import type {
    BackendProps,
    BlockValue,
    BlockedBlock,
    MediaOption,
    SchemaBlock,
} from '../types';
import BlockFields from './BlockFields.vue';
import BlockPalette from './BlockPalette.vue';
import Hex from './Hex.vue';
import Modal from './Modal.vue';
import {
    PART,
    SNIPPET,
    dissolveAt,
    isReference,
    referenceBlock,
    snippetSummary,
} from './layoutEditor';
import { useReferenceOptions } from './references';
import {
    adoptKey,
    blockSummary,
    cloneBlock,
    errorsUnder,
    keyOf,
    newBlock,
} from './blocks';
import { vDialog } from './a11y';

/**
 * Die Blockliste (Mockup 11): je Block ein Formularblatt mit Kopf (Typ-Sechseck, Label, Name mono, Kurzinhalt
 * wenn eingeklappt), Operationen (↑ ↓, Duplizieren, Einklappen, Löschen mit Bestätigung), Schema-Fehler als
 * Karmin-Marke; darunter die Palette aus dem Theme — erlaubte Blöcke klickbar, gesperrte sichtbar mit Grund.
 * Verschachtelt sich über Felder vom Typ `blocks` (BlockFields → BlockList) beliebig tief, mit Einrückung an
 * einer Lava-Linie. Unter 768 px Karten mit Kurzinhalt; Tippen öffnet den Block als Blatt.
 */
const props = withDefaults(
    defineProps<{
        modelValue: BlockValue[];
        schema: SchemaBlock[];
        allowed: string[];
        blocked?: BlockedBlock[];
        media: MediaOption[];
        errors: Record<string, string>;
        path: string;
        pasteUrl: string;
        depth?: number;
        /** Wofür die Liste gilt, für den Hilfetext: «den Typ Seite» oder «das Feld ‹Inhalt›». */
        scope?: string;
        /** Der Lava-Bezug der Sperre: «type page · allow» oder «field content · allow». */
        scopeCode?: string;
        /** Wo die Liste steht (verschachtelt), für den Untertitel des Fliesstext-Modals */
        context?: string;
        /** Autosave: Schlüssel (`keyOf`) der Blöcke, die gerade gespeichert wurden — Häkchen im Kopf */
        savedKeys?: number[];
        /** TB 10 Scheibe 3 (M-28): «+ Baustein» und «+ Lava-Teil» oben in der Liste — in Bausteinen nur Teile */
        references?: 'all' | 'parts' | 'none';
    }>(),
    {
        blocked: undefined,
        depth: 0,
        scope: '',
        scopeCode: 'allow',
        context: '',
        savedKeys: () => [],
        references: 'all',
    },
);
const emit = defineEmits<{
    (e: 'update:modelValue', value: BlockValue[]): void;
}>();

const isDesktop = useMediaQuery('(min-width: 768px)');
/** Ohne `scope` gilt die Sperre für «diese Liste» — in der Sprache der Oberfläche. */
const scopeText = computed(() => props.scope || t('diese Liste'));
const collapsed = ref<number[]>([]);
const confirming = ref<number | null>(null);
const openIndex = ref<number | null>(null);
const paletteOpen = ref(false);

const byName = computed(
    () =>
        Object.fromEntries(props.schema.map((b) => [b.name, b])) as Record<
            string,
            SchemaBlock
        >,
);

/** Die Palette: erlaubte Blöcke in Theme-Reihenfolge, gesperrte danach — mit dem Grund vom Server oder dem Feld. */
const palette = computed(() => {
    const allowed = props.schema
        .filter((b) => props.allowed.includes(b.name))
        .map((b) => ({ ...b, reason: null as string | null }));
    const blocked = props.schema
        .filter((b) => !props.allowed.includes(b.name))
        .map((b) => ({
            ...b,
            reason:
                props.blocked?.find((x) => x.name === b.name)?.reason ??
                t('«:block» ist für :scope nicht erlaubt (:code)', {
                    block: b.label,
                    scope: scopeText.value,
                    code: props.scopeCode,
                }),
        }));

    return [...allowed, ...blocked];
});
/** Der Hilfetext unter der Palette: Gründe vom Server einzeln, die übrigen gesperrten Blöcke in einem Satz. */
const blockedHelp = computed(() => {
    const lines: string[] = [];
    const rest: string[] = [];
    props.schema
        .filter((b) => !props.allowed.includes(b.name))
        .forEach((b) => {
            const reason = props.blocked?.find(
                (x) => x.name === b.name,
            )?.reason;
            if (reason) {
                lines.push(reason);
            } else {
                rest.push(b.label);
            }
        });
    if (rest.length === 1) {
        lines.push(
            t('«:block» ist für :scope nicht erlaubt (:code)', {
                block: rest[0] ?? '',
                scope: scopeText.value,
                code: props.scopeCode,
            }),
        );
    } else if (rest.length > 1) {
        lines.push(
            t('Nicht erlaubt für :scope: :blocks (:code)', {
                scope: scopeText.value,
                blocks: rest.map((r) => `«${r}»`).join(', '),
                code: props.scopeCode,
            }),
        );
    }

    return lines;
});

function update(next: BlockValue[]): void {
    emit('update:modelValue', next);
}

/* ── Bausteine und Lava-Teile als Verweis (TB 10 Scheibe 3, M-28) ── */

const backend = usePage<BackendProps>().props.backend;
const offers = computed(() => (props.depth > 0 ? 'none' : props.references));
const { options: refOptions, load: loadRefs } = useReferenceOptions(
    backend?.content_url ?? '/admin',
);
const picker = ref<'snippet' | 'part' | null>(null);

onMounted(() => {
    if (offers.value !== 'none' && props.modelValue.some(isReference)) {
        void loadRefs();
    }
});

function openPicker(kind: 'snippet' | 'part'): void {
    picker.value = kind;
    paletteOpen.value = false;
    void loadRefs();
}

function insertReference(kind: 'snippet' | 'part', name: string): void {
    update([...props.modelValue, referenceBlock(kind, name)]);
    picker.value = null;
}

function snippetOf(block: BlockValue | undefined) {
    return block?.type === SNIPPET
        ? (refOptions.value?.snippets.find(
              (s) => s.handle === block.data.snippet,
          ) ?? null)
        : null;
}

/** Bezeichnung eines Blocks: Label aus dem Theme, «Baustein» oder «Lava-Teil». */
function labelOf(block: BlockValue | undefined): string {
    if (!block) {
        return '';
    }
    if (block.type === SNIPPET) {
        return t('Baustein');
    }
    if (block.type === PART) {
        return t('Lava-Teil');
    }

    return byName.value[block.type]?.label ?? block.type;
}

function summaryOf(block: BlockValue): string {
    if (block.type === SNIPPET) {
        const snippet = snippetOf(block);

        return snippet
            ? `${snippet.name} · ${snippetSummary(snippet.blocks, props.schema)}`
            : `«${String(block.data.snippet ?? '')}»`;
    }
    if (block.type === PART) {
        const part = refOptions.value?.parts.find(
            (p) => p.name === block.data.part,
        );

        return `${String(block.data.part ?? '')}${part ? ` · ${part.kind}` : ''}`;
    }

    return blockSummary(block, byName.value[block.type]);
}

/** Auflösen hier: der Verweis wird zu Kopien — nur auf dieser Seite, der Baustein bleibt. */
function dissolve(index: number): void {
    const snippet = snippetOf(props.modelValue[index]);
    if (snippet) {
        update(dissolveAt(props.modelValue, index, snippet));
    }
}

function add(block: SchemaBlock): void {
    update([...props.modelValue, newBlock(block)]);
    paletteOpen.value = false;
    if (!isDesktop.value) {
        openIndex.value = props.modelValue.length;
    }
}

function move(index: number, delta: number): void {
    const target = index + delta;
    if (target < 0 || target >= props.modelValue.length) {
        return;
    }
    const next = [...props.modelValue];
    const a = next[index];
    const b = next[target];
    if (a && b) {
        next[index] = b;
        next[target] = a;
        update(next);
        if (openIndex.value === index) {
            openIndex.value = target;
        }
    }
}

function duplicate(index: number): void {
    const source = props.modelValue[index];
    if (!source) {
        return;
    }
    const next = [...props.modelValue];
    next.splice(index + 1, 0, cloneBlock(source));
    update(next);
}

function remove(index: number): void {
    update(props.modelValue.filter((_, i) => i !== index));
    confirming.value = null;
    openIndex.value = null;
}

function setData(index: number, data: BlockValue['data']): void {
    const next = [...props.modelValue];
    const current = next[index];
    if (current) {
        const replaced = { type: current.type, data };
        adoptKey(current, replaced);
        next[index] = replaced;
        update(next);
    }
}

function toggle(block: BlockValue): void {
    const key = keyOf(block);
    collapsed.value = collapsed.value.includes(key)
        ? collapsed.value.filter((k) => k !== key)
        : [...collapsed.value, key];
}

function isCollapsed(block: BlockValue): boolean {
    return collapsed.value.includes(keyOf(block));
}

/** «Block 2 · Text», verschachtelt mit dem Ort davor: «Block 1 · Spalten › Block 2 · Text» */
function blockContext(index: number): string {
    const block = props.modelValue[index];
    const own = t('Block :number · :label', {
        number: index + 1,
        label: (block && byName.value[block.type]?.label) ?? block?.type ?? '',
    });

    return props.context ? `${props.context} › ${own}` : own;
}

function isSaved(block: BlockValue): boolean {
    return props.savedKeys.includes(keyOf(block));
}

function errorCount(index: number): number {
    return errorsUnder(props.errors, `${props.path}.${index}`);
}

const opButton =
    'inline-flex min-h-6 min-w-6 items-center justify-center rounded px-1 text-[12px] leading-5 text-basalt-light hover:bg-ash-light hover:text-basalt disabled:cursor-not-allowed disabled:opacity-35 disabled:hover:bg-transparent';
const chip =
    'rounded-md border px-2.5 py-[5px] text-xs font-semibold transition-colors';
</script>

<template>
    <div>
        <p v-if="modelValue.length === 0" class="text-ash-dark text-xs">
            {{ $t('Noch keine Blöcke — unten einen Block hinzufügen.') }}
        </p>

        <!-- Desktop: Formularblätter in Seitenreihenfolge -->
        <template v-if="isDesktop">
            <section
                v-for="(block, i) in modelValue"
                :key="keyOf(block)"
                class="mb-2 overflow-hidden rounded-lg border bg-white"
                :class="
                    errorCount(i) > 0 ? 'border-danger/60' : 'border-ash-light'
                "
                :aria-label="$t('Block :label', { label: labelOf(block) })"
            >
                <div
                    class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-ash-light bg-paper px-2.5 py-1.5 text-xs"
                >
                    <Hex
                        :label="labelOf(block).slice(0, 1).toUpperCase()"
                        :tone="isReference(block) ? 'lava-dark' : 'basalt'"
                        size="sm"
                    />
                    <span class="font-semibold">{{ labelOf(block) }}</span>
                    <span class="font-mono text-[10px] text-basalt-light">{{
                        block.type
                    }}</span>
                    <span
                        v-if="errorCount(i) > 0"
                        class="hex bg-danger inline-flex h-3 w-[13px] items-center justify-center font-mono text-[9px] font-bold text-white"
                        :title="
                            $tc(
                                ':count Feld zu prüfen|:count Felder zu prüfen',
                                errorCount(i),
                            )
                        "
                        >{{ errorCount(i) }}</span
                    >
                    <Transition
                        enter-active-class="transition duration-200 motion-reduce:transition-none"
                        enter-from-class="opacity-0"
                        leave-active-class="transition duration-500 motion-reduce:transition-none"
                        leave-to-class="opacity-0"
                        ><span
                            v-if="isSaved(block)"
                            class="text-success text-[11px] font-semibold"
                            >✓ {{ $t('gespeichert') }}</span
                        ></Transition
                    >
                    <span
                        v-if="isCollapsed(block)"
                        class="min-w-0 truncate text-[11.5px] text-basalt-light"
                        >{{ summaryOf(block) }}</span
                    >
                    <span class="ml-auto flex items-center gap-0.5">
                        <template v-if="confirming === keyOf(block)">
                            <span class="mr-1 text-[11px] text-basalt">{{
                                $t('Block löschen?')
                            }}</span>
                            <button
                                type="button"
                                class="text-danger rounded px-1.5 text-[11px] font-semibold hover:underline"
                                @click="remove(i)"
                            >
                                {{ $t('Ja, löschen') }}
                            </button>
                            <button
                                type="button"
                                :class="opButton"
                                @click="confirming = null"
                            >
                                {{ $t('Nein') }}
                            </button>
                        </template>
                        <template v-else>
                            <button
                                type="button"
                                :class="opButton"
                                :disabled="i === 0"
                                :title="$t('nach oben')"
                                :aria-label="$t('nach oben')"
                                @click="move(i, -1)"
                            >
                                ↑
                            </button>
                            <button
                                type="button"
                                :class="opButton"
                                :disabled="i === modelValue.length - 1"
                                :title="$t('nach unten')"
                                :aria-label="$t('nach unten')"
                                @click="move(i, 1)"
                            >
                                ↓
                            </button>
                            <button
                                type="button"
                                :class="opButton"
                                :title="$t('Duplizieren')"
                                :aria-label="$t('Duplizieren')"
                                @click="duplicate(i)"
                            >
                                ⧉
                            </button>
                            <button
                                type="button"
                                :class="opButton"
                                :title="
                                    isCollapsed(block)
                                        ? $t('Ausklappen')
                                        : $t('Einklappen')
                                "
                                :aria-expanded="!isCollapsed(block)"
                                @click="toggle(block)"
                            >
                                {{ isCollapsed(block) ? '▸' : '▾' }}
                            </button>
                            <button
                                type="button"
                                :class="[opButton, 'hover:text-danger']"
                                :title="$t('Löschen')"
                                :aria-label="$t('Löschen')"
                                @click="confirming = keyOf(block)"
                            >
                                ✕
                            </button>
                        </template>
                    </span>
                </div>
                <div v-show="!isCollapsed(block)" class="px-2.5 py-2.5">
                    <div
                        v-if="isReference(block)"
                        class="flex flex-wrap items-center gap-2 text-[12.5px]"
                    >
                        <span class="min-w-0 flex-1">{{
                            summaryOf(block)
                        }}</span>
                        <template v-if="block.type === SNIPPET">
                            <button
                                v-if="snippetOf(block)"
                                type="button"
                                class="rounded-md border border-ash bg-white px-2 py-0.5 text-[11.5px] font-semibold"
                                :title="
                                    $t(
                                        'Kopien der Blöcke statt des Verweises — nur auf dieser Seite',
                                    )
                                "
                                @click="dissolve(i)"
                            >
                                {{ $t('Auflösen') }}
                            </button>
                            <a
                                v-if="refOptions?.manage"
                                :href="`${refOptions.manage}?baustein=${String(block.data.snippet ?? '')}`"
                                class="text-[11.5px] text-lava-dark underline"
                                >{{ $t('zentral bearbeiten') }}</a
                            >
                        </template>
                        <span
                            class="basis-full text-[11px] text-basalt-light"
                            >{{
                                block.type === SNIPPET
                                    ? $t(
                                          'Verweis — Änderungen am Baustein wirken auf allen Seiten, die ihn tragen.',
                                      )
                                    : $t(
                                          'Lava-Teil des Themes mit seinen Einstellungen (Einrichtung › Layout › Teile).',
                                      )
                            }}</span
                        >
                    </div>
                    <BlockFields
                        v-else-if="byName[block.type]"
                        :fields="byName[block.type]?.fields ?? []"
                        :model-value="block.data"
                        :media="media"
                        :errors="errors"
                        :path="`${path}.${i}`"
                        :paste-url="pasteUrl"
                        :blocks="schema"
                        :depth="depth"
                        :context="blockContext(i)"
                        @update:model-value="setData(i, $event)"
                    />
                    <p v-else class="text-danger text-xs font-semibold">
                        {{
                            $t(
                                'Unbekannter Block «:type» — im Theme nicht (mehr) definiert.',
                                { type: block.type },
                            )
                        }}
                    </p>
                    <p
                        v-if="errors[`${path}.${i}.type`]"
                        class="text-danger mt-1 text-[11.5px] font-semibold"
                        role="alert"
                    >
                        {{ errors[`${path}.${i}.type`] }}
                    </p>
                </div>
            </section>

            <!-- TB 28: Palette mit Schema und Gruppen — bei leerer Liste offen, sonst über «+ Block einfügen» -->
            <button
                v-if="modelValue.length > 0 && !paletteOpen"
                type="button"
                class="mt-2.5 flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-ash bg-white py-2 text-[12.5px] font-semibold text-basalt hover:border-lava hover:text-lava-dark"
                @click="paletteOpen = true"
            >
                + {{ $t('Block einfügen') }}
            </button>
            <BlockPalette
                v-else
                class="mt-2.5"
                :items="palette"
                :offers="offers"
                :closable="modelValue.length > 0"
                :autofocus="modelValue.length > 0"
                @add="add"
                @pick="openPicker"
                @close="paletteOpen = false"
            />
            <p
                v-if="blockedHelp.length"
                class="mt-1.5 text-[11.5px] text-basalt-light"
            >
                {{ blockedHelp.join(' · ') }}
            </p>
        </template>

        <!-- Mobil: Karten mit Kurzinhalt, Block als Blatt -->
        <template v-else>
            <button
                v-for="(block, i) in modelValue"
                :key="keyOf(block)"
                type="button"
                class="mb-1.5 flex w-full items-center gap-2 rounded-lg border bg-white px-3 py-2 text-left"
                :class="
                    errorCount(i) > 0 ? 'border-danger/60' : 'border-ash-light'
                "
                @click="openIndex = i"
            >
                <span class="text-ash-dark" aria-hidden="true">⋮⋮</span>
                <Hex
                    :label="labelOf(block).slice(0, 1).toUpperCase()"
                    :tone="isReference(block) ? 'lava-dark' : 'basalt'"
                    size="sm"
                />
                <span class="min-w-0 flex-1">
                    <span class="block text-[13px] font-semibold">{{
                        labelOf(block)
                    }}</span>
                    <span
                        class="block truncate text-[11.5px] text-basalt-light"
                        >{{ summaryOf(block) }}</span
                    >
                </span>
                <span
                    v-if="isSaved(block)"
                    class="text-success text-[11px] font-semibold"
                    :title="$t('gespeichert')"
                    :aria-label="$t('gespeichert')"
                    >✓</span
                >
                <span
                    v-if="errorCount(i) > 0"
                    class="hex bg-danger inline-flex h-3 w-[13px] items-center justify-center font-mono text-[9px] font-bold text-white"
                    >{{ errorCount(i) }}</span
                >
                <span class="text-ash-dark" aria-hidden="true">›</span>
            </button>
            <button
                type="button"
                class="mt-1 w-full rounded-md border border-ash bg-white py-2 text-xs font-semibold text-basalt"
                @click="paletteOpen = true"
            >
                + {{ $t('Block') }}
            </button>

            <div
                v-if="paletteOpen"
                class="fixed inset-0 z-40 flex items-end bg-basalt/40"
                @click.self="paletteOpen = false"
            >
                <div
                    v-dialog="() => (paletteOpen = false)"
                    class="w-full rounded-t-2xl border-t border-ash bg-white px-4 pt-2 pb-5"
                    role="dialog"
                    aria-modal="true"
                    :aria-label="$t('Block hinzufügen')"
                >
                    <div
                        class="mx-auto mb-3 h-1 w-9 rounded bg-ash"
                        aria-hidden="true"
                    />
                    <div class="scroll-y max-h-[75vh]">
                        <BlockPalette
                            :items="palette"
                            :offers="offers"
                            @add="add"
                            @pick="openPicker"
                        />
                    </div>
                    <p
                        v-if="blockedHelp.length"
                        class="mt-2 text-[11.5px] text-basalt-light"
                    >
                        {{ blockedHelp.join(' · ') }}
                    </p>
                </div>
            </div>

            <div
                v-if="openIndex !== null && modelValue[openIndex]"
                v-dialog="() => (openIndex = null)"
                class="fixed inset-0 z-40 flex flex-col bg-white"
                role="dialog"
                aria-modal="true"
                :aria-label="
                    $t('Block :label', {
                        label: labelOf(modelValue[openIndex]),
                    })
                "
            >
                <div
                    class="flex items-center gap-2 border-b border-ash-light bg-paper px-3 py-2"
                >
                    <button
                        type="button"
                        class="text-[12.5px] text-lava-dark underline"
                        @click="openIndex = null"
                    >
                        ‹ {{ $t('Zurück') }}
                    </button>
                    <Hex
                        :label="
                            labelOf(modelValue[openIndex])
                                .slice(0, 1)
                                .toUpperCase() || '?'
                        "
                        tone="basalt"
                        size="sm"
                    />
                    <span class="text-[13px] font-semibold">{{
                        labelOf(modelValue[openIndex])
                    }}</span>
                    <span class="font-mono text-[10px] text-basalt-light">{{
                        modelValue[openIndex]?.type
                    }}</span>
                </div>
                <div class="scroll-y flex-1 px-3 py-3">
                    <div
                        v-if="
                            modelValue[openIndex] &&
                            isReference(modelValue[openIndex] as BlockValue)
                        "
                        class="space-y-2 text-[13px]"
                    >
                        <p>
                            {{ summaryOf(modelValue[openIndex] as BlockValue) }}
                        </p>
                        <button
                            v-if="snippetOf(modelValue[openIndex])"
                            type="button"
                            class="rounded-md border border-ash bg-white px-3 py-1.5 text-xs font-semibold"
                            @click="
                                dissolve(openIndex);
                                openIndex = null;
                            "
                        >
                            {{ $t('Auflösen (eigene Blöcke)') }}
                        </button>
                    </div>
                    <BlockFields
                        v-else-if="byName[modelValue[openIndex]?.type ?? '']"
                        :fields="
                            byName[modelValue[openIndex]?.type ?? '']?.fields ??
                            []
                        "
                        :model-value="modelValue[openIndex]?.data ?? {}"
                        :media="media"
                        :errors="errors"
                        :path="`${path}.${openIndex}`"
                        :paste-url="pasteUrl"
                        :blocks="schema"
                        :depth="depth"
                        :context="blockContext(openIndex)"
                        @update:model-value="setData(openIndex, $event)"
                    />
                    <p v-else class="text-danger text-xs font-semibold">
                        {{ $t('Unbekannter Block.') }}
                    </p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="rounded-md border border-ash bg-white px-3 py-1.5 text-xs font-semibold"
                            :disabled="openIndex === 0"
                            @click="move(openIndex, -1)"
                        >
                            ↑
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-ash bg-white px-3 py-1.5 text-xs font-semibold"
                            :disabled="openIndex === modelValue.length - 1"
                            @click="move(openIndex, 1)"
                        >
                            ↓
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-ash bg-white px-3 py-1.5 text-xs font-semibold"
                            @click="duplicate(openIndex)"
                        >
                            ⧉ {{ $t('Duplizieren') }}
                        </button>
                        <button
                            v-if="
                                confirming !==
                                keyOf(modelValue[openIndex] as object)
                            "
                            type="button"
                            class="border-danger text-danger ml-auto rounded-md border bg-white px-3 py-1.5 text-xs font-semibold"
                            @click="
                                confirming = keyOf(
                                    modelValue[openIndex] as object,
                                )
                            "
                        >
                            {{ $t('Entfernen') }}
                        </button>
                        <button
                            v-else
                            type="button"
                            class="bg-danger ml-auto rounded-md px-3 py-1.5 text-xs font-semibold text-white"
                            @click="remove(openIndex)"
                        >
                            {{ $t('Wirklich entfernen') }}
                        </button>
                    </div>
                </div>
                <div class="border-t border-ash-light px-3 py-2.5">
                    <button
                        type="button"
                        class="w-full rounded-md bg-lava-dark py-2.5 text-[13px] font-semibold text-white"
                        @click="openIndex = null"
                    >
                        {{ $t('Fertig') }}
                    </button>
                </div>
            </div>
        </template>
        <Modal
            :open="picker !== null"
            :title="
                picker === 'snippet'
                    ? $t('Baustein einsetzen')
                    : $t('Lava-Teil einsetzen')
            "
            :subtitle="
                picker === 'snippet'
                    ? $t(
                          'Als Verweis — Änderungen am Baustein wirken auf allen Seiten',
                      )
                    : $t(
                          'Ein Teil des Themes mit seinen Einstellungen, an dieser Stelle der Seite',
                      )
            "
            :wide="false"
            @close="picker = null"
        >
            <p v-if="refOptions === null" class="text-sm text-basalt-light">
                {{ $t('Lädt …') }}
            </p>
            <ul v-else-if="picker === 'snippet'" class="space-y-1.5">
                <li v-for="s in refOptions.snippets" :key="s.handle">
                    <button
                        type="button"
                        class="w-full rounded-md border border-ash-light bg-white px-3 py-2 text-left hover:bg-paper"
                        @click="insertReference('snippet', s.handle)"
                    >
                        <span class="block text-[13px] font-semibold">{{
                            s.name
                        }}</span>
                        <span class="block text-[11.5px] text-basalt-light">{{
                            snippetSummary(s.blocks, schema)
                        }}</span>
                    </button>
                </li>
                <li
                    v-if="refOptions.snippets.length === 0"
                    class="text-sm text-basalt-light"
                >
                    {{ $t('Noch keine Bausteine.')
                    }}<a
                        v-if="refOptions.manage"
                        :href="refOptions.manage"
                        class="ml-1 text-lava-dark underline"
                        >{{ $t('Bausteine anlegen') }}</a
                    >
                </li>
            </ul>
            <ul v-else class="flex flex-wrap gap-1.5">
                <li v-for="p in refOptions.parts" :key="p.name">
                    <button
                        type="button"
                        :class="[
                            chip,
                            'border-ash bg-white text-basalt hover:bg-paper',
                        ]"
                        @click="insertReference('part', p.name)"
                    >
                        {{ p.name }}
                        <span
                            class="font-mono text-[10.5px] font-normal text-basalt-light"
                            >{{ p.kind }}</span
                        >
                    </button>
                </li>
                <li
                    v-if="refOptions.parts.length === 0"
                    class="text-sm text-basalt-light"
                >
                    {{ $t('Das Theme hat keine Lava-Teile.') }}
                </li>
            </ul>
        </Modal>
    </div>
</template>
