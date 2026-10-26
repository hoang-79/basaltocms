<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { vDialog } from './a11y';
import HelpImageViewer from './HelpImageViewer.vue';
import {
    clampHelpWidth,
    closeHelp,
    helpImage,
    helpShortcut,
    helpState,
    helpTarget,
    HELP_WIDTH,
    toggleHelp,
} from './help';
import type { HelpImage } from './help';
import { http } from '@inertiajs/vue3';
import './help-prose.css';
import { locale, t } from '../i18n';
import type { BackendProps } from '../types';

/**
 * Webhilfe (TB 20): das Hilfe-Panel am rechten Rand, über jeder Backend-Seite. Es öffnet mit der Seite zur Route
 * («?» im Seitenkopf, Taste ? oder F1), sucht über die ganze Doku (Redaktion, Betrieb, Entwickler, Marktplatz) und
 * folgt Links zwischen Hilfeseiten im Panel selbst. Nicht modal: Die Seite dahinter bleibt bedienbar, Esc schliesst
 * und gibt den Fokus zurück. Die Texte kommen als HTML vom Server (aus Markdown, ohne rohes HTML).
 *
 * TB 29: Die Breite zieht man am linken Rand (oder mit ← → am Griff); sie gilt sofort und wird nach dem Loslassen im
 * Profil gespeichert. Ein Klick auf ein Bild zeigt es gross im Modal.
 */
type Page = {
    slug: string;
    title: string;
    html: string;
    locale: string;
    section: string;
    url: string;
};
type Section = {
    key: string;
    label: string;
    pages: { slug: string; title: string; url: string }[];
};
type Result = {
    slug: string;
    title: string;
    section: string;
    heading: string | null;
    anchor: string | null;
    snippet: string;
    url: string;
};

const page = usePage<BackendProps>();
const help = computed(() => page.props.backend?.help ?? null);

const current = ref<Page | null>(null);
const sections = ref<Section[]>([]);
const failed = ref(false);
const query = ref('');
const results = ref<Result[]>([]);
const searching = ref(false);
const active = ref(-1);
const showKeys = ref(false);
const searchInput = ref<HTMLInputElement | null>(null);
const body = ref<HTMLElement | null>(null);
const enlarged = ref<HelpImage | null>(null);

/* ── Breite: ziehen am linken Rand, gespeichert im Profil ── */
const viewport = ref(typeof window !== 'undefined' ? window.innerWidth : 1280);
const chosen = ref<number>(help.value?.width ?? HELP_WIDTH.fallback);
const width = computed(() => clampHelpWidth(chosen.value, viewport.value));
const dragging = ref(false);
let saved = chosen.value;
let saveTimer: number | undefined;

watch(
    () => help.value?.width,
    (stored) => {
        if (stored && !dragging.value) {
            chosen.value = stored;
            saved = stored;
        }
    },
);

function saveWidth(): void {
    window.clearTimeout(saveTimer);
    saveTimer = window.setTimeout(() => {
        const value = width.value;
        if (!help.value?.width_url || value === saved) {
            return;
        }
        saved = value;
        void http
            .getClient()
            .request({
                method: 'put',
                url: help.value.width_url,
                data: { width: value },
                headers: { Accept: 'application/json' },
            })
            .catch(() => {
                /* nicht gespeichert — die Breite gilt trotzdem für diese Sitzung */
            });
    }, 400);
}

function onResizeStart(event: PointerEvent): void {
    if (event.button !== 0) {
        return;
    }
    event.preventDefault();
    dragging.value = true;
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
}

function onResizeMove(event: PointerEvent): void {
    if (dragging.value) {
        chosen.value = clampHelpWidth(
            window.innerWidth - event.clientX,
            window.innerWidth,
        );
    }
}

function onResizeEnd(): void {
    if (dragging.value) {
        dragging.value = false;
        saveWidth();
    }
}

function onResizeKey(event: KeyboardEvent): void {
    const step = event.shiftKey ? 64 : 16;
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        event.preventDefault();
        chosen.value = clampHelpWidth(
            width.value + (event.key === 'ArrowLeft' ? step : -step),
            window.innerWidth,
        );
        saveWidth();
    } else if (event.key === 'Home' || event.key === 'End') {
        event.preventDefault();
        chosen.value = clampHelpWidth(
            event.key === 'Home' ? HELP_WIDTH.max : HELP_WIDTH.min,
            window.innerWidth,
        );
        saveWidth();
    }
}

