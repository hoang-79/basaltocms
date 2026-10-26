<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '../../components/Button.vue';
import EmptyState from '../../components/EmptyState.vue';
import PageHeader from '../../components/PageHeader.vue';
import StatusHex from '../../components/StatusHex.vue';
import Table from '../../components/Table.vue';
import { t, tc } from '../../i18n';
import { versionStatus } from '../../support';
import type { BackendProps } from '../../types';
import type {
    ReviewCounts,
    ReviewFilter,
    ReviewFilters,
    ReviewRow,
} from '../../types-workflow';

/**
 * Die Station «Freigabe» (Modus Inhalte): alle Sprachfassungen in Prüfung, freigegeben oder geplant — Titel und Art,
 * Sprache, Fassung, Status, wer wann eingereicht hat bzw. wann geplant ist, und der Weg zur Freigabe. Eigene Fassungen
 * tragen die Marke «eigene» (Vier-Augen-Prinzip). Filter als Knopfgruppe mit Zahl.
 */
const props = defineProps<{
    rows: ReviewRow[];
    counts: ReviewCounts;
    filters: ReviewFilters;
    urls: { index: string };
}>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);

const counts = computed<ReviewCounts>(
    () => props.counts ?? { in_review: 0, approved: 0, scheduled: 0 },
);
const active = computed<ReviewFilter>(() => props.filters?.status ?? '');
const rows = computed(() => props.rows ?? []);

const lead = computed(() => {
    const c = counts.value;

    return [
        tc(':count wartet auf Prüfung|:count warten auf Prüfung', c.in_review),
        t(':count freigegeben', { count: c.approved }),
        t(':count geplant', { count: c.scheduled }),
    ].join(' · ');
});

const filterOptions = computed<
    { value: ReviewFilter; label: string; count: number | null }[]
>(() => [
    { value: '', label: t('Alle'), count: null },
    {
        value: 'in_review',
        label: t('In Prüfung'),
        count: counts.value.in_review,
    },
    {
        value: 'approved',
        label: t('Freigegeben'),
        count: counts.value.approved,
    },
    { value: 'scheduled', label: t('Geplant'), count: counts.value.scheduled },
]);

function filter(value: ReviewFilter): void {
    router.get(props.urls?.index ?? page.url, value ? { status: value } : {}, {
        preserveState: true,
        replace: true,
    });
}

/** «Prüfen» nur für fremde Fassungen in Prüfung; alles andere ansehen. */
function actionOf(row: ReviewRow): { label: string; primary: boolean } {
    return row.status === 'in_review' && !row.own
        ? { label: t('Prüfen'), primary: true }
        : { label: t('Ansehen'), primary: false };
}
</script>

<template>
    <Head :title="$t('Freigabe')" />
    <PageHeader
        :title="$t('Freigabe')"
        :crumb="$t(':site › Inhalte', { site: siteName })"
        :lead="lead"
    />

    <div
        class="mb-2.5 flex flex-wrap gap-1.5"
        role="group"
        :aria-label="$t('Filter nach Status')"
    >
        <button
            v-for="o in filterOptions"
            :key="o.value"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-[5px] text-xs font-semibold transition-colors"
            :class="
                active === o.value
                    ? 'border-basalt bg-basalt text-paper'
                    : 'border-ash-light bg-white text-basalt-light hover:border-ash hover:text-basalt'
            "
            :aria-pressed="active === o.value"
            @click="filter(o.value)"
        >
            {{ o.label }}
            <span
                v-if="o.count !== null"
                class="font-mono text-[10.5px] font-normal"
                >{{ o.count }}</span
            >
        </button>
    </div>

    <div
        v-if="rows.length === 0"
        class="rounded-lg border border-ash-light bg-white"
    >
        <EmptyState
            :title="
                active
                    ? $t('Nichts in diesem Filter')
                    : $t('Nichts wartet auf Freigabe')
            "
            :text="
                active
                    ? $t('Andere Stufen zeigen «Alle».')
                    : $t(
                          'Eingereichte Fassungen erscheinen hier, bis sie veröffentlicht sind.',
                      )
            "
        >
            <Button
                v-if="active"
                variant="secondary"
                size="sm"
                @click="filter('')"
                >{{ $t('Alle zeigen') }}</Button
            >
        </EmptyState>
    </div>
    <Table v-else>
        <template #head>
            <tr>
                <th class="w-full px-3 py-2">{{ $t('Titel · Art') }}</th>
                <th class="px-3 py-2">{{ $t('Sprache') }}</th>
                <th class="px-3 py-2">{{ $t('Fassung') }}</th>
                <th class="px-3 py-2">{{ $t('Status') }}</th>
                <th class="px-3 py-2 md:whitespace-nowrap">
                    {{ $t('Eingereicht · geplant') }}
                </th>
                <th class="px-3 py-2">
                    <span class="sr-only">{{ $t('Aktion') }}</span>
                </th>
            </tr>
        </template>
        <tr v-for="row in rows" :key="row.version_id" class="align-top">
            <td class="px-3 py-2.5">
                <Link
                    :href="row.url"
                    class="text-[13.5px] font-semibold break-words hover:underline"
                    >{{ row.title }}</Link
                >
                <span
                    v-if="row.own"
                    class="ml-1.5 rounded border border-ash-light bg-paper px-[5px] py-px text-[10px] text-basalt-light"
                    :title="$t('Sie haben diese Fassung erstellt')"
                    >{{ $t('eigene') }}</span
                >
                <p class="text-[11.5px] text-basalt-light">
                    {{ row.type_label }}
                </p>
            </td>
            <td
                :data-label="$t('Sprache')"
                class="px-3 py-2.5 font-mono text-[12px]"
            >
                {{ row.locale.toUpperCase() }}
            </td>
            <td
                :data-label="$t('Fassung')"
                class="px-3 py-2.5 text-[12.5px] md:whitespace-nowrap"
            >
                {{ $t('Nr. :number', { number: row.number }) }}
            </td>
            <td
                :data-label="$t('Status')"
                class="px-3 py-2.5 md:whitespace-nowrap"
            >
                <StatusHex
                    :status="versionStatus(row.status)"
                    :label="row.status_label"
                />
            </td>
            <td
                :data-label="$t('Eingereicht · geplant')"
                class="px-3 py-2.5 text-[12px] md:whitespace-nowrap"
            >
                <template v-if="row.status === 'scheduled'">
                    <span class="block text-basalt-light">{{
                        $t('geplant für')
                    }}</span>
                    <span class="block">{{ row.scheduled_for ?? '—' }}</span>
                </template>
                <template v-else>
                    <span class="block text-basalt-light">{{
                        row.author ?? '—'
                    }}</span>
                    <span
                        v-if="row.at"
                        class="text-ash-dark block text-[11px]"
                        >{{ row.at }}</span
                    >
                </template>
            </td>
            <td class="px-3 py-2.5 md:text-right">
                <Button
                    :href="row.url"
                    size="sm"
                    :variant="actionOf(row).primary ? 'primary' : 'secondary'"
                    >{{ actionOf(row).label }}</Button
                >
            </td>
        </tr>
        <template #foot>
            {{ $tc(':count Fassung|:count Fassungen', rows.length) }} ·
            {{
                $t(
                    '«Prüfen» (fremde Fassungen in Prüfung) bzw. «Ansehen» öffnet die Freigabe mit Diff gegen die live Fassung.',
                )
            }}
        </template>
    </Table>
</template>
