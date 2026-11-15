<script setup lang="ts">
import { http, Link, router, usePage } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { t } from '../i18n';
import { isCurrent } from '../support';
import Hex from './Hex.vue';
import {
    barItems,
    countOf,
    groupItems,
    hiddenCount,
    togglePin,
    type BarItem,
    type MenuGroup,
} from './menuBar';

/**
 * Leiste eines Modus (TB 120, Variante H): angeheftete Einträge, die aktuelle Seite und «Alle» — ein Panel mit allen
 * Einträgen nach Gruppen und einer Nadel je Eintrag, die festlegt, was in der Leiste steht (je Person gespeichert).
 * Die Leiste bricht nicht mehr um; auf schmalen Bildschirmen zeigt sie nur die aktuelle Seite und «Alle».
 */
const props = defineProps<{
    mode: 'content' | 'admin';
    items: BarItem[];
    groups: MenuGroup[];
    pinsUrl: string;
    version?: string | null;
}>();

const page = usePage();
const current = (href: string): boolean => isCurrent(page.url, href);
const currentRoute = computed(
    () => props.items.find((item) => current(item.href))?.route ?? null,
);

const pinned = ref<Set<string>>(new Set());
watch(
    () => props.items,
    (items) => {
        pinned.value = new Set(
            items.filter((item) => item.pinned !== false).map((i) => i.route),
        );
    },
    { immediate: true },
);

const bar = computed(() =>
    barItems(props.items, pinned.value, currentRoute.value),
);
const grouped = computed(() => groupItems(props.items, props.groups));
// Zahlen hinter «Alle»: ohne das, was die Leiste schon zeigt — auch die aktuelle Seite
const hidden = computed(() =>
    hiddenCount(
        props.items,
        new Set([
            ...pinned.value,
            ...(currentRoute.value ? [currentRoute.value] : []),
        ]),
    ),
);

const open = ref(false);
const root = ref<HTMLElement | null>(null);
const toggleButton = ref<HTMLButtonElement | null>(null);
const panelId = `leiste-alle-${props.mode}`;

function close(focus = false): void {
    open.value = false;
    if (focus) {
        void nextTick(() => toggleButton.value?.focus());
    }
}

function onKey(event: KeyboardEvent): void {
    if (event.key === 'Escape' && open.value) {
        close(true);
    }
}

function onPointer(event: PointerEvent): void {
    if (
        open.value &&
        root.value &&
        !root.value.contains(event.target as Node)
    ) {
        close();
    }
}

let stopNavigate: (() => void) | null = null;
onMounted(() => {
    document.addEventListener('keydown', onKey);
    document.addEventListener('pointerdown', onPointer);
    stopNavigate = router.on('navigate', () => close());
});
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKey);
    document.removeEventListener('pointerdown', onPointer);
    stopNavigate?.();
});

const saving = ref(false);
async function pin(route: string): Promise<void> {
    const before = pinned.value;
    const routes = togglePin(props.items, before, route);
    pinned.value = new Set(routes);
    saving.value = true;
    try {
        await http.getClient().request({
            method: 'put',
            url: props.pinsUrl,
            data: { mode: props.mode, routes },
            headers: { Accept: 'application/json' },
        });
    } catch {
        // nicht gespeichert — die Leiste zeigt wieder den gespeicherten Stand
        pinned.value = before;
    } finally {
        saving.value = false;
    }
}

const tab =
    '-mb-px flex min-h-8 items-center gap-[7px] border-b-2 px-[11px] text-[12.5px] whitespace-nowrap';
</script>

