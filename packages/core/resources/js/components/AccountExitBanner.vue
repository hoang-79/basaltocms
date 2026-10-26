<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { BackendProps } from '../types';

/**
 * TB 12 Scheibe 4 (M-21, Mockup 24): Hinweisband nach der Kündigung («Gekündigt · Laufzeit bis … · Löschung am …» mit
 * «Kündigung zurücknehmen»), während der Sperre (nur lesbar, Export) und während eines Umzugs. Dazu der Satz, wenn eine
 * Änderung abgewiesen wurde, weil die Site gesperrt ist oder umzieht. Bernstein für «gekündigt», Rot für «gesperrt».
 */
const page = usePage<BackendProps & { errors?: Record<string, string> }>();
const notice = computed(() => page.props.backend?.account_exit ?? null);
const refused = computed(() => page.props.errors?.locked ?? null);
</script>

<template>
    <div v-if="notice || refused">
        <p
            v-if="notice"
            role="status"
            class="m-0 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 border-l-[3px] px-4 py-2 text-center text-[12.5px] leading-snug font-semibold text-basalt"
            :class="
                notice.tone === 'danger'
                    ? 'border-danger bg-danger/10'
                    : 'border-warning bg-warning/10'
            "
        >
            <span>{{ notice.text }}</span>
            <Link
                v-if="notice.href"
                :href="notice.href"
                class="text-lava-dark underline hover:text-basalt"
                >{{ notice.label }}</Link
            >
        </p>
        <p
            v-if="refused"
            role="alert"
            class="border-danger bg-danger/10 m-0 border-l-[3px] px-4 py-2 text-center text-[12.5px] leading-snug font-semibold text-basalt"
        >
            {{ refused }}
        </p>
    </div>
</template>
