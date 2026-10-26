<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { t } from '../../i18n';
import Tabs from '../Tabs.vue';
import BarList from './BarList.vue';
import Donut from './Donut.vue';
import {
    chartPoints,
    type FilterKey,
    formatDuration,
    formatMs,
    formatNumber,
    type StatisticsPayload,
    uiLocale,
} from './types';

/**
 * TB 54: Die Statistik einer Site (wie Fristo) — Zeitraum, Bots, Eingrenzen per Klick und sechs Reiter: Übersicht,
 * Herkunft, Verhalten, Technik, Sprachen und Länder, Leistung. Lädt über `url` neu (Inertia), behält die Scrollposition.
 * Wird im Backend (Verwaltung › Statistik) und im Extranet verwendet.
 */
const props = defineProps<{ stats: StatisticsPayload; url: string }>();

const locale = uiLocale();
const num = (value: number): string => formatNumber(value, locale);
const DIRECT = '__direct__';

const tabs = computed(() => [
    { key: 'uebersicht', label: t('Übersicht') },
    { key: 'herkunft', label: t('Herkunft') },
    { key: 'verhalten', label: t('Verhalten') },
    { key: 'technik', label: t('Technik') },
    { key: 'sprachen', label: t('Sprachen und Länder') },
    { key: 'leistung', label: t('Leistung') },
]);
const active = ref('uebersicht');

const RANGES = [
    { key: '7d', label: t('7 Tage') },
    { key: '30d', label: t('30 Tage') },
    { key: '90d', label: t('90 Tage') },
];

function go(patch: {
    range?: string;
    bots?: boolean;
    filters?: Partial<Record<FilterKey, string>>;
}): void {
    const query: Record<string, string> = {};
    const range = patch.range ?? props.stats.range;
    if (range !== '7d') {
        query.range = range;
    }
    if (patch.bots ?? props.stats.include_bots) {
        query.bots = '1';
    }
    for (const [key, value] of Object.entries(
        patch.filters ?? props.stats.filters,
    )) {
        if (value) {
            query[key] = value;
        }
    }
    router.get(props.url, query, { preserveScroll: true, preserveState: true });
}

function filter(key: FilterKey, value: string): void {
    if (props.stats.filters[key] !== value) {
        go({ filters: { ...props.stats.filters, [key]: value } });
    }
}

function unfilter(key: FilterKey): void {
    const next = { ...props.stats.filters };
    delete next[key];
    go({ filters: next });
}

const FILTER_LABELS: Record<FilterKey, string> = {
    path: t('Seite'),
    referrer: t('Herkunft'),
    browser: t('Browser'),
    os: t('Betriebssystem'),
    device: t('Gerät'),
    country: t('Land'),
    source: t('Kampagne'),
    locale: t('Sprache'),
};

const CHANNELS: Record<string, string> = {
    direct: t('Direkt'),
    organic: t('Suchmaschinen'),
    social: t('Soziale Netzwerke'),
    campaign: t('Kampagnen'),
    referral: t('Verweise'),
};
const DEVICES: Record<string, string> = {
    desktop: t('Computer'),
    mobile: t('Telefon'),
    tablet: t('Tablet'),
    bot: t('Bot'),
};
const REFERRERS: Record<string, string> = {
    [DIRECT]: t('Direkt / ohne Verweis'),
};

const chips = computed(() =>
    (Object.entries(props.stats.filters) as [FilterKey, string][])
        .filter(([, value]) => !!value)
        .map(([key, value]) => ({
            key,
            label: FILTER_LABELS[key],
            value: value === DIRECT ? t('Direkt') : (DEVICES[value] ?? value),
        })),
);

const kpis = computed(() => [
    { label: t('Besucher'), value: num(props.stats.kpis.visitors) },
    { label: t('Seitenaufrufe'), value: num(props.stats.kpis.pageviews) },
    { label: t('Sitzungen'), value: num(props.stats.kpis.sessions) },
    {
        label: t('Seiten je Sitzung'),
        value: props.stats.kpis.pages_per_session.toLocaleString(locale),
    },
    { label: t('Kundenkonten'), value: num(props.stats.kpis.accounts) },
]);

