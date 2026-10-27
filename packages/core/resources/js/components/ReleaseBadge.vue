<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Stand der Installation unten links auf jeder Seite (Hoang, 27.10.2026): Umgebung als farbiges Abzeichen — PROD rot,
 * TESTING bernstein, DEMO blau, LOKAL grün —, dazu Version und Zeitpunkt der Auslieferung; der Commit im Tooltip.
 * Klein und ohne Klickfläche, damit es nichts verdeckt; im Druck ausgeblendet.
 */
type Release = {
    version: string;
    commit: string | null;
    at: string | null;
    environment: string;
    label: string;
};

const page = usePage<{ release?: Release }>();
const release = computed(() => page.props.release ?? null);

const tones: Record<string, string> = {
    prod: 'bg-[#b8232f] text-white',
    testing: 'bg-[#b26b00] text-white',
    demo: 'bg-[#2557d6] text-white',
    lokal: 'bg-[#2a744a] text-white',
};
</script>

<template>
    <div
        v-if="release"
        class="dark:bg-basalt-dark/90 pointer-events-none fixed bottom-1.5 left-1.5 z-30 flex items-center gap-1.5 rounded-full border border-ash-light bg-white/90 py-0.5 pr-2 pl-0.5 text-[10.5px] leading-4 text-basalt-light shadow-sm backdrop-blur-sm dark:border-basalt dark:text-ash-light print:hidden"
        :title="release.commit ? `Commit ${release.commit}` : undefined"
        data-release
    >
        <span
            class="rounded-full px-1.5 font-semibold tracking-wide"
            :class="tones[release.environment] ?? 'bg-basalt-light text-white'"
            >{{ release.label }}</span
        >
        <span class="tabular-nums"
            >{{ release.version
            }}<template v-if="release.at"> · {{ release.at }}</template></span
        >
    </div>
</template>
