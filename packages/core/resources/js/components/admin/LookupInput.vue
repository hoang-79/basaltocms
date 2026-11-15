<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId } from 'vue';
import { getJson } from '../../http';
import { t } from '../../i18n';
import { controlClasses } from '../control';
import { lookupQuery, type LookupSuggestion } from './lookup';

/**
 * Textfeld mit Vorschlägen aus einem Nachschlagedienst des Kerns (CR-021): ab drei Zeichen nach kurzer Pause fragt es
 * den Server (der fragt Register oder Adressdaten im Land des Formulars), zeigt die Treffer als Liste und meldet die
 * Auswahl mit allen Werten. Tastatur: Pfeile, Enter, Escape; ohne Auswahl bleibt das Getippte, wie es ist.
 */
const props = withDefaults(
    defineProps<{
        url: string;
        country: string;
        id?: string;
        invalid?: boolean;
        describedby?: string;
        placeholder?: string;
        mono?: boolean;
    }>(),
    {
        id: undefined,
        invalid: false,
        describedby: undefined,
        placeholder: undefined,
        mono: false,
    },
);
const model = defineModel<string>({ default: '' });
const emit = defineEmits<{
    select: [values: Record<string, string>];
    leave: [];
}>();

const uid = useId();
const listId = `${props.id ?? `nachschlagen-${uid}`}-liste`;
const results = ref<LookupSuggestion[]>([]);
const open = ref(false);
const active = ref(-1);
const loading = ref(false);
const searched = ref('');
const input = ref<HTMLInputElement | null>(null);
let timer: number | undefined;
let request = 0;

/** Keine Vorschläge mehr erwarten: Pause und laufende Anfrage gelten nicht mehr (Escape, Feld verlassen). */
function stop(): void {
    window.clearTimeout(timer);
    request++;
    loading.value = false;
}

const control = controlClasses();
const expanded = computed(
    () => open.value && (results.value.length > 0 || searched.value !== ''),
);

function onInput(value: string): void {
    model.value = value;
    window.clearTimeout(timer);
    const query = lookupQuery(value);
    if (query === null) {
        results.value = [];
        open.value = false;
        searched.value = '';

        return;
    }
    timer = window.setTimeout(() => void search(query), 300);
}

async function search(query: string): Promise<void> {
    const mine = ++request;
    loading.value = true;
    try {
        const separator = props.url.includes('?') ? '&' : '?';
        const response = await getJson<{ results: LookupSuggestion[] }>(
            `${props.url}${separator}q=${encodeURIComponent(query)}&land=${encodeURIComponent(props.country)}`,
        );
        if (mine !== request) {
            return;
        }
        results.value = response.results ?? [];
        searched.value = query;
        active.value = results.value.length > 0 ? 0 : -1;
        // Nur aufklappen, solange man im Feld ist — Antworten nach dem Verlassen bleiben zu
        open.value = document.activeElement === input.value;
    } catch {
        // Dienst nicht erreichbar oder gedrosselt — still, das Feld bleibt ein Textfeld
        if (mine === request) {
            results.value = [];
            open.value = false;
        }
    } finally {
        if (mine === request) {
            loading.value = false;
        }
    }
}

function choose(index: number): void {
    const hit = results.value[index];
    if (!hit) {
        return;
    }
    open.value = false;
    results.value = [];
    searched.value = '';
    emit('select', hit.values);
}

function onKey(event: KeyboardEvent): void {
    if (event.key === 'Escape' && !expanded.value) {
        stop();

        return;
    }
    if (!expanded.value) {
        return;
    }
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = Math.min(results.value.length - 1, active.value + 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(0, active.value - 1);
    } else if (event.key === 'Enter' && active.value >= 0) {
        event.preventDefault();
        choose(active.value);
    } else if (event.key === 'Escape') {
        event.preventDefault();
        stop();
        open.value = false;
    }
}

function onBlur(): void {
    stop();
    // Ein Klick in die Liste kommt nach dem Verlassen — kurz warten, damit er zählt
    window.setTimeout(() => {
        open.value = false;
        emit('leave');
    }, 150);
}

onBeforeUnmount(() => window.clearTimeout(timer));
</script>

<template>
    <div class="relative">
        <input
            :id="id"
            ref="input"
            :value="model"
            type="text"
            role="combobox"
            autocomplete="off"
            :aria-expanded="expanded"
            :aria-controls="listId"
            aria-autocomplete="list"
            :aria-activedescendant="
                expanded && active >= 0 ? `${listId}-${active}` : undefined
            "
            :aria-invalid="invalid ? 'true' : undefined"
            :aria-describedby="describedby"
            :placeholder="placeholder"
            :class="[control, mono ? 'font-mono' : '', 'pr-8']"
            @input="onInput(($event.target as HTMLInputElement).value)"
            @keydown="onKey"
            @blur="onBlur"
        />
        <span
            v-if="loading"
            class="absolute top-1/2 right-2.5 size-3.5 -translate-y-1/2 animate-spin rounded-full border-2 border-ash-light border-t-basalt"
            aria-hidden="true"
        />
        <ul
            v-if="expanded"
            :id="listId"
            role="listbox"
            class="absolute inset-x-0 top-full z-30 mt-1 max-h-72 overflow-y-auto rounded-md border border-ash-light bg-white py-1 shadow-lg"
        >
            <li
                v-for="(hit, i) in results"
                :id="`${listId}-${i}`"
                :key="i"
                role="option"
                :aria-selected="i === active"
                class="cursor-pointer px-3 py-1.5 text-[13px]"
                :class="i === active ? 'bg-paper' : ''"
                @mousedown.prevent="choose(i)"
                @mouseenter="active = i"
            >
                <span class="block font-semibold text-basalt">{{
                    hit.label
                }}</span>
                <span
                    v-if="hit.detail"
                    class="block text-[11.5px] text-basalt-light"
                    >{{ hit.detail }}</span
                >
            </li>
            <li
                v-if="results.length === 0"
                class="px-3 py-1.5 text-[12px] text-basalt-light"
                role="presentation"
            >
                {{ t('Keine Vorschläge — so übernehmen, wie getippt.') }}
            </li>
        </ul>
    </div>
</template>
