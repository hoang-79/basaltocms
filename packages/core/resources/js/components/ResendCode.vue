<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { tc } from '../i18n';

/**
 * «Code erneut senden» mit Wartezeit (TB 72): zählt die Sekunden herunter, die in der Verwaltung unter
 * «Neuer Code frühestens nach» stehen, erst danach lässt sich ein neuer Code anfordern. Der Server prüft dieselbe
 * Wartezeit noch einmal — der Knopf ist Bequemlichkeit, kein Schutz.
 */
const props = defineProps<{ url: string; wait: number; seconds: number }>();

const remaining = ref(props.wait);
const sending = ref(false);
let timer: ReturnType<typeof setInterval> | undefined;

function send(): void {
    if (remaining.value > 0 || sending.value) {
        return;
    }
    sending.value = true;
    router.post(
        props.url,
        {},
        {
            preserveScroll: true,
            // Die Antwort trägt wieder dieselbe Wartezeit — ein watch sähe keine Änderung, also hier neu beginnen
            onSuccess: () => (remaining.value = props.seconds),
            onFinish: () => (sending.value = false),
        },
    );
}

watch(
    () => props.wait,
    (wait) => (remaining.value = wait),
);
onMounted(
    () =>
        (timer = setInterval(
            () => (remaining.value = Math.max(0, remaining.value - 1)),
            1000,
        )),
);
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <button
        type="button"
        class="underline disabled:cursor-default disabled:no-underline disabled:opacity-70"
        :disabled="remaining > 0 || sending"
        @click="send"
    >
        <template v-if="remaining > 0">{{
            tc(
                'Neuer Code in :count Sekunde|Neuer Code in :count Sekunden',
                remaining,
            )
        }}</template>
        <template v-else>{{ $t('Code erneut senden') }}</template>
    </button>
</template>
