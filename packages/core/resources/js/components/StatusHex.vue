<script setup lang="ts">
import { computed } from 'vue';
import type { StatusKind } from '../types';

/**
 * Ein Statuspunkt als SVG-Sechseck: Form + Farbe + Kürzel — Farbe trägt nie allein (Mockup E-04).
 * «live» gefüllt grün, «review» Lava-Ring, «draft» Asche, «stale» Bernstein, «scheduled» grüner Ring, «missing» gestrichelt.
 */
const props = withDefaults(
    defineProps<{
        status: StatusKind;
        label?: string;
        title?: string;
        size?: 'sm' | 'md';
    }>(),
    { label: '', title: undefined, size: 'sm' },
);

const polygon: Record<StatusKind, string> = {
    live: 'fill-success stroke-success stroke-[2.5]',
    review: 'fill-white stroke-lava-dark stroke-[4]',
    draft: 'fill-ash-light stroke-ash stroke-[2.5]',
    stale: 'fill-warning stroke-warning stroke-[2.5]',
    scheduled: 'fill-white stroke-success stroke-[4]',
    missing: 'fill-none stroke-ash stroke-[2.5] [stroke-dasharray:3_2.5]',
    success: 'fill-success stroke-success stroke-[2.5]',
    warning: 'fill-warning stroke-warning stroke-[2.5]',
    danger: 'fill-danger stroke-danger stroke-[2.5]',
    lava: 'fill-lava stroke-lava stroke-[2.5]',
    none: 'fill-ash-light stroke-ash stroke-[2.5]',
};

const svgSize = computed(() =>
    props.size === 'md' ? 'h-[14px] w-[15px]' : 'h-[11px] w-3',
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1 font-mono text-[10px] text-basalt-light"
        :title="title"
    >
        <svg
            viewBox="-2 -2 24 22"
            class="shrink-0"
            :class="svgSize"
            aria-hidden="true"
        >
            <polygon
                points="5,1 15,1 19,9 15,17 5,17 1,9"
                :class="polygon[status]"
            />
        </svg>
        <span v-if="label">{{ label }}</span>
    </span>
</template>
