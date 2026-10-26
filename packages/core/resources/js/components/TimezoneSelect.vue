<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import {
    browserTimezone,
    buildOptions,
    groupByRegion,
    searchTimezones,
    type TimezoneOption,
} from './timezone';

/**
 * Zeitzone wählen ohne Liste mit 400 Einträgen (Profil): geschlossen Stadt, Uhrzeit dort und Abstand zu UTC; offen
 * oben die im Browser erkannte Zone als Vorschlag, dann die Suche (Stadt, Land, Region, «+7») und die Treffer nach
 * Region gruppiert, jede Zeile mit der Ortszeit. Tastatur: Pfeile, Enter, Escape.
 */
const props = withDefaults(
    defineProps<{
        zones: string[];
        id?: string;
        invalid?: boolean;
        hour12?: boolean;
    }>(),
    {
        id: undefined,
        invalid: false,
        hour12: false,
    },
);
const model = defineModel<string>({ default: 'Europe/Zurich' });

const now = ref(new Date());
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(() => (now.value = new Date()), 30_000);
});

const open = ref(false);
const query = ref('');
const active = ref(0);
const root = ref<HTMLElement | null>(null);
const search = ref<HTMLInputElement | null>(null);
const list = ref<HTMLElement | null>(null);

const options = computed(() =>
    buildOptions(
        props.zones.includes(model.value)
            ? props.zones
            : [model.value, ...props.zones],
        now.value,
    ),
);
const current = computed(() => options.value.find((o) => o.id === model.value));
const detected = computed(() => {
    const zone = browserTimezone();

    return zone && zone !== model.value
        ? options.value.find((o) => o.id === zone)
        : undefined;
});
const results = computed(() => searchTimezones(options.value, query.value));
const groups = computed(() => groupByRegion(results.value));
/** Flache Reihenfolge der sichtbaren Zeilen für die Tastatur. */
const flat = computed<TimezoneOption[]>(() =>
    groups.value.flatMap((g) => g.items),
);

function time(o: TimezoneOption | undefined): string {
    if (!o) {
        return '';
    }

    return props.hour12
        ? new Intl.DateTimeFormat('en-US', {
              timeZone: o.id,
              hour: 'numeric',
              minute: '2-digit',
              hour12: true,
          }).format(now.value)
        : o.time;
}

function show(): void {
    open.value = true;
    query.value = '';
    void nextTick(() => {
        active.value = Math.max(
            0,
            flat.value.findIndex((o) => o.id === model.value),
        );
        search.value?.focus();
        scrollActive();
    });
    document.addEventListener('pointerdown', outside);
}

function close(): void {
    open.value = false;
    document.removeEventListener('pointerdown', outside);
}

function toggle(): void {
    if (open.value) {
        close();
    } else {
        show();
    }
}

function outside(event: PointerEvent): void {
    if (root.value && !root.value.contains(event.target as Node)) {
        close();
    }
}

function pick(id: string): void {
    model.value = id;
    close();
}

function scrollActive(): void {
    void nextTick(() =>
        list.value
            ?.querySelector<HTMLElement>('[data-active="true"]')
            ?.scrollIntoView({ block: 'nearest' }),
    );
}

function onSearch(): void {
    active.value = 0;
}

function onKey(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = Math.min(flat.value.length - 1, active.value + 1);
        scrollActive();
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(0, active.value - 1);
        scrollActive();
    } else if (event.key === 'Enter') {
        event.preventDefault();
        const hit = flat.value[active.value];
        if (hit) {
            pick(hit.id);
        }
    } else if (event.key === 'Escape') {
        close();
    }
}

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', outside);
    if (timer) {
        clearInterval(timer);
    }
});
</script>

