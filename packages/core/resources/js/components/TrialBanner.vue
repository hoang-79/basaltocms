<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { BackendProps } from '../types';

/**
 * TB 12 Scheibe 1 (M-21): Hinweisband der SaaS, wenn die Testphase des Kontos abgelaufen ist und noch kein Plan gewählt
 * wurde — nichts ist gesperrt, das Band führt zu Konto › Plan. Bernstein-Tönung wie Warnungen, Basalt-Text (gut lesbar).
 */
const page = usePage<BackendProps>();
const notice = computed(() => page.props.backend?.trial ?? null);
</script>

<template>
    <p
        v-if="notice"
        role="status"
        class="border-warning bg-warning/10 m-0 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 border-l-[3px] px-4 py-2 text-center text-[12.5px] leading-snug font-semibold text-basalt"
    >
        <span>{{ notice.text }}</span>
        <Link
            v-if="notice.href"
            :href="notice.href"
            class="text-lava-dark underline hover:text-basalt"
            >{{ notice.label }}</Link
        >
    </p>
</template>
