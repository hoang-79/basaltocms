<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import type { ThemeImage } from '../types';
import { controlClasses } from './control';
import { groupThemeImages } from './themeImages';

/**
 * Bilder des Themes als Auswahl: alles unter assets/ mit Bild-Endung, geordnet nach Ordner (Reiter mit Zahl, unter
 * «Alle» je Ordner ein Abschnitt) und innerhalb eines Ordners nach Motiv — «buehne-foto.webp», «buehne-foto-960.webp»
 * und «buehne-schema.svg» stehen als eine Karte «buehne» mit Foto, Foto · 960 und Schema. Filter für Fotos und
 * Grafiken, Suche über den Pfad. Gewählt wird der Pfad ohne «assets/», so wie ein Bildfeld ihn speichert.
 */
const props = defineProps<{
    images: ThemeImage[];
    modelValue: string;
}>();
const emit = defineEmits<{ (e: 'pick', path: string): void }>();

const query = ref('');
const folder = ref<string | null>(null);
const kind = ref<'all' | 'photo' | 'graphic'>('all');
const list = ref<HTMLElement | null>(null);

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();

    return props.images.filter(
        (i) =>
            (q === '' || i.path.toLowerCase().includes(q)) &&
            (kind.value === 'all' ||
                (kind.value === 'graphic') ===
                    i.path.toLowerCase().endsWith('.svg')),
    );
});

/** Alle Ordner mit Zahl — nach Suche und Art gefiltert, damit die Reiter zeigen, wo Treffer liegen. */
const folders = computed(() => groupThemeImages(filtered.value));
const visible = computed(() =>
    folder.value === null
        ? folders.value
        : folders.value.filter((f) => f.name === folder.value),
);
const current = computed(
    () => props.images.find((i) => i.path === props.modelValue) ?? null,
);
const broken = ref<string[]>([]);

function folderLabel(name: string): string {
    return name === '' ? 'assets' : name;
}

onMounted(() => {
    void nextTick(() => {
        list.value
            ?.querySelector('[aria-selected="true"]')
            ?.scrollIntoView({ block: 'center' });
    });
});
</script>

