<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { computed, h, ref, watch, type FunctionalComponent } from 'vue';
import {
    announceError,
    announceSaved,
    announceSaving,
    useAutosave,
} from '../../components/autosave';
import Button from '../../components/Button.vue';
import Card from '../../components/Card.vue';
import CodeBlock from '../../components/CodeBlock.vue';
import { labelClasses } from '../../components/control';
import EmptyState from '../../components/EmptyState.vue';
import Field from '../../components/Field.vue';
import Notice from '../../components/Notice.vue';
import PageHeader from '../../components/PageHeader.vue';
import Toggle from '../../components/Toggle.vue';
import {
    canIndent,
    canMoveSibling,
    canOutdent,
    dropRange,
    indent,
    moveBlock,
    moveSibling,
    outdent,
    subtreeEnd,
    toOrder,
} from '../../components/navigation';
import {
    looksLikeAddress,
    matchingPages,
    missingPages,
    normalizeAddress,
    pageAt,
} from '../../components/navAssist';
import { t, tc } from '../../i18n';
import type {
    BackendProps,
    NavigationItem,
    NavigationMenu,
    NavigationPage,
    NavigationPreview,
} from '../../types';

/**
 * Station D «nav» (Bereich 15, M-07, L-08): die Menüs aus dem Theme. Freie Menüs (`items: db`) pflegt die Station —
 * Einträge je Sprache beschriftet, Ziel Seite oder URL, sortiert per Ziehen oder mit ↑ ↓ ← → (Tiefe nach Lava);
 * Speichern ist live: Reihenfolge sofort, ein bestehender Eintrag per Autosave (neue Einträge mit Knopf). Menüs aus dem Seitenbaum, einer Typ-Liste oder dem Theme stehen nur lesend mit ihrem Code —
 * die Reihenfolge gehört dort dem Seitenbaum. Mobil: Menüwahl als Karten, Eintrag als eigene Ansicht.
 */
const props = defineProps<{
    navs: NavigationMenu[];
    nav: string;
    locale: string;
    locales: { locale: string; is_default: boolean }[];
    items: NavigationItem[];
    preview: NavigationPreview[];
    pages: NavigationPage[];
    max_depth: number;
    urls: { index: string; store: string | null; order: string | null };
    can_edit: boolean;
    status: string | null;
}>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);
const isDesktop = useMediaQuery('(min-width: 768px)');

const menu = computed<NavigationMenu | null>(
    () =>
        props.navs.find((n) => n.handle === props.nav) ?? props.navs[0] ?? null,
);
const editable = computed(
    () => Boolean(menu.value?.editable) && props.can_edit,
);
const defaultLocale = computed(
    () =>
        props.locales.find((l) => l.is_default)?.locale ??
        props.locales[0]?.locale ??
        props.locale,
);
const pagesHref = computed(
    () =>
        page.props.backend.menus.content.find(
            (m) => m.route === 'basalto.pages.index',
        )?.href ?? '/admin/pages',
);
const ownStatus = computed(() =>
    props.status && props.status !== page.props.backend.flash?.status
        ? props.status
        : null,
);

function visit(params: { nav?: string; locale?: string }): void {
    router.get(
        props.urls.index,
        { nav: params.nav ?? props.nav, locale: params.locale ?? props.locale },
        { preserveScroll: true },
    );
}

const lead = computed(() => {
    const m = menu.value;
    if (!m) {
        return t('Das Theme definiert noch keine Menüs.');
    }
    if (m.editable) {
        return tc(
            ':count Menü aus dem Theme. Freie Menüs sortieren Sie hier; Menüs aus dem Seitenbaum folgen der Reihenfolge in «Seiten». Änderungen speichern sich selbst — Navigation ist Struktur, nicht Inhalt.|:count Menüs aus dem Theme. Freie Menüs sortieren Sie hier; Menüs aus dem Seitenbaum folgen der Reihenfolge in «Seiten». Änderungen speichern sich selbst — Navigation ist Struktur, nicht Inhalt.',
            props.navs.length,
        );
    }

    return t(
        'Das Menü «:menu» kommt :source. Links die Einstellungen, rechts der Code derselben Quelle.',
        {
            menu: m.label,
            source:
                m.source === 'pages'
                    ? t('aus dem Seitenbaum')
                    : m.source === 'type'
                      ? t('aus einer Typ-Liste')
                      : t('aus dem Theme'),
        },
    );
});

const codeRef = computed(() => {
    const m = menu.value;
    if (!m) {
        return '';
    }

    return `${m.line ? `theme.lava:${m.line} · ` : ''}nav ${m.handle}`;
});

function menuSummary(m: NavigationMenu): string {
    const parts = [m.source_label];
    if (m.source !== 'db' && m.depth > 0) {
        parts.push(t('Tiefe :depth', { depth: m.depth }));
    }
    if (m.only.length > 0) {
        parts.push(t('nur :types', { types: m.only.join(', ') }));
    }

    return parts.join(' · ');
}

/* ── Einträge (lokal sortiert, live gespeichert) ── */

type FreeItem = NavigationItem & { id: number };

const rows = ref<FreeItem[]>(freeItems());

function freeItems(): FreeItem[] {
    return props.items.filter((i): i is FreeItem => i.id !== null);
}

watch(
    () => props.items,
    () => {
        rows.value = freeItems();
    },
);

