<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { t } from '../i18n';
import { fromDateTimeLocal, toDateTimeLocal } from '../support';
import type {
    BackendProps,
    BlockValue,
    FieldValue,
    MediaOption,
    SchemaBlock,
    SchemaField,
} from '../types';
import BlockList from './BlockList.vue';
import MediaDialog from './MediaDialog.vue';
import ModuleSlot from './ModuleSlot.vue';
import RichTextField from './RichTextField.vue';
import Toggle from './Toggle.vue';
import { controlClasses, labelClasses } from './control';
import {
    linkAddress,
    placeholderFor,
    primaryFields,
    requiredProgress,
    targetTitle,
    themeImagePath,
    useLinkTargets,
    useThemeImages,
} from './guidedFields';
import { uploadedMedia } from './media';

/**
 * Die Felder eines Blocks oder Typs (M-03): jeder Feldtyp aus Lava mit passender Eingabe — Text, Textbereich,
 * Fliesstext, Markdown, Zahl, Schalter, Datum, Auswahl, Tags, Bild und Galerie aus der Mediathek, Datei, Link,
 * Relation, Repeater, verschachtelte Blöcke, Slug, E-Mail, Adresse, Farbe, Code, SEO, JSON.
 * Ein unbekannter Typ bekommt ein Textfeld mit Hinweis. Fehler stehen am Feld in Karmin.
 */
const props = withDefaults(
    defineProps<{
        fields: SchemaField[];
        modelValue: Record<string, FieldValue>;
        media: MediaOption[];
        errors: Record<string, string>;
        path: string;
        pasteUrl?: string;
        blocks?: SchemaBlock[];
        depth?: number;
        /** Wo die Felder stehen, für den Untertitel des Fliesstext-Modals: «Block 2 · Text» */
        context?: string;
    }>(),
    { pasteUrl: '', blocks: () => [], depth: 0, context: '' },
);
const emit = defineEmits<{
    (e: 'update:modelValue', value: Record<string, FieldValue>): void;
}>();

/* ── TB 30: geführtes Blockformular — das Wichtigste zuerst, Hilfe am Feld, abgeleitete Werte, Linkziele ── */
const page = usePage<BackendProps>();
const linkTargets = useLinkTargets(page.props.backend.editor?.links_url);
const themeImages = useThemeImages(page.props.backend.editor?.theme_images_url);
const showMore = ref(false);
const primary = computed(() =>
    primaryFields(props.fields, props.modelValue, (name) =>
        Boolean(error(name)),
    ),
);
const hiddenCount = computed(() => props.fields.length - primary.value.size);
const progress = computed(() =>
    requiredProgress(props.fields, props.modelValue),
);

function visible(field: SchemaField): boolean {
    return showMore.value || primary.value.has(field.name);
}

function hint(field: SchemaField): string {
    return placeholderFor(
        field,
        props.fields,
        props.modelValue,
        linkTargets.value,
    );
}

function fieldId(field: SchemaField): string {
    return `${props.path}.${field.name}`;
}

function helpId(field: SchemaField): string | undefined {
    return field.help ? `${fieldId(field)}-hilfe` : undefined;
}

/** Adresse eines Links nach der Eingabe: ein Seitentitel («Kontakt») wird zur Adresse der Seite («/kontakt»). */
function settleLink(name: string): void {
    const href = link(name).href;
    const address = linkAddress(href, linkTargets.value);
    if (address !== null && address !== href) {
        set(name, { ...link(name), href: address });
    }
}

/** Steht im Feld «Adresse» etwas, das keine Adresse und keine Seite ist? */
function notAnAddress(name: string): boolean {
    const href = link(name).href.trim();

    return (
        href !== '' &&
        linkTargets.value.length > 0 &&
        linkAddress(href, linkTargets.value) === null
    );
}

/** Beschriftung eines Links: leer = Titel der Zielseite, wenn die Adresse eine Seite der Site ist. */
function linkLabelHint(name: string): string {
    const title = targetTitle(link(name).href, linkTargets.value);

    return title ? t('leer = «:value»', { value: title }) : t('Beschriftung');
}

/* TB 30: Bild, Galerie, Datei und Bildadresse wählen im Dialog der Mediathek (Hochladen und Hineinziehen inklusive) */
type DialogMode = 'image' | 'gallery' | 'file' | 'address';
const dialog = ref<{ field: SchemaField; mode: DialogMode } | null>(null);

function openDialog(field: SchemaField, mode: DialogMode): void {
    dialog.value = { field, mode };
}

function onDialogPick(item: MediaOption): void {
    const current = dialog.value;
    if (!current) {
        return;
    }
    if (current.mode === 'image') {
        pickImage(current.field, item);
    } else if (current.mode === 'gallery') {
        pickGallery(current.field, item);
    } else if (current.mode === 'file') {
        set(current.field.name, item.id);
    } else {
        set(current.field.name, `media:${item.id}`);
    }
}

/** Was im Dialog als gewählt gilt — das Bild, die Datei oder das Bild der Bildadresse. */
const dialogValue = computed<number | null>(() => {
    const current = dialog.value;
    if (!current) {
        return null;
    }
    if (current.mode === 'image') {
        return image(current.field.name).id;
    }
    if (current.mode === 'file') {
        const v = props.modelValue[current.field.name];

        return typeof v === 'number' ? v : null;
    }

    return current.mode === 'address' ? addressMedia(current.field.name) : null;
});

