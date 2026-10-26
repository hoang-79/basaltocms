<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '../../components/Button.vue';
import { controlClasses } from '../../components/control';
import EmptyState from '../../components/EmptyState.vue';
import Hex from '../../components/Hex.vue';
import PageHeader from '../../components/PageHeader.vue';
import Table from '../../components/Table.vue';
import TranslationMark from '../../components/TranslationMark.vue';
import { t, tc } from '../../i18n';
import { pageTypeIcon } from '../../support';
import type {
    BackendProps,
    PageLocaleOption,
    TranslationFilters,
    TranslationRow,
} from '../../types';

/**
 * Die Station «Übersetzung» im Modus Inhalte (M-06): alle Seiten und Inhalte mit ihrem Stand je Sprache —
 * veraltet und fehlend zuerst, Pflichtsprachen vor den übrigen. Jede Marke führt zu «Übersetzen nebeneinander».
 */
const props = defineProps<{
    rows: TranslationRow[];
    locales: PageLocaleOption[];
    filters: TranslationFilters;
    counts: { outdated: number; missing: number; total: number };
    urls: { index: string };
}>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);

const emptyFilters: TranslationFilters = { locale: '', state: '' };
const locale = ref((props.filters ?? emptyFilters).locale);
const state = ref<TranslationFilters['state']>(
    (props.filters ?? emptyFilters).state,
);
watch(
    () => props.filters,
    (f) => {
        locale.value = f?.locale ?? '';
        state.value = f?.state ?? '';
    },
);

const filtered = computed(() =>
    Boolean(props.filters?.locale || props.filters?.state),
);

const localeOptions = computed(() => [
    { value: '', label: t('Sprache: alle') },
    ...props.locales
        .filter((l) => !l.is_default)
        .map((l) => ({
            value: l.locale,
            label: l.is_required
                ? t(':locale · Pflicht', { locale: l.locale.toUpperCase() })
                : l.locale.toUpperCase(),
        })),
]);
const stateOptions = [
    { value: '', label: t('Stand: alle') },
    { value: 'outdated', label: t('veraltet') },
    { value: 'missing', label: t('fehlt') },
];

function apply(): void {
    const params: Record<string, string> = {};
    if (locale.value) {
        params.locale = locale.value;
    }
    if (state.value) {
        params.state = state.value;
    }
    router.get(props.urls.index, params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function reset(): void {
    locale.value = '';
    state.value = '';
    apply();
}

const lead = computed(() => {
    const { outdated, missing } = props.counts;
    if (outdated === 0 && missing === 0) {
        return t('Alle Fassungen sind aktuell.');
    }

    return tc(
        ':outdated veraltet · :count fehlt (Pflichtsprachen zuerst)|:outdated veraltet · :count fehlen (Pflichtsprachen zuerst)',
        missing,
        { outdated },
    );
});

const small = controlClasses({ small: true });
</script>

<template>
    <Head :title="$t('Übersetzung')" />
    <PageHeader
        :title="$t('Übersetzung')"
        :crumb="$t(':site › Inhalte', { site: siteName })"
        :lead="lead"
    />

    <form
        class="mb-3 flex flex-wrap items-center gap-2 border-b border-ash-light pb-2.5 text-[12.5px]"
        :aria-label="$t('Filter')"
        @submit.prevent="apply"
    >
        <label class="sr-only" for="translations-locale">{{
            $t('Sprache')
        }}</label>
        <select
            id="translations-locale"
            v-model="locale"
            :class="[small, 'w-auto!']"
            @change="apply"
        >
            <option v-for="o in localeOptions" :key="o.value" :value="o.value">
                {{ o.label }}
            </option>
        </select>
        <label class="sr-only" for="translations-state">{{
            $t('Stand')
        }}</label>
        <select
            id="translations-state"
            v-model="state"
            :class="[small, 'w-auto!']"
            @change="apply"
        >
            <option v-for="o in stateOptions" :key="o.value" :value="o.value">
                {{ o.label }}
            </option>
        </select>
        <Button v-if="filtered" variant="link" size="sm" @click="reset">{{
            $t('Filter zurücksetzen')
        }}</Button>
        <span class="ml-auto text-[11.5px] text-basalt-light">{{
            $tc(':count Inhalt|:count Inhalte', counts.total)
        }}</span>
    </form>

    <div
        v-if="rows.length === 0"
        class="rounded-lg border border-ash-light bg-white"
    >
        <EmptyState
            v-if="!filtered"
            :title="$t('Alles übersetzt')"
            :text="
                $t(
                    'Jede Sprachfassung beruht auf der aktuellen Fassung der Standardsprache.',
                )
            "
        />
        <EmptyState
            v-else
            :title="$t('Keine Treffer')"
            :text="$t('Mit diesem Filter ist nichts offen.')"
            icon="translation"
            tone="ash"
        >
            <Button variant="link" size="sm" @click="reset">{{
                $t('Filter zurücksetzen')
            }}</Button>
        </EmptyState>
    </div>

    <Table v-else>
        <template #head>
            <tr>
                <th class="px-3 py-2">{{ $t('Titel') }}</th>
                <th v-for="l in locales" :key="l.locale" class="px-3 py-2">
                    {{ l.locale.toUpperCase() }}
                    <span
                        v-if="l.is_default"
                        class="font-normal tracking-normal normal-case"
                        >· {{ $t('Standard') }}</span
                    >
                    <span
                        v-else-if="l.is_required"
                        class="font-normal tracking-normal normal-case"
                        >· {{ $t('Pflicht') }}</span
                    >
                </th>
            </tr>
        </template>
        <tr v-for="row in rows" :key="row.entry_id" class="align-top">
            <td class="px-3 py-2.5">
                <div class="flex items-start gap-2">
                    <Hex
                        :icon="
                            row.kind === 'page'
                                ? pageTypeIcon('page')
                                : 'entries'
                        "
                        :tone="row.kind === 'page' ? 'basalt' : 'ash'"
                        :title="
                            row.kind === 'page' ? $t('Seite') : $t('Inhalt')
                        "
                    />
                    <div class="min-w-0">
                        <span
                            class="block text-[13.5px] font-semibold break-words"
                            >{{ row.title || $t('Ohne Titel') }}</span
                        >
                        <span class="text-[11.5px] text-basalt-light">{{
                            row.type_label
                        }}</span>
                    </div>
                </div>
            </td>
            <td
                v-for="l in locales"
                :key="l.locale"
                :data-label="l.locale.toUpperCase()"
                class="px-3 py-2.5"
            >
                <TranslationMark
                    v-if="l.is_default || !row.urls[l.locale]"
                    :state="
                        row.states[l.locale] ??
                        (l.is_default ? 'source' : 'missing')
                    "
                    :locale="l.locale"
                />
                <Link
                    v-else
                    :href="row.urls[l.locale] ?? ''"
                    class="inline-flex items-center gap-1 hover:underline"
                    :title="
                        $t(':locale übersetzen', {
                            locale: l.locale.toUpperCase(),
                        })
                    "
                >
                    <TranslationMark
                        :state="row.states[l.locale] ?? 'missing'"
                        :locale="l.locale"
                    />
                    <span class="text-[11px] text-lava-dark" aria-hidden="true"
                        >→</span
                    >
                </Link>
            </td>
        </tr>
        <template #foot>
            {{ $tc(':count Zeile|:count Zeilen', rows.length) }} ·
            {{
                $t(
                    'veraltet heisst: ein übersetzbares Feld der Standardsprache hat sich seit der Übersetzung geändert.',
                )
            }}
        </template>
    </Table>
</template>