function persist(next: FreeItem[]): void {
    if (next === rows.value) {
        return;
    }
    rows.value = next;
    if (props.urls.order) {
        announceSaving();
        router.post(
            props.urls.order,
            { items: toOrder(next) },
            {
                preserveScroll: true,
                preserveState: true,
                // Rückmeldung unten rechts statt Hinweis oben — nur Liste und Vorschau neu
                only: ['items', 'preview', 'errors'],
                onSuccess: () => announceSaved(t('Reihenfolge')),
                onError: (e) =>
                    announceError(
                        Object.values(e)[0] ?? t('Reihenfolge prüfen'),
                    ),
            },
        );
    }
}

/** Woher die Beschriftung kommt, wenn diese Sprache keine eigene hat: Standardsprache oder Seitentitel. */
function fallbackTag(
    item: NavigationItem,
): { text: string; title: string } | null {
    if (!item.label_fallback) {
        return null;
    }
    const def = defaultLocale.value;
    if (props.locale !== def && (item.label[def] ?? '').trim() !== '') {
        return {
            text: def.toUpperCase(),
            title: t('Keine Beschriftung in :locale — übernimmt :default', {
                locale: props.locale.toUpperCase(),
                default: def.toUpperCase(),
            }),
        };
    }
    if (item.target.kind === 'entry') {
        return {
            text: t('Seitentitel'),
            title: t('Ohne eigene Beschriftung — folgt dem Seitentitel'),
        };
    }

    return null;
}

function targetText(item: NavigationItem): string {
    if (item.target.kind === 'url') {
        return `→ ${t(':url (frei)', { url: item.target.url ?? '—' })}`;
    }

    return `→ ${t('Seite «:title»', { title: item.target.title ?? '?' })}${item.target.path ? ` · ${item.target.path}` : ''}`;
}

/* Ziehen: Einfügemarke vor einem Eintrag, Tiefe aus der waagrechten Lage */

const dragFrom = ref<number | null>(null);
const drop = ref<{ before: number; depth: number; at: number } | null>(null);
const listEl = ref<HTMLElement | null>(null);
const indentPx = 24;

function onDragStart(event: DragEvent, index: number): void {
    dragFrom.value = index;
    event.dataTransfer?.setData('text/plain', String(rows.value[index]?.id));
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
    }
}

function onDragOver(event: DragEvent, index: number): void {
    const from = dragFrom.value;
    if (from === null) {
        return;
    }
    const target = event.currentTarget as HTMLElement;
    const rect = target.getBoundingClientRect();
    const after = event.clientY > rect.top + rect.height / 2;
    const blockEnd = subtreeEnd(rows.value, from);
    const at = after ? subtreeEnd(rows.value, index) : index;
    if (at > from && at < blockEnd) {
        drop.value = null;

        return;
    }
    const before = at > from ? at - (blockEnd - from) : at;
    const range = dropRange(rows.value, from, before, props.max_depth);
    if (!range) {
        drop.value = null;

        return;
    }
    const left = listEl.value?.getBoundingClientRect().left ?? rect.left;
    const wanted = Math.floor((event.clientX - left - 28) / indentPx);
    const depth = Math.min(range.max, Math.max(range.min, wanted));
    drop.value = { before, depth, at };
    event.preventDefault();
}

function onDrop(): void {
    const from = dragFrom.value;
    const target = drop.value;
    dragFrom.value = null;
    drop.value = null;
    if (from === null || !target) {
        return;
    }
    persist(
        moveBlock(
            rows.value,
            from,
            target.before,
            target.depth,
            props.max_depth,
        ),
    );
}

function onDragEnd(): void {
    dragFrom.value = null;
    drop.value = null;
}

/* ── Eintrag bearbeiten ── */

type ItemForm = {
    label: Record<string, string>;
    kind: 'entry' | 'url';
    entry_id: number | null;
    url: string;
    visible: boolean;
    new_window: boolean;
    parent_id: number | null;
};

const editing = ref<number | 'new' | null>(null);
const confirmingDelete = ref(false);

function blankLabels(source?: Record<string, string>): Record<string, string> {
    const labels: Record<string, string> = {};
    props.locales.forEach((l) => {
        labels[l.locale] = source?.[l.locale] ?? '';
    });

    return labels;
}

const form = useForm<ItemForm>({
    label: blankLabels(),
    kind: 'entry',
    entry_id: null,
    url: '',
    visible: true,
    new_window: false,
    parent_id: null,
});

const errors = computed(() => form.errors as unknown as Record<string, string>);

const current = computed(() =>
    typeof editing.value === 'number'
        ? (rows.value.find((r) => r.id === editing.value) ?? null)
        : null,
);

/** Ein Ziel, das vollständig genug zum Speichern ist (wie die Regel der PHP-Seite, aber ohne halbe Hosts beim Tippen). */
function urlReady(url: string): boolean {
    return /^(https?:\/\/[^\s/.]+\.[^\s/]{2,}|\/|mailto:\S+@\S+|tel:\+?\d|#)/.test(
        url.trim(),
    );
}

/*
 * Autosave nur für einen bestehenden Eintrag; neu anlegen bleibt mit Knopf. Danach Liste und Vorschau frisch.
 * Die Elternstelle kommt immer aus der Liste — Sortieren ändert sie, das Formular kennte sonst die alte.
 */
const autosave = useAutosave(form, {
    url: () => current.value?.urls?.update ?? '',
    only: ['items', 'preview', 'errors'],
    enabled: () =>
        typeof editing.value === 'number' &&
        Boolean(current.value?.urls) &&
        (form.kind === 'entry'
            ? form.entry_id !== null
            : urlReady(form.url) &&
              Object.values(form.label).some((l) => l.trim() !== '')),
    labels: {
        label: t('Beschriftung'),
        kind: t('Art'),
        entry_id: t('Ziel'),
        url: t('Ziel'),
        visible: t('Sichtbar'),
        new_window: t('Neues Fenster'),
    },
});