/** Bildadresse (Text mit «pick: image»): ein Bild der Mediathek als «media:<id>» — sonst eine Adresse unter assets/. */
function addressMedia(name: string): number | null {
    const match = /^media:(\d+)$/.exec(text(name));

    return match ? Number(match[1]) : null;
}

/** Vorschau der Bildadresse: das Bild der Mediathek, das Bild des Themes unter assets/ oder eine Adresse im Netz. */
function addressPreview(name: string): string | null {
    const id = addressMedia(name);
    if (id !== null) {
        return mediaUrl(id);
    }
    const value = text(name).trim();
    if (value === '') {
        return null;
    }
    if (/^https?:\/\//.test(value)) {
        return value;
    }
    const path = themeImagePath(value);

    return themeImages.value.find((i) => i.path === path)?.url ?? null;
}

/** Steht eine Adresse im Feld, die es nicht gibt — Datei gelöscht oder kein Bild des Themes mit diesem Pfad? */
function addressMissing(name: string): boolean {
    const url = addressPreview(name);
    if (url !== null) {
        return isBroken(url);
    }

    return (
        text(name).trim() !== '' &&
        addressMedia(name) === null &&
        themeImages.value.length > 0
    );
}

/** Der Pfad des Theme-Bilds im Feld, damit der Dialog es gewählt zeigt — leer bei Mediathek und Adressen im Netz. */
function addressThemeValue(name: string): string {
    const value = text(name).trim();

    return addressMedia(name) !== null || /^https?:\/\//.test(value)
        ? ''
        : themeImagePath(value);
}

/* Bilder, deren Datei nicht lädt (gelöscht, Ablage geleert): «Datei fehlt» statt eines kaputten Bildes */
const brokenPreviews = ref<string[]>([]);

function preview(url: string | null): string | null {
    return url !== null && !brokenPreviews.value.includes(url) ? url : null;
}

function isBroken(url: string | null): boolean {
    return url !== null && brokenPreviews.value.includes(url);
}

function markBroken(event: Event): void {
    const src = (event.target as HTMLImageElement).getAttribute('src');
    if (src && !brokenPreviews.value.includes(src)) {
        brokenPreviews.value.push(src);
    }
}

function onDialogPickAsset(path: string): void {
    if (dialog.value?.mode === 'address') {
        set(dialog.value.field.name, path);
    }
}
const tagDraft = ref<Record<string, string>>({});
const jsonErrors = ref<Record<string, string>>({});

function set(name: string, value: FieldValue): void {
    emit('update:modelValue', { ...props.modelValue, [name]: value });
}

function text(name: string): string {
    const v = props.modelValue[name];

    return typeof v === 'string' || typeof v === 'number' ? String(v) : '';
}

function list(name: string): FieldValue[] {
    const v = props.modelValue[name];

    return Array.isArray(v) ? (v as FieldValue[]) : [];
}

function record(name: string): Record<string, FieldValue> {
    const v = props.modelValue[name];

    return v && typeof v === 'object' && !Array.isArray(v)
        ? (v as Record<string, FieldValue>)
        : {};
}

function link(name: string): { href: string; label: string; target: string } {
    const v = record(name);

    return {
        href: String(v.href ?? ''),
        label: String(v.label ?? ''),
        target: String(v.target ?? ''),
    };
}

/** Bild: gespeichert als `{ id, alt }` — eine nackte Zahl aus älteren Fassungen gilt auch. */
function image(name: string): { id: number | null; alt: string } {
    const v = props.modelValue[name];
    if (typeof v === 'number') {
        return { id: v, alt: '' };
    }
    const r = record(name);

    return {
        id: typeof r.id === 'number' ? r.id : null,
        alt: String(r.alt ?? ''),
    };
}

function gallery(name: string): { id: number; alt: string }[] {
    return list(name)
        .map((item) =>
            typeof item === 'number'
                ? { id: item, alt: '' }
                : {
                      id: Number((item as { id?: unknown })?.id),
                      alt: String((item as { alt?: unknown })?.alt ?? ''),
                  },
        )
        .filter((item) => Number.isFinite(item.id));
}

function seo(name: string): { title: string; description: string } {
    const r = record(name);

    return {
        title: String(r.title ?? ''),
        description: String(r.description ?? ''),
    };
}

function rows(name: string): Record<string, FieldValue>[] {
    return list(name) as Record<string, FieldValue>[];
}

function addRow(field: SchemaField): void {
    const row: Record<string, FieldValue> = {};
    field.fields.forEach(
        (f) => (row[f.name] = (f.default as FieldValue) ?? null),
    );
    set(field.name, [...rows(field.name), row]);
}

function setRow(
    name: string,
    index: number,
    value: Record<string, FieldValue>,
): void {
    const next = [...rows(name)];
    next[index] = value;
    set(name, next);
}

function removeRow(name: string, index: number): void {
    set(
        name,
        rows(name).filter((_, i) => i !== index),
    );
}

function moveRow(name: string, index: number, delta: number): void {
    const next = [...list(name)];
    const target = index + delta;
    const a = next[index];
    const b = next[target];
    if (a === undefined || b === undefined) {
        return;
    }
    next[index] = b;
    next[target] = a;
    set(name, next);
}

function error(name: string): string | undefined {
    return props.errors[`${props.path}.${name}`] ?? jsonErrors.value[name];
}

const allMedia = computed<MediaOption[]>(() => [
    ...uploadedMedia,
    ...props.media,
]);

