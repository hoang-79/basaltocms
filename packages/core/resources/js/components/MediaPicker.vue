<script setup lang="ts">
import { computed, ref } from 'vue';
import { requestError } from '../http';
import { t } from '../i18n';
import type { MediaFolder, MediaOption } from '../types';
import { isImageOption, uploadedMedia, uploaderFor } from './media';
import { controlClasses } from './control';

/**
 * Die Mediathek als Auswahl (Mockup 11, Bild-Dialog): Raster mit Suche und Ordner-Auswahl, Upload-Knopf zum
 * Einfügen-Endpunkt; `accept` schränkt auf Bilder ein oder lässt alle Dateien zu. Mehrfachauswahl für Galerien
 * über `multiple`. Kacheln ohne Alt-Text tragen die Marke «Alt-Text fehlt» (K-10). TB 30: Dateien lassen sich
 * hineinziehen; `large` für den Dialog — Ablagefläche oben, grössere Kacheln mit Namen.
 */
const props = withDefaults(
    defineProps<{
        media: MediaOption[];
        pasteUrl: string;
        modelValue: number | null;
        accept?: 'image' | 'all';
        multiple?: boolean;
        selected?: number[];
        folders?: MediaFolder[];
        large?: boolean;
    }>(),
    {
        large: false,
        accept: 'image',
        multiple: false,
        selected: () => [],
        folders: () => [],
    },
);
const emit = defineEmits<{
    (e: 'update:modelValue', id: number | null): void;
    (e: 'pick', item: MediaOption): void;
}>();

const query = ref('');
const folder = ref('');
const uploading = ref(false);
const error = ref('');
const fileInput = ref<HTMLInputElement | null>(null);
const dragging = ref(false);
let dragDepth = 0;

const all = computed<MediaOption[]>(() => {
    const seen = new Set<number>();
    const merged: MediaOption[] = [];
    [...uploadedMedia, ...props.media].forEach((m) => {
        if (!seen.has(m.id)) {
            seen.add(m.id);
            merged.push(m);
        }
    });

    return merged.filter((m) => props.accept === 'all' || isImageOption(m));
});

const items = computed(() => {
    const q = query.value.trim().toLowerCase();
    const inFolder =
        folder.value === ''
            ? all.value
            : all.value.filter(
                  (m) => String(m.folder_id ?? '') === folder.value,
              );

    return q === ''
        ? inFolder
        : inFolder.filter(
              (m) => m.name.toLowerCase().includes(q) || String(m.id) === q,
          );
});

/* Einträge, deren Datei nicht lädt (gelöscht, Ablage geleert): «Datei fehlt» statt eines kaputten Bildes */
const broken = ref<number[]>([]);

function altMissing(item: MediaOption): number {
    return item.alt_missing?.length ?? 0;
}

function isSelected(item: MediaOption): boolean {
    return props.multiple
        ? props.selected.includes(item.id)
        : props.modelValue === item.id;
}

function pick(item: MediaOption): void {
    emit('update:modelValue', item.id);
    emit('pick', item);
}

async function upload(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';
    await uploadFiles(files);
}

/* Hineinziehen: die Zähltiefe hält die Markierung, solange der Zeiger über Kindelementen ist */
function onDragEnter(event: DragEvent): void {
    if (!event.dataTransfer?.types.includes('Files')) {
        return;
    }
    dragDepth++;
    dragging.value = true;
}

function onDragLeave(): void {
    dragDepth = Math.max(0, dragDepth - 1);
    dragging.value = dragDepth > 0;
}

async function onDrop(event: DragEvent): Promise<void> {
    dragDepth = 0;
    dragging.value = false;
    const files = Array.from(event.dataTransfer?.files ?? []).filter(
        (f) => props.accept === 'all' || f.type.startsWith('image/'),
    );
    if (files.length === 0) {
        error.value = t('Nur Bilder lassen sich hier ablegen.');

        return;
    }
    await uploadFiles(props.multiple ? files : files.slice(0, 1));
}

async function uploadFiles(files: File[]): Promise<void> {
    if (files.length === 0 || !props.pasteUrl) {
        return;
    }
    uploading.value = true;
    error.value = '';
    const uploader = uploaderFor(props.pasteUrl);
    try {
        for (const file of files) {
            const uploaded = await uploader({ file });
            const option = uploadedMedia.find((m) => m.id === uploaded.id);
            if (option) {
                pick(option);
            }
        }
    } catch (e) {
        error.value = requestError(e);
    } finally {
        uploading.value = false;
    }
}
</script>

