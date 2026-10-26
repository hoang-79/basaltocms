<script setup lang="ts">
import { ref, watch } from 'vue';
import { autosaveToast } from './autosave';

/**
 * Die Meldung des Autosave unten rechts: dezent (klein, Papier-Grund), aber nicht zu übersehen — sie erscheint bei
 * jedem Speichern für drei Sekunden mit Häkchen und Uhrzeit; ein Fehler bleibt stehen, bis das nächste Speichern
 * gelingt. Für Screenreader eine höfliche Live-Region.
 */
const visible = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => [autosaveToast.seq, autosaveToast.status] as const,
    () => {
        // Zurück auf den gespeicherten Stand (Abbrechen, Wert zurückgesetzt): ein stehender Fehler gilt nicht mehr
        if (autosaveToast.status === 'idle') {
            visible.value = false;

            return;
        }
        if (autosaveToast.status === 'pending') {
            return;
        }
        visible.value = true;
        if (timer) {
            clearTimeout(timer);
        }
        if (autosaveToast.status === 'saved') {
            timer = setTimeout(() => (visible.value = false), 3000);
        }
    },
);
</script>

<template>
    <div
        class="pointer-events-none fixed right-4 bottom-4 z-50 max-md:right-3 max-md:bottom-20"
        aria-live="polite"
        role="status"
    >
        <Transition
            enter-active-class="transition duration-200 ease-out motion-reduce:transition-none"
            enter-from-class="translate-y-2 opacity-0"
            leave-active-class="transition duration-300 ease-in motion-reduce:transition-none"
            leave-to-class="translate-y-1 opacity-0"
        >
            <div
                v-if="visible"
                class="pointer-events-auto flex max-w-[24rem] items-center gap-2 rounded-2xl border px-3.5 py-1.5 text-[12.5px] shadow-md"
                :class="
                    autosaveToast.status === 'error'
                        ? 'border-danger/40 text-danger bg-white'
                        : 'border-success/40 bg-white text-basalt'
                "
            >
                <span
                    v-if="autosaveToast.status === 'saving'"
                    class="h-3 w-3 flex-none animate-spin rounded-full border-2 border-ash border-t-lava motion-reduce:animate-none"
                    aria-hidden="true"
                />
                <span
                    v-else
                    class="flex h-4 w-4 flex-none items-center justify-center rounded-full text-[10px] font-bold text-white"
                    :class="
                        autosaveToast.status === 'error'
                            ? 'bg-danger'
                            : 'bg-success'
                    "
                    aria-hidden="true"
                    >{{ autosaveToast.status === 'error' ? '!' : '✓' }}</span
                >
                <span
                    class="line-clamp-2 min-w-0"
                    :title="autosaveToast.text"
                    >{{ autosaveToast.text }}</span
                >
                <span
                    v-if="autosaveToast.at && autosaveToast.status !== 'saving'"
                    class="flex-none font-mono text-[11px] text-basalt-light"
                    >{{ autosaveToast.at }}</span
                >
            </div>
        </Transition>
    </div>
</template>