function mediaName(id: number | null): string {
    if (id === null) {
        return '';
    }

    return allMedia.value.find((m) => m.id === id)?.name ?? `#${id}`;
}

function mediaUrl(id: number | null): string | null {
    return id === null
        ? null
        : (allMedia.value.find((m) => m.id === id)?.url ?? null);
}

function pickImage(field: SchemaField, item: MediaOption): void {
    const current = image(field.name);
    set(field.name, {
        id: item.id,
        alt: current.alt || item.name.replace(/\.[^.]+$/, ''),
    });
}

function pickGallery(field: SchemaField, item: MediaOption): void {
    const current = gallery(field.name);
    if (current.some((g) => g.id === item.id)) {
        set(
            field.name,
            current.filter((g) => g.id !== item.id),
        );
    } else {
        set(field.name, [
            ...current,
            { id: item.id, alt: item.name.replace(/\.[^.]+$/, '') },
        ]);
    }
}

function setGalleryAlt(field: SchemaField, index: number, alt: string): void {
    const next = gallery(field.name);
    const item = next[index];
    if (item) {
        next[index] = { id: item.id, alt };
        set(field.name, next);
    }
}

function addTag(field: SchemaField): void {
    const draft = (tagDraft.value[field.name] ?? '').trim();
    if (draft === '') {
        return;
    }
    const current = list(field.name).map(String);
    if (!current.includes(draft)) {
        set(field.name, [...current, draft]);
    }
    tagDraft.value[field.name] = '';
}

function removeTag(field: SchemaField, tag: string): void {
    set(
        field.name,
        list(field.name)
            .map(String)
            .filter((t) => t !== tag),
    );
}

function checkJson(field: SchemaField, raw: string): void {
    if (raw.trim() === '') {
        delete jsonErrors.value[field.name];
        set(field.name, null);

        return;
    }
    try {
        set(field.name, JSON.parse(raw) as FieldValue);
        delete jsonErrors.value[field.name];
    } catch (e) {
        jsonErrors.value[field.name] = t('Kein gültiges JSON: :message', {
            message: (e as Error).message,
        });
    }
}

function jsonText(name: string): string {
    const v = props.modelValue[name];
    if (v === null || v === undefined) {
        return '';
    }

    return typeof v === 'string' ? v : JSON.stringify(v, null, 2);
}

const hexColor = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i;

function nestedScope(field: SchemaField): string {
    return t('das Feld «:label»', { label: field.label });
}

const knownTypes = [
    'text',
    'slug',
    'email',
    'url',
    'textarea',
    'markdown',
    'code',
    'number',
    'checkbox',
    'bool',
    'date',
    'datetime',
    'select',
    'tags',
    'richtext',
    'image',
    'gallery',
    'file',
    'link',
    'relation',
    'repeater',
    'blocks',
    'color',
    'seo',
    'json',
];
</script>

