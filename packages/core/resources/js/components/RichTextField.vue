<script setup lang="ts">
import { generateHTML } from '@tiptap/core';
import { computed, ref } from 'vue';
import { t } from '../i18n';
import Button from './Button.vue';
import Modal from './Modal.vue';
import RichTextEditor from './RichTextEditor.vue';
import { toDoc } from './richtext/doc';
import { richTextExtensions } from './richtext/extensions';
import type { FieldValue, MediaOption } from '../types';

/**
 * Fliesstext im Formular (Wunsch Hoang 16.10.2026): Im Formular steht nur der formatierte Text, ohne Werkzeuge —
 * ein Klick (oder Enter) öffnet ein Modal mit dem vollständigen Editor über unscharfem Hintergrund. Jede Änderung
 * im Modal geht sofort ins Modell; gespeichert wird über das Autosave des Formulars.
 */
const props = withDefaults(
    defineProps<{
        modelValue: FieldValue | undefined;
        label: string;
        id?: string;
        media?: MediaOption[];
        pasteUrl?: string;
        placeholder?: string;
        invalid?: boolean;
        disabled?: boolean;
        subtitle?: string;
    }>(),
    {
        id: undefined,
        media: () => [],
        pasteUrl: '',
        placeholder: '',
        invalid: false,
        disabled: false,
        subtitle: '',
    },
);
const emit = defineEmits<{
    (e: 'update:modelValue', value: FieldValue): void;
}>();
const open = ref(false);
/** Ohne eigenen Platzhalter «Text schreiben …» in der Sprache der Oberfläche. */
const placeholderText = computed(
    () => props.placeholder || t('Text schreiben …'),
);

const html = computed(() => {
    const doc = toDoc(props.modelValue);
    if (doc === null) {
        return '';
    }
    try {
        const out = generateHTML(
            doc as Parameters<typeof generateHTML>[0],
            richTextExtensions(),
        );

        return out.replace(/<p><\/p>/g, '').trim();
    } catch {
        return '';
    }
});

function openEditor(): void {
    if (!props.disabled) {
        open.value = true;
    }
}
</script>

<template>
    <div>
        <div
            class="group relative min-h-[4.5rem] cursor-text rounded-md border bg-white px-3 py-2 text-[13px] transition-colors"
            :class="[
                invalid
                    ? 'border-danger ring-danger/15 ring-2'
                    : 'border-ash-dark focus-within:border-lava-dark focus-within:ring-1 focus-within:ring-lava-dark hover:border-lava-dark',
                disabled ? 'cursor-not-allowed bg-paper' : '',
            ]"
            @click="openEditor"
        >
            <div
                v-if="html"
                class="richtext-preview prose-sm max-h-60 overflow-hidden"
                v-html="html"
            />
            <p v-else class="text-ash-dark">{{ placeholderText }}</p>
            <!-- Der Knopf liegt über der Vorschau: sein Name nennt das Feld, sein sichtbarer Text «Bearbeiten» steht
                 darin (WCAG 2.5.3) — die Vorschau selbst bleibt Text und wird nicht zum Knopfnamen. -->
            <button
                :id="id"
                type="button"
                class="absolute inset-0 flex items-end justify-end rounded-md p-1.5 focus:outline-none disabled:cursor-not-allowed"
                :aria-label="$t(':label bearbeiten', { label })"
                :disabled="disabled"
                @click.stop="openEditor"
            >
                <span
                    v-if="!disabled"
                    class="rounded bg-white/90 px-1.5 text-[10.5px] font-semibold text-lava-dark opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100"
                    >{{ $t('Bearbeiten') }} ↗</span
                >
            </button>
        </div>
        <Modal
            :open="open"
            :title="label"
            :subtitle="
                subtitle || $t('Fliesstext · wird automatisch gespeichert')
            "
            @close="open = false"
        >
            <RichTextEditor
                :model-value="modelValue"
                :media="media"
                :paste-url="pasteUrl"
                :placeholder="placeholderText"
                :label="label"
                @update:model-value="emit('update:modelValue', $event)"
            />
            <template #footer>
                <span class="text-[11.5px] text-basalt-light">{{
                    $t(
                        'Escape oder «Fertig» schliesst — Änderungen sind schon übernommen.',
                    )
                }}</span>
                <Button @click="open = false">{{ $t('Fertig') }}</Button>
            </template>
        </Modal>
    </div>
</template>

<style scoped>
.richtext-preview :deep(h2) {
    font-size: 1.05rem;
    font-weight: 600;
    margin: 0.4rem 0 0.2rem;
}
.richtext-preview :deep(h3),
.richtext-preview :deep(h4) {
    font-weight: 600;
    margin: 0.35rem 0 0.15rem;
}
.richtext-preview :deep(p) {
    margin: 0.2rem 0;
}
.richtext-preview :deep(ul) {
    list-style: disc;
    padding-left: 1.2rem;
}
.richtext-preview :deep(ol) {
    list-style: decimal;
    padding-left: 1.2rem;
}
.richtext-preview :deep(a) {
    color: var(--color-lava-dark, #b5452c);
    text-decoration: underline;
}
.richtext-preview :deep(blockquote) {
    border-left: 3px solid #d8d4cc;
    padding-left: 0.6rem;
    color: #555;
}
.richtext-preview :deep(code) {
    font-family: ui-monospace, monospace;
    font-size: 0.85em;
}
.richtext-preview :deep(img) {
    max-height: 6rem;
    display: inline-block;
}
.richtext-preview :deep(table) {
    border-collapse: collapse;
}
.richtext-preview :deep(td),
.richtext-preview :deep(th) {
    border: 1px solid #e3e0da;
    padding: 0.15rem 0.35rem;
}
</style>