function load(data: ItemForm): void {
    form.defaults(data);
    form.reset();
    form.clearErrors();
    autosave.rebase();
    confirmingDelete.value = false;
}

function edit(item: FreeItem): void {
    // Was am vorigen Eintrag noch wartet, geht vorher raus
    autosave.flush();
    editing.value = item.id;
    form.transform((data) => ({
        ...data,
        parent_id: current.value?.parent_id ?? data.parent_id,
    }));
    load({
        label: blankLabels(item.label),
        kind: item.target.kind,
        entry_id: item.target.entry_id,
        url: item.target.url ?? '',
        visible: item.visible,
        new_window: item.new_window,
        parent_id: item.parent_id,
    });
}

function create(kind: 'entry' | 'url'): void {
    autosave.flush();
    editing.value = 'new';
    load({
        label: blankLabels(),
        kind,
        entry_id: null,
        url: '',
        visible: true,
        new_window: false,
        parent_id: null,
    });
}

function close(): void {
    autosave.flush();
    editing.value = null;
    confirmingDelete.value = false;
    form.clearErrors();
}

/** Nach dem Speichern zeigt die Karte den gespeicherten Eintrag; ist er weg (gelöscht), schliesst sie. */
watch(rows, () => {
    if (typeof editing.value === 'number' && !current.value) {
        close();
    }
});

function save(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            if (editing.value === 'new' || !isDesktop.value) {
                close();
            }
        },
    };
    if (editing.value === 'new' && props.urls.store) {
        form.transform((data) => ({ ...data, locale: props.locale })).post(
            props.urls.store,
            options,
        );
    } else if (current.value?.urls) {
        form.transform((data) => data).put(current.value.urls.update, options);
    }
}

function destroy(): void {
    const url = current.value?.urls?.delete;
    if (url) {
        router.delete(url, { preserveScroll: true, onSuccess: close });
    }
}

const pageOptions = computed(() => [
    { value: '', label: t('— Seite wählen —') },
    ...props.pages.map((p) => ({
        value: String(p.id),
        label: `${'\u00a0\u00a0'.repeat(p.depth)}${p.depth > 0 ? '└ ' : ''}${p.title}${p.path ? ` (${p.path})` : ''}${p.live ? '' : ` · ${t('nicht veröffentlicht')}`}`,
    })),
]);

const editorTitle = computed(() => {
    if (editing.value === 'new') {
        return form.kind === 'url'
            ? t('Neuer Eintrag · frei (URL)')
            : t('Neuer Eintrag · aus dem Seitenbaum');
    }

    return t('Eintrag «:label»', {
        label: current.value?.label_effective ?? '',
    });
});

function labelPlaceholder(locale: string): string {
    if (locale !== defaultLocale.value) {
        return t('— übernimmt :default', {
            default: defaultLocale.value.toUpperCase(),
        });
    }

    return form.kind === 'entry' ? t('— folgt dem Seitentitel') : '';
}

/* ── TB 34: geführt — ein Feld für Seite oder Adresse, fehlende Seiten mit einem Klick, Seite statt Pfad ── */
const quick = ref('');
const quickOpen = ref(false);
const quickPages = computed(() => matchingPages(quick.value, props.pages));
const quickIsAddress = computed(() => looksLikeAddress(quick.value));
const missing = computed(() => missingPages(props.pages, rows.value));
const otherLabelsOpen = ref(false);
const otherLocales = computed(() =>
    props.locales.filter((l) => l.locale !== defaultLocale.value),
);
/** Eine freie Adresse, hinter der eine Seite der Site steht — besser verknüpfen, sie folgt Umbenennungen. */
const urlPage = computed(() =>
    form.kind === 'url' ? pageAt(form.url, props.pages) : null,
);

/** Eine Seite sofort ins Menü — die Beschriftung folgt dem Seitentitel. */
function addPage(target: NavigationPage): void {
    if (!props.urls.store) {
        return;
    }
    autosave.flush();
    router.post(
        props.urls.store,
        {
            label: blankLabels(),
            kind: 'entry',
            entry_id: target.id,
            url: '',
            visible: true,
            new_window: false,
            parent_id: null,
            locale: props.locale,
        },
        { preserveScroll: true },
    );
    quick.value = '';
    quickOpen.value = false;
}

/** Eine Adresse: Ist sie eine Seite der Site, kommt die Seite; sonst ein freier Eintrag (fremde Adressen im neuen Fenster). */
function addAddress(): void {
    const url = normalizeAddress(quick.value);
    const target = pageAt(url, props.pages);
    if (target) {
        addPage(target);

        return;
    }
    create('url');
    form.url = url;
    form.new_window = /^https?:\/\//i.test(url);
    quick.value = '';
    quickOpen.value = false;
}

function onQuickEnter(): void {
    if (quickIsAddress.value) {
        addAddress();
    } else if (quickPages.value[0]) {
        addPage(quickPages.value[0]);
    }
}

function linkPage(): void {
    const target = urlPage.value;
    if (target) {
        form.kind = 'entry';
        form.entry_id = target.id;
        form.url = '';
    }
}

/* ── Vorschau ── */

const PreviewList: FunctionalComponent<{ entries: NavigationPreview[] }> = (
    p,
) =>
    h(
        'ul',
        { class: 'flex flex-wrap gap-x-3.5 gap-y-1' },
        p.entries.map((e) =>
            h('li', { class: 'min-w-0' }, [
                h(
                    'span',
                    {
                        class: e.active ? 'font-semibold' : '',
                        title: e.href,
                    },
                    `${e.label}${e.children.length > 0 ? ' ▾' : ''}`,
                ),
                e.children.length > 0
                    ? h(
                          'span',
                          {
                              class: 'block text-[11px] text-basalt-light',
                          },
                          e.children.map((c) => c.label).join(' · '),
                      )
                    : null,
            ]),
        ),
    );

