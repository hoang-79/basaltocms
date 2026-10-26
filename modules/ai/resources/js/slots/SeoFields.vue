<script setup lang="ts">
import { computed, ref } from 'vue';
import { t } from '@core/i18n';
import SuggestButton from '../components/SuggestButton.vue';
import SuggestionDialog from '../components/SuggestionDialog.vue';
import { change, useAi, useSuggestion, type SeoSuggestion } from '../ai';

/**
 * «SEO-Vorschläge» im Reiter Metadaten (M-24): Seitentitel und Beschreibung je Sprache aus dem Inhalt der neuesten
 * Fassung. «Übernehmen» setzt beide Felder; das Autosave der Seiteneinstellungen schreibt sie in den Entwurf.
 */
const props = defineProps<{
    context: {
        entry: number;
        locale: string;
        title: string;
        description: string;
    };
}>();
const emit = defineEmits<{ (e: 'apply', value: unknown): void }>();

const ai = useAi();
const available = computed(() => Boolean(ai.value?.features.seo));
const open = ref(false);
const { loading, result, error, request, applied } =
    useSuggestion<SeoSuggestion>();

function ask(): void {
    open.value = true;
    if (!available.value || !ai.value) {
        return;
    }
    void request(ai.value.urls.seo, {
        entry: props.context.entry,
        locale: props.context.locale,
    });
}

function apply(): void {
    if (result.value) {
        emit('apply', {
            title: result.value.title,
            description: result.value.description,
        });
        applied();
    }
    open.value = false;
}

const changes = computed(() =>
    result.value
        ? [
              change(
                  'meta.title',
                  t('Seitentitel'),
                  props.context.title,
                  result.value.title,
              ),
              change(
                  'meta.description',
                  t('Beschreibung'),
                  props.context.description,
                  result.value.description,
              ),
          ]
        : [],
);
</script>

<template>
    <div v-if="ai" class="mt-1 flex justify-end">
        <SuggestButton
            :available="available"
            :loading="loading"
            :field="t('Metadaten')"
            :label="
                t('SEO-Vorschlag (:locale)', {
                    locale: context.locale.toUpperCase(),
                })
            "
            @click="ask"
        />
        <SuggestionDialog
            :open="open"
            :title="
                t('SEO-Vorschlag · :locale', {
                    locale: context.locale.toUpperCase(),
                })
            "
            :ai="ai"
            :available="available"
            :loading="loading"
            :error="error"
            :changes="changes"
            :target="
                t(
                    '«Übernehmen» setzt Seitentitel und Beschreibung; gespeichert wird in den Entwurf der Seite. Veröffentlicht wird erst nach Freigabe.',
                )
            "
            @close="open = false"
            @apply="apply"
            @retry="ask"
        />
    </div>
</template>
