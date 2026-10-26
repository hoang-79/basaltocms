<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { MediaFolder, MediaOption, ThemeImage } from '../types';
import MediaPicker from './MediaPicker.vue';
import Modal from './Modal.vue';
import ThemeImagePicker from './ThemeImagePicker.vue';

/**
 * Bild oder Datei wählen (TB 30): die Mediathek als Dialog über der Seite — oben eine Fläche zum Hineinziehen und
 * «vom Computer wählen», darunter alles aus der Mediathek mit Suche und Ordnern. Ein Bild wählen schliesst den Dialog;
 * bei Galerien (`multiple`) bleibt er offen, bis «Fertig». Hochgeladenes ist sofort gewählt.
 *
 * Bildfelder mit Adresse (`themeImages`): ein zweiter Reiter mit den Bildern unter assets/ des Themes; steht im Feld
 * schon eines davon (`themeValue`), öffnet der Dialog dort und zeigt es gewählt.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        media: MediaOption[];
        pasteUrl: string;
        modelValue?: number | null;
        accept?: 'image' | 'all';
        multiple?: boolean;
        selected?: number[];
        folders?: MediaFolder[];
        themeImages?: ThemeImage[];
        themeValue?: string;
    }>(),
    {
        modelValue: null,
        accept: 'image',
        multiple: false,
        selected: () => [],
        folders: () => [],
        themeImages: () => [],
        themeValue: '',
    },
);
const emit = defineEmits<{
    (e: 'pick', item: MediaOption): void;
    (e: 'pickAsset', path: string): void;
    (e: 'close'): void;
}>();

const tab = ref<'media' | 'theme'>('media');
const hasThemeTab = computed(() => props.themeImages.length > 0);

watch(
    () => props.open,
    (open) => {
        if (open) {
            tab.value =
                hasThemeTab.value &&
                props.themeImages.some((i) => i.path === props.themeValue)
                    ? 'theme'
                    : 'media';
        }
    },
    { immediate: true },
);
</script>

<template>
    <Modal
        :open="open"
        :title="title"
        :subtitle="
            multiple
                ? $t(
                      'Bilder anklicken zum Wählen oder Abwählen — oder neue hineinziehen.',
                  )
                : themeImages.length > 0
                  ? $t(
                        'Ein Bild aus der Mediathek oder des Themes wählen — oder ein neues hochladen.',
                    )
                  : $t(
                        'Ein Bild aus der Mediathek wählen oder ein neues hochladen.',
                    )
        "
        @close="emit('close')"
    >
        <div
            v-if="hasThemeTab"
            class="mb-3 flex gap-1 border-b border-ash-light"
            role="tablist"
        >
            <button
                v-for="t in [
                    { key: 'media', label: $t('Mediathek') },
                    {
                        key: 'theme',
                        label: `${$t('Bilder des Themes')} · ${themeImages.length}`,
                    },
                ] as const"
                :key="t.key"
                type="button"
                role="tab"
                :aria-selected="tab === t.key"
                class="-mb-px border-b-2 px-3 py-1.5 text-[13px] font-semibold"
                :class="
                    tab === t.key
                        ? 'border-lava-dark text-basalt'
                        : 'border-transparent text-basalt-light hover:text-basalt'
                "
                @click="tab = t.key"
            >
                {{ t.label }}
            </button>
        </div>
        <ThemeImagePicker
            v-if="hasThemeTab && tab === 'theme'"
            :images="themeImages"
            :model-value="themeValue"
            @pick="
                emit('pickAsset', $event);
                emit('close');
            "
        />
        <MediaPicker
            v-else
            large
            :media="media"
            :paste-url="pasteUrl"
            :model-value="modelValue"
            :accept="accept"
            :multiple="multiple"
            :selected="selected"
            :folders="folders"
            @pick="
                emit('pick', $event);
                if (!multiple) emit('close');
            "
        />
        <template v-if="multiple" #footer>
            <span class="text-[12px] text-basalt-light">{{
                $tc(
                    ':count Bild gewählt|:count Bilder gewählt',
                    selected.length,
                )
            }}</span>
            <button
                type="button"
                class="rounded-md bg-lava-dark px-3 py-1.5 text-[13px] font-semibold text-white"
                @click="emit('close')"
            >
                {{ $t('Fertig') }}
            </button>
        </template>
    </Modal>
</template>
