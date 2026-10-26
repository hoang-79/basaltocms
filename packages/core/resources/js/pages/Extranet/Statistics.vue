<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../components/PageHeader.vue';
import MetricPanel from '../../components/statistics/MetricPanel.vue';
import StatisticsBoard from '../../components/statistics/StatisticsBoard.vue';
import {
    formatMetric,
    type MetricKpi,
    trend,
    uiLocale,
} from '../../components/statistics/types';
import { t } from '../../i18n';
import type { StatisticsProps } from '../../types-extranet';

/**
 * TB 55: Extranet › Statistik — wie gut Basalto läuft. Reiter «Überblick» mit den wichtigsten Zahlen jedes
 * Abschnitts, «Website» mit den Seitenaufrufen von basaltocms.com (dieselbe Auswertung wie Verwaltung › Statistik)
 * und je ein Reiter für Produkt, Lizenzen, Marktplatz, Community, Kundenkonten und SaaS. Reiter und Zeitraum wählt
 * der Server (`?tab=`, `?range=`), so bleiben Links teilbar.
 */
const props = defineProps<StatisticsProps>();

const locale = uiLocale();
const RANGES = [
    { key: '7d', label: t('7 Tage') },
    { key: '30d', label: t('30 Tage') },
    { key: '90d', label: t('90 Tage') },
];

function go(patch: { tab?: string; range?: string }): void {
    const tab = patch.tab ?? props.tab;
    const range = patch.range ?? props.range;
    const query: Record<string, string> = {};
    if (tab !== 'ueberblick') {
        query.tab = tab;
    }
    if (range !== '7d') {
        query.range = range;
    }
    router.get(props.urls.index, query, {
        preserveScroll: true,
        preserveState: false,
    });
}

const tabUrl = (key: string): string =>
    `${props.urls.index}?${new URLSearchParams({
        tab: key,
        ...(props.range !== '7d' ? { range: props.range } : {}),
    }).toString()}`;

function day(date: string): string {
    return new Date(`${date}T12:00:00`).toLocaleDateString(locale, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}

const trendOf = (kpi: MetricKpi): { text: string; tone: string } | null =>
    trend(kpi, t('▲ neu'));
</script>

<template>
    <Head :title="$t('Statistik')" />
    <PageHeader
        :title="$t('Statistik')"
        :crumb="$t('Extranet › Statistik')"
        :lead="
            $t(
                'Wie Basalto ankommt: Website, Downloads und Installationen, Lizenzen, Marktplatz und Community — gezählt ohne Dienste anderer Anbieter.',
            )
        "
    />

    <nav
        class="mb-3 flex flex-wrap gap-x-[18px] border-b border-ash-light"
        :aria-label="$t('Bereiche der Statistik')"
    >
        <button
            v-for="item in tabs"
            :key="item.key"
            type="button"
            class="-mb-px border-b-2 pb-[7px] text-[13px]"
            :class="
                item.key === tab
                    ? 'border-lava font-semibold text-basalt'
                    : 'border-transparent text-basalt-light hover:text-basalt'
            "
            :aria-current="item.key === tab ? 'page' : undefined"
            @click="go({ tab: item.key })"
        >
            {{ item.label }}
        </button>
    </nav>

    <div
        v-if="tab !== 'website'"
        class="mb-4 flex flex-wrap items-center justify-between gap-3"
    >
        <p class="m-0 text-[12.5px] text-basalt-light">
            {{ $t('Zeitraum') }}: {{ day(period.from) }} – {{ day(period.to) }}
        </p>
        <div
            class="inline-flex rounded-md border border-ash bg-white p-0.5"
            role="group"
            :aria-label="$t('Zeitraum')"
        >
            <button
                v-for="item in RANGES"
                :key="item.key"
                type="button"
                class="rounded px-3 py-1.5 text-[12.5px] font-semibold"
                :class="
                    range === item.key
                        ? 'bg-basalt text-paper'
                        : 'text-basalt-light hover:bg-paper'
                "
                :aria-pressed="range === item.key"
                @click="go({ range: item.key })"
            >
                {{ item.label }}
            </button>
        </div>
    </div>

    <!-- Überblick: die wichtigsten Zahlen jedes Abschnitts -->
    <div
        v-if="tab === 'ueberblick' && overview"
        class="grid gap-4 lg:grid-cols-2"
    >
        <section
            v-for="group in overview"
            :key="group.key"
            class="rounded-lg border border-ash-light bg-white p-4"
        >
            <div class="mb-3 flex items-baseline justify-between gap-2">
                <h2 class="m-0 text-[14px] font-semibold text-basalt">
                    {{ group.label }}
                </h2>
                <Link
                    :href="tabUrl(group.key)"
                    class="text-[12px] font-semibold text-lava-dark underline hover:text-basalt"
                    >{{ $t('Details') }} →</Link
                >
            </div>
            <ul class="m-0 grid list-none grid-cols-2 gap-3 p-0">
                <li v-for="kpi in group.kpis" :key="kpi.label" class="min-w-0">
                    <p
                        class="m-0 text-[11.5px] font-semibold tracking-wide text-basalt-light uppercase"
                    >
                        {{ kpi.label }}
                    </p>
                    <p
                        class="m-0 mt-1 text-2xl font-bold text-basalt tabular-nums"
                    >
                        {{
                            formatMetric(
                                kpi.value,
                                kpi.format,
                                kpi.currency,
                                locale,
                            )
                        }}
                    </p>
                    <p
                        v-if="trendOf(kpi)"
                        class="m-0 mt-0.5 text-[11.5px] font-semibold tabular-nums"
                        :class="trendOf(kpi)?.tone"
                        :title="$t('Gegenüber dem Zeitraum davor')"
                    >
                        {{ trendOf(kpi)?.text }}
                    </p>
                </li>
            </ul>
        </section>
    </div>

    <!-- Website: Seitenaufrufe wie unter Verwaltung › Statistik -->
    <StatisticsBoard
        v-else-if="tab === 'website' && website"
        :stats="website"
        :url="urls.website"
    />

    <MetricPanel v-else-if="section" :section="section" />
</template>
