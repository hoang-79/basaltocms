<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { postJson, requestError } from '../../http';
import { t } from '../../i18n';
import {
    canAdd,
    filterChoices,
    groupChoices,
    type AdminChoice,
} from './choice';

/**
 * Auswahl aus einer Auswahlliste der Site (CR-022, Wunsch Hoang 15.11.2026): ein Knopf wie die Länderauswahl, darunter
 * Suche, die Werte nach Gruppen und — wer darf — «+ «…» hinzufügen»: Der neue Wert kommt in die Liste der Site und ist
 * gleich gewählt. Tastatur: Pfeile, Enter, Escape.
 */
const props = withDefaults(
    defineProps<{
        options: AdminChoice[];
        label: string;
        addUrl?: string | null;
        id?: string;
        invalid?: boolean;
        describedby?: string;
    }>(),
    { addUrl: null, id: undefined, invalid: false, describedby: undefined },
);
const model = defineModel<string | null>({ default: null });

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
const query = ref('');
const active = ref(0);
const adding = ref(false);
const problem = ref('');
const root = ref<HTMLElement | null>(null);
const search = ref<HTMLInputElement | null>(null);
const list = ref<HTMLElement | null>(null);
const listId = `${props.id ?? 'auswahl'}-liste`;

const current = computed(() =>
    local.value.find((o) => o.value === model.value),
);
const results = computed(() => filterChoices(local.value, query.value));
const groups = computed(() => groupChoices(results.value));
const grouped = computed(() => local.value.some((o) => o.group !== null));
const offerAdd = computed(
    () => props.addUrl !== null && canAdd(local.value, query.value),
);
/** Einträge für die Tastatur: die Werte, dahinter «hinzufügen» */
const count = computed(() => results.value.length + (offerAdd.value ? 1 : 0));

function show(): void {
    open.value = true;
    query.value = '';
    problem.value = '';
    active.value = Math.max(
        0,
        results.value.findIndex((o) => o.value === model.value),
    );
    void nextTick(() => {
        search.value?.focus();
        scrollActive();
    });
    document.addEventListener('pointerdown', outside);
}

function close(): void {
    open.value = false;
    document.removeEventListener('pointerdown', outside);
}

function outside(event: PointerEvent): void {
    if (root.value && !root.value.contains(event.target as Node)) {
        close();
    }
}

function pick(option: AdminChoice): void {
    model.value = option.value;
    close();
}

async function add(): Promise<void> {
    if (!props.addUrl || adding.value) {
        return;
    }
    adding.value = true;
    problem.value = '';
    try {
        const response = await postJson<{ option: AdminChoice }>(props.addUrl, {
            label: query.value.trim(),
        });
        if (!local.value.some((o) => o.value === response.option.value)) {
            local.value = [...local.value, response.option];
        }
        pick(response.option);
    } catch (error) {
        problem.value = requestError(error);
    } finally {
        adding.value = false;
    }
}

function choose(index: number): void {
    const hit = results.value[index];
    if (hit) {
        pick(hit);
    } else if (offerAdd.value && index === results.value.length) {
        void add();
    }
}

function scrollActive(): void {
    void nextTick(() =>
        list.value
            ?.querySelector<HTMLElement>('[data-active="true"]')
            ?.scrollIntoView({ block: 'nearest' }),
    );
}

function onKey(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = Math.min(count.value - 1, active.value + 1);
        scrollActive();
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(0, active.value - 1);
        scrollActive();
    } else if (event.key === 'Enter') {
        event.preventDefault();
        choose(active.value);
    } else if (event.key === 'Escape') {
        event.preventDefault();
        close();
    }
}

watch(query, () => {
    active.value = 0;
});

onBeforeUnmount(() => document.removeEventListener('pointerdown', outside));
</script>

