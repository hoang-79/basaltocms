<script setup lang="ts">
import type { AutosaveStatus } from './autosave';

/**
 * Stand des Autosave neben einem Formular, wo früher «Speichern» stand (CR-013): wartet, speichert, gespeichert —
 * oder warum nicht (`blocked`, z. B. Fehler beim Prüfen). Für Screenreader eine höfliche Live-Region.
 */
withDefaults(
    defineProps<{
        status: AutosaveStatus;
        blocked?: string;
        /** «Entwurf gespeichert» statt «Gespeichert» (Theme-Entwürfe) */
        draft?: boolean;
    }>(),
    { blocked: '', draft: false },
);
</script>

<template>
    <p class="text-[12px] text-basalt-light" role="status" aria-live="polite">
        <span v-if="blocked && status === 'pending'" class="text-danger">{{
            blocked
        }}</span>
        <span v-else-if="status === 'pending' || status === 'saving'">{{
            $t('Speichert …')
        }}</span>
        <span v-else-if="status === 'saved'" class="text-success"
            >✓ {{ draft ? $t('Entwurf gespeichert') : $t('Gespeichert') }}</span
        >
        <span v-else-if="status === 'error'" class="text-danger">{{
            $t('Nicht gespeichert')
        }}</span>
        <span v-else-if="status === 'waiting'">{{
            $t('Enter oder Feld verlassen speichert')
        }}</span>
        <span v-else>{{
            draft
                ? $t('Änderungen speichern sich selbst im Entwurf.')
                : $t('Änderungen speichern sich selbst.')
        }}</span>
    </p>
</template>
