<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Suche im Kopf des Backends (M-12): Inhalte, Medien, Benutzer — ab zwei Zeichen, kurz verzögert; ⌘K oder Strg+K
 * springt hinein, Pfeile wählen, Enter öffnet, Escape schliesst.
 */
type Item = { title: string; meta: string; url: string };
type Group = { label: string; items: Item[] };

const props = defineProps<{ url: string }>();

const query = ref('');
const groups = ref<Group[]>([]);
const open = ref(false);
const active = ref(-1);
const loading = ref(false);
const input = ref<HTMLInputElement | null>(null);
let timer: number | undefined;
let controller: AbortController | null = null;

const flat = (): Item[] => groups.value.flatMap((g) => g.items);

async function load(q: string): Promise<void> {
    controller?.abort();
    if (q.trim().length < 2) {
        groups.value = [];
        loading.value = false;
        return;
    }
    controller = new AbortController();
    loading.value = true;
    try {
        const response = await fetch(
            `${props.url}?q=${encodeURIComponent(q.trim())}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            },
        );
        const data = (await response.json()) as { groups: Group[] };
        groups.value = data.groups;
        active.value = flat().length > 0 ? 0 : -1;
    } catch {
        /* abgebrochen oder offline — die Liste bleibt, wie sie war */
    } finally {
        loading.value = false;
    }
}

watch(query, (q) => {
    window.clearTimeout(timer);
    open.value = true;
    timer = window.setTimeout(() => void load(q), 200);
});

function go(item: Item | undefined): void {
    if (!item) {
        return;
    }
    open.value = false;
    query.value = '';
    router.visit(item.url);
}

function onKey(event: KeyboardEvent): void {
    const items = flat();
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = Math.min(items.length - 1, active.value + 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(0, active.value - 1);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        go(items[active.value]);
    } else if (event.key === 'Escape') {
        open.value = false;
        input.value?.blur();
    }
}

function shortcut(event: KeyboardEvent): void {
    // Hat ein Editor ⌘K schon genommen (Link im Rich-Text), bleibt die Suche zu
    if (event.defaultPrevented) {
        return;
    }
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        input.value?.focus();
        open.value = query.value.length > 0;
    }
}

function indexOf(item: Item): number {
    return flat().indexOf(item);
}

onMounted(() => window.addEventListener('keydown', shortcut));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', shortcut);
    window.clearTimeout(timer);
    controller?.abort();
});
</script>

<template>
    <div class="relative ml-auto hidden md:block">
        <div
            class="flex items-center gap-2 rounded-md border border-basalt-light px-2.5 py-1 text-xs text-ash focus-within:border-paper focus-within:ring-1 focus-within:ring-paper"
        >
            <span aria-hidden="true">⌕</span>
            <input
                ref="input"
                v-model="query"
                type="search"
                :placeholder="$t('Suchen …')"
                :aria-label="$t('Inhalte, Medien und Benutzer suchen')"
                role="combobox"
                aria-autocomplete="list"
                :aria-expanded="open && query.length >= 2"
                aria-controls="backend-search-list"
                class="w-40 bg-transparent text-paper placeholder:text-ash focus:outline-none"
                @keydown="onKey"
                @focus="open = query.length >= 2"
                @blur="open = false"
            />
            <kbd
                class="rounded-sm bg-basalt-light px-1 font-mono text-[10px] text-paper"
                >⌘K</kbd
            >
        </div>
        <div
            v-if="open && query.trim().length >= 2"
            id="backend-search-list"
            role="listbox"
            class="absolute right-0 z-50 mt-1 w-80 rounded-md border border-ash-light bg-white py-1 text-basalt shadow-lg"
        >
            <p
                v-if="loading && groups.length === 0"
                class="px-3 py-2 text-xs text-basalt-light"
            >
                {{ $t('Suche …') }}
            </p>
            <p
                v-else-if="groups.length === 0"
                class="px-3 py-2 text-xs text-basalt-light"
            >
                {{ $t('Keine Treffer für «:query».', { query: query.trim() }) }}
            </p>
            <div v-for="group in groups" :key="group.label">
                <p
                    class="px-3 pt-2 pb-0.5 text-[9.5px] font-semibold tracking-[.1em] text-basalt-light uppercase"
                >
                    {{ group.label }}
                </p>
                <button
                    v-for="item in group.items"
                    :key="item.url + item.title"
                    type="button"
                    role="option"
                    :aria-selected="indexOf(item) === active"
                    class="block w-full px-3 py-1.5 text-left"
                    :class="indexOf(item) === active ? 'bg-paper' : ''"
                    @mousedown.prevent="go(item)"
                    @mouseenter="active = indexOf(item)"
                >
                    <span class="block truncate text-[12.5px] font-semibold">{{
                        item.title
                    }}</span>
                    <span
                        class="block truncate text-[11px] text-basalt-light"
                        >{{ item.meta }}</span
                    >
                </button>
            </div>
        </div>
    </div>
</template>