function onViewport(): void {
    viewport.value = window.innerWidth;
}

const isMac =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.platform);
const shortcuts = computed(() => [
    { keys: ['?', 'F1'], label: t('Hilfe öffnen oder schliessen') },
    { keys: ['/'], label: t('In der Hilfe suchen') },
    { keys: ['Esc'], label: t('Hilfe schliessen') },
    { keys: [isMac ? '⌘ K' : 'Strg K'], label: t('Im Backend suchen') },
    { keys: ['↑', '↓', '↵'], label: t('Treffer wählen und öffnen') },
]);

const fallbackNotice = computed(() => {
    const shown = current.value?.locale ?? 'de';
    if (shown === locale()) {
        return '';
    }

    return shown === 'en'
        ? t(
              'Diese Hilfeseite gibt es nicht in Ihrer Sprache. Sie sehen sie auf Englisch.',
          )
        : t(
              'Diese Hilfeseite gibt es nicht in Ihrer Sprache. Sie sehen sie auf Deutsch.',
          );
});

let pageController: AbortController | null = null;
let searchController: AbortController | null = null;
let timer: number | undefined;

async function load(slug: string, anchor: string | null = null): Promise<void> {
    if (!help.value) {
        return;
    }
    pageController?.abort();
    pageController = new AbortController();
    failed.value = false;
    try {
        const response = await fetch(`${help.value.panel_url}/${slug}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: pageController.signal,
        });
        if (!response.ok) {
            throw new Error(String(response.status));
        }
        const data = (await response.json()) as {
            page: Page;
            sections: Section[];
        };
        current.value = data.page;
        sections.value = data.sections;
        query.value = '';
        showKeys.value = false;
        await nextTick();
        scrollTo(anchor);
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            failed.value = true;
        }
    }
}

function scrollTo(anchor: string | null): void {
    const target = anchor
        ? body.value?.querySelector<HTMLElement>(`[id="${CSS.escape(anchor)}"]`)
        : null;
    if (target) {
        target.scrollIntoView({ block: 'start' });
    } else {
        body.value?.scrollTo({ top: 0 });
    }
}

async function search(q: string): Promise<void> {
    searchController?.abort();
    if (!help.value || q.trim().length < 2) {
        results.value = [];
        searching.value = false;
        return;
    }
    searchController = new AbortController();
    searching.value = true;
    try {
        const response = await fetch(
            `${help.value.search_url}?q=${encodeURIComponent(q.trim())}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: searchController.signal,
            },
        );
        results.value = (
            (await response.json()) as { results: Result[] }
        ).results;
        active.value = results.value.length > 0 ? 0 : -1;
    } catch {
        /* abgebrochen oder offline — die Liste bleibt, wie sie war */
    } finally {
        searching.value = false;
    }
}

watch(query, (q) => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => void search(q), 200);
});

watch(
    () => helpState.version,
    () => {
        if (helpState.open) {
            void load(
                helpState.slug ?? help.value?.page ?? 'index',
                helpState.anchor,
            );
        }
    },
);

function choose(result: Result | undefined): void {
    if (result) {
        void load(result.slug, result.anchor);
    }
}

function onSearchKey(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        const count = results.value.length;
        if (count > 0) {
            active.value =
                (active.value + (event.key === 'ArrowDown' ? 1 : count - 1)) %
                count;
        }
    } else if (event.key === 'Enter') {
        event.preventDefault();
        choose(results.value[active.value] ?? results.value[0]);
    }
}

/** Links im Text: auf Hilfeseiten im Panel bleiben, Anker im Panel anspringen, alles andere wie gewohnt. */
function onArticleClick(event: MouseEvent): void {
    if (
        event.defaultPrevented ||
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey
    ) {
        return;
    }
    // TB 29: Bilder gross im Modal statt in einem neuen Tab
    const image = helpImage(event.target);
    if (image) {
        event.preventDefault();
        enlarged.value = image;

        return;
    }
    const link = (event.target as HTMLElement | null)?.closest('a');
    const href = link?.getAttribute('href');
    if (!link || !href || !help.value) {
        return;
    }
    if (href.startsWith('#')) {
        event.preventDefault();
        scrollTo(decodeURIComponent(href.slice(1)));

        return;
    }
    const target = helpTarget(href, help.value.url, window.location.origin);
    if (target) {
        event.preventDefault();
        void load(target.slug, target.anchor);
    }
}