<template>
    <div ref="root" class="relative w-full">
        <button
            :id="id"
            type="button"
            :aria-label="`${label}: ${current?.label ?? '—'}`"
            :aria-expanded="open"
            aria-haspopup="listbox"
            :aria-describedby="describedby"
            :aria-invalid="invalid ? 'true' : undefined"
            class="flex w-full items-center gap-2 rounded-md border bg-white px-2.5 py-2 text-left text-[13px] focus:ring-1 focus:outline-none"
            :class="
                invalid
                    ? 'border-danger ring-danger/15 ring-2'
                    : 'border-ash-dark focus:border-lava-dark focus:ring-lava-dark'
            "
            @click="open ? close() : show()"
            @keydown.down.prevent="show"
        >
            <span
                class="min-w-0 flex-1 truncate"
                :class="current ? '' : 'text-ash-dark'"
                >{{ current?.label ?? t('Bitte wählen') }}</span
            >
            <svg
                class="h-3 w-3 flex-none text-basalt-light"
                viewBox="0 0 12 12"
                aria-hidden="true"
            >
                <path
                    d="M3 4.5 6 7.5 9 4.5"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                />
            </svg>
        </button>
        <div
            v-if="open"
            class="absolute top-full left-0 z-30 mt-1 w-full max-w-[calc(100vw-2rem)] min-w-64 rounded-lg border border-ash-light bg-white p-2 shadow-lg"
        >
            <input
                ref="search"
                v-model="query"
                type="search"
                role="combobox"
                :aria-expanded="true"
                :aria-controls="listId"
                :aria-activedescendant="`${listId}-${active}`"
                :placeholder="
                    addUrl
                        ? t('Suchen oder neuen Wert eingeben …')
                        : t('Suchen …')
                "
                :aria-label="t(':label suchen', { label })"
                class="border-ash-dark w-full rounded-md border px-2.5 py-1.5 text-[13px] focus:border-lava-dark focus:ring-1 focus:ring-lava-dark focus:outline-none"
                @keydown="onKey"
            />
            <ul
                :id="listId"
                ref="list"
                role="listbox"
                :aria-label="label"
                class="scroll-y mt-1.5 max-h-64 overflow-y-auto"
            >
                <template v-for="g in groups" :key="g.group ?? '—'">
                    <li
                        v-if="grouped"
                        role="presentation"
                        class="px-2 pt-2 pb-0.5 text-[10.5px] font-semibold tracking-wide text-basalt-light uppercase"
                    >
                        {{ g.group ?? t('Weitere') }}
                    </li>
                    <li
                        v-for="item in g.items"
                        :id="`${listId}-${item.index}`"
                        :key="item.option.value"
                        role="option"
                        :aria-selected="item.option.value === model"
                        :data-active="item.index === active"
                        class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-[13px]"
                        :class="[
                            item.index === active ? 'bg-paper' : '',
                            item.option.value === model ? 'font-semibold' : '',
                        ]"
                        @pointerenter="active = item.index"
                        @click="pick(item.option)"
                    >
                        <span class="min-w-0 flex-1 truncate">{{
                            item.option.label
                        }}</span>
                        <span
                            v-if="item.option.value === model"
                            class="text-lava-dark"
                            aria-hidden="true"
                            >✓</span
                        >
                    </li>
                </template>
                <li
                    v-if="offerAdd"
                    :id="`${listId}-${results.length}`"
                    role="option"
                    :aria-selected="false"
                    :data-active="active === results.length"
                    class="mt-1 flex cursor-pointer items-center gap-2 rounded-md border-t border-ash-light px-2 py-1.5 text-[13px] font-semibold text-lava-dark"
                    :class="active === results.length ? 'bg-paper' : ''"
                    @pointerenter="active = results.length"
                    @click="add"
                >
                    <span aria-hidden="true">+</span>
                    <span class="min-w-0 flex-1 truncate">{{
                        adding
                            ? t('Wird hinzugefügt …')
                            : t('«:value» hinzufügen', { value: query.trim() })
                    }}</span>
                </li>
                <li
                    v-if="results.length === 0 && !offerAdd"
                    role="presentation"
                    class="px-2 py-2 text-[12.5px] text-basalt-light"
                >
                    {{ t('Nichts gefunden.') }}
                </li>
            </ul>
            <p
                v-if="problem"
                class="text-danger mt-1 text-[11.5px]"
                role="alert"
            >
                {{ problem }}
            </p>
        </div>
    </div>
</template>