const opButton =
    'flex h-6 w-6 items-center justify-center rounded text-[12px] text-basalt-light hover:bg-paper hover:text-basalt disabled:text-ash-light disabled:hover:bg-transparent';
const tag =
    'rounded border border-ash-light bg-paper px-[5px] font-mono text-[10px] font-normal text-basalt-light';
</script>

<template>
    <Head :title="$t('Navigation')" />
    <PageHeader
        :title="$t('Navigation')"
        :crumb="$t(':site › Einrichtung', { site: siteName })"
        :station="{ letter: 'D', keyword: 'nav' }"
        :lead="lead"
    />

    <Notice v-if="ownStatus" tone="success" class="mb-3">{{
        ownStatus
    }}</Notice>

    <div v-if="!menu" class="rounded-lg border border-ash-light bg-white">
        <EmptyState
            :title="$t('Das Theme definiert keine Menüs')"
            :text="
                $t(
                    'Station D in theme.lava ist leer — «nav main { from: pages }» legt ein Menü aus dem Seitenbaum an.',
                )
            "
            icon="line"
            tone="ash"
        />
    </div>

    <template v-else>
        <!-- Menüwahl: Desktop als Filterzeile, mobil als Karten -->
        <div
            v-if="isDesktop"
            class="mb-3 flex flex-wrap items-end gap-x-4 border-b border-ash-light"
            role="tablist"
            :aria-label="$t('Menüs')"
        >
            <button
                v-for="n in navs"
                :key="n.handle"
                type="button"
                role="tab"
                :aria-selected="n.handle === menu.handle"
                class="-mb-px flex items-baseline gap-1.5 border-b-2 py-2 text-[13px] whitespace-nowrap"
                :class="
                    n.handle === menu.handle
                        ? 'border-lava font-semibold text-basalt'
                        : 'border-transparent text-basalt-light hover:text-basalt'
                "
                @click="visit({ nav: n.handle })"
            >
                {{ n.label }}
                <span class="font-mono text-[11px] font-normal">{{
                    n.source_label
                }}</span>
            </button>
            <span
                class="ml-auto pb-2 font-mono text-[11.5px] text-basalt-light"
                >{{ codeRef }}</span
            >
        </div>
        <div
            v-else-if="editing === null"
            class="mb-3 flex flex-col gap-1.5"
            :aria-label="$t('Menüs')"
        >
            <p
                class="text-[10px] font-semibold tracking-[.1em] text-basalt-light uppercase"
            >
                {{ $t('Station D · Menüs aus dem Theme') }}
            </p>
            <button
                v-for="n in navs"
                :key="n.handle"
                type="button"
                class="rounded-lg border bg-white px-3.5 py-2.5 text-left"
                :class="
                    n.handle === menu.handle
                        ? 'border-lava/50 shadow-[inset_3px_0_0_var(--color-lava)]'
                        : 'border-ash-light'
                "
                :aria-current="n.handle === menu.handle ? 'true' : undefined"
                @click="visit({ nav: n.handle })"
            >
                <span class="block text-sm font-semibold">{{ n.label }}</span>
                <span class="block text-xs text-basalt-light">{{
                    menuSummary(n)
                }}</span>
            </button>
        </div>

        <!-- ── Freies Menü ── -->
        <template v-if="menu.editable">
            <div
                class="grid gap-4"
                :class="isDesktop ? 'grid-cols-[minmax(0,1fr)_300px]' : ''"
            >
                <div v-if="isDesktop || editing === null" class="min-w-0">
                    <nav
                        class="mb-2 flex flex-wrap border-b border-ash-light"
                        :aria-label="$t('Sprache der Beschriftung')"
                    >
                        <button
                            v-for="l in locales"
                            :key="l.locale"
                            type="button"
                            class="-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2 text-[12.5px]"
                            :class="
                                l.locale === locale
                                    ? 'border-lava font-semibold text-basalt'
                                    : 'border-transparent text-basalt-light hover:text-basalt'
                            "
                            :aria-current="
                                l.locale === locale ? 'true' : undefined
                            "
                            @click="visit({ locale: l.locale })"
                        >
                            {{ l.locale.toUpperCase() }}
                            <span
                                v-if="l.is_default"
                                class="text-ash-dark text-[10px]"
                                >{{ $t('Standard') }}</span
                            >
                        </button>
                    </nav>

                    <div
                        ref="listEl"
                        class="rounded-lg border border-ash-light bg-white"
                        @dragover.prevent
                        @drop.prevent="onDrop"
                    >
                        <p
                            v-if="rows.length === 0"
                            class="px-3.5 py-4 text-[12.5px] text-basalt-light"
                        >
                            {{ $t('Noch keine Einträge —') }}
                            <template v-if="editable">{{
                                $t(
                                    '«+ Aus Seitenbaum» oder «+ Frei (URL)» legt einen an.',
                                )
                            }}</template>
                            <template v-else>{{
                                $t('Sie dürfen dieses Menü nicht ändern.')
                            }}</template>
                        </p>
                        <ul v-else class="divide-y divide-ash-light">
                            <li
                                v-for="(item, index) in rows"
                                :key="item.id"
                                class="relative flex items-start gap-2 py-2 pr-2.5"
                                :class="[
                                    editing === item.id
                                        ? 'bg-lava/5 shadow-[inset_3px_0_0_var(--color-lava)]'
                                        : '',
                                    dragFrom !== null &&
                                    index >= dragFrom &&
                                    index < subtreeEnd(rows, dragFrom)
                                        ? 'opacity-40'
                                        : '',
                                ]"
                                :style="{
                                    paddingLeft: `${12 + item.depth * indentPx}px`,
                                }"
                                :draggable="editable"
                                @dragstart="onDragStart($event, index)"
                                @dragover="onDragOver($event, index)"
                                @dragend="onDragEnd"
                            >
                                <span
                                    v-if="drop && drop.at === index"
                                    class="pointer-events-none absolute -top-px right-2 h-0.5 bg-lava"
                                    :style="{
                                        left: `${12 + drop.depth * indentPx}px`,
                                    }"
                                    aria-hidden="true"
                                />
                                <span
                                    v-if="editable"
                                    class="text-ash-dark mt-0.5 cursor-grab select-none"
                                    :title="
                                        $t(
                                            'Ziehen sortiert · weiter rechts ablegen macht einen Untereintrag',
                                        )
                                    "
                                    aria-hidden="true"
                                    >⋮⋮</span
                                >
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[13.5px] font-semibold"
                                        :class="
                                            item.live && item.visible
                                                ? ''
                                                : 'text-ash-dark'
                                        "
                                    >
                                        <span class="break-words">{{
                                            item.label_effective
                                        }}</span>
                                        <span
                                            v-if="fallbackTag(item)"
                                            :class="tag"
                                            :title="fallbackTag(item)?.title"
                                            >{{ fallbackTag(item)?.text }}</span
                                        >
                                        <span
                                            v-if="!item.visible && !item.hint"
                                            :class="tag"
                                            >{{ $t('verborgen') }}</span
                                        >
                                        <span
                                            v-if="item.new_window"
                                            :class="tag"
                                            :title="
                                                $t('öffnet in neuem Fenster')
                                            "
                                            >{{ $t('↗ neues Fenster') }}</span
                                        >
                                    </p>
                                    <p
                                        class="font-mono text-[11px] break-all text-basalt-light"
                                    >
                                        {{ targetText(item) }}
                                    </p>
                                    <p
                                        v-if="item.hint"
                                        class="text-warning text-[11.5px]"
                                    >
                                        {{ item.hint }}
                                    </p>
                                </div>
                                <span
                                    v-if="editable"
                                    class="flex shrink-0 flex-wrap items-center justify-end gap-0.5"
                                >
                                    <button
                                        type="button"
                                        :class="opButton"
                                        :disabled="
                                            !canMoveSibling(rows, index, -1)
                                        "
                                        :aria-label="
                                            $t('«:item» nach oben', {
                                                item: item.label_effective,
                                            })
                                        "
                                        :title="$t('nach oben')"
                                        @click="
                                            persist(
                                                moveSibling(rows, index, -1),
                                            )
                                        "
                                    >
                                        ↑
                                    </button>
                                    <button
                                        type="button"
                                        :class="opButton"
                                        :disabled="
                                            !canMoveSibling(rows, index, 1)
                                        "
                                        :aria-label="
                                            $t('«:item» nach unten', {
                                                item: item.label_effective,
                                            })
                                        "
                                        :title="$t('nach unten')"
                                        @click="
                                            persist(moveSibling(rows, index, 1))
                                        "
                                    >
                                        ↓
                                    </button>
                                    <button
                                        type="button"
                                        :class="opButton"
                                        :disabled="!canOutdent(rows, index)"
                                        :aria-label="
                                            $t('«:item» eine Ebene höher', {
                                                item: item.label_effective,
                                            })
                                        "
                                        :title="$t('höher (Ebene zurück)')"
                                        @click="persist(outdent(rows, index))"
                                    >
                                        ←
                                    </button>
                                    <button
                                        type="button"
                                        :class="opButton"
                                        :disabled="
                                            !canIndent(rows, index, max_depth)
                                        "
                                        :aria-label="
                                            $t('«:item» eine Ebene tiefer', {
                                                item: item.label_effective,
                                            })
                                        "
                                        :title="$t('tiefer (Untereintrag)')"
                                        @click="
                                            persist(
                                                indent(rows, index, max_depth),
                                            )
                                        "
                                    >
                                        →
                                    </button>
                                    <Button
                                        variant="link"
                                        size="sm"
                                        @click="edit(item)"
                                        >{{ $t('Bearbeiten') }}</Button
                                    >
                                </span>
                            </li>
                            <li
                                v-if="drop && drop.at === rows.length"
                                class="relative h-2"
                                aria-hidden="true"
                            >
                                <span
                                    class="absolute top-0 right-2 h-0.5 bg-lava"
                                    :style="{
                                        left: `${12 + drop.depth * indentPx}px`,
                                    }"
                                />
                            </li>
                        </ul>
                        <p
                            class="border-t border-ash-light bg-paper px-3 py-2 text-[11.5px] text-basalt-light"
                        >
                            <template v-if="editable"
                                >{{
                                    $t(
                                        'Ziehen sortiert · weiter rechts ablegen macht einen Untereintrag · ↑ ↓ ← → mit der Tastatur',
                                    )
                                }}
                                ·</template
                            >
                            {{
                                max_depth > 0
                                    ? $t('Tiefe :depth', { depth: max_depth })
                                    : $t('Tiefe ohne Grenze')
                            }}
                            — nav {{ menu.handle
                            }}<template v-if="menu.line"
                                >, theme.lava:{{ menu.line }}</template
                            >
                        </p>
                    </div>
                    <div v-if="editable && urls.store" class="mt-2.5">
                        <label :class="labelClasses" for="nav-quick">{{
                            $t('Eintrag hinzufügen')
                        }}</label>
                        <div class="relative">
                            <input
                                id="nav-quick"
                                v-model="quick"
                                type="text"
                                role="combobox"
                                autocomplete="off"
                                :aria-expanded="quickOpen"
                                aria-controls="nav-quick-list"
                                :placeholder="
                                    $t('Seite suchen oder Adresse eingeben …')
                                "
                                class="w-full rounded-md border border-ash bg-white px-2.5 py-1.5 text-[13px] focus:border-lava-dark focus:outline-none"
                                @focus="quickOpen = true"
                                @input="quickOpen = true"
                                @keydown.enter.prevent="onQuickEnter"
                                @keydown.esc="quickOpen = false"
                                @blur="quickOpen = false"
                            />
                            <ul
                                v-if="
                                    quickOpen &&
                                    (quickPages.length > 0 || quickIsAddress)
                                "
                                id="nav-quick-list"
                                role="listbox"
                                class="absolute z-10 mt-1 max-h-64 w-full overflow-y-auto rounded-md border border-ash-light bg-white py-1 text-[12.5px] shadow-lg"
                            >
                                <li v-if="quickIsAddress" role="option">
                                    <button
                                        type="button"
                                        class="w-full px-2.5 py-1.5 text-left hover:bg-paper"
                                        @mousedown.prevent="addAddress"
                                    >
                                        {{
                                            pageAt(quick, pages)
                                                ? $t(
                                                      'Seite «:title» einfügen',
                                                      {
                                                          title:
                                                              pageAt(
                                                                  quick,
                                                                  pages,
                                                              )?.title ?? '',
                                                      },
                                                  )
                                                : $t(
                                                      'Freie Adresse «:url» einfügen …',
                                                      {
                                                          url: quick.trim(),
                                                      },
                                                  )
                                        }}
                                    </button>
                                </li>
                                <li
                                    v-for="p in quickPages"
                                    :key="p.id"
                                    role="option"
                                >
                                    <button
                                        type="button"
                                        class="flex w-full items-baseline gap-2 px-2.5 py-1.5 text-left hover:bg-paper"
                                        @mousedown.prevent="addPage(p)"
                                    >
                                        <span
                                            :style="{
                                                paddingLeft: `${p.depth * 12}px`,
                                            }"
                                            class="font-semibold"
                                            >{{ p.title }}</span
                                        >
                                        <span
                                            class="font-mono text-[11px] text-basalt-light"
                                            >{{ p.path }}</span
                                        >
                                        <span
                                            v-if="!p.live"
                                            class="ml-auto text-[11px] text-basalt-light"
                                            >{{ $t('noch nicht live') }}</span
                                        >
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <p class="mt-1 text-[11.5px] text-basalt-light">
                            {{
                                $t(
                                    'Eine Seite folgt ihrem Titel und ihrer Adresse; eine freie Adresse (/blog, https://…) bleibt, wie sie ist. Enter fügt den ersten Treffer ein.',
                                )
                            }}
                        </p>
                        <div
                            v-if="missing.length > 0"
                            class="mt-2 flex flex-wrap items-center gap-1.5 text-[12px]"
                        >
                            <span class="text-basalt-light">{{
                                $t('Fehlt noch:')
                            }}</span>
                            <button
                                v-for="p in missing"
                                :key="p.id"
                                type="button"
                                class="rounded-full border border-lava-dark/30 bg-lava/5 px-2 py-0.5 text-lava-dark hover:border-lava"
                                :title="p.path ?? ''"
                                @click="addPage(p)"
                            >
                                + {{ p.title }}
                            </button>
                        </div>
                    </div>
                </div>

                <div class="min-w-0 space-y-3">
                    <section
                        v-if="isDesktop || editing === null"
                        class="rounded-lg border border-ash-light bg-paper px-3.5 py-3 text-[12.5px]"
                    >
                        <p
                            class="mb-1.5 text-[10px] tracking-[.1em] text-basalt-light uppercase"
                        >
                            {{ $t('Vorschau · so rendert das Theme') }}
                        </p>
                        <PreviewList
                            v-if="preview.length > 0"
                            :entries="preview"
                        />
                        <p v-else class="text-ash-dark">
                            {{
                                $t(
                                    'Leer — sichtbar sind nur Einträge auf veröffentlichte Seiten.',
                                )
                            }}
                        </p>
                    </section>

                    <!-- Eintrag (mobil als eigene Ansicht) -->
                    <form
                        v-if="editing !== null"
                        class="rounded-lg border border-ash-light bg-white px-3.5 py-3"
                        novalidate
                        @submit.prevent="
                            editing === 'new' ? save() : autosave.flush()
                        "
                    >
                        <button
                            v-if="!isDesktop"
                            type="button"
                            class="mb-2 text-[12px] font-semibold text-lava-dark"
                            @click="close"
                        >
                            ‹ {{ menu.label }}
                        </button>
                        <h2 class="text-sm font-semibold break-words">
                            {{ editorTitle }}
                        </h2>
                        <p
                            v-if="editing !== 'new'"
                            class="mt-0.5 text-[11.5px] text-basalt-light"
                        >
                            {{ $t('Änderungen speichern sich selbst.') }}
                        </p>

                        <template v-if="editing !== 'new'">
                            <span :class="[labelClasses, 'mt-2.5']"
                                >{{ $t('Art')
                                }}<span
                                    v-if="autosave.saved('kind')"
                                    class="text-success ml-1.5 font-semibold tracking-normal normal-case"
                                    >{{ $t('✓ gespeichert') }}</span
                                ></span
                            >
                            <div class="flex flex-wrap gap-x-4 text-[12.5px]">
                                <label class="flex items-center gap-1.5">
                                    <input
                                        v-model="form.kind"
                                        type="radio"
                                        value="entry"
                                        class="accent-lava-dark"
                                    />
                                    {{ $t('Seite') }}
                                </label>
                                <label class="flex items-center gap-1.5">
                                    <input
                                        v-model="form.kind"
                                        type="radio"
                                        value="url"
                                        class="accent-lava-dark"
                                    />
                                    {{ $t('Frei (URL)') }}
                                </label>
                            </div>
                        </template>

                        <Field
                            v-if="form.kind === 'entry'"
                            :model-value="
                                form.entry_id === null
                                    ? ''
                                    : String(form.entry_id)
                            "
                            :saved="autosave.saved('entry_id')"
                            :label="$t('Ziel')"
                            type="select"
                            :options="pageOptions"
                            :error="errors.entry_id"
                            :help="
                                $t(
                                    'Die Beschriftung folgt dem Seitentitel, bis Sie sie überschreiben.',
                                )
                            "
                            class="mt-2.5!"
                            @update:model-value="
                                form.entry_id = $event ? Number($event) : null
                            "
                        />
                        <Field
                            v-else
                            v-model="form.url"
                            :saved="autosave.saved('url')"
                            :label="$t('Ziel (URL)')"
                            mono
                            :placeholder="$t('/blog oder https://…')"
                            :error="errors.url"
                            class="mt-2.5!"
                        />
                        <p
                            v-if="urlPage"
                            class="mt-1 text-[11.5px] text-basalt-light"
                        >
                            {{
                                $t('Das ist die Seite «:title».', {
                                    title: urlPage.title,
                                })
                            }}
                            <button
                                type="button"
                                class="font-semibold text-lava-dark underline"
                                @click="linkPage"
                            >
                                {{ $t('Als Seite verknüpfen') }}
                            </button>
                            —
                            {{ $t('sie folgt dann Umbenennungen.') }}
                        </p>

                        <Field
                            v-model="form.label[defaultLocale]"
                            :saved="autosave.saved(`label.${defaultLocale}`)"
                            :label="$t('Beschriftung')"
                            :placeholder="labelPlaceholder(defaultLocale)"
                            :error="errors[`label.${defaultLocale}`]"
                            :help="
                                form.kind === 'entry'
                                    ? $t(
                                          'Leer lassen: Der Eintrag heisst wie die Seite.',
                                      )
                                    : $t('So steht der Eintrag im Menü.')
                            "
                            :maxlength="120"
                        />
                        <template v-if="otherLocales.length > 0">
                            <button
                                type="button"
                                class="mt-2 text-[12px] font-semibold text-lava-dark underline hover:text-basalt"
                                :aria-expanded="
                                    otherLabelsOpen ||
                                    otherLocales.some(
                                        (l) => errors[`label.${l.locale}`],
                                    )
                                "
                                @click="otherLabelsOpen = !otherLabelsOpen"
                            >
                                {{
                                    $t(
                                        'Beschriftung in weiteren Sprachen (:codes)',
                                        {
                                            codes: otherLocales
                                                .map((l) =>
                                                    l.locale.toUpperCase(),
                                                )
                                                .join(' · '),
                                        },
                                    )
                                }}
                            </button>
                            <div
                                v-show="
                                    otherLabelsOpen ||
                                    otherLocales.some(
                                        (l) => errors[`label.${l.locale}`],
                                    )
                                "
                            >
                                <Field
                                    v-for="l in otherLocales"
                                    :key="l.locale"
                                    v-model="form.label[l.locale]"
                                    :saved="autosave.saved(`label.${l.locale}`)"
                                    :label="
                                        $t('Beschriftung :code', {
                                            code: l.locale.toUpperCase(),
                                        })
                                    "
                                    :placeholder="labelPlaceholder(l.locale)"
                                    :error="errors[`label.${l.locale}`]"
                                    :maxlength="120"
                                />
                            </div>
                        </template>

                        <div class="mt-3 flex flex-col gap-2 text-[12.5px]">
                            <label class="flex items-center gap-2">
                                <Toggle
                                    v-model="form.visible"
                                    :label="$t('Sichtbar')"
                                />
                                {{ $t('Sichtbar') }}
                                <span class="text-[11.5px] text-basalt-light">{{
                                    form.visible
                                        ? $t('— steht im Menü der Website')
                                        : $t(
                                              '— bleibt hier, erscheint aber nicht auf der Website',
                                          )
                                }}</span>
                                <span
                                    v-if="autosave.saved('visible')"
                                    class="text-success font-semibold"
                                    >{{ $t('✓ gespeichert') }}</span
                                >
                            </label>
                            <label class="flex items-center gap-2">
                                <Toggle
                                    v-model="form.new_window"
                                    :label="$t('In neuem Fenster')"
                                />
                                {{ $t('In neuem Fenster') }}
                                <span class="text-[11.5px] text-basalt-light">{{
                                    $t('— für fremde Websites üblich')
                                }}</span>
                                <span
                                    v-if="autosave.saved('new_window')"
                                    class="text-success font-semibold"
                                    >{{ $t('✓ gespeichert') }}</span
                                >
                            </label>
                        </div>
                        <p
                            v-if="errors.parent_id"
                            class="text-danger mt-2 text-[11.5px] font-semibold"
                            role="alert"
                        >
                            {{ errors.parent_id }}
                        </p>

                        <div
                            class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-ash-light pt-3"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <template v-if="current?.urls">
                                    <Button
                                        v-if="!confirmingDelete"
                                        variant="danger"
                                        size="sm"
                                        @click="confirmingDelete = true"
                                        >{{ $t('Löschen') }}</Button
                                    >
                                    <span
                                        v-else
                                        class="inline-flex flex-wrap items-center gap-2 text-[12.5px]"
                                        role="alertdialog"
                                    >
                                        {{ $t('Wirklich löschen?') }}
                                        <Button
                                            variant="danger"
                                            size="sm"
                                            @click="destroy"
                                            >{{ $t('Ja, löschen') }}</Button
                                        >
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            @click="confirmingDelete = false"
                                            >{{ $t('Nein') }}</Button
                                        >
                                    </span>
                                </template>
                                <Button
                                    variant="link"
                                    size="sm"
                                    @click="close"
                                    >{{
                                        editing === 'new'
                                            ? $t('Abbrechen')
                                            : $t('Schliessen')
                                    }}</Button
                                >
                            </div>
                            <Button
                                v-if="editing === 'new'"
                                type="submit"
                                :disabled="form.processing"
                                >{{ $t('Speichern') }}</Button
                            >
                        </div>
                    </form>
                </div>
            </div>
        </template>

        <!-- ── Menü aus Seitenbaum, Typ-Liste oder Theme: nur lesend ── -->
        <div v-else class="grid gap-4 lg:grid-cols-2">
            <div class="min-w-0">
                <div class="grid gap-x-3 gap-y-2.5 *:mt-0! sm:grid-cols-2">
                    <Field
                        :label="$t('Quelle')"
                        :model-value="menu.source_label"
                        disabled
                    />
                    <Field
                        :label="$t('Tiefe')"
                        :model-value="
                            menu.depth > 0
                                ? $tc(':count Ebene|:count Ebenen', menu.depth)
                                : $t('ohne Grenze')
                        "
                        disabled
                    />
                    <Field
                        :label="$t('Ausschluss')"
                        :model-value="menu.exclude.join(', ') || '—'"
                        mono
                        disabled
                    />
                    <Field
                        :label="$t('Startebene')"
                        :model-value="String(menu.start)"
                        disabled
                    />
                    <Field
                        v-if="menu.only.length > 0"
                        :label="$t('Nur')"
                        :model-value="menu.only.join(', ')"
                        mono
                        disabled
                    />
                </div>

                <div class="mt-3 rounded-lg border border-ash-light bg-white">
                    <p
                        v-if="items.length === 0"
                        class="px-3.5 py-4 text-[12.5px] text-basalt-light"
                    >
                        {{
                            $t(
                                'Leer — das Menü zeigt nur veröffentlichte, sichtbare Seiten.',
                            )
                        }}
                    </p>
                    <ul v-else class="divide-y divide-ash-light">
                        <li
                            v-for="(item, index) in items"
                            :key="index"
                            class="py-2 pr-2.5"
                            :style="{
                                paddingLeft: `${12 + item.depth * indentPx}px`,
                            }"
                        >
                            <p
                                class="text-[13.5px] font-semibold break-words"
                                :class="item.live ? '' : 'text-ash-dark'"
                            >
                                {{ item.label_effective }}
                            </p>
                            <p
                                class="font-mono text-[11px] break-all text-basalt-light"
                            >
                                {{ item.target.path ?? item.target.url ?? '—' }}
                            </p>
                            <p
                                v-if="item.hint"
                                class="text-warning text-[11.5px]"
                            >
                                {{ item.hint }}
                            </p>
                        </li>
                    </ul>
                    <p
                        class="border-t border-ash-light bg-paper px-3 py-2 text-[11.5px] text-basalt-light"
                    >
                        <template v-if="menu.source === 'pages'">
                            {{
                                $t(
                                    'Keine Griffe: die Reihenfolge gehört dem Seitenbaum',
                                )
                            }}
                            ·
                            <Link
                                :href="pagesHref"
                                class="text-lava-dark underline hover:text-basalt"
                                >{{
                                    $t('Reihenfolge in «Seiten» ändern')
                                }}</Link
                            >
                        </template>
                        <template v-else-if="menu.source === 'type'">
                            {{
                                $t(
                                    'Keine Griffe: die Reihenfolge folgt der Typ-Liste (Datum, Titel).',
                                )
                            }}
                        </template>
                        <template v-else>
                            {{
                                $t(
                                    'Keine Griffe: die Einträge stehen in theme.lava.',
                                )
                            }}
                        </template>
                    </p>
                </div>
            </div>

            <div class="min-w-0 space-y-3">
                <CodeBlock :code="menu.code" :start="menu.line" />
                <p class="text-[11.5px] text-basalt-light">
                    {{
                        $t('Code derselben Quelle (:ref) — bearbeiten in', {
                            ref: codeRef,
                        })
                    }}
                    <span class="font-mono">theme.lava</span>,
                    {{ $t('Station D.') }}
                </p>
                <section
                    class="rounded-lg border border-ash-light bg-paper px-3.5 py-3 text-[12.5px]"
                >
                    <p
                        class="mb-1.5 text-[10px] tracking-[.1em] text-basalt-light uppercase"
                    >
                        {{ $t('Vorschau · so rendert das Theme') }}
                    </p>
                    <PreviewList v-if="preview.length > 0" :entries="preview" />
                    <p v-else class="text-ash-dark">{{ $t('Leer.') }}</p>
                </section>
            </div>
        </div>
    </template>
</template>
