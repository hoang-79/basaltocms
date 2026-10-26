<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { t } from '@core/i18n';
import SuggestButton from '../components/SuggestButton.vue';
import SuggestionDialog from '../components/SuggestionDialog.vue';
import { change, useAi, useSuggestion, type FieldSuggestion } from '../ai';

/**
 * «Vorschlag» je Feld beim Übersetzen nebeneinander (M-24, Mockup 12: ein vierter Knopf je Feld, nie mehr). Der Server
 * nimmt den Text aus der Quellfassung der Standardsprache; «Übernehmen» legt die Übersetzung ins Zielfeld, das
 * Autosave schreibt sie in den Entwurf der Zielsprache.
 */
const props = defineProps<{
    context: {
        field: { path: string; label: string; type: string };
        value: unknown;
    };
}>();
const emit = defineEmits<{ (e: 'apply', value: unknown): void }>();

const ai = useAi();
const page = usePage<{
    entry?: { id: number };
    source?: { locale: string } | null;
    target?: { locale: string };
}>();
const visible = computed(
    () =>
        ai.value !== null &&
        page.component === 'core::Translate' &&
        page.props.entry !== undefined &&
        page.props.source !== null,
);
const available = computed(() => Boolean(ai.value?.features.translate));
const open = ref(false);
const { loading, result, error, request, applied } =
    useSuggestion<FieldSuggestion>();

const direction = computed(
    () =>
        `${(page.props.source?.locale ?? '').toUpperCase()} → ${(page.props.target?.locale ?? '').toUpperCase()}`,
);

function ask(): void {
    open.value = true;
    if (!available.value || !ai.value || !page.props.entry) {
        return;
    }
    void request(ai.value.urls.translate, {
        entry: page.props.entry.id,
        locale: page.props.target?.locale,
        path: props.context.field.path,
    });
}

function apply(): void {
    if (result.value) {
        emit('apply', result.value.value);
        applied();
    }
    open.value = false;
}

const changes = computed(() =>
    result.value
        ? [
              change(
                  props.context.field.path,
                  props.context.field.label,
                  result.value.before,
                  result.value.after,
              ),
          ]
        : [],
);
</script>

<template>
    <div v-if="visible && ai" class="mt-1 flex justify-end">
        <SuggestButton
            :available="available"
            :loading="loading"
            :field="context.field.label"
            :label="t('Vorschlag')"
            @click="ask"
        />
        <SuggestionDialog
            :open="open"
            :title="
                t('Übersetzung :direction · :field', {
                    direction,
                    field: context.field.label,
                })
            "
            :ai="ai"
            :available="available"
            :loading="loading"
            :error="error"
            :changes="changes"
            :target="
                t(
                    'Links die Quelle, rechts der Vorschlag. «Übernehmen» setzt ihn ins Zielfeld; gespeichert wird er in den Entwurf dieser Sprache.',
                )
            "
            :apply-label="t('Ins Zielfeld übernehmen')"
            :old-label="
                t('Quelle :locale', {
                    locale: (page.props.source?.locale ?? '').toUpperCase(),
                })
            "
            :new-label="
                t('Vorschlag :locale', {
                    locale: (page.props.target?.locale ?? '').toUpperCase(),
                })
            "
            @close="open = false"
            @apply="apply"
            @retry="ask"
        />
    </div>
</template>
