<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { t } from '@core/i18n';
import SuggestButton from '../components/SuggestButton.vue';
import SuggestionDialog from '../components/SuggestionDialog.vue';
import {
    hasText,
    change,
    useAi,
    useSuggestion,
    type FieldSuggestion,
} from '../ai';

/**
 * «Text verbessern» am Text-, Textbereich- oder Fliesstextfeld im Block-Editor (M-24). Der Vorschlag kommt erst auf
 * Klick und steht nebeneinander mit dem bisherigen Text; «Übernehmen» legt ihn ins Feld — das Autosave schreibt ihn in
 * den offenen Entwurf (bei einer veröffentlichten Fassung entsteht er mit «Als neuen Entwurf speichern»), nie in die
 * veröffentlichte Fassung.
 */
const props = defineProps<{
    context: {
        field: { name: string; label: string; type: string };
        value: unknown;
        path: string;
    };
}>();
const emit = defineEmits<{ (e: 'apply', value: unknown): void }>();

const ai = useAi();
const page = usePage<{
    entry?: { id: number };
    locale?: string;
    version?: { can_edit: boolean } | null;
}>();
const visible = computed(
    () =>
        ai.value !== null &&
        page.component === 'core::EntryEdit' &&
        page.props.entry !== undefined &&
        hasText(props.context.value),
);
/** Offener Entwurf: das Autosave speichert. Sonst (veröffentlicht, in Prüfung) entsteht der Entwurf erst mit «Als neuen Entwurf speichern». */
const isDraft = computed(() => page.props.version?.can_edit !== false);
const available = computed(() => Boolean(ai.value?.features.improve));
const open = ref(false);
const { loading, result, error, request, applied } =
    useSuggestion<FieldSuggestion>();

function ask(): void {
    open.value = true;
    if (!available.value || !ai.value || !page.props.entry) {
        return;
    }
    void request(ai.value.urls.improve, {
        entry: page.props.entry.id,
        locale: page.props.locale,
        type: props.context.field.type,
        value: props.context.value ?? null,
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
                  props.context.path,
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
            :label="t('Text verbessern')"
            @click="ask"
        />
        <SuggestionDialog
            :open="open"
            :title="
                t('Text verbessern · :field', { field: context.field.label })
            "
            :ai="ai"
            :available="available"
            :loading="loading"
            :error="error"
            :changes="changes"
            :target="
                isDraft
                    ? t(
                          '«Übernehmen» setzt den Vorschlag ins Feld; gespeichert wird er in den Entwurf. Veröffentlicht wird erst nach Einreichen und Freigabe.',
                      )
                    : t(
                          '«Übernehmen» setzt den Vorschlag ins Feld. Gespeichert wird er erst mit «Als neuen Entwurf speichern» — als neuer Entwurf; die veröffentlichte Fassung bleibt, wie sie ist.',
                      )
            "
            @close="open = false"
            @apply="apply"
            @retry="ask"
        />
    </div>
</template>
