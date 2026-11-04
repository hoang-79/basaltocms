<script setup lang="ts">
import { computed, onBeforeUnmount, watch } from 'vue';
import { t } from '../i18n';
import Modal from './Modal.vue';

/**
 * Bilder gross in einem Modal statt in einem neuen Tab: Blättern mit Knöpfen und den Pfeiltasten, der Zähler steht im
 * Untertitel. `index` ist das gezeigte Bild, `null` heisst zu (v-model:index).
 */
const props = defineProps<{
    images: { src: string; alt: string }[];
    index: number | null;
    title: string;
}>();
const emit = defineEmits<{ (e: 'update:index', value: number | null): void }>();

const current = computed(() =>
    props.index === null ? null : (props.images[props.index] ?? null),
);

function go(step: number): void {
    if (props.index === null || props.images.length === 0) {
        return;
    }
    const count = props.images.length;
    emit('update:index', (props.index + step + count) % count);
}

function onKey(event: KeyboardEvent): void {
    if (event.key === 'ArrowLeft') {
        event.preventDefault();
        go(-1);
    } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        go(1);
    }
}

watch(
    () => current.value !== null,
    (open) => {
        if (open) {
            window.addEventListener('keydown', onKey);
        } else {
            window.removeEventListener('keydown', onKey);
        }
    },
);
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

const arrow =
    'absolute top-1/2 grid h-11 w-11 -translate-y-1/2 place-items-center rounded-full border border-ash-light bg-white text-[22px] leading-none text-basalt shadow hover:bg-paper focus-visible:outline-2 focus-visible:outline-lava';
</script>

<template>
    <Modal
        :open="current !== null"
        full
        :title="title"
        :subtitle="
            current !== null && index !== null
                ? t('Bild :n von :count', {
                      n: index + 1,
                      count: images.length,
                  })
                : ''
        "
        @close="emit('update:index', null)"
    >
        <div
            v-if="current"
            class="relative flex min-h-0 flex-1 items-center justify-center bg-paper p-3 md:px-16"
        >
            <img
                :src="current.src"
                :alt="current.alt"
                class="max-h-full max-w-full rounded border border-ash-light bg-white object-contain"
            />
            <template v-if="images.length > 1">
                <button
                    type="button"
                    :class="[arrow, 'left-3']"
                    :aria-label="t('Vorheriges Bild')"
                    @click="go(-1)"
                >
                    <span aria-hidden="true">‹</span>
                </button>
                <button
                    type="button"
                    :class="[arrow, 'right-3']"
                    :aria-label="t('Nächstes Bild')"
                    @click="go(1)"
                >
                    <span aria-hidden="true">›</span>
                </button>
            </template>
        </div>
    </Modal>
</template>
