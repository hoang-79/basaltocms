<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { openHelp } from './help';
import type { BackendProps } from '../types';

/**
 * Der Inhaltskopf: Krume, Titel, Führungssatz in Prosa (statt Kachelreihe), Knöpfe rechts.
 * Mit `station` der Stationskopf der roten Linie (gestaltung.md § 7): Buchstabe im Lava-Kreis, Titel, «· Schlüsselwort».
 * Die Krume kann statt Text ein Slot sein (Links zurück). Gibt es zur Route eine Seite der Redaktions-Hilfe
 * (`backend.help_url`), steht rechts ein «?» dorthin (N-09) — seit TB 20 öffnet es das Hilfe-Panel; mit ⌘/Strg-Klick
 * oder mittlerer Taste bleibt es ein Link auf die Hilfeseite.
 */
withDefaults(
    defineProps<{
        title: string;
        crumb?: string;
        lead?: string;
        station?: { letter: string; keyword: string } | null;
    }>(),
    { crumb: '', lead: '', station: null },
);

const page = usePage<BackendProps>();
const helpUrl = computed(() => page.props.backend?.help_url ?? null);

function onHelp(event: MouseEvent): void {
    if (
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey ||
        event.button !== 0
    ) {
        return;
    }
    event.preventDefault();
    openHelp(page.props.backend?.help?.page ?? null);
}
</script>

<template>
    <header class="mb-3.5">
        <p
            v-if="crumb || $slots.crumb"
            class="mb-1 text-[11px] text-basalt-light"
        >
            <slot name="crumb">{{ crumb }}</slot>
        </p>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 items-center gap-3.5">
                <span
                    v-if="station"
                    class="flex h-[42px] w-[42px] shrink-0 items-center justify-center rounded-full bg-lava font-mono text-xl font-bold text-white"
                    aria-hidden="true"
                    >{{ station.letter }}</span
                >
                <div class="min-w-0">
                    <h1
                        class="text-[23px] leading-tight font-bold tracking-[-.015em]"
                    >
                        {{ title }}
                        <span
                            v-if="station"
                            class="font-mono text-xs font-normal tracking-normal text-basalt-light"
                            >· {{ station.keyword }}</span
                        >
                    </h1>
                    <p
                        v-if="lead || $slots.lead"
                        class="mt-1.5 max-w-[62ch] text-[13.5px] text-basalt-light"
                    >
                        <slot name="lead">{{ lead }}</slot>
                    </p>
                </div>
            </div>
            <div
                v-if="$slots.actions || helpUrl"
                class="flex flex-wrap items-center gap-2"
            >
                <slot name="actions" />
                <a
                    v-if="helpUrl"
                    :href="helpUrl"
                    class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-ash-light bg-white text-[13px] font-semibold text-basalt-light hover:border-lava hover:text-lava"
                    :title="$t('Hilfe zu dieser Seite (?)')"
                    :aria-label="$t('Hilfe zu dieser Seite')"
                    aria-keyshortcuts="? F1"
                    @click="onHelp"
                    >?</a
                >
            </div>
        </div>
    </header>
</template>
