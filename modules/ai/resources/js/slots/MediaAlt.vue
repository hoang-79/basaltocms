<script setup lang="ts">
import { computed, ref } from 'vue';
import { t } from '@core/i18n';
import SuggestButton from '../components/SuggestButton.vue';
import SuggestionDialog from '../components/SuggestionDialog.vue';
import { change, useAi, useSuggestion, type AltSuggestion } from '../ai';

/**
 * «Alt-Texte» im Bild-Detail der Mediathek (M-24): je Sprache ein Vorschlag aus dem Bild. Medien haben keine Fassungen —
 * übernommene Alt-Texte speichert das Autosave sofort, und sie gelten gleich auf allen Seiten. Das sagt der Dialog
 * deutlich; die Übernahme steht im Protokoll. «Nur leere füllen» lässt vorhandene Alt-Texte stehen.
 */
const props = defineProps<{
    context: {
        media: number;
        mime: string;
        alt: Record<string, string>;
        locales: string[];
    };
}>();
const emit = defineEmits<{ (e: 'apply', value: unknown): void }>();

const ai = useAi();
const isRaster = computed(
    () =>
        props.context.mime.startsWith('image/') &&
        props.context.mime !== 'image/svg+xml',
);
const available = computed(() => Boolean(ai.value?.features.alt_text));
const open = ref(false);
const { loading, result, error, request, applied } =
    useSuggestion<AltSuggestion>();

function ask(): void {
    open.value = true;
    if (!available.value || !ai.value) {
        return;
    }
    void request(ai.value.urls.alt_text, { media: props.context.media });
}

const empties = computed(() =>
    props.context.locales.filter(
        (l) => (props.context.alt[l] ?? '').trim() === '',
    ),
);

function apply(onlyEmpty: boolean): void {
    if (result.value) {
        const locales = onlyEmpty ? empties.value : props.context.locales;
        const next: Record<string, string> = {};
        for (const locale of locales) {
            const value = result.value.alt[locale];
            if (value) {
                next[locale] = value;
            }
        }
        emit('apply', next);
        applied({ locales: Object.keys(next) });
    }
    open.value = false;
}

const changes = computed(() =>
    result.value
        ? props.context.locales.map((locale) =>
              change(
                  `alt.${locale}`,
                  t('Alt-Text :locale', { locale: locale.toUpperCase() }),
                  props.context.alt[locale] ?? '',
                  result.value?.alt[locale] ?? '',
              ),
          )
        : [],
);
</script>

<template>
    <div
        v-if="ai && isRaster"
        class="-mt-1 mb-2 flex flex-wrap justify-end gap-1"
    >
        <SuggestButton
            :available="available"
            :loading="loading"
            :field="t('Alt-Text')"
            :label="t('Alt-Texte vorschlagen')"
            @click="ask"
        />
        <SuggestionDialog
            :open="open"
            :title="t('Alt-Texte vorschlagen')"
            :ai="ai"
            :available="available"
            :loading="loading"
            :error="error"
            :changes="changes"
            :warning="
                t(
                    'Medien haben keine Fassungen: Übernommene Alt-Texte werden sofort gespeichert und gelten gleich auf allen Seiten, die das Bild zeigen. Die Übernahme steht im Protokoll.',
                )
            "
            :target="
                t(
                    'Das Bild geht zur Beschreibung an den Anbieter. Bitte prüfen: Die KI kennt weder Namen noch Zusammenhang.',
                )
            "
            :apply-label="t('Alle übernehmen')"
            :secondary-label="
                empties.length > 0 && empties.length < context.locales.length
                    ? t('Nur leere füllen')
                    : ''
            "
            @apply-secondary="apply(true)"
            @close="open = false"
            @apply="apply(false)"
            @retry="ask"
        />
    </div>
</template>
