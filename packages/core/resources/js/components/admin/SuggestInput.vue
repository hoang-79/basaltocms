<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { postJson, requestError } from '../../http';
import { t } from '../../i18n';
import { controlClasses } from '../control';
import {
    canAdd,
    filterChoices,
    groupChoices,
    type AdminChoice,
} from './choice';

/**
 * Text mit Vorschlägen aus einer Auswahlliste (CR-022): Funktion, Abteilung, Ort. Getippt wird frei; darunter stehen
 * passende Vorschläge nach Gruppen, und — wer darf — «+ «…» in die Liste aufnehmen», damit der Text künftig zur Wahl
 * steht. Bringt ein Vorschlag Werte für andere Felder mit (`fill`), meldet `pick` sie.
 */
const props = withDefaults(
    defineProps<{
        options: AdminChoice[];
        addUrl?: string | null;
        id?: string;
        invalid?: boolean;
        describedby?: string;
        placeholder?: string;
    }>(),
    {
        addUrl: null,
        id: undefined,
        invalid: false,
        describedby: undefined,
        placeholder: undefined,
    },
);
const model = defineModel<string | null>({ default: null });
const emit = defineEmits<{ pick: [option: AdminChoice] }>();

const uid = useId();
const listId = `${props.id ?? `vorschlag-${uid}`}-liste`;
const local = ref<AdminChoice[]>([...props.options]);
watch(
    () => props.options,
    (options) => {
        local.value = [
            ...options,
            ...local.value.filter(
                (o) => !options.some((p) => p.value === o.value),
            ),
        ];
    },
);
const open = ref(false);
const active = ref(-1);
const adding = ref(false);
const problem = ref('');
const control = controlClasses();
let blurTimer: number | undefined;

const text = computed(() => model.value ?? '');
const results = computed(() =>
    filterChoices(local.value, text.value).filter(
        (o) => o.label !== text.value,
    ),
);
const groups = computed(() => groupChoices(results.value));
const grouped = computed(() => local.value.some((o) => o.group !== null));
const offerAdd = computed(
    () => props.addUrl !== null && canAdd(local.value, text.value),
);
const count = computed(() => results.value.length + (offerAdd.value ? 1 : 0));
const expanded = computed(() => open.value && count.value > 0);

function onInput(value: string): void {
    model.value = value;
    open.value = true;
    active.value = -1;
    problem.value = '';
}

function pick(option: AdminChoice): void {
    model.value = option.label;
    open.value = false;
    emit('pick', option);
}

async function add(): Promise<void> {
    if (!props.addUrl || adding.value) {
        return;
    }
    adding.value = true;
    try {
        const response = await postJson<{ option: AdminChoice }>(props.addUrl, {
            label: text.value.trim(),
        });
        local.value = [
            ...local.value.filter((o) => o.value !== response.option.value),
            response.option,
        ];
        model.value = response.option.label;
        open.value = false;
    } catch (error) {
        problem.value = requestError(error);
    } finally {
        adding.value = false;
    }
}

function onKey(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        open.value = true;
        active.value = Math.min(count.value - 1, active.value + 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(0, active.value - 1);
    } else if (event.key === 'Enter' && expanded.value && active.value >= 0) {
        event.preventDefault();
        const hit = results.value[active.value];
        if (hit) {
            pick(hit);
        } else if (offerAdd.value) {
            void add();
        }
    } else if (event.key === 'Escape') {
        open.value = false;
    }
}

function onBlur(): void {
    // Ein Klick in die Liste kommt nach dem Verlassen — kurz warten, damit er zählt
    blurTimer = window.setTimeout(() => {
        open.value = false;
    }, 150);
}

onBeforeUnmount(() => window.clearTimeout(blurTimer));
</script>

<template>
    <div class="relative">
        <input
            :id="id"
            :value="text"
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
            :class="control"
            @input="onInput(($event.target as HTMLInputElement).value)"
            @focus="open = true"
            @keydown="onKey"
            @blur="onBlur"
        />
        <ul
            v-if="expanded"
            :id="listId"
            role="listbox"
            class="scroll-y absolute inset-x-0 top-full z-30 mt-1 max-h-72 overflow-y-auto rounded-md border border-ash-light bg-white py-1 shadow-lg"
        >
            <template v-for="g in groups" :key="g.group ?? '—'">
                <li
                    v-if="grouped"
                    role="presentation"
                    class="px-3 pt-2 pb-0.5 text-[10.5px] font-semibold tracking-wide text-basalt-light uppercase"
                >
                    {{ g.group ?? t('Weitere') }}
                </li>
                <li
                    v-for="item in g.items"
                    :id="`${listId}-${item.index}`"
                    :key="item.option.value"
                    role="option"
                    :aria-selected="item.index === active"
                    class="cursor-pointer px-3 py-1.5 text-[13px]"
                    :class="item.index === active ? 'bg-paper' : ''"
                    @mousedown.prevent="pick(item.option)"
                    @mouseenter="active = item.index"
                >
                    {{ item.option.label }}
                </li>
            </template>
            <li
                v-if="offerAdd"
                :id="`${listId}-${results.length}`"
                role="option"
                :aria-selected="active === results.length"
                class="mt-1 cursor-pointer border-t border-ash-light px-3 py-1.5 text-[13px] font-semibold text-lava-dark"
                :class="active === results.length ? 'bg-paper' : ''"
                @mousedown.prevent="add"
                @mouseenter="active = results.length"
            >
                +
                {{
                    adding
                        ? t('Wird hinzugefügt …')
                        : t('«:value» in die Liste aufnehmen', {
                              value: text.trim(),
                          })
                }}
            </li>
        </ul>
        <p v-if="problem" class="text-danger mt-1 text-[11.5px]" role="alert">
            {{ problem }}
        </p>
    </div>
</template>
