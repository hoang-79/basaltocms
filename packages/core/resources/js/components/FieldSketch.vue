<script setup lang="ts">
import { computed } from 'vue';
import { fieldSketch } from './formPalette';

/** TB 31: die Zeichnung eines Formularfelds für die Palette — wie die Skizzen der Blöcke, 160 × 100. */
const props = defineProps<{ type: string }>();
const shapes = computed(() => fieldSketch(props.type));
</script>

<template>
    <svg
        viewBox="0 0 160 100"
        class="block h-auto w-full overflow-hidden"
        aria-hidden="true"
        stroke-width="1.2"
        stroke-linecap="round"
        stroke-linejoin="round"
    >
        <template v-for="(s, i) in shapes" :key="i">
            <rect
                v-if="s.el === 'rect'"
                :x="s.x"
                :y="s.y"
                :width="s.w"
                :height="s.h"
                :rx="s.r ?? 0"
                :class="s.cls"
                :stroke-dasharray="s.dash ? '3 3' : undefined"
            />
            <circle
                v-else-if="s.el === 'circle'"
                :cx="s.x"
                :cy="s.y"
                :r="s.r"
                :class="s.cls"
            />
            <path v-else :d="s.d" :class="s.cls" stroke-width="2" />
        </template>
    </svg>
</template>
