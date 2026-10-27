<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ReleaseBadge from '../components/ReleaseBadge.vue';
import DemoBanner from '../components/DemoBanner.vue';
import Wordmark from '../components/Wordmark.vue';
import { isEmbedded } from '../components/embed';
import type { BackendProps } from '../types';

/**
 * TB 12 Scheibe 1 (Mockup 22): Rahmen der Plattform für Gäste — Kopfzeile Papier mit Wortmarke (nicht Basalt wie das
 * Backend), darunter eine breitere Arbeitsfläche als die Anmeldekarte, weil die Theme-Wahl drei Karten nebeneinander zeigt.
 * Eingebettet (Modal auf basaltocms.com) ohne Kopfzeile — die Website darum herum trägt die Marke.
 */
const embedded = isEmbedded();
// CR-011 TB 59 (§ 312k BGB): Hier entstehen Abos — darum auch der Weg, sie zu kündigen (eingebettet trägt ihn die Website)
const page = usePage<BackendProps>();
const cancellation = computed(() => page.props.backend?.cancellation ?? null);
</script>

<template>
    <div class="flex flex-col" :class="embedded ? '' : 'min-h-screen'">
        <DemoBanner />
        <header v-if="!embedded" class="border-b border-ash-light bg-white">
            <div
                class="mx-auto flex min-h-12 max-w-[820px] items-center justify-between gap-3 px-5"
            >
                <span class="text-[19px]"><Wordmark tone="paper" /></span>
                <span class="text-ash-dark text-[11px]">{{
                    $t('Aus einem Guss.')
                }}</span>
            </div>
        </header>
        <main
            id="inhalt"
            class="mx-auto w-full max-w-[820px] flex-1 px-5"
            :class="embedded ? 'py-5' : 'py-7'"
        >
            <slot />
        </main>
        <footer
            v-if="!embedded && cancellation"
            class="text-ash-dark pb-6 text-center text-[11px]"
        >
            <a :href="cancellation" class="underline hover:text-basalt">{{
                $t('Verträge hier kündigen')
            }}</a>
        </footer>
        <ReleaseBadge />
    </div>
</template>
