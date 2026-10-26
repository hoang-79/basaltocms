<script setup lang="ts">
import { computed, ref } from 'vue';
import { chartPoints, formatNumber, uiLocale } from './types';

/**
 * TB 55: Verlauf je Tag als Fläche (wie im Verlauf der Website) — beim Überfahren der Tag mit seiner Zahl, darunter
 * Anfang, Mitte und Ende des Zeitraums. Oben rechts die Summe oder, wo sich Tage nicht addieren lassen, der Höchstwert.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        points: { date: string; value: number }[];
        summary?: 'total' | 'max';
    }>(),
    { summary: 'total' },
);

const locale = uiLocale();
const HEIGHT = 160;
const coords = computed(() =>
    chartPoints(
        props.points.map((point) => point.value),
        HEIGHT,
    ),
);
const line = computed(() =>
    coords.value.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x},${p.y}`).join(' '),
);
const area = computed(() => {
    const first = coords.value[0];
    const last = coords.value[coords.value.length - 1];

    return first && last
        ? `M${first.x},${HEIGHT} ${line.value.slice(1)} L${last.x},${HEIGHT} Z`
        : '';
});
const total = computed(() =>
    props.summary === 'max'
        ? Math.max(0, ...props.points.map((point) => point.value))
        : props.points.reduce((sum, point) => sum + point.value, 0),
);

const hover = ref<number | null>(null);
const chart = ref<HTMLElement | null>(null);
function move(event: MouseEvent): void {
    const n = props.points.length;
    if (!chart.value || n === 0) {
        return;
    }
    const rect = chart.value.getBoundingClientRect();
    hover.value = Math.round(
        Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width)) *
            (n - 1),
    );
}
const hoverPoint = computed(() =>
    hover.value === null ? null : props.points[hover.value],
);
const hoverLeft = computed(() => {
    const n = props.points.length;

    return hover.value === null || n <= 1 ? 0 : (hover.value / (n - 1)) * 100;
});
function day(date: string): string {
    return new Date(`${date}T12:00:00`).toLocaleDateString(locale, {
        day: '2-digit',
        month: '2-digit',
    });
}
const axis = computed(() => {
    const series = props.points;

    return series.length === 0
        ? []
        : [
              series[0],
              series[Math.floor((series.length - 1) / 2)],
              series[series.length - 1],
          ];
});
</script>

<template>
    <section class="rounded-lg border border-ash-light bg-white p-4">
        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="m-0 text-[13px] font-semibold text-basalt">
                {{ title }}
            </h3>
            <span class="text-[12px] text-basalt-light tabular-nums">{{
                summary === 'max'
                    ? $t('Höchstwert :count', {
                          count: formatNumber(total, locale),
                      })
                    : $t('Total :count', { count: formatNumber(total, locale) })
            }}</span>
        </div>
        <div
            ref="chart"
            class="relative"
            role="img"
            :aria-label="
                summary === 'max'
                    ? $t(':title: höchstens :count', {
                          title,
                          count: formatNumber(total, locale),
                      })
                    : $t(':title: :count im Zeitraum', {
                          title,
                          count: formatNumber(total, locale),
                      })
            "
            @mousemove="move"
            @mouseleave="hover = null"
        >
            <svg
                :viewBox="`0 0 1000 ${HEIGHT}`"
                preserveAspectRatio="none"
                class="block h-[140px] w-full"
            >
                <path :d="area" fill="var(--color-lava)" fill-opacity="0.12" />
                <path
                    :d="line"
                    fill="none"
                    stroke="var(--color-lava)"
                    stroke-width="2.5"
                    vector-effect="non-scaling-stroke"
                />
            </svg>
            <div
                v-if="hoverPoint"
                class="pointer-events-none absolute inset-y-0 w-px bg-basalt/30"
                :style="{ left: `${hoverLeft}%` }"
            />
            <div
                v-if="hoverPoint"
                class="pointer-events-none absolute top-1 rounded-md border border-ash-light bg-white px-2.5 py-1.5 text-[12px] shadow"
                :style="{
                    left: `min(max(${hoverLeft}%, 3rem), calc(100% - 7rem))`,
                }"
            >
                <b class="block">{{ day(hoverPoint.date) }}</b>
                {{ formatNumber(hoverPoint.value, locale) }}
            </div>
        </div>
        <div
            class="mt-1 flex justify-between text-[11px] text-basalt-light tabular-nums"
        >
            <span v-for="(point, i) in axis" :key="`${point.date}-${i}`">{{
                day(point.date)
            }}</span>
        </div>
    </section>
</template>
