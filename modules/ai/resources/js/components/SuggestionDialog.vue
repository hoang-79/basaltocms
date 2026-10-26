<script setup lang="ts">
import Button from '@core/components/Button.vue';
import FieldDiff from '@core/components/FieldDiff.vue';
import Modal from '@core/components/Modal.vue';
import Notice from '@core/components/Notice.vue';
import { t } from '@core/i18n';
import type { Change } from '@core/types-workflow';
import type { AiShared } from '../ai';

/**
 * Der Vorschlag nebeneinander (M-24): bisher und neu als Wort-Diff wie beim Vergleich der Fassungen, darunter wohin
 * «Übernehmen» schreibt und wohin der Text ging (Anbieter). Ohne Schlüssel steht hier nur der Grund; bei einem
 * Fehler die freundliche Meldung mit «Noch einmal». Nichts geschieht ohne Klick.
 */
withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        ai: AiShared;
        available: boolean;
        loading: boolean;
        error: string;
        changes: Change[];
        target: string;
        applyLabel?: string;
        /** Ein zweiter Weg zu übernehmen (Alt-Texte: «Nur leere füllen») — leer = keiner */
        secondaryLabel?: string;
        warning?: string;
        oldLabel?: string;
        newLabel?: string;
    }>(),
    {
        applyLabel: '',
        secondaryLabel: '',
        warning: '',
        oldLabel: '',
        newLabel: '',
    },
);
const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'apply'): void;
    (e: 'applySecondary'): void;
    (e: 'retry'): void;
}>();
</script>

<template>
    <Modal
        :open="open"
        :title="title"
        :subtitle="t('KI-Vorschlag · :provider', { provider: ai.provider })"
        @close="emit('close')"
    >
        <div class="space-y-3">
            <Notice v-if="!available" tone="grey" :title="t('KI aus')">
                {{
                    ai.notice ??
                    t('Diese Funktion ist für diese Site ausgeschaltet.')
                }}
                <a
                    v-if="ai.urls.settings"
                    :href="ai.urls.settings"
                    class="ml-1 font-semibold text-lava-dark underline"
                    >{{ t('Zu den KI-Einstellungen') }}</a
                >
            </Notice>
            <p
                v-else-if="loading"
                class="text-[12.5px] text-basalt-light"
                role="status"
            >
                {{ t('Vorschlag kommt …') }}
            </p>
            <Notice
                v-else-if="error"
                tone="danger"
                :title="t('Kein Vorschlag')"
            >
                {{ error }}
            </Notice>
            <template v-else-if="changes.length > 0">
                <FieldDiff
                    :fields="changes"
                    :old-label="oldLabel || t('Bisher')"
                    :new-label="newLabel || t('Vorschlag')"
                />
                <Notice v-if="warning" tone="warning">{{ warning }}</Notice>
                <p class="text-[12px] text-basalt-light">{{ target }}</p>
            </template>
            <p
                v-if="available"
                class="border-t border-ash-light pt-2 text-[11.5px] text-basalt-light"
            >
                {{ ai.privacy }}
            </p>
        </div>
        <template #footer>
            <Button variant="secondary" @click="emit('close')">{{
                available && changes.length > 0
                    ? t('Verwerfen')
                    : t('Schliessen')
            }}</Button>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="available && error"
                    variant="secondary"
                    @click="emit('retry')"
                    >{{ t('Noch einmal') }}</Button
                >
                <Button
                    v-if="
                        secondaryLabel &&
                        available &&
                        !loading &&
                        !error &&
                        changes.length > 0
                    "
                    variant="secondary"
                    @click="emit('applySecondary')"
                    >{{ secondaryLabel }}</Button
                >
                <Button
                    v-if="available && !loading && !error && changes.length > 0"
                    @click="emit('apply')"
                    >{{ applyLabel || t('Übernehmen') }}</Button
                >
            </div>
        </template>
    </Modal>
</template>
