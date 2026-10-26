<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '../components/Button.vue';
import { controlClasses } from '../components/control';
import EmptyState from '../components/EmptyState.vue';
import Hex from '../components/Hex.vue';
import Notice from '../components/Notice.vue';
import PageHeader from '../components/PageHeader.vue';
import StatusHex from '../components/StatusHex.vue';
import Table from '../components/Table.vue';
import TranslationMark from '../components/TranslationMark.vue';
import { t } from '../i18n';
import { pathOf, versionLabels, versionStatus } from '../support';
import type {
    BackendProps,
    EntryFilters,
    EntryPagination,
    EntryRow,
    EntryTypeTab,
} from '../types';

/**
 * Typ-Listen (Bereich 10): je Inhaltstyp ein Reiter mit Zahl, darunter die Inhalte mit Statuspunkten je Sprache,
 * Filter nach Status, Sprache und Titel. Seiten leben im Seitenbaum; «Neuer Inhalt» fragt nach dem Typ.
 * Ein Typ, den das Theme nicht mehr definiert, bleibt als Reiter mit Bernstein-Marke — nichts verschwindet still.
 */
const props = defineProps<{
    types: EntryTypeTab[];
    active: string | null;
    entries: EntryRow[];
    filters: EntryFilters;
    pagination: EntryPagination;
    locales: string[];
    site: { handle: string; name: string };
    create_url: string;
    missing_type: boolean;
    can_create: boolean;
    types_url: string;
}>();

const page = usePage<BackendProps>();
const basePath = computed(() => pathOf(page.url));

const activeTab = computed(
    () => props.types.find((t) => t.name === props.active) ?? null,
);
const total = computed(() => activeTab.value?.count ?? 0);
// TB 29: Einzelinhalte (global) gibt es genau einmal — kein «Neuer Inhalt»
const creatable = computed(() =>
    props.types.filter((t) => t.in_theme && !t.global),
);

/* ── Filter ── */

const statusOptions = [
    { value: '', label: t('Status: alle') },
    ...Object.entries(versionLabels).map(([value, label]) => ({
        value,
        label,
    })),
];
const localeOptions = computed(() => [
    { value: '', label: t('Sprache: alle') },
    ...props.locales.map((l) => ({ value: l, label: l.toUpperCase() })),
]);

const status = ref(props.filters.status);
const locale = ref(props.filters.locale);
const q = ref(props.filters.q);
watch(
    () => props.filters,
    (f) => {
        status.value = f.status;
        locale.value = f.locale;
        q.value = f.q;
    },
);

const filtered = computed(() =>
    Boolean(props.filters.status || props.filters.locale || props.filters.q),
);

function apply(): void {
    const params: Record<string, string> = {};
    if (props.active) {
        params.type = props.active;
    }
    if (status.value) {
        params.status = status.value;
    }
    if (locale.value) {
        params.locale = locale.value;
    }
    if (q.value.trim()) {
        params.q = q.value.trim();
    }
    router.get(basePath.value, params, { preserveState: true, replace: true });
}

function resetFilters(): void {
    status.value = '';
    locale.value = '';
    q.value = '';
    apply();
}

function tabHref(name: string): string {
    return `${basePath.value}?type=${encodeURIComponent(name)}`;
}

/* ── Neuer Inhalt: Typ wählen ── */

const choosing = ref(false);

function create(type: string): void {
    choosing.value = false;
    router.post(props.create_url, { type });
}

/** Der Knopf im Kopf: bei einem Typ direkt anlegen, sonst das Menü öffnen. */
function startCreate(): void {
    if (creatable.value.length === 1 && creatable.value[0]) {
        create(creatable.value[0].name);

        return;
    }
    choosing.value = !choosing.value;
}

function statusTitle(l: string, s: string | null): string {
    const label = s ? (versionLabels[s] ?? s) : t('fehlt');

    return `${l.toUpperCase()} · ${label}`;
}