<template>
    <div
        class="relative"
        @dragenter.prevent="onDragEnter"
        @dragover.prevent
        @dragleave.prevent="onDragLeave"
        @drop.prevent="onDrop"
    >
        <div
            v-if="large"
            class="mb-3 flex flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed px-4 py-6 text-center"
            :class="
                dragging
                    ? 'border-lava bg-lava/10'
                    : 'border-ash-light bg-paper'
            "
        >
            <span class="text-[13.5px] font-semibold">{{
                uploading
                    ? $t('Lädt hoch …')
                    : accept === 'image'
                      ? $t('Bild hierher ziehen')
                      : $t('Datei hierher ziehen')
            }}</span>
            <span class="text-[12px] text-basalt-light"
                >{{ $t('oder') }}
                <button
                    type="button"
                    class="font-semibold text-lava-dark underline disabled:text-ash"
                    :disabled="uploading || !pasteUrl"
                    @click="fileInput?.click()"
                >
                    {{ $t('vom Computer wählen') }}
                </button>
                ·
                {{
                    accept === 'image'
                        ? $t('JPEG, PNG, WebP oder GIF, höchstens 10 MB')
                        : $t('höchstens 10 MB')
                }}</span
            >
        </div>
        <div
            v-else-if="dragging"
            class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center rounded-md border-2 border-dashed border-lava bg-lava/10 text-[13px] font-semibold"
        >
            {{ $t('Hier ablegen') }}
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <input
                v-model="query"
                type="search"
                :placeholder="$t('Mediathek durchsuchen …')"
                :class="controlClasses({ small: true })"
                class="max-w-56"
                :aria-label="$t('Mediathek durchsuchen')"
            />
            <select
                v-if="folders.length > 0"
                v-model="folder"
                :class="controlClasses({ small: true })"
                class="w-auto max-w-40"
                :aria-label="$t('Ordner')"
            >
                <option value="">{{ $t('Alle Ordner') }}</option>
                <option v-for="f in folders" :key="f.id" :value="String(f.id)">
                    {{ '– '.repeat(f.depth) }}{{ f.name }}
                </option>
            </select>
            <button
                type="button"
                class="rounded-md border border-ash bg-white px-2.5 py-1 text-xs font-semibold text-basalt hover:bg-paper disabled:text-ash"
                :disabled="uploading || !pasteUrl"
                :title="
                    pasteUrl
                        ? $t('Datei in die Mediathek laden')
                        : $t('Upload-Endpunkt fehlt')
                "
                @click="fileInput?.click()"
            >
                {{ uploading ? $t('Lädt hoch …') : `↑ ${$t('Hochladen')}` }}
            </button>
            <input
                ref="fileInput"
                type="file"
                class="hidden"
                :accept="accept === 'image' ? 'image/*' : undefined"
                :multiple="multiple"
                @change="upload"
            />
            <span class="ml-auto font-mono text-[10.5px] text-basalt-light">{{
                $tc(':count Eintrag|:count Einträge', items.length)
            }}</span>
        </div>
        <p
            v-if="error"
            class="text-danger mt-1 text-[11.5px] font-semibold"
            role="alert"
        >
            {{ error }}
        </p>
        <p v-if="items.length === 0" class="text-ash-dark mt-2 text-xs">
            {{
                all.length === 0
                    ? $t(
                          'Noch nichts in der Mediathek — Datei hochladen oder Station «Medien».',
                      )
                    : $t('Kein Treffer.')
            }}
        </p>
        <div
            v-else
            class="scroll-y mt-2 grid gap-1.5"
            :class="
                large
                    ? 'max-h-[50vh] grid-cols-[repeat(auto-fill,minmax(7.5rem,1fr))]'
                    : 'max-h-56 grid-cols-[repeat(auto-fill,minmax(4.5rem,1fr))]'
            "
            role="listbox"
            :aria-multiselectable="multiple"
        >
            <button
                v-for="m in items"
                :key="m.id"
                type="button"
                role="option"
                :aria-selected="isSelected(m)"
                class="relative overflow-hidden rounded-md border bg-paper"
                :class="[
                    large ? 'h-28' : 'h-[4.5rem]',
                    isSelected(m)
                        ? 'border-lava-dark shadow-[0_0_0_2px_var(--color-lava-light)]'
                        : 'border-ash-light hover:border-ash',
                ]"
                :title="`${m.name}${m.width ? ` · ${m.width} × ${m.height}` : ''} · #${m.id}`"
                @click="pick(m)"
            >
                <img
                    v-if="m.url && !broken.includes(m.id)"
                    :src="m.url"
                    :alt="m.name"
                    class="h-full w-full object-cover"
                    @error="broken.push(m.id)"
                />
                <span
                    v-else-if="broken.includes(m.id)"
                    class="flex h-full flex-col items-center justify-center gap-0.5 px-1 text-center"
                    :title="
                        $t(
                            'Die Datei fehlt in der Ablage — bitte neu hochladen.',
                        )
                    "
                >
                    <span class="text-danger text-[10.5px] font-semibold">{{
                        $t('Datei fehlt')
                    }}</span>
                    <span
                        v-if="!large"
                        class="block text-[9.5px] break-all text-basalt-light"
                        >{{ m.name }}</span
                    >
                </span>
                <span
                    v-else
                    class="block px-1 text-[10px] break-all text-basalt-light"
                    >{{ m.name }}</span
                >
                <span
                    v-if="large && m.url"
                    class="absolute inset-x-0 bottom-0 truncate bg-white/90 px-1.5 py-0.5 text-left text-[10.5px] text-basalt"
                    >{{ m.name }}</span
                >
                <span
                    v-if="isSelected(m)"
                    class="hex absolute top-1 right-1 flex h-3.5 w-4 items-center justify-center bg-lava-dark text-[9px] font-bold text-white"
                    aria-hidden="true"
                    >✓</span
                >
                <span
                    v-if="altMissing(m) > 0"
                    class="text-danger absolute bottom-0 left-0 flex items-center gap-1 rounded-tr bg-white/90 px-1 text-[9px] font-semibold"
                    :title="
                        $t('Alt-Text fehlt: :locales', {
                            locales: (m.alt_missing ?? [])
                                .map((l) => l.toUpperCase())
                                .join(', '),
                        })
                    "
                >
                    <span
                        class="hex bg-danger inline-flex h-2.5 w-[11px] items-center justify-center font-mono text-[7px] text-white"
                        aria-hidden="true"
                        >{{ altMissing(m) }}</span
                    >
                    {{ $t('Alt-Text fehlt') }}
                </span>
            </button>
        </div>
    </div>
</template>
