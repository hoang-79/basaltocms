<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { t } from '../i18n';
import { filterCountries, flagOf } from './phone';

/**
 * Länderwahl mit Suche: geschlossen nur die Flagge (kompakt, z. B. vor der Telefonnummer) oder Flagge und Name;
 * offen ein Suchfeld (Name, Code oder Vorwahl) und die Liste mit Flagge, Name und Vorwahl. Tastatur: Pfeile,
 * Enter, Escape; Klick ausserhalb schliesst.
 */
type Country = { code: string; name: string; dial: string };

const props = withDefaults(
    defineProps<{
        countries: Country[];
        compact?: boolean;
        label?: string;
        invalid?: boolean;
        id?: string;
    }>(),
    { compact: false, label: () => t('Land'), invalid: false, id: undefined },
);
const model = defineModel<string>({ default: 'CH' });

const open = ref(false);
const query = ref('');
const active = ref(0);
const root = ref<HTMLElement | null>(null);
const search = ref<HTMLInputElement | null>(null);
const list = ref<HTMLElement | null>(null);

const current = computed(() =>
    props.countries.find((c) => c.code === model.value),
);
const results = computed(() => filterCountries(props.countries, query.value));

watch(query, () => {
    active.value = 0;
});

function toggle(): void {
    if (open.value) {
        close();
    } else {
        show();
    }
}

function show(): void {
    open.value = true;
    query.value = '';
    active.value = Math.max(
        0,
        results.value.findIndex((c) => c.code === model.value),
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

function pick(code: string): void {
    model.value = code;
    close();
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
        active.value = Math.min(results.value.length - 1, active.value + 1);
        scrollActive();
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(0, active.value - 1);
        scrollActive();
    } else if (event.key === 'Enter') {
        event.preventDefault();
        const hit = results.value[active.value];
        if (hit) {
            pick(hit.code);
        }
    } else if (event.key === 'Escape') {
        close();
    }
}

onBeforeUnmount(() => document.removeEventListener('pointerdown', outside));
</script>

<template>
    <div ref="root" class="relative" :class="compact ? 'flex-none' : 'w-full'">
        <button
            :id="id"
            type="button"
            :aria-label="`${label}: ${current?.name ?? '—'}`"
            :aria-expanded="open"
            aria-haspopup="listbox"
            :title="current ? `${current.name} (+${current.dial})` : label"
            class="flex h-full w-full items-center gap-2 rounded-md border bg-white px-2.5 py-2 text-left text-[13px] focus:ring-1 focus:outline-none"
            :class="
                invalid
                    ? 'border-danger ring-danger/15 ring-2'
                    : 'border-ash-dark focus:border-lava-dark focus:ring-lava-dark'
            "
            @click="toggle"
            @keydown.down.prevent="show"
        >
            <span class="text-[18px] leading-none" aria-hidden="true">{{
                current ? flagOf(current.code) : '🏳️'
            }}</span>
            <span v-if="!compact" class="min-w-0 flex-1 truncate">{{
                current?.name ?? $t('Land wählen')
            }}</span>
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
            class="absolute top-full left-0 z-30 mt-1 w-72 max-w-[calc(100vw-2rem)] rounded-lg border border-ash-light bg-white p-2 shadow-lg"
        >
            <input
                ref="search"
                v-model="query"
                type="search"
                :placeholder="$t('Land, Code oder Vorwahl suchen …')"
                :aria-label="$t('Land suchen')"
                class="border-ash-dark w-full rounded-md border px-2.5 py-1.5 text-[13px] focus:border-lava-dark focus:ring-1 focus:ring-lava-dark focus:outline-none"
                @keydown="onKey"
            />
            <ul
                ref="list"
                role="listbox"
                :aria-label="label"
                class="scroll-y mt-1.5 max-h-64 overflow-y-auto"
            >
                <li
                    v-for="(c, i) in results"
                    :key="c.code"
                    role="option"
                    :aria-selected="c.code === model"
                    :data-active="i === active"
                    class="flex cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 text-[13px]"
                    :class="[
                        i === active ? 'bg-paper' : '',
                        c.code === model ? 'font-semibold' : '',
                    ]"
                    @pointerenter="active = i"
                    @click="pick(c.code)"
                >
                    <span class="text-[18px] leading-none" aria-hidden="true">{{
                        flagOf(c.code)
                    }}</span>
                    <span class="min-w-0 flex-1 truncate">{{ c.name }}</span>
                    <span class="font-mono text-[11.5px] text-basalt-light"
                        >+{{ c.dial }}</span
                    >
                </li>
                <li
                    v-if="results.length === 0"
                    class="px-2 py-2 text-[12.5px] text-basalt-light"
                >
                    {{ $t('Kein Land gefunden.') }}
                </li>
            </ul>
        </div>
    </div>
</template>
