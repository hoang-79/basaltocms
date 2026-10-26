<script setup lang="ts">
import { computed } from 'vue';

/**
 * Lava-Code nur lesend (wie Station E): Basalt-Fläche, Zeilennummern ab `start`, lange Zeilen brechen um —
 * nichts scrollt waagrecht. Ohne `start` ohne Nummern.
 */
const props = withDefaults(
    defineProps<{ code: string; start?: number | null }>(),
    { start: null },
);

const lines = computed(() => props.code.replace(/\n$/, '').split('\n'));
</script>

<template>
    <ol
        class="rounded-md bg-basalt px-3 py-2.5 font-mono text-[12px] leading-[1.55] text-paper"
        :aria-label="
            start ? $t('Lava ab Zeile :line', { line: start }) : 'Lava'
        "
    >
        <li
            v-for="(line, i) in lines"
            :key="i"
            class="grid gap-2"
            :class="start ? 'grid-cols-[2.5rem_1fr]' : 'grid-cols-1'"
        >
            <span v-if="start" class="text-right text-ash select-none">{{
                start + i
            }}</span>
            <span class="break-words whitespace-pre-wrap">{{
                line || ' '
            }}</span>
        </li>
    </ol>
</template>
