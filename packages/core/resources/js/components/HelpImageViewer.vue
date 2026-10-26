<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue';
import { vDialog } from './a11y';

/**
 * Ein Bild der Hilfe gross (TB 29, Wunsch Hoang): Modal über der ganzen Seite, Hintergrund abgedunkelt und unscharf.
 * Esc, ein Klick daneben oder × schliessen; der Fokus geht hinein und danach zurück zum angeklickten Bild (v-dialog).
 */
const props = defineProps<{
    image: { src: string; alt: string; caption: string } | null;
}>();
const emit = defineEmits<{ (e: 'close'): void }>();

watch(
    () => props.image,
    (image) => {
        document.body.style.overflow = image ? 'hidden' : '';
    },
);

onBeforeUnmount(() => {
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-150 motion-reduce:transition-none"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-150 motion-reduce:transition-none"
            leave-to-class="opacity-0"
        >
            <div
                v-if="image"
                class="fixed inset-0 z-50 flex items-center justify-center bg-basalt/45 p-3 backdrop-blur-md md:p-8"
                data-hilfe-bild-gross
                @mousedown.self="emit('close')"
            >
                <figure
                    v-dialog="() => emit('close')"
                    role="dialog"
                    aria-modal="true"
                    :aria-label="image.caption || image.alt || $t('Bild')"
                    class="relative flex max-h-full max-w-[min(96vw,1400px)] flex-col overflow-hidden rounded-xl border border-ash-light bg-white shadow-2xl"
                >
                    <button
                        type="button"
                        class="absolute top-2 right-2 z-10 inline-flex h-8 w-8 items-center justify-center rounded-full bg-white/90 text-[18px] leading-none text-basalt shadow hover:text-lava"
                        :aria-label="$t('Schliessen')"
                        @click="emit('close')"
                    >
                        ×
                    </button>
                    <img
                        :src="image.src"
                        :alt="image.alt"
                        class="block max-h-[calc(100vh-7rem)] w-auto max-w-full object-contain"
                    />
                    <figcaption
                        v-if="image.caption"
                        class="border-t border-ash-light px-4 py-2.5 text-[13px] text-basalt-light"
                    >
                        {{ image.caption }}
                    </figcaption>
                </figure>
            </div>
        </Transition>
    </Teleport>
</template>
