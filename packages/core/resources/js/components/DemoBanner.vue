<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { BackendProps } from '../types';

/**
 * TB 11 Scheibe 4 (G-10): Hinweisband der öffentlichen Demo — nur, wenn der Server es schickt (BASALTO_DEMO=true).
 * Basalt auf Lava-Tönung über Papier (13:1), die Lava-Linie links wie bei Hinweisen.
 */
const page = usePage<BackendProps>();
const notice = computed(() => page.props.backend?.demo ?? null);
</script>

<template>
    <p
        v-if="notice"
        role="note"
        data-demo-banner
        class="m-0 border-l-[3px] border-lava bg-lava/10 px-4 py-2 text-center text-[12.5px] leading-snug font-semibold text-basalt"
    >
        {{ notice }}
    </p>
</template>
