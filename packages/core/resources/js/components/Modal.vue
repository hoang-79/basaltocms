<script setup lang="ts">
import { onBeforeUnmount, useId, watch } from 'vue';
import { vDialog } from './a11y';

/**
 * Ein Dialog über der Seite: Hintergrund abgedunkelt und unscharf, die Seite darunter scrollt nicht. Teleport an
 * `body` — das Ziel gibt es auf jeder Seite. Fokus, Escape und Tab-Falle über `v-dialog` (WCAG 2.1.2, 2.4.3):
 * Der Fokus geht hinein (Eingabefeld, sonst erstes Bedienelement), Escape und Klick daneben schliessen, beim
 * Schliessen kehrt er zum auslösenden Knopf zurück. Titel und Untertitel benennen und beschreiben den Dialog.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        subtitle?: string;
        wide?: boolean;
    }>(),
    { subtitle: '', wide: true },
);
const emit = defineEmits<{ (e: 'close'): void }>();
const id = useId();

watch(
    () => props.open,
    (open) => {
        document.body.style.overflow = open ? 'hidden' : '';
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
                v-if="open"
                class="fixed inset-0 z-40 flex items-start justify-center bg-basalt/35 p-3 backdrop-blur-sm md:p-8"
                @mousedown.self="emit('close')"
            >
                <div
                    v-dialog="() => emit('close')"
                    role="dialog"
                    aria-modal="true"
                    :aria-labelledby="`${id}-title`"
                    :aria-describedby="subtitle ? `${id}-subtitle` : undefined"
                    class="flex max-h-full w-full flex-col overflow-hidden rounded-xl border border-ash-light bg-white shadow-2xl"
                    :class="wide ? 'max-w-4xl' : 'max-w-lg'"
                >
                    <!-- div statt header/footer: im Dialog an body wären es zweite Landmarken «banner»/«contentinfo» -->
                    <div
                        class="flex items-start justify-between gap-3 border-b border-ash-light px-4 py-3"
                    >
                        <div class="min-w-0">
                            <h2
                                :id="`${id}-title`"
                                class="text-[15px] font-semibold"
                            >
                                {{ title }}
                            </h2>
                            <p
                                v-if="subtitle"
                                :id="`${id}-subtitle`"
                                class="text-[11.5px] text-basalt-light"
                            >
                                {{ subtitle }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="min-h-7 min-w-7 rounded-md px-2 py-1 text-[18px] leading-none text-basalt-light hover:bg-paper"
                            :aria-label="$t('Schliessen')"
                            @click="emit('close')"
                        >
                            ×
                        </button>
                    </div>
                    <div
                        class="scroll-y min-h-0 flex-1 overflow-y-auto px-4 py-3"
                    >
                        <slot />
                    </div>
                    <div
                        v-if="$slots.footer"
                        class="flex flex-wrap items-center justify-between gap-2 border-t border-ash-light px-4 py-2.5"
                    >
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
