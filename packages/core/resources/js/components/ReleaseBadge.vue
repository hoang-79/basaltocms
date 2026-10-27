<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Stand der Installation unten links auf jeder Seite (Hoang, 27.10.2026): Umgebung als farbiges Abzeichen — PROD rot,
 * TESTING bernstein, DEMO blau, LOKAL grün —, dazu Version und Zeitpunkt der Auslieferung; der Commit im Tooltip.
 * Klein und ohne Klickfläche, damit es nichts verdeckt; im Druck ausgeblendet. Nur Backend und Extranet — die Website
 * zeigt keinen Stand (Hoang, 27.10.2026). Dunkel über die Farbvariablen des Backends (`data-theme`), nicht über `dark:` —
 * das griff bei dunklem macOS am Profil vorbei und machte den Text unlesbar.
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
    prod: 'bg-[#b8232f] text-[#fff]',
    testing: 'bg-[#b26b00] text-[#fff]',
    demo: 'bg-[#2557d6] text-[#fff]',
    lokal: 'bg-[#2a744a] text-[#fff]',
};
</script>

<template>
    <div
        v-if="release"
        class="pointer-events-none fixed bottom-1.5 left-1.5 z-30 flex items-center gap-1.5 rounded-full border border-ash-light bg-white py-0.5 pr-2.5 pl-0.5 text-[11px] leading-4 font-medium text-basalt shadow-sm print:hidden"
        :title="release.commit ? `Commit ${release.commit}` : undefined"
        data-release
    >
        <span
            class="rounded-full px-1.5 font-semibold tracking-wide"
            :class="tones[release.environment] ?? 'bg-basalt-light text-[#fff]'"
            >{{ release.label }}</span
        >
        <span class="tabular-nums"
            >{{ release.version
            }}<template v-if="release.at"> · {{ release.at }}</template></span
        >
    </div>
</template>
