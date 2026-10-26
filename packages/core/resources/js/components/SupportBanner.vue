<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { BackendProps } from '../types';

/**
 * TB 19 (CR-004 P-16): Hinweisband, solange der Support des Herstellers lesend auf diese Installation zugreift. Den Satz
 * (mit Frist) liefert der Server in `backend.support.text`. Lava dunkel als Rand, damit das Band neben Demo- und
 * Testphasen-Band auffällt; Basalt-Text bleibt gut lesbar.
 */
const page = usePage<BackendProps>();
const notice = computed(() => page.props.backend?.support ?? null);
</script>

<template>
    <p
        v-if="notice"
        role="status"
        class="m-0 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 border-l-[3px] border-lava-dark bg-lava/15 px-4 py-2 text-center text-[12.5px] leading-snug font-semibold text-basalt"
    >
        <span>{{ notice.text }}</span>
    </p>
</template>
