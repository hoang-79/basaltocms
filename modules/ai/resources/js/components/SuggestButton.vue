<script setup lang="ts">
import { t } from '@core/i18n';

/**
 * Der Knopf «✦ Vorschlag» (M-24) — klein unter dem Feld. Ohne Schlüssel oder ausgeschaltet bleibt er sichtbar, aber
 * grau (`aria-disabled`): Ein Klick zeigt dann den Grund statt eines Vorschlags. Während der Anfrage «Vorschlag kommt …».
 */
withDefaults(
    defineProps<{
        available: boolean;
        loading?: boolean;
        label?: string;
        /** Das Feld, um das es geht — für den Namen des Knopfs («Vorschlag für «Titel»»), damit zehn Knöpfe unterscheidbar sind */
        field?: string;
        describedBy?: string;
    }>(),
    { loading: false, label: '', field: '', describedBy: undefined },
);
const emit = defineEmits<{ (e: 'click'): void }>();
</script>

<template>
    <button
        type="button"
        class="inline-flex min-h-6 items-center gap-1 rounded px-1.5 py-0.5 text-[11.5px] font-semibold transition-colors"
        :class="
            available
                ? 'text-lava-dark hover:bg-lava/10'
                : 'cursor-help text-basalt-light hover:bg-paper'
        "
        :aria-label="
            field
                ? t(':action für «:field»', {
                      action: label || t('Vorschlag'),
                      field,
                  }) + (available ? '' : ` (${t('KI aus')})`)
                : undefined
        "
        :aria-disabled="available ? undefined : 'true'"
        :aria-busy="loading ? 'true' : undefined"
        :aria-describedby="describedBy"
        :title="
            available
                ? t(
                      'KI-Vorschlag — nur auf Klick, ändert nichts, bis Sie übernehmen',
                  )
                : t('KI aus — Klick zeigt den Grund')
        "
        @click="emit('click')"
    >
        <span aria-hidden="true">✦</span>
        <span>{{
            loading ? t('Vorschlag kommt …') : label || t('Vorschlag')
        }}</span>
        <span v-if="!available" class="font-normal">({{ t('KI aus') }})</span>
    </button>
</template>