const engagement = computed(() => [
    {
        label: t('Ø Sitzung'),
        value: formatDuration(props.stats.engagement.avg_session_seconds),
    },
    {
        label: t('Ø Zeit je Seite'),
        value: formatDuration(props.stats.engagement.avg_time_on_page_seconds),
    },
    {
        label: t('Absprungrate'),
        value: `${props.stats.engagement.bounce_rate.toLocaleString(locale)} %`,
    },
]);

// Zeitreihe: Fläche und Linie, beim Überfahren der Tag mit seinen Zahlen
const metric = ref<'pageviews' | 'visitors' | 'sessions'>('pageviews');
const METRICS = computed(() => [
    { key: 'pageviews' as const, label: t('Seitenaufrufe') },
    { key: 'visitors' as const, label: t('Besucher') },
    { key: 'sessions' as const, label: t('Sitzungen') },
]);
const HEIGHT = 220;
const points = computed(() =>
    chartPoints(
        props.stats.timeseries.map((point) => point[metric.value]),
        HEIGHT,
    ),
);
const line = computed(() =>
    points.value.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x},${p.y}`).join(' '),
);
const area = computed(() => {
    const first = points.value[0];
    const last = points.value[points.value.length - 1];

    return first && last
        ? `M${first.x},${HEIGHT} ${line.value.slice(1)} L${last.x},${HEIGHT} Z`
        : '';
});
const hover = ref<number | null>(null);
const chart = ref<HTMLElement | null>(null);
function move(event: MouseEvent): void {
    const n = props.stats.timeseries.length;
    if (!chart.value || n === 0) {
        return;
    }
    const rect = chart.value.getBoundingClientRect();
    hover.value = Math.round(
        Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width)) *
            (n - 1),
    );
}
const hoverDay = computed(() =>
    hover.value === null ? null : props.stats.timeseries[hover.value],
);
const hoverLeft = computed(() => {
    const n = props.stats.timeseries.length;

    return hover.value === null || n <= 1 ? 0 : (hover.value / (n - 1)) * 100;
});
function day(date: string): string {
    return new Date(`${date}T12:00:00`).toLocaleDateString(locale, {
        day: '2-digit',
        month: '2-digit',
    });
}
const axis = computed(() => {
    const series = props.stats.timeseries;

    return series.length === 0
        ? []
        : [
              series[0],
              series[Math.floor((series.length - 1) / 2)],
              series[series.length - 1],
          ];
});

const maxHour = computed(() =>
    Math.max(1, ...props.stats.hourly.map((h) => h.pageviews)),
);

const newReturning = computed(() => [
    {
        label: t('Neue Besucher'),
        value: 'neu',
        count: props.stats.new_vs_returning.new,
    },
    {
        label: t('Wiederkehrende Besucher'),
        value: 'wieder',
        count: props.stats.new_vs_returning.returning,
    },
]);

const performance = computed(() => [
    {
        label: t('Ø Antwortzeit'),
        value: formatMs(props.stats.performance.avg_ms, locale),
    },
    { label: 'p50', value: formatMs(props.stats.performance.p50, locale) },
    { label: 'p90', value: formatMs(props.stats.performance.p90, locale) },
    { label: 'p99', value: formatMs(props.stats.performance.p99, locale) },
]);

function time(iso: string): string {
    return new Date(iso).toLocaleString(locale, {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const card = 'rounded-lg border border-ash-light bg-white p-4';
const th =
    'border-b border-ash-light px-2 py-1.5 text-left text-[11px] font-semibold tracking-wide text-basalt-light uppercase';
const td = 'border-b border-ash-light px-2 py-1.5 align-top';
</script>

<template>
    <div class="grid gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="m-0 text-[12.5px] text-basalt-light">
                {{ $t('Zeitraum') }}: {{ day(stats.period.from) }} –
                {{ day(stats.period.to) }}
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <div
                    class="inline-flex rounded-md border border-ash bg-white p-0.5"
                    role="group"
                    :aria-label="$t('Zeitraum')"
                >
                    <button
                        v-for="range in RANGES"
                        :key="range.key"
                        type="button"
                        class="rounded px-3 py-1.5 text-[12.5px] font-semibold"
                        :class="
                            stats.range === range.key
                                ? 'bg-basalt text-paper'
                                : 'text-basalt-light hover:bg-paper'
                        "
                        :aria-pressed="stats.range === range.key"
                        @click="go({ range: range.key })"
                    >
                        {{ range.label }}
                    </button>
                </div>
                <button
                    type="button"
                    class="rounded-md border px-3 py-1.5 text-[12.5px] font-semibold"
                    :class="
                        stats.include_bots
                            ? 'border-lava/50 bg-lava/10 text-lava-dark'
                            : 'border-ash bg-white text-basalt-light hover:bg-paper'
                    "
                    :aria-pressed="stats.include_bots"
                    @click="go({ bots: !stats.include_bots })"
                >
                    {{
                        stats.include_bots
                            ? $t('Bots mitgezählt')
                            : $t('Ohne Bots')
                    }}
                </button>
            </div>
        </div>

        <div
            v-if="chips.length > 0"
            class="flex flex-wrap items-center gap-2 rounded-lg border border-lava/30 bg-lava/5 px-3 py-2"
        >
            <span class="text-[12px] font-semibold text-lava-dark">{{
                $t('Eingegrenzt')
            }}</span>
            <button
                v-for="chip in chips"
                :key="chip.key"
                type="button"
                class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-lava/40 bg-white px-2.5 py-1 text-[12px]"
                :aria-label="
                    $t(':filter entfernen', {
                        filter: `${chip.label}: ${chip.value}`,
                    })
                "
                @click="unfilter(chip.key)"
            >
                <span class="text-basalt-light">{{ chip.label }}:</span>
                <span class="break-all">{{ chip.value }}</span>
                <span aria-hidden="true">×</span>
            </button>
            <button
                type="button"
                class="text-[12px] font-semibold text-lava-dark underline"
                @click="go({ filters: {} })"
            >
                {{ $t('Alle entfernen') }}
            </button>
        </div>

        <Tabs v-model="active" :tabs="tabs">
            <template #uebersicht>
                <div class="grid gap-4">
                    <ul
                        class="m-0 grid list-none grid-cols-2 gap-3 p-0 sm:grid-cols-3 lg:grid-cols-5"
                    >
                        <li v-for="kpi in kpis" :key="kpi.label" :class="card">
                            <p
                                class="m-0 text-[11.5px] font-semibold tracking-wide text-basalt-light uppercase"
                            >
                                {{ kpi.label }}
                            </p>
                            <p
                                class="m-0 mt-1 text-2xl font-bold text-basalt tabular-nums"
                            >
                                {{ kpi.value }}
                            </p>
                        </li>
                    </ul>
                    <p
                        v-if="stats.kpis.anonymous > 0"
                        class="m-0 text-[12px] text-basalt-light"
                    >
                        {{
                            $tc(
                                ':count Aufruf ohne Einwilligung — anonym gezählt, ohne Besucher und Sitzung.|:count Aufrufe ohne Einwilligung — anonym gezählt, ohne Besucher und Sitzung.',
                                stats.kpis.anonymous,
                            )
                        }}
                    </p>
                    <ul class="m-0 grid list-none gap-3 p-0 sm:grid-cols-3">
                        <li
                            v-for="item in engagement"
                            :key="item.label"
                            :class="card"
                        >
                            <p
                                class="m-0 text-[11.5px] font-semibold tracking-wide text-basalt-light uppercase"
                            >
                                {{ item.label }}
                            </p>
                            <p
                                class="m-0 mt-1 text-xl font-bold text-basalt tabular-nums"
                            >
                                {{ item.value }}
                            </p>
                        </li>
                    </ul>

                    <section :class="card">
                        <div
                            class="mb-3 flex flex-wrap items-center justify-between gap-2"
                        >
                            <h3
                                class="m-0 text-[13px] font-semibold text-basalt"
                            >
                                {{ $t('Verlauf im Zeitraum') }}
                            </h3>
                            <div
                                class="inline-flex rounded-md border border-ash-light p-0.5"
                                role="group"
                                :aria-label="$t('Kennzahl')"
                            >
                                <button
                                    v-for="item in METRICS"
                                    :key="item.key"
                                    type="button"
                                    class="rounded px-2.5 py-1 text-[12px] font-semibold"
                                    :class="
                                        metric === item.key
                                            ? 'bg-lava text-white'
                                            : 'text-basalt-light hover:bg-paper'
                                    "
                                    :aria-pressed="metric === item.key"
                                    @click="metric = item.key"
                                >
                                    {{ item.label }}
                                </button>
                            </div>
                        </div>
                        <div
                            ref="chart"
                            class="relative"
                            role="img"
                            :aria-label="
                                $t('Verlauf der :metric im Zeitraum', {
                                    metric:
                                        METRICS.find((m) => m.key === metric)
                                            ?.label ?? '',
                                })
                            "
                            @mousemove="move"
                            @mouseleave="hover = null"
                        >
                            <svg
                                :viewBox="`0 0 1000 ${HEIGHT}`"
                                preserveAspectRatio="none"
                                class="block h-[220px] w-full"
                            >
                                <path
                                    :d="area"
                                    fill="var(--color-lava)"
                                    fill-opacity="0.12"
                                />
                                <path
                                    :d="line"
                                    fill="none"
                                    stroke="var(--color-lava)"
                                    stroke-width="2.5"
                                    vector-effect="non-scaling-stroke"
                                />
                            </svg>
                            <div
                                v-if="hoverDay"
                                class="pointer-events-none absolute inset-y-0 w-px bg-basalt/30"
                                :style="{ left: `${hoverLeft}%` }"
                            />
                            <div
                                v-if="hoverDay"
                                class="pointer-events-none absolute top-1 rounded-md border border-ash-light bg-white px-2.5 py-1.5 text-[12px] shadow"
                                :style="{
                                    left: `min(max(${hoverLeft}%, 4rem), calc(100% - 9rem))`,
                                }"
                            >
                                <b class="block">{{ day(hoverDay.date) }}</b>
                                {{ $t('Seitenaufrufe') }}:
                                {{ num(hoverDay.pageviews) }}<br />
                                {{ $t('Besucher') }}: {{ num(hoverDay.visitors)
                                }}<br />
                                {{ $t('Sitzungen') }}:
                                {{ num(hoverDay.sessions) }}
                            </div>
                        </div>
                        <div
                            class="mt-1 flex justify-between text-[11px] text-basalt-light tabular-nums"
                        >
                            <span
                                v-for="(point, i) in axis"
                                :key="`${point.date}-${i}`"
                                >{{ day(point.date) }}</span
                            >
                        </div>
                    </section>

                    <div
                        class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]"
                    >
                        <Donut
                            :title="$t('Neu und wiederkehrend')"
                            :rows="newReturning"
                            :interactive="false"
                        />
                        <section :class="card">
                            <h3
                                class="mb-3 text-[13px] font-semibold text-basalt"
                            >
                                {{ $t('Tageszeit') }}
                            </h3>
                            <div
                                class="flex h-32 items-end gap-0.5"
                                role="img"
                                :aria-label="$t('Seitenaufrufe je Stunde')"
                            >
                                <div
                                    v-for="hour in stats.hourly"
                                    :key="hour.hour"
                                    class="flex-1 rounded-t bg-lava/70"
                                    :style="{
                                        height: `${Math.max(2, (hour.pageviews / maxHour) * 100)}%`,
                                    }"
                                    :title="`${String(hour.hour).padStart(2, '0')}:00 — ${num(hour.pageviews)}`"
                                />
                            </div>
                            <div
                                class="mt-1 flex justify-between text-[11px] text-basalt-light tabular-nums"
                            >
                                <span>00</span><span>06</span><span>12</span
                                ><span>18</span><span>23</span>
                            </div>
                        </section>
                    </div>
                </div>
            </template>

            <template #herkunft>
                <div class="grid gap-4 lg:grid-cols-2">
                    <Donut
                        :title="$t('Kanäle')"
                        :rows="stats.channels"
                        :labels="CHANNELS"
                        :interactive="false"
                    />
                    <BarList
                        :title="$t('Verweise')"
                        :rows="stats.top_referrers"
                        :labels="REFERRERS"
                        :active-value="stats.filters.referrer"
                        @select="filter('referrer', $event)"
                    />
                    <section :class="[card, 'lg:col-span-2']">
                        <h3 class="mb-3 text-[13px] font-semibold text-basalt">
                            {{ $t('Kampagnen (UTM)') }}
                        </h3>
                        <p
                            v-if="stats.campaigns.length === 0"
                            class="m-0 text-[12px] text-basalt-light"
                        >
                            {{
                                $t(
                                    'Noch keine Aufrufe mit utm_source in der Adresse.',
                                )
                            }}
                        </p>
                        <table
                            v-else
                            class="w-full border-collapse text-[12.5px]"
                        >
                            <thead>
                                <tr>
                                    <th :class="th">{{ $t('Quelle') }}</th>
                                    <th :class="th">{{ $t('Medium') }}</th>
                                    <th :class="th">{{ $t('Kampagne') }}</th>
                                    <th :class="[th, 'text-right']">
                                        {{ $t('Aufrufe') }}
                                    </th>
                                    <th :class="[th, 'text-right']">
                                        {{ $t('Besucher') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in stats.campaigns"
                                    :key="`${row.source}-${row.medium}-${row.campaign}`"
                                >
                                    <td :class="td">
                                        <button
                                            type="button"
                                            class="text-left font-semibold text-lava-dark underline"
                                            @click="
                                                filter('source', row.source)
                                            "
                                        >
                                            {{ row.source }}
                                        </button>
                                    </td>
                                    <td :class="td">{{ row.medium ?? '—' }}</td>
                                    <td :class="[td, 'break-all']">
                                        {{ row.campaign ?? '—' }}
                                    </td>
                                    <td
                                        :class="[td, 'text-right tabular-nums']"
                                    >
                                        {{ num(row.views) }}
                                    </td>
                                    <td
                                        :class="[td, 'text-right tabular-nums']"
                                    >
                                        {{ num(row.visitors) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </section>
                </div>
            </template>

            <template #verhalten>
                <div class="grid gap-4 lg:grid-cols-2">
                    <BarList
                        class="lg:col-span-2"
                        :title="$t('Meistbesuchte Seiten')"
                        :rows="stats.top_pages"
                        mono
                        :active-value="stats.filters.path"
                        @select="filter('path', $event)"
                    />
                    <BarList
                        :title="$t('Einstiegsseiten')"
                        :rows="stats.landing_pages"
                        mono
                        @select="filter('path', $event)"
                    />
                    <BarList
                        :title="$t('Ausstiegsseiten')"
                        :rows="stats.exit_pages"
                        mono
                        @select="filter('path', $event)"
                    />
                    <section :class="[card, 'lg:col-span-2']">
                        <h3 class="mb-3 text-[13px] font-semibold text-basalt">
                            {{ $t('Letzte Aufrufe') }}
                        </h3>
                        <p
                            v-if="stats.recent.length === 0"
                            class="m-0 text-[12px] text-basalt-light"
                        >
                            {{ $t('Noch keine Daten.') }}
                        </p>
                        <table
                            v-else
                            class="w-full border-collapse text-[12.5px]"
                        >
                            <thead>
                                <tr>
                                    <th :class="th">{{ $t('Zeit') }}</th>
                                    <th :class="th">{{ $t('Seite') }}</th>
                                    <th :class="[th, 'hidden sm:table-cell']">
                                        {{ $t('Herkunft') }}
                                    </th>
                                    <th :class="[th, 'hidden md:table-cell']">
                                        {{ $t('Technik') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(row, i) in stats.recent"
                                    :key="`${row.created_at}-${i}`"
                                >
                                    <td
                                        :class="[
                                            td,
                                            'whitespace-nowrap tabular-nums',
                                        ]"
                                    >
                                        {{ time(row.created_at) }}
                                    </td>
                                    <td
                                        :class="[
                                            td,
                                            'font-mono text-[12px] break-all',
                                        ]"
                                    >
                                        {{ row.path }}
                                    </td>
                                    <td
                                        :class="[
                                            td,
                                            'hidden break-all sm:table-cell',
                                        ]"
                                    >
                                        {{ row.referrer_host ?? $t('Direkt') }}
                                    </td>
                                    <td :class="[td, 'hidden md:table-cell']">
                                        {{
                                            [
                                                row.browser,
                                                row.os,
                                                row.device_type
                                                    ? (DEVICES[
                                                          row.device_type
                                                      ] ?? row.device_type)
                                                    : null,
                                                row.country,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </section>
                </div>
            </template>

            <template #technik>
                <div class="grid gap-4 lg:grid-cols-3">
                    <Donut
                        :title="$t('Geräte')"
                        :rows="stats.devices"
                        :labels="DEVICES"
                        :active-value="stats.filters.device"
                        @select="filter('device', $event)"
                    />
                    <Donut
                        :title="$t('Browser')"
                        :rows="stats.browsers"
                        :active-value="stats.filters.browser"
                        @select="filter('browser', $event)"
                    />
                    <Donut
                        :title="$t('Betriebssysteme')"
                        :rows="stats.operating_systems"
                        :active-value="stats.filters.os"
                        @select="filter('os', $event)"
                    />
                </div>
            </template>

            <template #sprachen>
                <div class="grid gap-4 lg:grid-cols-2">
                    <Donut
                        :title="$t('Sprachen')"
                        :rows="stats.languages"
                        :active-value="stats.filters.locale"
                        @select="filter('locale', $event)"
                    />
                    <BarList
                        :title="$t('Länder')"
                        :rows="stats.countries"
                        :active-value="stats.filters.country"
                        :hint="
                            $t(
                                'Das Land kennt Basalto nur, wenn ein vorgeschalteter Dienst (etwa Cloudflare) es mitgibt — ohne Datenbank und ohne Abfrage nach aussen.',
                            )
                        "
                        @select="filter('country', $event)"
                    />
                </div>
            </template>

            <template #leistung>
                <div class="grid gap-4">
                    <ul
                        class="m-0 grid list-none grid-cols-2 gap-3 p-0 lg:grid-cols-4"
                    >
                        <li
                            v-for="item in performance"
                            :key="item.label"
                            :class="card"
                        >
                            <p
                                class="m-0 text-[11.5px] font-semibold tracking-wide text-basalt-light uppercase"
                            >
                                {{ item.label }}
                            </p>
                            <p
                                class="m-0 mt-1 text-xl font-bold text-basalt tabular-nums"
                            >
                                {{ item.value }}
                            </p>
                        </li>
                    </ul>
                    <p class="m-0 text-[12px] text-basalt-light">
                        {{
                            $t(
                                'Antwortzeit des Servers bis zum Senden der Seite, aus :count Messungen.',
                                { count: num(stats.performance.measured) },
                            )
                        }}
                    </p>
                    <section :class="card">
                        <h3 class="mb-3 text-[13px] font-semibold text-basalt">
                            {{ $t('Langsamste Seiten') }}
                        </h3>
                        <p
                            v-if="stats.performance.slowest.length === 0"
                            class="m-0 text-[12px] text-basalt-light"
                        >
                            {{ $t('Noch keine Daten.') }}
                        </p>
                        <table
                            v-else
                            class="w-full border-collapse text-[12.5px]"
                        >
                            <thead>
                                <tr>
                                    <th :class="th">{{ $t('Seite') }}</th>
                                    <th :class="[th, 'text-right']">Ø</th>
                                    <th :class="[th, 'text-right']">
                                        {{ $t('Höchstens') }}
                                    </th>
                                    <th :class="[th, 'text-right']">
                                        {{ $t('Aufrufe') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in stats.performance.slowest"
                                    :key="row.label"
                                >
                                    <td
                                        :class="[
                                            td,
                                            'font-mono text-[12px] break-all',
                                        ]"
                                    >
                                        {{ row.label }}
                                    </td>
                                    <td
                                        :class="[
                                            td,
                                            'text-right whitespace-nowrap tabular-nums',
                                        ]"
                                    >
                                        {{ formatMs(row.avg_ms, locale) }}
                                    </td>
                                    <td
                                        :class="[
                                            td,
                                            'text-right whitespace-nowrap tabular-nums',
                                        ]"
                                    >
                                        {{ formatMs(row.max_ms, locale) }}
                                    </td>
                                    <td
                                        :class="[td, 'text-right tabular-nums']"
                                    >
                                        {{ num(row.count) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </section>
                    <section :class="card">
                        <div
                            class="mb-3 flex flex-wrap items-baseline justify-between gap-2"
                        >
                            <h3
                                class="m-0 text-[13px] font-semibold text-basalt"
                            >
                                {{ $t('Bots') }}
                            </h3>
                            <span class="text-[12px] text-basalt-light">
                                {{
                                    $t(
                                        ':bots von :total Aufrufen (:share %) — Menschen: :humans',
                                        {
                                            bots: num(stats.bot_traffic.bots),
                                            total: num(stats.bot_traffic.total),
                                            share: stats.bot_traffic.bot_share.toLocaleString(
                                                locale,
                                            ),
                                            humans: num(
                                                stats.bot_traffic.humans,
                                            ),
                                        },
                                    )
                                }}
                            </span>
                        </div>
                        <BarList
                            :title="$t('Häufigste Bots')"
                            :rows="stats.bot_traffic.top_bots"
                            :interactive="false"
                            class="border-0 p-0"
                        />
                    </section>
                </div>
            </template>
        </Tabs>
    </div>
</template>