function onWindowKey(event: KeyboardEvent): void {
    // TB 21: im Profil abschaltbar — dann öffnen nur «?» im Seitenkopf und das Benutzermenü die Hilfe
    if (help.value?.shortcuts === false) {
        return;
    }
    const action = helpShortcut(event, helpState.open);
    if (action === 'toggle' && help.value) {
        event.preventDefault();
        toggleHelp(help.value.page);
    } else if (action === 'search') {
        event.preventDefault();
        showKeys.value = false;
        searchInput.value?.focus();
    }
}

onMounted(() => {
    window.addEventListener('keydown', onWindowKey);
    window.addEventListener('resize', onViewport);
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onWindowKey);
    window.removeEventListener('resize', onViewport);
    window.clearTimeout(timer);
    window.clearTimeout(saveTimer);
    pageController?.abort();
    searchController?.abort();
});
</script>

<template>
    <aside
        v-if="helpState.open && help"
        v-dialog="{ close: closeHelp, modal: false }"
        role="dialog"
        aria-modal="false"
        aria-labelledby="hilfe-panel-titel"
        class="fixed inset-y-0 right-0 z-30 flex w-full flex-col border-l border-ash-light bg-white shadow-[-12px_0_40px_-20px_rgb(35_38_43/0.45)] sm:w-(--hilfe-breite)"
        :class="dragging ? 'select-none' : ''"
        :style="{ '--hilfe-breite': `${width}px` }"
    >
        <!-- TB 29: Griff zum Ziehen der Breite (ab 640 px; darunter füllt die Leiste den Bildschirm) -->
        <div
            role="separator"
            tabindex="0"
            aria-orientation="vertical"
            :aria-label="$t('Breite der Hilfe ändern')"
            :aria-valuenow="width"
            :aria-valuemin="HELP_WIDTH.min"
            :aria-valuemax="clampHelpWidth(HELP_WIDTH.max, viewport)"
            :title="$t('Ziehen ändert die Breite · ← → mit der Tastatur')"
            class="group absolute inset-y-0 -left-1.5 z-10 hidden w-3 cursor-col-resize touch-none focus:outline-none sm:block"
            @pointerdown="onResizeStart"
            @pointermove="onResizeMove"
            @pointerup="onResizeEnd"
            @pointercancel="onResizeEnd"
            @keydown="onResizeKey"
        >
            <span
                class="absolute inset-y-0 left-1/2 w-0.5 -translate-x-1/2 transition-colors group-hover:bg-lava/60 group-focus-visible:bg-lava"
                :class="dragging ? 'bg-lava' : 'bg-transparent'"
                aria-hidden="true"
            />
        </div>
        <div
            class="flex items-center gap-2 border-b border-ash-light px-4 py-3"
        >
            <h2 id="hilfe-panel-titel" class="mr-auto text-[15px] font-bold">
                {{ $t('Hilfe') }}
            </h2>
            <button
                type="button"
                class="rounded-md px-2 py-1 text-[12px] text-basalt-light hover:bg-paper"
                :aria-pressed="showKeys"
                @click="showKeys = !showKeys"
            >
                {{ $t('Tastenkürzel') }}
            </button>
            <a
                v-if="current"
                :href="current.url"
                class="rounded-md px-2 py-1 text-[12px] text-basalt-light hover:bg-paper"
                >{{ $t('Als Seite öffnen') }}</a
            >
            <button
                type="button"
                class="inline-flex h-7 w-7 items-center justify-center rounded-full text-[17px] text-basalt-light hover:bg-paper hover:text-lava"
                :aria-label="$t('Hilfe schliessen')"
                @click="closeHelp"
            >
                ×
            </button>
        </div>

        <div class="border-b border-ash-light px-4 py-2.5">
            <label for="hilfe-panel-suche" class="sr-only">{{
                $t('In der Hilfe suchen')
            }}</label>
            <input
                id="hilfe-panel-suche"
                ref="searchInput"
                v-model="query"
                type="search"
                autocomplete="off"
                class="w-full rounded-md border border-ash-light bg-paper px-3 py-1.5 text-[13px] focus:border-lava focus:outline-none"
                :placeholder="$t('In der Hilfe suchen …')"
                aria-controls="hilfe-panel-treffer"
                @keydown="onSearchKey"
            />
        </div>

        <div ref="body" class="scroll-y min-h-0 flex-1 px-4 py-4">
            <section v-if="showKeys" :aria-label="$t('Tastenkürzel')">
                <h3 class="mb-2 text-[14px] font-bold">
                    {{ $t('Tastenkürzel') }}
                </h3>
                <dl
                    class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-[13px]"
                >
                    <template
                        v-for="shortcut in shortcuts"
                        :key="shortcut.label"
                    >
                        <dt class="flex gap-1">
                            <kbd
                                v-for="key in shortcut.keys"
                                :key="key"
                                class="rounded border border-ash-light bg-paper px-1.5 font-mono text-[12px]"
                                >{{ key }}</kbd
                            >
                        </dt>
                        <dd>{{ shortcut.label }}</dd>
                    </template>
                </dl>
            </section>

            <section
                v-else-if="query.trim().length >= 2"
                id="hilfe-panel-treffer"
                :aria-label="$t('Treffer in der Hilfe')"
                aria-live="polite"
            >
                <p
                    v-if="!searching && results.length === 0"
                    class="text-[13px] text-basalt-light"
                >
                    {{ $t('Nichts gefunden.') }}
                </p>
                <ul class="grid gap-1">
                    <li v-for="(result, index) in results" :key="result.url">
                        <button
                            type="button"
                            class="block w-full rounded-md px-2.5 py-2 text-left"
                            :class="
                                index === active
                                    ? 'bg-paper shadow-[inset_3px_0_0_var(--color-lava)]'
                                    : 'hover:bg-paper'
                            "
                            @click="choose(result)"
                            @mouseenter="active = index"
                        >
                            <span class="block text-[13px] font-semibold">{{
                                result.title
                            }}</span>
                            <span class="block text-[11px] text-basalt-light"
                                >{{ result.section
                                }}<template v-if="result.heading">
                                    · {{ result.heading }}</template
                                ></span
                            >
                            <span
                                class="mt-0.5 block text-[12px] text-basalt-light"
                                >{{ result.snippet }}</span
                            >
                        </button>
                    </li>
                </ul>
            </section>

            <template v-else>
                <p v-if="failed" class="text-[13px] text-basalt-light">
                    {{ $t('Die Hilfe lässt sich gerade nicht laden.') }}
                </p>
                <article
                    v-else-if="current"
                    :lang="current.locale"
                    @click="onArticleClick"
                >
                    <p class="mb-1 text-[11px] text-basalt-light">
                        {{ current.section }}
                    </p>
                    <h3 class="mb-3 text-[19px] leading-tight font-bold">
                        {{ current.title }}
                    </h3>
                    <p
                        v-if="fallbackNotice"
                        class="mb-3 rounded-md bg-paper px-3 py-2 text-[12px] text-basalt-light"
                    >
                        {{ fallbackNotice }}
                    </p>
                    <!-- eslint-disable-next-line vue/no-v-html -- Markdown aus dem Repository, auf dem Server ohne rohes HTML gerendert -->
                    <div class="hilfe" v-html="current.html" />
                </article>
                <HelpImageViewer :image="enlarged" @close="enlarged = null" />

                <nav
                    v-if="sections.length"
                    class="mt-6 border-t border-ash-light pt-4"
                    :aria-label="$t('Alle Hilfeseiten')"
                >
                    <details
                        v-for="section in sections"
                        :key="section.key"
                        class="mb-1"
                        :open="current?.section === section.label"
                    >
                        <summary
                            class="cursor-pointer py-1 text-[12px] font-semibold text-basalt-light"
                        >
                            {{ section.label }}
                        </summary>
                        <ul class="grid gap-0.5 pb-2 pl-3 text-[13px]">
                            <li v-for="item in section.pages" :key="item.slug">
                                <a
                                    :href="item.url"
                                    class="block rounded px-2 py-1 hover:bg-paper"
                                    :aria-current="
                                        item.slug === current?.slug
                                            ? 'page'
                                            : undefined
                                    "
                                    :class="
                                        item.slug === current?.slug
                                            ? 'font-semibold'
                                            : ''
                                    "
                                    @click.prevent="load(item.slug)"
                                    >{{ item.title }}</a
                                >
                            </li>
                        </ul>
                    </details>
                </nav>
            </template>
        </div>
    </aside>
</template>