<template>
    <div class="grid gap-2.5">
        <div class="flex flex-wrap items-center gap-2">
            <input
                v-model="query"
                type="search"
                :placeholder="$t('Bilder des Themes durchsuchen …')"
                :class="controlClasses({ small: true })"
                class="max-w-56"
                :aria-label="$t('Bilder des Themes durchsuchen')"
            />
            <div
                class="inline-flex rounded-md border border-ash-light bg-paper p-0.5"
                role="group"
                :aria-label="$t('Art')"
            >
                <button
                    v-for="k in [
                        { key: 'all', label: $t('Alle') },
                        { key: 'photo', label: $t('Fotos') },
                        { key: 'graphic', label: $t('Grafiken') },
                    ] as const"
                    :key="k.key"
                    type="button"
                    class="rounded px-2 py-0.5 text-[12px] font-semibold"
                    :class="
                        kind === k.key
                            ? 'bg-white text-basalt shadow-sm'
                            : 'text-basalt-light hover:text-basalt'
                    "
                    :aria-pressed="kind === k.key"
                    @click="kind = k.key"
                >
                    {{ k.label }}
                </button>
            </div>
            <span class="ml-auto font-mono text-[10.5px] text-basalt-light">{{
                $tc(':count Eintrag|:count Einträge', filtered.length)
            }}</span>
        </div>

        <div
            class="flex flex-wrap gap-1.5"
            role="group"
            :aria-label="$t('Ordner')"
        >
            <button
                type="button"
                class="rounded-full border px-2.5 py-0.5 text-[12px] font-semibold"
                :class="
                    folder === null
                        ? 'border-basalt bg-basalt text-white'
                        : 'border-ash-light bg-white text-basalt-light hover:border-ash'
                "
                :aria-pressed="folder === null"
                @click="folder = null"
            >
                {{ $t('Alle Ordner') }}
                <span class="font-mono font-normal opacity-80">{{
                    filtered.length
                }}</span>
            </button>
            <button
                v-for="f in folders"
                :key="f.name"
                type="button"
                class="inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-[12px] font-semibold"
                :class="
                    folder === f.name
                        ? 'border-basalt bg-basalt text-white'
                        : 'border-ash-light bg-white text-basalt-light hover:border-ash'
                "
                :aria-pressed="folder === f.name"
                @click="folder = f.name"
            >
                <svg viewBox="0 0 16 16" class="h-3 w-3" aria-hidden="true">
                    <path
                        d="M1.5 4.5a1 1 0 0 1 1-1h3.6l1.4 1.5h6a1 1 0 0 1 1 1v6.5a1 1 0 0 1-1 1h-11a1 1 0 0 1-1-1Z"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.4"
                        stroke-linejoin="round"
                    />
                </svg>
                {{ folderLabel(f.name) }}
                <span class="font-mono font-normal opacity-80">{{
                    f.count
                }}</span>
            </button>
        </div>

        <div
            v-if="current"
            class="border-lava-light flex items-center gap-2.5 rounded-md border bg-lava/5 px-2 py-1.5"
        >
            <img
                :src="current.url"
                alt=""
                class="h-9 w-12 rounded border border-ash-light bg-white"
                :class="
                    current.path.endsWith('.svg')
                        ? 'object-contain p-0.5'
                        : 'object-cover'
                "
            />
            <span class="min-w-0 text-[12px]">
                <span
                    class="block text-[10.5px] font-semibold tracking-wide text-basalt-light uppercase"
                    >{{ $t('Aktuell gewählt') }}</span
                >
                <span class="block truncate font-mono text-basalt">{{
                    current.path
                }}</span>
            </span>
        </div>

        <p v-if="visible.length === 0" class="text-ash-dark text-xs">
            {{ $t('Kein Treffer.') }}
        </p>
        <div
            v-else
            ref="list"
            class="scroll-y grid max-h-[46vh] gap-4 pr-1"
            role="listbox"
            :aria-label="$t('Bilder des Themes')"
        >
            <section
                v-for="f in visible"
                :key="f.name"
                :aria-label="folderLabel(f.name)"
            >
                <h3
                    v-if="folder === null"
                    class="sticky top-0 z-10 mb-2 flex items-center gap-1.5 border-b border-ash-light bg-white/95 py-1 text-[12.5px] font-bold text-basalt backdrop-blur-sm"
                >
                    <svg
                        viewBox="0 0 16 16"
                        class="h-3.5 w-3.5 text-lava-dark"
                        aria-hidden="true"
                    >
                        <path
                            d="M1.5 4.5a1 1 0 0 1 1-1h3.6l1.4 1.5h6a1 1 0 0 1 1 1v6.5a1 1 0 0 1-1 1h-11a1 1 0 0 1-1-1Z"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.4"
                            stroke-linejoin="round"
                        />
                    </svg>
                    {{ folderLabel(f.name) }}
                    <span
                        class="font-mono text-[10.5px] font-normal text-basalt-light"
                        >{{ $tc(':count Bild|:count Bilder', f.count) }}</span
                    >
                </h3>
                <div class="flex flex-wrap gap-2">
                    <div
                        v-for="group in f.groups"
                        :key="group.key"
                        class="rounded-lg border p-1.5"
                        :class="
                            group.tiles.length > 1
                                ? 'border-ash-light bg-paper'
                                : 'border-transparent p-0'
                        "
                    >
                        <p
                            v-if="group.tiles.length > 1"
                            class="mb-1 px-0.5 text-[11px] font-bold text-basalt"
                        >
                            {{ group.key }}
                        </p>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="tile in group.tiles"
                                :key="tile.image.path"
                                type="button"
                                role="option"
                                :aria-selected="modelValue === tile.image.path"
                                class="relative w-[7.25rem] overflow-hidden rounded-md border bg-white text-left"
                                :class="
                                    modelValue === tile.image.path
                                        ? 'border-lava-dark shadow-[0_0_0_2px_var(--color-lava-light)]'
                                        : 'border-ash-light hover:border-ash'
                                "
                                :title="`assets/${tile.image.path}`"
                                @click="emit('pick', tile.image.path)"
                            >
                                <span
                                    class="block h-20 bg-[repeating-conic-gradient(#f1efe9_0%_25%,#fff_0%_50%)] bg-[length:12px_12px]"
                                >
                                    <img
                                        v-if="!broken.includes(tile.image.url)"
                                        :src="tile.image.url"
                                        :alt="tile.image.path"
                                        loading="lazy"
                                        class="h-full w-full"
                                        :class="
                                            tile.image.path.endsWith('.svg')
                                                ? 'object-contain p-1.5'
                                                : 'object-cover'
                                        "
                                        @error="broken.push(tile.image.url)"
                                    />
                                    <span
                                        v-else
                                        class="text-danger flex h-full items-center justify-center text-[10.5px] font-semibold"
                                        >{{ $t('Datei fehlt') }}</span
                                    >
                                </span>
                                <span
                                    class="flex items-center gap-1 border-t border-ash-light px-1.5 py-0.5 text-[10.5px] text-basalt"
                                >
                                    <span class="min-w-0 flex-1 truncate">{{
                                        tile.label
                                    }}</span>
                                    <span
                                        class="font-mono text-[9px] text-basalt-light uppercase"
                                        >{{ tile.ext }}</span
                                    >
                                </span>
                                <span
                                    v-if="modelValue === tile.image.path"
                                    class="hex absolute top-1 right-1 flex h-3.5 w-4 items-center justify-center bg-lava-dark text-[9px] font-bold text-white"
                                    aria-hidden="true"
                                    >✓</span
                                >
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