const small = controlClasses({ small: true });
const opClass = 'text-[11px] text-lava-dark underline hover:text-basalt';
const countClass =
    'rounded border border-ash-light bg-paper px-[5px] font-mono text-[10.5px] font-normal text-basalt-light';
</script>

<template>
    <Head :title="$t('Inhalte')" />
    <PageHeader
        :title="$t('Inhalte')"
        :crumb="$t(':site › Inhalte', { site: site.name })"
    >
        <template #lead>
            <template
                v-for="(part, i) in $t(
                    'Typen ohne Seitenbaum (:index-Listen): Liste und Formular entstehen aus den Feld-Definitionen (K-13).',
                ).split(':index')"
                :key="i"
                ><span v-if="i > 0" class="font-mono">index</span
                >{{ part }}</template
            >
        </template>
        <template v-if="can_create && creatable.length > 0" #actions>
            <Button :aria-expanded="choosing" @click="startCreate">{{
                $t('Neuer Inhalt')
            }}</Button>
        </template>
    </PageHeader>

    <!-- Typ wählen -->
    <section
        v-if="choosing"
        class="mb-4 rounded-lg border border-ash bg-white px-3.5 py-3"
        :aria-label="$t('Neuer Inhalt — Typ wählen')"
    >
        <div class="text-sm font-semibold">
            {{ $t('Neuer Inhalt — Typ wählen') }}
        </div>
        <p class="mt-0.5 text-xs text-basalt-light">
            <template
                v-for="(part, i) in $t(
                    'Die Typen kommen aus dem Theme (:code, Station E) oder von dieser Site.',
                ).split(':code')"
                :key="i"
                ><span v-if="i > 0" class="font-mono">type …</span
                >{{ part }}</template
            >
        </p>
        <ul
            class="mt-2 divide-y divide-ash-light rounded-md border border-ash-light"
        >
            <li v-for="t in creatable" :key="t.name">
                <button
                    type="button"
                    class="flex w-full items-center gap-2.5 px-3 py-2 text-left hover:bg-paper"
                    @click="create(t.name)"
                >
                    <Hex :label="t.label" tone="ash" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-[13px] font-semibold">{{
                            t.label
                        }}</span>
                        <span
                            class="block font-mono text-[11px] text-basalt-light"
                            >{{ t.name }} ·
                            {{
                                $tc(':count Inhalt|:count Inhalte', t.count)
                            }}</span
                        >
                    </span>
                    <span class="text-ash-dark" aria-hidden="true">›</span>
                </button>
            </li>
        </ul>
        <p class="mt-2 text-[11.5px] text-basalt-light">
            {{
                $t(
                    'Seiten entstehen im Seitenbaum (Station «Seiten») — dort wählt man Typ und Ort im Baum.',
                )
            }}
        </p>
        <div class="mt-2 flex justify-end">
            <Button variant="secondary" size="sm" @click="choosing = false">{{
                $t('Abbrechen')
            }}</Button>
        </div>
    </section>

    <div
        v-if="types.length === 0"
        class="rounded-lg border border-ash-light bg-white"
    >
        <EmptyState
            :title="$t('Keine Inhaltstypen')"
            :text="
                $t(
                    'Das Theme definiert keinen Typ ohne Seitenbaum — Station E legt einen an.',
                )
            "
            icon="type"
            tone="ash"
        >
            <Button variant="secondary" :href="types_url">{{
                $t('Station E öffnen')
            }}</Button>
        </EmptyState>
    </div>

    <template v-else>
        <!-- Reiter je Typ -->
        <nav
            class="flex flex-wrap border-b border-ash-light"
            :aria-label="$t('Typen')"
        >
            <Link
                v-for="t in types"
                :key="t.name"
                :href="tabHref(t.name)"
                :title="t.name"
                class="-mb-px flex items-center gap-1.5 border-b-2 px-[13px] py-2 text-[12.5px] whitespace-nowrap"
                :class="
                    t.name === active
                        ? 'border-lava font-semibold text-basalt'
                        : 'border-transparent text-basalt-light hover:text-basalt'
                "
                :aria-current="t.name === active ? 'page' : undefined"
            >
                {{ t.label }}
                <span :class="countClass">{{ t.count }}</span>
                <Hex
                    v-if="!t.in_theme"
                    icon="error"
                    tone="warning"
                    size="xs"
                    :title="$t('im Theme nicht mehr definiert')"
                />
            </Link>
        </nav>

        <Notice v-if="missing_type && active" tone="warning" class="mt-3">
            <template
                v-for="(part, i) in $t(
                    'Typ :type ist im Theme nicht mehr definiert — die Inhalte bleiben erhalten, bearbeitbar sind sie erst, wenn der Typ zurück ist.',
                ).split(':type')"
                :key="i"
                ><span v-if="i > 0" class="font-mono">{{ active }}</span
                >{{ part }}</template
            >
            <Link :href="types_url" class="font-semibold underline">{{
                $t('Station E öffnen')
            }}</Link>
        </Notice>

        <!-- Filterzeile -->
        <form
            class="mt-3 flex flex-wrap items-center gap-2 text-[12.5px]"
            @submit.prevent="apply"
        >
            <label class="sr-only" for="entries-status">{{
                $t('Status')
            }}</label>
            <select
                id="entries-status"
                v-model="status"
                :class="[small, 'w-auto!']"
                @change="apply"
            >
                <option
                    v-for="o in statusOptions"
                    :key="o.value"
                    :value="o.value"
                >
                    {{ o.label }}
                </option>
            </select>
            <label class="sr-only" for="entries-locale">{{
                $t('Sprache')
            }}</label>
            <select
                id="entries-locale"
                v-model="locale"
                :class="[small, 'w-auto!']"
                @change="apply"
            >
                <option
                    v-for="o in localeOptions"
                    :key="o.value"
                    :value="o.value"
                >
                    {{ o.label }}
                </option>
            </select>
            <label class="sr-only" for="entries-q">{{ $t('Suche') }}</label>
            <input
                id="entries-q"
                v-model="q"
                type="search"
                :placeholder="$t('⌕ Titel suchen … (Enter)')"
                :class="[small, 'w-56! max-w-full']"
            />
            <Button
                v-if="filtered"
                variant="link"
                size="sm"
                @click="resetFilters"
                >{{ $t('Filter zurücksetzen') }}</Button
            >
        </form>

        <!-- Liste -->
        <div
            v-if="entries.length === 0"
            class="mt-3 rounded-lg border border-ash-light bg-white"
        >
            <EmptyState
                v-if="filtered"
                :title="$t('Keine Inhalte mit diesem Filter')"
                :text="$t(':count insgesamt.', { count: total })"
                icon="entries"
                tone="ash"
            >
                <Button variant="secondary" size="sm" @click="resetFilters">{{
                    $t('Filter zurücksetzen')
                }}</Button>
            </EmptyState>
            <EmptyState
                v-else
                :title="$t('Noch kein Inhalt dieses Typs')"
                :text="
                    missing_type
                        ? $t('Der Typ ist nicht mehr definiert.')
                        : $t(
                              'Der erste beginnt als Entwurf in der Standardsprache.',
                          )
                "
                icon="entries"
                tone="ash"
            >
                <Button
                    v-if="
                        can_create &&
                        active &&
                        activeTab?.in_theme &&
                        !activeTab?.global
                    "
                    @click="create(active)"
                    >{{ $t('Neuer Inhalt') }}</Button
                >
            </EmptyState>
        </div>
        <Table v-else class="mt-3">
            <template #head>
                <tr>
                    <th class="px-3 py-2">{{ $t('Titel · Datum · Typ') }}</th>
                    <th class="px-3 py-2">{{ $t('Status je Sprache') }}</th>
                    <th class="px-3 py-2">{{ $t('Operationen') }}</th>
                </tr>
            </template>
            <tr
                v-for="e in entries"
                :key="e.id"
                class="align-top"
                :class="missing_type ? 'bg-warning/5' : ''"
            >
                <td class="px-3 py-2.5">
                    <div class="flex items-start gap-2">
                        <Hex
                            :label="activeTab?.label ?? e.type"
                            :tone="missing_type ? 'warning' : 'ash'"
                            :title="activeTab?.label ?? e.type"
                        />
                        <div class="min-w-0">
                            <Link
                                v-if="!missing_type"
                                :href="e.edit_url"
                                class="text-[13.5px] font-semibold break-words hover:underline"
                                >{{ e.title }}</Link
                            >
                            <span
                                v-else
                                class="text-[13.5px] font-semibold break-words"
                                >{{ e.title }}</span
                            >
                            <p
                                class="flex flex-wrap gap-x-1.5 text-[11.5px] text-basalt-light"
                            >
                                <span v-if="e.date" class="font-mono">{{
                                    e.date
                                }}</span>
                                <span class="text-ash-dark font-mono"
                                    >· {{ e.type }}</span
                                >
                            </p>
                        </div>
                    </div>
                </td>
                <td :data-label="$t('Status je Sprache')" class="px-3 py-2.5">
                    <span class="inline-flex flex-wrap items-center gap-1.5">
                        <span
                            v-for="l in locales"
                            :key="l"
                            class="inline-flex items-center gap-1"
                        >
                            <StatusHex
                                :status="versionStatus(e.status[l])"
                                :label="l.toUpperCase()"
                                :title="statusTitle(l, e.status[l] ?? null)"
                            />
                            <TranslationMark
                                v-if="e.translation?.[l] === 'outdated'"
                                :state="e.translation[l]"
                                :locale="l"
                                quiet
                            />
                        </span>
                    </span>
                </td>
                <td
                    :data-label="$t('Operationen')"
                    class="px-3 py-2.5 whitespace-normal"
                >
                    <span
                        class="inline-flex flex-wrap items-center gap-x-2.5 gap-y-1"
                    >
                        <Link
                            v-if="!missing_type"
                            :href="e.edit_url"
                            :class="opClass"
                            >{{ $t('bearbeiten') }}</Link
                        >
                        <a
                            v-if="e.preview_url"
                            :href="e.preview_url"
                            target="_blank"
                            rel="noopener"
                            :class="opClass"
                            >{{ $t('Vorschau') }}</a
                        >
                        <span
                            v-if="missing_type && !e.preview_url"
                            class="text-ash-dark text-xs"
                            >—</span
                        >
                    </span>
                </td>
            </tr>
            <template #foot>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span>{{
                        $t(
                            ':count auf dieser Seite · :total insgesamt · :sort · :per je Seite',
                            {
                                count: entries.length,
                                total,
                                sort: pagination.sort,
                                per: pagination.per_page,
                            },
                        )
                    }}</span>
                    <span
                        v-if="pagination.prev || pagination.next"
                        class="inline-flex items-center gap-2.5"
                    >
                        <Link
                            v-if="pagination.prev"
                            :href="pagination.prev"
                            :class="opClass"
                            >← {{ $t('zurück') }}</Link
                        >
                        <span class="font-mono">{{
                            $t('Seite :page', { page: pagination.page })
                        }}</span>
                        <Link
                            v-if="pagination.next"
                            :href="pagination.next"
                            :class="opClass"
                            >{{ $t('weiter') }} →</Link
                        >
                    </span>
                </div>
                <p class="mt-1.5 inline-flex flex-wrap items-center gap-3">
                    <StatusHex status="live" :label="$t('Veröffentlicht')" />
                    <StatusHex
                        status="scheduled"
                        :label="$t('Freigegeben / Geplant')"
                    />
                    <StatusHex status="review" :label="$t('In Prüfung')" />
                    <StatusHex status="draft" :label="$t('Entwurf')" />
                    <StatusHex status="missing" :label="$t('fehlt')" />
                    <span class="inline-flex items-center gap-1"
                        ><TranslationMark state="outdated" />
                        {{
                            $t(
                                'Übersetzung beruht auf einer älteren Fassung der Standardsprache',
                            )
                        }}</span
                    >
                </p>
            </template>
        </Table>
    </template>
</template>
