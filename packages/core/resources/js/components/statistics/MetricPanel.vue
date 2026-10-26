<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { t } from '../../i18n';
import SeriesChart from './SeriesChart.vue';
import {
    formatMetric,
    type MetricKpi,
    type MetricSectionData,
    trend,
    uiLocale,
} from './types';

/**
 * TB 55: Ein Abschnitt der Statistik im Extranet — Kennzahlen mit Veränderung zum Zeitraum davor, Verläufe je Tag
 * und Ranglisten mit Balken. Die Daten baut der Server (Kern und Module, `MetricSection`).
 */
defineProps<{ section: MetricSectionData }>();

const locale = uiLocale();
const value = (kpi: MetricKpi): string =>
    formatMetric(kpi.value, kpi.format, kpi.currency, locale);

const trendOf = (kpi: MetricKpi): { text: string; tone: string } | null =>
    trend(kpi, t('▲ neu'));

function share(value: number, rows: { value: number }[]): number {
    const max = Math.max(1, ...rows.map((row) => row.value));

    return Math.round((value / max) * 100);
}

const card = 'rounded-lg border border-ash-light bg-white p-4';
</script>

<template>
    <div class="grid gap-4">
        <div
            v-if="section.lead || section.link"
            class="flex flex-wrap items-baseline justify-between gap-2"
        >
            <p
                v-if="section.lead"
                class="m-0 max-w-3xl text-[12.5px] text-basalt-light"
            >
                {{ section.lead }}
            </p>
            <Link
                v-if="section.link"
                :href="section.link.href"
                class="text-[12.5px] font-semibold text-lava-dark underline hover:text-basalt"
                >{{ section.link.label }} →</Link
            >
        </div>

        <ul
            class="m-0 grid list-none grid-cols-2 gap-3 p-0 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6"
        >
            <li v-for="kpi in section.kpis" :key="kpi.label" :class="card">
                <p
                    class="m-0 text-[11.5px] font-semibold tracking-wide text-basalt-light uppercase"
                >
                    {{ kpi.label }}
                </p>
                <p class="m-0 mt-1 text-2xl font-bold text-basalt tabular-nums">
                    {{ value(kpi) }}
                </p>
                <p
                    v-if="trendOf(kpi)"
                    class="m-0 mt-0.5 text-[11.5px] font-semibold tabular-nums"
                    :class="trendOf(kpi)?.tone"
                    :title="$t('Gegenüber dem Zeitraum davor')"
                >
                    {{ trendOf(kpi)?.text }}
                    <span class="font-normal text-basalt-light">{{
                        $t('zum Zeitraum davor')
                    }}</span>
                </p>
                <p
                    v-if="kpi.hint"
                    class="m-0 mt-1 text-[11.5px] text-basalt-light"
                >
                    {{ kpi.hint }}
                </p>
            </li>
        </ul>

        <div
            v-if="section.series.length > 0"
            class="grid gap-4"
            :class="section.series.length > 1 ? 'lg:grid-cols-2' : ''"
        >
            <SeriesChart
                v-for="series in section.series"
                :key="series.label"
                :title="series.label"
                :points="series.points"
                :summary="series.summary"
            />
        </div>

        <div
            v-if="section.lists.length > 0"
            class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
        >
            <section
                v-for="list in section.lists"
                :key="list.title"
                :class="card"
            >
                <h3 class="mb-3 text-[13px] font-semibold text-basalt">
                    {{ list.title }}
                </h3>
                <p
                    v-if="list.rows.length === 0"
                    class="m-0 text-[12px] text-basalt-light"
                >
                    {{ list.empty ?? $t('Noch keine Daten.') }}
                </p>
                <ul v-else class="m-0 grid list-none gap-1 p-0">
                    <li
                        v-for="row in list.rows"
                        :key="row.label"
                        class="relative overflow-hidden rounded px-2 py-1.5 text-[12.5px]"
                    >
                        <span
                            class="absolute inset-y-0 left-0 bg-lava/12"
                            :style="{
                                width: `${share(row.value, list.rows)}%`,
                            }"
                            aria-hidden="true"
                        />
                        <span class="relative flex items-center gap-3">
                            <span class="min-w-0 flex-1 break-words">{{
                                row.label
                            }}</span>
                            <span
                                v-if="row.hint"
                                class="shrink-0 text-[11.5px] text-basalt-light"
                                >{{ row.hint }}</span
                            >
                            <span class="shrink-0 font-semibold tabular-nums">{{
                                formatMetric(
                                    row.value,
                                    list.format,
                                    row.currency ?? list.currency,
                                    locale,
                                )
                            }}</span>
                        </span>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