<template>
    <div ref="root" class="relative flex w-full items-stretch">
        <Link
            v-for="{ item, extra } in bar"
            :key="item.route"
            :href="item.href"
            :class="[
                tab,
                current(item.href)
                    ? 'border-lava font-semibold text-basalt'
                    : 'border-transparent text-basalt-light hover:text-basalt',
                !current(item.href) && 'hidden md:flex',
            ]"
            :aria-current="current(item.href) ? 'page' : undefined"
            :title="extra ? t('Nicht angeheftet — im Panel «Alle»') : undefined"
        >
            <Hex
                :icon="item.icon ?? 'setup'"
                :label="item.label"
                :tone="current(item.href) ? 'basalt' : 'ash'"
            />
            {{ item.label }}
            <span
                v-if="countOf(item) > 0"
                class="rounded border bg-paper px-[5px] font-mono text-[10.5px] font-normal"
                :class="
                    (item.count ?? 0) > 0
                        ? 'border-warning text-warning'
                        : 'border-ash-light text-basalt-light'
                "
                :aria-label="t(':count offen', { count: countOf(item) })"
                >{{ countOf(item) }}</span
            >
        </Link>
        <span
            class="mx-1.5 my-auto hidden h-[18px] w-px bg-ash-light md:block"
            aria-hidden="true"
        />
        <button
            ref="toggleButton"
            type="button"
            :class="[
                tab,
                'cursor-pointer',
                open
                    ? 'border-lava font-semibold text-basalt'
                    : 'border-transparent text-basalt hover:text-basalt',
            ]"
            :aria-expanded="open"
            :aria-controls="panelId"
            @click="open = !open"
        >
            <Hex
                icon="overview"
                :label="t('Alle')"
                :tone="open ? 'basalt' : 'ash'"
            />
            {{ t('Alle') }}
            <span
                v-if="hidden.count > 0"
                class="rounded border bg-paper px-[5px] font-mono text-[10.5px] font-normal"
                :class="
                    hidden.waiting
                        ? 'border-warning text-warning'
                        : 'border-ash-light text-basalt-light'
                "
                :aria-label="t(':count offen', { count: hidden.count })"
                >{{ hidden.count }}</span
            >
            <svg
                viewBox="0 0 16 16"
                width="12"
                height="12"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
                :class="open && 'rotate-180'"
            >
                <path d="m4 6 4 4 4-4" />
            </svg>
        </button>
        <span
            v-if="version"
            class="text-ash-dark ml-auto hidden items-center pl-3 text-[10.5px] whitespace-nowrap md:flex"
            >Basalto {{ version }}</span
        >

        <div
            v-if="open"
            :id="panelId"
            class="absolute inset-x-0 top-full z-30 rounded-b-md border border-t-0 border-ash-light bg-white px-4 pt-3 pb-3.5 shadow-lg"
        >
            <div
                class="grid gap-x-6 gap-y-4"
                style="
                    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
                "
            >
                <section
                    v-for="group in grouped"
                    :key="group.key"
                    :aria-labelledby="`${panelId}-${group.key}`"
                >
                    <h2
                        :id="`${panelId}-${group.key}`"
                        class="text-ash-dark mb-1.5 font-mono text-[10.5px] tracking-[.08em] uppercase"
                    >
                        {{ group.label || t('Weitere') }}
                    </h2>
                    <ul class="flex flex-col gap-0.5">
                        <li
                            v-for="item in group.items"
                            :key="item.route"
                            class="flex items-center gap-1"
                        >
                            <Link
                                :href="item.href"
                                class="flex min-h-8 flex-1 items-center gap-[7px] rounded px-2 text-[12.5px] hover:bg-paper"
                                :class="
                                    current(item.href)
                                        ? 'font-semibold text-basalt'
                                        : 'text-basalt-light hover:text-basalt'
                                "
                                :aria-current="
                                    current(item.href) ? 'page' : undefined
                                "
                            >
                                <Hex
                                    :icon="item.icon ?? 'setup'"
                                    :label="item.label"
                                    :tone="
                                        current(item.href) ? 'basalt' : 'ash'
                                    "
                                />
                                {{ item.label }}
                                <span
                                    v-if="countOf(item) > 0"
                                    class="rounded border bg-paper px-[5px] font-mono text-[10.5px] font-normal"
                                    :class="
                                        (item.count ?? 0) > 0
                                            ? 'border-warning text-warning'
                                            : 'border-ash-light text-basalt-light'
                                    "
                                    :aria-label="
                                        t(':count offen', {
                                            count: countOf(item),
                                        })
                                    "
                                    >{{ countOf(item) }}</span
                                >
                            </Link>
                            <button
                                type="button"
                                class="flex size-8 shrink-0 cursor-pointer items-center justify-center rounded hover:bg-paper"
                                :class="
                                    pinned.has(item.route)
                                        ? 'text-lava'
                                        : 'text-ash-dark hover:text-basalt'
                                "
                                :aria-pressed="pinned.has(item.route)"
                                :aria-label="
                                    t('In der Leiste: :label', {
                                        label: item.label,
                                    })
                                "
                                :title="
                                    pinned.has(item.route)
                                        ? t('Aus der Leiste nehmen')
                                        : t('In der Leiste zeigen')
                                "
                                :disabled="saving"
                                @click="pin(item.route)"
                            >
                                <svg
                                    viewBox="0 0 24 24"
                                    width="15"
                                    height="15"
                                    :fill="
                                        pinned.has(item.route)
                                            ? 'currentColor'
                                            : 'none'
                                    "
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    aria-hidden="true"
                                >
                                    <path d="M12 17v5" />
                                    <path
                                        d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"
                                    />
                                </svg>
                            </button>
                        </li>
                    </ul>
                </section>
            </div>
            <p class="text-ash-dark mt-3 text-[11.5px]">
                {{
                    t(
                        'Mit der Nadel legen Sie fest, was in der Leiste steht — für Sie auf allen Geräten.',
                    )
                }}
            </p>
        </div>
    </div>
</template>
