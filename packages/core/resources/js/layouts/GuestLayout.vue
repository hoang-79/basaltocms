<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import DemoBanner from '../components/DemoBanner.vue';
import Wordmark from '../components/Wordmark.vue';
import type { BackendProps } from '../types';

/** Die Gastseite (Bereich 8): Papier, Wortmarke und Claim über einer weissen Karte, darunter die Installation. */
const page = usePage<BackendProps>();
const name = computed(() => page.props.backend?.name ?? 'Basalto');
// White-Label (G-02): mit Agentur-Schlüssel steht der Name der Agentur statt der Marke Basalto
const brand = computed(() => page.props.backend?.brand ?? null);
// CR-011 TB 59 (§ 312k BGB): wo die SaaS Verträge abschliesst, auch der Weg, sie zu kündigen
const cancellation = computed(() => page.props.backend?.cancellation ?? null);
</script>

<template>
    <main
        class="flex min-h-screen flex-col items-center justify-center px-5 py-10"
    >
        <DemoBanner class="mb-6 w-full max-w-[360px] rounded-md" />
        <div class="text-[26px]">
            <span v-if="brand" class="font-semibold tracking-tight">{{
                brand
            }}</span>
            <Wordmark v-else tone="paper" />
        </div>
        <p v-if="!brand" class="mt-1.5 mb-5 text-[11px] text-basalt-light">
            {{ $t('Aus einem Guss.') }}
        </p>
        <div v-else class="mb-5"></div>
        <div
            class="w-full max-w-[360px] rounded-lg border border-ash-light bg-white px-5 py-[18px]"
        >
            <slot />
        </div>
        <p class="text-ash-dark mt-4 text-[11px]">
            {{ name }} · {{ $t('Backend') }}
            <template v-if="cancellation">
                ·
                <a :href="cancellation" class="underline hover:text-basalt">{{
                    $t('Verträge hier kündigen')
                }}</a>
            </template>
        </p>
    </main>
</template>
