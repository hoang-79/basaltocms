<script setup lang="ts">
import { computed } from 'vue';
import type { Stage } from '../types-workflow';
import { lineStations } from './workflow';

/**
 * Die Bahn einer Fassung (Mockup 13): sechs Stationen Entwurf … Archiviert als Kreise auf einer Linie wie die rote
 * Linie — zurückgelegt Basalt, aktuell Lava gross mit Hof, kommend hohl. Unter 768 px stehen Kurzformen unter den
 * Kreisen; die Bahn bricht nicht und scrollt nicht.
 */
const props = defineProps<{ stage: Stage }>();

const stations = computed(() => lineStations(props.stage));
</script>

<template>
    <ol
        class="mt-1 mb-4 flex max-w-[720px] items-start"
        :aria-label="$t('Ablauf der Fassung')"
    >
        <li
            v-for="(s, i) in stations"
            :key="s.stage"
            class="relative flex min-w-0 flex-1 flex-col items-center gap-1.5 before:absolute before:top-[6px] before:left-1/2 before:h-0.5 before:w-full before:content-['']"
            :class="[
                i === stations.length - 1 ? 'before:hidden' : '',
                s.state === 'past' ? 'before:bg-basalt' : 'before:bg-ash-light',
            ]"
            :title="s.label"
            :aria-current="s.state === 'current' ? 'step' : undefined"
        >
            <span
                class="relative z-[1] flex h-3.5 items-center justify-center"
                aria-hidden="true"
            >
                <span
                    class="block rounded-full"
                    :class="
                        s.state === 'current'
                            ? 'h-3.5 w-3.5 bg-lava ring-[3px] ring-lava/20'
                            : s.state === 'past'
                              ? 'h-2.5 w-2.5 bg-basalt'
                              : 'h-2.5 w-2.5 border-2 border-ash bg-white'
                    "
                />
            </span>
            <span
                class="text-center text-[11px] leading-tight"
                :class="
                    s.state === 'current'
                        ? 'font-semibold text-basalt'
                        : s.state === 'past'
                          ? 'text-basalt'
                          : 'text-basalt-light'
                "
            >
                <span class="hidden md:inline">{{ s.label }}</span>
                <span class="md:hidden" aria-hidden="true">{{ s.short }}</span>
                <span class="sr-only md:hidden">{{ s.label }}</span>
                <span v-if="s.state === 'current'" class="sr-only">
                    ({{ $t('aktueller Schritt') }})</span
                >
            </span>
        </li>
    </ol>
</template>