<template>
    <div class="space-y-3">
        <p
            v-if="progress.total > 0 && fields.length > 2"
            class="text-[11.5px]"
            :class="
                progress.done === progress.total
                    ? 'text-success'
                    : 'text-basalt-light'
            "
        >
            <span v-if="progress.done === progress.total" aria-hidden="true"
                >✓
            </span>
            {{
                $t('Pflichtangaben: :done von :total', {
                    done: progress.done,
                    total: progress.total,
                })
            }}
        </p>
        <div v-for="field in fields" v-show="visible(field)" :key="field.name">
            <label :class="labelClasses" :for="`${path}.${field.name}`">
                {{ field.label
                }}<span v-if="field.required" class="text-lava"> *</span>
                <span
                    v-if="field.translatable"
                    class="text-ash-dark ml-1 font-normal tracking-normal normal-case"
                    :title="$t('Wird je Sprache übersetzt')"
                    ><span aria-hidden="true">⇄</span
                    ><span class="sr-only">{{ $t('übersetzbar') }}</span></span
                >
            </label>

            <!-- TB 30: Bildadresse — ein Bild der Mediathek («media:<id>») oder eine Datei unter assets/ des Themes -->
            <div
                v-if="field.type === 'text' && field.pick === 'image'"
                class="rounded-md border border-ash-light bg-paper p-2"
                :class="{ 'border-danger': error(field.name) }"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        :id="
                            addressMedia(field.name) !== null
                                ? `${path}.${field.name}`
                                : undefined
                        "
                        type="button"
                        class="flex h-11 w-14 shrink-0 items-center justify-center overflow-hidden rounded border border-ash-light bg-white hover:border-ash"
                        :aria-label="$t('Bild wählen')"
                        @click="openDialog(field, 'address')"
                    >
                        <img
                            v-if="preview(addressPreview(field.name))"
                            :src="preview(addressPreview(field.name)) ?? ''"
                            alt=""
                            class="h-full w-full"
                            :class="
                                text(field.name).trim().endsWith('.svg')
                                    ? 'object-contain p-1'
                                    : 'object-cover'
                            "
                            @error="markBroken"
                        />
                        <span
                            v-else-if="addressMissing(field.name)"
                            class="text-danger px-0.5 text-[10px] leading-tight font-semibold"
                            >{{ $t('Datei fehlt') }}</span
                        >
                        <span v-else class="text-ash-dark text-[10px]">{{
                            text(field.name) ? $t('Datei') : $t('kein Bild')
                        }}</span>
                    </button>
                    <span
                        v-if="addressMedia(field.name) !== null"
                        class="min-w-0 flex-1 truncate text-[12.5px]"
                        >{{ mediaName(addressMedia(field.name)) }}</span
                    >
                    <input
                        v-else
                        :id="`${path}.${field.name}`"
                        type="text"
                        :value="text(field.name)"
                        :class="
                            controlClasses({
                                error: Boolean(error(field.name)),
                                small: true,
                            })
                        "
                        class="min-w-40 flex-1"
                        :placeholder="
                            hint(field) ||
                            $t('Bild wählen — oder Adresse unter assets/')
                        "
                        :aria-describedby="helpId(field)"
                        @click="
                            text(field.name) === '' &&
                            openDialog(field, 'address')
                        "
                        @input="
                            set(
                                field.name,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <button
                        type="button"
                        class="rounded-md border border-ash bg-white px-2 py-1 text-xs font-semibold"
                        @click="openDialog(field, 'address')"
                    >
                        {{ text(field.name) ? $t('Ändern …') : $t('Wählen …') }}
                    </button>
                    <button
                        v-if="text(field.name)"
                        type="button"
                        class="hover:text-danger text-xs text-basalt-light"
                        @click="set(field.name, null)"
                    >
                        {{ $t('Entfernen') }}
                    </button>
                </div>
            </div>

            <!-- Text, Slug, E-Mail, Adresse -->
            <input
                v-else-if="
                    ['text', 'slug', 'email', 'url'].includes(field.type)
                "
                :id="`${path}.${field.name}`"
                :type="
                    field.type === 'email'
                        ? 'email'
                        : field.type === 'url'
                          ? 'url'
                          : 'text'
                "
                :value="text(field.name)"
                :class="
                    controlClasses({
                        error: Boolean(error(field.name)),
                        mono: field.type === 'slug',
                    })
                "
                :placeholder="
                    field.type === 'slug'
                        ? $t('leer = aus dem Titel')
                        : hint(field) ||
                          (field.type === 'url' ? 'https://…' : '')
                "
                :aria-describedby="helpId(field)"
                :inputmode="
                    field.type === 'url'
                        ? 'url'
                        : field.type === 'email'
                          ? 'email'
                          : undefined
                "
                @input="
                    set(field.name, ($event.target as HTMLInputElement).value)
                "
            />

            <!-- Textbereich, Markdown, Code -->
            <textarea
                v-else-if="
                    ['textarea', 'markdown', 'code'].includes(field.type)
                "
                :id="`${path}.${field.name}`"
                :value="text(field.name)"
                :rows="field.type === 'textarea' ? 3 : 6"
                :placeholder="hint(field)"
                :aria-describedby="helpId(field)"
                :class="
                    controlClasses({
                        error: Boolean(error(field.name)),
                        mono: field.type !== 'textarea',
                    })
                "
                :spellcheck="field.type === 'textarea'"
                @input="
                    set(
                        field.name,
                        ($event.target as HTMLTextAreaElement).value,
                    )
                "
            />

            <!-- Zahl -->
            <input
                v-else-if="field.type === 'number'"
                :id="`${path}.${field.name}`"
                type="number"
                :value="text(field.name)"
                :class="controlClasses({ error: Boolean(error(field.name)) })"
                class="max-w-40"
                @input="
                    set(
                        field.name,
                        ($event.target as HTMLInputElement).value === ''
                            ? null
                            : Number(($event.target as HTMLInputElement).value),
                    )
                "
            />

            <!-- Schalter -->
            <div
                v-else-if="['checkbox', 'bool'].includes(field.type)"
                class="flex items-center gap-2 text-[13px]"
            >
                <Toggle
                    :model-value="Boolean(modelValue[field.name])"
                    :label="field.label"
                    @update:model-value="set(field.name, $event)"
                />
                <span>{{
                    Boolean(modelValue[field.name]) ? $t('ein') : $t('aus')
                }}</span>
            </div>

            <!-- Datum und Zeitpunkt -->
            <input
                v-else-if="field.type === 'date'"
                :id="`${path}.${field.name}`"
                type="date"
                :value="text(field.name)"
                :class="
                    controlClasses({
                        error: Boolean(error(field.name)),
                        mono: true,
                    })
                "
                class="max-w-48"
                @input="
                    set(
                        field.name,
                        ($event.target as HTMLInputElement).value || null,
                    )
                "
            />
            <input
                v-else-if="field.type === 'datetime'"
                :id="`${path}.${field.name}`"
                type="datetime-local"
                :value="toDateTimeLocal(text(field.name))"
                :class="
                    controlClasses({
                        error: Boolean(error(field.name)),
                        mono: true,
                    })
                "
                class="max-w-60"
                @input="
                    set(
                        field.name,
                        ($event.target as HTMLInputElement).value
                            ? fromDateTimeLocal(
                                  ($event.target as HTMLInputElement).value,
                              )
                            : null,
                    )
                "
            />

            <!-- Auswahl -->
            <select
                v-else-if="field.type === 'select'"
                :id="`${path}.${field.name}`"
                :value="text(field.name) || String(field.default ?? '')"
                :class="controlClasses({ error: Boolean(error(field.name)) })"
                class="max-w-72"
                @change="
                    set(field.name, ($event.target as HTMLSelectElement).value)
                "
            >
                <option v-if="!field.required" value="">—</option>
                <option v-for="o in field.options" :key="o" :value="o">
                    {{ o }}
                </option>
            </select>

            <!-- Tags -->
            <div
                v-else-if="field.type === 'tags'"
                class="flex flex-wrap items-center gap-1.5 rounded-md border border-ash bg-white px-2 py-1.5"
                :class="{ 'border-danger': error(field.name) }"
            >
                <span
                    v-for="t in list(field.name).map(String)"
                    :key="t"
                    class="inline-flex items-center gap-1 rounded border border-ash-light bg-paper px-1.5 py-0.5 text-xs"
                >
                    {{ t }}
                    <button
                        type="button"
                        class="hover:text-danger text-ash-dark"
                        :aria-label="$t(':tag entfernen', { tag: t })"
                        @click="removeTag(field, t)"
                    >
                        ✕
                    </button>
                </span>
                <input
                    :id="`${path}.${field.name}`"
                    v-model="tagDraft[field.name]"
                    type="text"
                    class="min-w-24 flex-1 border-0 bg-transparent text-[13px] focus:outline-none"
                    :placeholder="$t('Eingabe + Enter')"
                    @keydown.enter.prevent="addTag(field)"
                    @keydown.,.prevent="addTag(field)"
                    @blur="addTag(field)"
                />
            </div>

            <!-- Fliesstext: im Formular formatiert ohne Werkzeuge, Klick öffnet den Editor im Modal -->
            <RichTextField
                v-else-if="field.type === 'richtext'"
                :id="`${path}.${field.name}`"
                :model-value="modelValue[field.name]"
                :label="field.label"
                :subtitle="context"
                :invalid="Boolean(error(field.name))"
                :media="media"
                :paste-url="pasteUrl"
                @update:model-value="set(field.name, $event)"
            />

            <!-- Bild aus der Mediathek mit Alt -->
            <div
                v-else-if="field.type === 'image'"
                class="rounded-md border border-ash-light bg-paper p-2"
                :class="{ 'border-danger': error(field.name) }"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="flex h-11 w-14 items-center justify-center overflow-hidden rounded border border-ash-light bg-white hover:border-ash"
                        :aria-label="$t('Bild wählen')"
                        @click="openDialog(field, 'image')"
                    >
                        <img
                            v-if="preview(mediaUrl(image(field.name).id))"
                            :src="preview(mediaUrl(image(field.name).id)) ?? ''"
                            :alt="image(field.name).alt"
                            class="h-full w-full object-cover"
                            @error="markBroken"
                        />
                        <span
                            v-else-if="isBroken(mediaUrl(image(field.name).id))"
                            class="text-danger px-0.5 text-[10px] leading-tight font-semibold"
                            >{{ $t('Datei fehlt') }}</span
                        >
                        <span v-else class="text-ash-dark text-[10px]">{{
                            $t('kein Bild')
                        }}</span>
                    </button>
                    <span class="min-w-0 flex-1 truncate text-[12.5px]">{{
                        image(field.name).id === null
                            ? $t('Kein Bild gewählt')
                            : mediaName(image(field.name).id)
                    }}</span>
                    <button
                        type="button"
                        class="rounded-md border border-ash bg-white px-2 py-1 text-xs font-semibold"
                        @click="openDialog(field, 'image')"
                    >
                        {{
                            image(field.name).id === null
                                ? $t('Wählen …')
                                : $t('Ändern …')
                        }}
                    </button>
                    <button
                        v-if="image(field.name).id !== null"
                        type="button"
                        class="hover:text-danger text-xs text-basalt-light"
                        @click="set(field.name, null)"
                    >
                        {{ $t('Entfernen') }}
                    </button>
                </div>
                <input
                    v-if="image(field.name).id !== null"
                    type="text"
                    :value="image(field.name).alt"
                    :class="controlClasses({ small: true })"
                    class="mt-2"
                    :placeholder="$t('Alt-Text (beschreibt das Bild)')"
                    :aria-label="$t('Alt-Text')"
                    @input="
                        set(field.name, {
                            id: image(field.name).id,
                            alt: ($event.target as HTMLInputElement).value,
                        })
                    "
                />
            </div>

            <!-- Galerie: mehrere Bilder, sortierbar -->
            <div
                v-else-if="field.type === 'gallery'"
                class="rounded-md border border-ash-light bg-paper p-2"
                :class="{ 'border-danger': error(field.name) }"
            >
                <ol v-if="gallery(field.name).length" class="space-y-1">
                    <li
                        v-for="(g, i) in gallery(field.name)"
                        :key="g.id"
                        class="flex items-center gap-2 rounded border border-ash-light bg-white px-1.5 py-1"
                    >
                        <span
                            class="flex h-9 w-12 items-center justify-center overflow-hidden rounded bg-paper"
                        >
                            <img
                                v-if="preview(mediaUrl(g.id))"
                                :src="preview(mediaUrl(g.id)) ?? ''"
                                :alt="g.alt"
                                class="h-full w-full object-cover"
                                @error="markBroken"
                            />
                            <span
                                v-else
                                class="text-[10px]"
                                :class="
                                    isBroken(mediaUrl(g.id))
                                        ? 'text-danger font-semibold'
                                        : 'text-ash-dark'
                                "
                                >{{
                                    isBroken(mediaUrl(g.id))
                                        ? $t('fehlt')
                                        : `#${g.id}`
                                }}</span
                            >
                        </span>
                        <input
                            type="text"
                            :value="g.alt"
                            :class="controlClasses({ small: true })"
                            class="min-w-0 flex-1"
                            :placeholder="$t('Alt-Text')"
                            :aria-label="
                                $t('Alt-Text Bild :number', { number: i + 1 })
                            "
                            @input="
                                setGalleryAlt(
                                    field,
                                    i,
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                        />
                        <button
                            type="button"
                            class="inline-flex min-h-6 min-w-6 items-center justify-center rounded px-1 text-xs text-basalt-light hover:bg-ash-light disabled:opacity-35"
                            :disabled="i === 0"
                            :aria-label="$t('nach oben')"
                            @click="moveRow(field.name, i, -1)"
                        >
                            ↑
                        </button>
                        <button
                            type="button"
                            class="inline-flex min-h-6 min-w-6 items-center justify-center rounded px-1 text-xs text-basalt-light hover:bg-ash-light disabled:opacity-35"
                            :disabled="i === gallery(field.name).length - 1"
                            :aria-label="$t('nach unten')"
                            @click="moveRow(field.name, i, 1)"
                        >
                            ↓
                        </button>
                        <button
                            type="button"
                            class="hover:text-danger px-1 text-xs text-basalt-light"
                            :aria-label="$t('entfernen')"
                            @click="
                                set(
                                    field.name,
                                    gallery(field.name).filter(
                                        (_, j) => j !== i,
                                    ),
                                )
                            "
                        >
                            ✕
                        </button>
                    </li>
                </ol>
                <p v-else class="text-ash-dark text-xs">
                    {{ $t('Noch keine Bilder.') }}
                </p>
                <button
                    type="button"
                    class="mt-2 rounded-md border border-ash bg-white px-2 py-1 text-xs font-semibold"
                    @click="openDialog(field, 'gallery')"
                >
                    {{ $t('+ Bilder wählen …') }}
                </button>
            </div>

            <!-- Datei aus der Mediathek, alle Typen -->
            <div
                v-else-if="field.type === 'file'"
                class="rounded-md border border-ash-light bg-paper p-2"
                :class="{ 'border-danger': error(field.name) }"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="min-w-0 flex-1 truncate font-mono text-[12.5px]"
                        >{{
                            typeof modelValue[field.name] === 'number'
                                ? mediaName(modelValue[field.name] as number)
                                : $t('Keine Datei gewählt')
                        }}</span
                    >
                    <button
                        type="button"
                        class="rounded-md border border-ash bg-white px-2 py-1 text-xs font-semibold"
                        @click="openDialog(field, 'file')"
                    >
                        {{ $t('Wählen …') }}
                    </button>
                    <button
                        v-if="typeof modelValue[field.name] === 'number'"
                        type="button"
                        class="hover:text-danger text-xs text-basalt-light"
                        @click="set(field.name, null)"
                    >
                        {{ $t('Entfernen') }}
                    </button>
                </div>
            </div>

            <!-- Link: Adresse, Beschriftung, Ziel -->
            <div
                v-else-if="field.type === 'link'"
                class="grid gap-2 md:grid-cols-[1fr_1fr_auto]"
            >
                <input
                    :id="`${path}.${field.name}`"
                    type="text"
                    :placeholder="$t('Adresse (/kontakt, https://…)')"
                    :list="
                        linkTargets.length > 0
                            ? `${path}.${field.name}-ziele`
                            : undefined
                    "
                    :aria-describedby="helpId(field)"
                    :value="link(field.name).href"
                    :class="
                        controlClasses({
                            error: Boolean(error(field.name)),
                            mono: true,
                        })
                    "
                    @input="
                        set(field.name, {
                            ...link(field.name),
                            href: ($event.target as HTMLInputElement).value,
                        })
                    "
                    @change="settleLink(field.name)"
                />
                <datalist
                    v-if="linkTargets.length > 0"
                    :id="`${path}.${field.name}-ziele`"
                >
                    <option
                        v-for="target in linkTargets"
                        :key="target.path"
                        :value="target.path"
                    >
                        {{ target.title }}
                    </option>
                </datalist>
                <input
                    type="text"
                    :placeholder="linkLabelHint(field.name)"
                    :value="link(field.name).label"
                    :class="
                        controlClasses({ error: Boolean(error(field.name)) })
                    "
                    :aria-label="$t('Beschriftung')"
                    @input="
                        set(field.name, {
                            ...link(field.name),
                            label: ($event.target as HTMLInputElement).value,
                        })
                    "
                />
                <select
                    :value="link(field.name).target"
                    :class="controlClasses({})"
                    :aria-label="$t('Ziel')"
                    @change="
                        set(field.name, {
                            ...link(field.name),
                            target: ($event.target as HTMLSelectElement).value,
                        })
                    "
                >
                    <option value="">{{ $t('gleiches Fenster') }}</option>
                    <option value="_blank">{{ $t('neues Fenster') }}</option>
                </select>
                <p
                    v-if="notAnAddress(field.name)"
                    class="text-warning text-[11.5px] font-semibold md:col-span-3"
                >
                    {{
                        $t(
                            '«:value» ist keine Adresse — eine Seite aus der Liste wählen oder mit / (Seite), https:// oder mailto: beginnen.',
                            { value: link(field.name).href.trim() },
                        )
                    }}
                </p>
            </div>

            <!-- Relation -->
            <template v-else-if="field.type === 'relation'">
                <select
                    v-if="field.options.length"
                    :id="`${path}.${field.name}`"
                    :value="text(field.name)"
                    :class="
                        controlClasses({ error: Boolean(error(field.name)) })
                    "
                    class="max-w-96"
                    @change="
                        set(
                            field.name,
                            ($event.target as HTMLSelectElement).value || null,
                        )
                    "
                >
                    <option value="">
                        {{
                            $t('— :type wählen —', {
                                type: field.relation ?? $t('Inhalt'),
                            })
                        }}
                    </option>
                    <option v-for="o in field.options" :key="o" :value="o">
                        {{ o }}
                    </option>
                </select>
                <template v-else>
                    <input
                        :id="`${path}.${field.name}`"
                        type="number"
                        :value="text(field.name)"
                        :class="
                            controlClasses({
                                error: Boolean(error(field.name)),
                                mono: true,
                            })
                        "
                        class="max-w-40"
                        :placeholder="$t('Kennung')"
                        @input="
                            set(
                                field.name,
                                ($event.target as HTMLInputElement).value === ''
                                    ? null
                                    : Number(
                                          ($event.target as HTMLInputElement)
                                              .value,
                                      ),
                            )
                        "
                    />
                    <p class="mt-1 text-[11.5px] text-basalt-light">
                        <template
                            v-for="(part, p) in $t(
                                'Kennung eines Inhalts vom Typ :type — die Auswahlliste folgt, sobald der Server Optionen liefert.',
                            ).split(':type')"
                            :key="p"
                            ><span v-if="p > 0" class="font-mono">{{
                                field.relation ?? '?'
                            }}</span
                            >{{ part }}</template
                        >
                    </p>
                </template>
            </template>

            <!-- Repeater -->
            <div v-else-if="field.type === 'repeater'" class="space-y-2">
                <div
                    v-for="(row, i) in rows(field.name)"
                    :key="i"
                    class="rounded-md border border-ash-light p-3"
                >
                    <div class="mb-2 flex items-center gap-2 text-xs">
                        <span class="text-ash-dark">{{
                            $t('Zeile :number', { number: i + 1 })
                        }}</span>
                        <span class="ml-auto flex gap-1">
                            <button
                                type="button"
                                class="inline-flex min-h-6 min-w-6 items-center justify-center rounded px-1 text-basalt-light hover:bg-ash-light disabled:opacity-35"
                                :disabled="i === 0"
                                :aria-label="$t('nach oben')"
                                @click="moveRow(field.name, i, -1)"
                            >
                                ↑
                            </button>
                            <button
                                type="button"
                                class="inline-flex min-h-6 min-w-6 items-center justify-center rounded px-1 text-basalt-light hover:bg-ash-light disabled:opacity-35"
                                :disabled="i === rows(field.name).length - 1"
                                :aria-label="$t('nach unten')"
                                @click="moveRow(field.name, i, 1)"
                            >
                                ↓
                            </button>
                            <button
                                type="button"
                                class="text-danger px-1"
                                @click="removeRow(field.name, i)"
                            >
                                {{ $t('Entfernen') }}
                            </button>
                        </span>
                    </div>
                    <BlockFields
                        :fields="field.fields"
                        :model-value="row"
                        :media="media"
                        :errors="errors"
                        :path="`${path}.${field.name}.${i}`"
                        :paste-url="pasteUrl"
                        :blocks="blocks"
                        :depth="depth"
                        :context="
                            [
                                context,
                                field.label,
                                $t('Zeile :number', { number: i + 1 }),
                            ]
                                .filter(Boolean)
                                .join(' · ')
                        "
                        @update:model-value="setRow(field.name, i, $event)"
                    />
                </div>
                <button
                    type="button"
                    class="rounded-md border border-ash bg-white px-2.5 py-1 text-xs font-semibold"
                    @click="addRow(field)"
                >
                    + {{ $t('Zeile') }}
                </button>
            </div>

            <!-- Verschachtelte Blöcke an der Lava-Linie -->
            <div
                v-else-if="field.type === 'blocks'"
                class="ml-1 border-l-2 border-lava pl-3"
            >
                <BlockList
                    :model-value="list(field.name) as BlockValue[]"
                    :schema="blocks"
                    :allowed="field.allow ?? []"
                    :media="media"
                    :errors="errors"
                    :path="`${path}.${field.name}`"
                    :paste-url="pasteUrl"
                    :depth="depth + 1"
                    :scope="nestedScope(field)"
                    :scope-code="`field ${field.name} · allow`"
                    :context="context"
                    @update:model-value="set(field.name, $event)"
                />
            </div>

            <!-- Farbe -->
            <div
                v-else-if="field.type === 'color'"
                class="flex items-center gap-2"
            >
                <input
                    type="color"
                    :value="
                        hexColor.test(text(field.name))
                            ? text(field.name)
                            : '#23262b'
                    "
                    class="h-8 w-10 cursor-pointer rounded border border-ash bg-white p-0.5"
                    :aria-label="$t('Farbe wählen')"
                    @input="
                        set(
                            field.name,
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
                <input
                    :id="`${path}.${field.name}`"
                    type="text"
                    :value="text(field.name)"
                    :class="
                        controlClasses({
                            error: Boolean(error(field.name)),
                            mono: true,
                        })
                    "
                    class="max-w-32"
                    placeholder="#e4572e"
                    maxlength="7"
                    @input="
                        set(
                            field.name,
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
            </div>

            <!-- SEO: Titel und Beschreibung mit Zählern -->
            <div
                v-else-if="field.type === 'seo'"
                class="space-y-2 rounded-md border border-ash-light bg-paper p-2"
            >
                <div>
                    <input
                        :id="`${path}.${field.name}`"
                        type="text"
                        :value="seo(field.name).title"
                        :class="
                            controlClasses({
                                error: Boolean(error(field.name)),
                            })
                        "
                        :placeholder="$t('Titel für Suchmaschinen')"
                        maxlength="120"
                        @input="
                            set(field.name, {
                                ...seo(field.name),
                                title: ($event.target as HTMLInputElement)
                                    .value,
                            })
                        "
                    />
                    <p
                        class="mt-0.5 text-right font-mono text-[10.5px]"
                        :class="
                            seo(field.name).title.length > 60
                                ? 'text-warning'
                                : 'text-basalt-light'
                        "
                    >
                        {{ seo(field.name).title.length }} / 60
                    </p>
                </div>
                <div>
                    <textarea
                        :value="seo(field.name).description"
                        rows="2"
                        :class="controlClasses({})"
                        :placeholder="$t('Beschreibung')"
                        maxlength="320"
                        :aria-label="$t('Beschreibung')"
                        @input="
                            set(field.name, {
                                ...seo(field.name),
                                description: (
                                    $event.target as HTMLTextAreaElement
                                ).value,
                            })
                        "
                    />
                    <p
                        class="mt-0.5 text-right font-mono text-[10.5px]"
                        :class="
                            seo(field.name).description.length > 160
                                ? 'text-warning'
                                : 'text-basalt-light'
                        "
                    >
                        {{ seo(field.name).description.length }} / 160
                    </p>
                </div>
            </div>

            <!-- JSON: Prüfung beim Verlassen -->
            <textarea
                v-else-if="field.type === 'json'"
                :id="`${path}.${field.name}`"
                :value="jsonText(field.name)"
                rows="5"
                :class="
                    controlClasses({
                        error: Boolean(error(field.name)),
                        mono: true,
                    })
                "
                spellcheck="false"
                @blur="
                    checkJson(
                        field,
                        ($event.target as HTMLTextAreaElement).value,
                    )
                "
            />

            <!-- Unbekannter Typ -->
            <template v-else>
                <input
                    :id="`${path}.${field.name}`"
                    type="text"
                    :value="text(field.name)"
                    :class="
                        controlClasses({ error: Boolean(error(field.name)) })
                    "
                    @input="
                        set(
                            field.name,
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
                <p
                    v-if="!knownTypes.includes(field.type)"
                    class="text-warning mt-1 text-[11.5px]"
                >
                    <template
                        v-for="(part, p) in $t(
                            'Feldtyp :type hat noch keine eigene Eingabe — der Wert wird als Text geführt.',
                        ).split(':type')"
                        :key="p"
                        ><span v-if="p > 0" class="font-mono">{{
                            field.type
                        }}</span
                        >{{ part }}</template
                    >
                </p>
            </template>

            <!-- TB 13 Scheibe 3: Teile der Module am Textfeld (z. B. KI «Text verbessern») — übernommen wird ins Feld -->
            <ModuleSlot
                v-if="['text', 'textarea', 'richtext'].includes(field.type)"
                name="EntryField"
                :context="{
                    field: {
                        name: field.name,
                        label: field.label,
                        type: field.type,
                    },
                    value: modelValue[field.name],
                    path: `${path}.${field.name}`,
                }"
                @apply="set(field.name, $event as FieldValue)"
            />

            <p
                v-if="field.help"
                :id="helpId(field)"
                class="mt-1 text-[11.5px] text-basalt-light"
            >
                {{ field.help }}
            </p>
            <p
                v-if="error(field.name)"
                class="text-danger mt-1 text-[11.5px] font-semibold"
                role="alert"
            >
                {{ error(field.name) }}
            </p>
        </div>
        <MediaDialog
            :open="dialog !== null"
            :title="
                dialog?.mode === 'gallery'
                    ? $t('Bilder wählen')
                    : dialog?.mode === 'file'
                      ? $t('Datei wählen')
                      : $t('Bild wählen')
            "
            :media="media"
            :paste-url="pasteUrl"
            :model-value="dialogValue"
            :accept="dialog?.mode === 'file' ? 'all' : 'image'"
            :multiple="dialog?.mode === 'gallery'"
            :selected="
                dialog?.mode === 'gallery'
                    ? gallery(dialog.field.name).map((g) => g.id)
                    : []
            "
            :theme-images="dialog?.mode === 'address' ? themeImages : []"
            :theme-value="
                dialog?.mode === 'address'
                    ? addressThemeValue(dialog.field.name)
                    : ''
            "
            @pick="onDialogPick"
            @pick-asset="onDialogPickAsset"
            @close="dialog = null"
        />
        <button
            v-if="hiddenCount > 0"
            type="button"
            class="flex w-full items-center gap-1.5 rounded-md border border-dashed border-ash-light px-3 py-2 text-left text-[12.5px] font-semibold text-basalt-light hover:border-ash hover:text-basalt"
            :aria-expanded="showMore"
            @click="showMore = !showMore"
        >
            <span aria-hidden="true">{{ showMore ? '−' : '+' }}</span>
            {{
                showMore
                    ? $t('Weniger zeigen')
                    : $tc(
                          'Weitere Möglichkeiten (:count Feld)|Weitere Möglichkeiten (:count Felder)',
                          hiddenCount,
                      )
            }}
        </button>
    </div>
</template>
