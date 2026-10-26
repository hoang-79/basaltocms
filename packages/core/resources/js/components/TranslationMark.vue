<script setup lang="ts">
import { computed } from 'vue';
import type { TranslationState } from '../types';
import StatusHex from './StatusHex.vue';
import { translationLabels, translationStatus } from './translation';

/**
 * Der Stand einer Sprachfassung als kleine Marke neben dem Sprachkürzel: Sechseck (aktuell grün, veraltet Bernstein,
 * fehlt gestrichelt) und das Stand-Wort. `quiet`: nur veraltet und fehlt sprechen, aktuell und Standardsprache schweigen
 * (Seitenbaum, Typ-Listen).
 */
const props = withDefaults(
    defineProps<{
        state: TranslationState | null | undefined;
        locale?: string;
        quiet?: boolean;
    }>(),
    { locale: '', quiet: false },
);

const visible = computed(
    () =>
        props.state !== null &&
        props.state !== undefined &&
        (!props.quiet ||
            props.state === 'outdated' ||
            props.state === 'missing'),
);

const tone: Record<TranslationState, string> = {
    source: 'border-ash-light bg-paper text-basalt-light',
    current: 'border-success/40 bg-success/5 text-success',
    outdated: 'border-warning bg-warning/10 text-warning',
    missing: 'border-dashed border-ash bg-white text-basalt-light',
};

const title = computed(() =>
    props.state
        ? `${props.locale ? `${props.locale.toUpperCase()} · ` : ''}${translationLabels[props.state]}`
        : undefined,
);
</script>

<template>
    <span
        v-if="visible && state"
        class="inline-flex items-center gap-1 rounded border px-[5px] py-px text-[10px] leading-tight font-normal whitespace-nowrap"
        :class="tone[state]"
        :title="title"
    >
        <StatusHex
            v-if="state !== 'source'"
            :status="translationStatus[state]"
        />
        {{ translationLabels[state] }}
    </span>
</template>