<template>
    <div ref="root" class="relative w-full">
        <button
            :id="id"
            type="button"
            :aria-expanded="open"
            aria-haspopup="listbox"
            :aria-label="
                $t('Zeitzone: :zone', { zone: current?.city ?? model })
            "
            class="flex w-full items-center gap-2 rounded-md border bg-white px-2.5 py-2 text-left text-[13px] focus:ring-1 focus:outline-none"
            :class="
                invalid
                    ? 'border-danger ring-danger/15 ring-2'
                    : 'border-ash-dark focus:border-lava-dark focus:ring-lava-dark'
            "
            @click="toggle"
            @keydown.down.prevent="show"
        >
            <span class="min-w-0 flex-1 truncate">
                <span class="font-semibold">{{ current?.city ?? model }}</span>
                <span class="text-basalt-light"> · {{ current?.region }}</span>
            </span>
            <span class="flex-none font-mono text-[12px] tabular-nums">{{
                time(current)
            }}</span>
            <span
                class="flex-none rounded bg-paper px-1.5 py-0.5 font-mono text-[10.5px] text-basalt-light"
                >{{ current?.offsetLabel }}</span
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
            class="absolute top-full right-0 left-0 z-30 mt-1 min-w-72 rounded-lg border border-ash-light bg-white p-2 shadow-lg"
        >
            <button
                v-if="detected"
                type="button"
                class="mb-2 flex w-full items-center gap-2 rounded-md border border-dashed border-lava/60 bg-lava/5 px-2.5 py-1.5 text-left text-[12.5px] hover:bg-lava/10"
                @click="pick(detected.id)"
            >
                <span class="min-w-0 flex-1 truncate"
                    >{{ $t('Ihr Gerät:') }} <b>{{ detected.city }}</b> ·
                    {{ detected.region }}</span
                >
                <span class="font-mono text-[11.5px] tabular-nums">{{
                    time(detected)
                }}</span>
                <span class="font-semibold text-lava-dark">{{
                    $t('verwenden')
                }}</span>
            </button>
            <input
                ref="search"
                v-model="query"
                type="search"
                :placeholder="
                    $t('Stadt, Land oder Abstand («Wien», «Vietnam», «+7») …')
                "
                :aria-label="$t('Zeitzone suchen')"
                class="border-ash-dark w-full rounded-md border px-2.5 py-1.5 text-[13px] focus:border-lava-dark focus:ring-1 focus:ring-lava-dark focus:outline-none"
                @input="onSearch"
                @keydown="onKey"
            />
            <div
                ref="list"
                role="listbox"
                :aria-label="$t('Zeitzonen')"
                class="scroll-y mt-1.5 max-h-72 overflow-y-auto"
            >
                <template v-for="g in groups" :key="g.region">
                    <div
                        class="sticky top-0 z-[1] bg-white px-2 pt-2 pb-1 text-[9.5px] font-semibold tracking-[.1em] text-basalt-light uppercase"
                    >
                        {{ g.region }}
                        <span class="font-normal normal-case"
                            >· {{ g.items.length }}</span
                        >
                    </div>
                    <div
                        v-for="o in g.items"
                        :key="o.id"
                        role="option"
                        :aria-selected="o.id === model"
                        :data-active="flat[active]?.id === o.id"
                        class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-[13px]"
                        :class="[
                            flat[active]?.id === o.id ? 'bg-paper' : '',
                            o.id === model ? 'font-semibold' : '',
                        ]"
                        @pointerenter="active = flat.indexOf(o)"
                        @click="pick(o.id)"
                    >
                        <span class="min-w-0 flex-1 truncate">{{
                            o.city
                        }}</span>
                        <span
                            class="flex-none font-mono text-[12px] tabular-nums"
                            >{{ time(o) }}</span
                        >
                        <span
                            class="w-16 flex-none text-right font-mono text-[10.5px] text-basalt-light"
                            >{{ o.offsetLabel }}</span
                        >
                    </div>
                </template>
                <p
                    v-if="flat.length === 0"
                    class="px-2 py-2 text-[12.5px] text-basalt-light"
                >
                    {{
                        $t(
                            'Keine Zeitzone gefunden — versuchen Sie eine grössere Stadt in der Nähe oder den Abstand («+1»).',
                        )
                    }}
                </p>
            </div>
        </div>
    </div>
</template>
