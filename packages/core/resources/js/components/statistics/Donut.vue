<script setup lang="ts">
import { computed } from 'vue';
import { t } from '../../i18n';
import { type Bucket, formatNumber, uiLocale } from './types';

/**
 * TB 54: Ringdiagramm der Statistik (wie Fristo) — die fünf grössten Anteile, der Rest als «Andere». Ein Klick auf
 * Ring oder Legende grenzt die Auswertung ein (`select`), wenn `interactive`.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        rows: Bucket[];
        activeValue?: string | null;
        interactive?: boolean;
        labels?: Record<string, string>;
    }>(),
    { activeValue: null, interactive: true, labels: () => ({}) },
);
const emit = defineEmits<{ (e: 'select', value: string): void }>();

const PALETTE = [
    'var(--color-lava)',
    'var(--color-basalt)',
    'var(--color-lava-light)',
    'var(--color-ash-dark)',
    'var(--color-ash)',
];

const locale = uiLocale();
const total = computed(() =>
    props.rows.reduce((sum, row) => sum + row.count, 0),
);

const segments = computed(() => {
    const sorted = [...props.rows].sort((a, b) => b.count - a.count);
    const rest = sorted.slice(5).reduce((sum, row) => sum + row.count, 0);
    const list = sorted.slice(0, 5).map((row, i) => ({
        ...row,
        label: props.labels[row.label] ?? row.label,
        color: PALETTE[i % PALETTE.length],
        selectable: props.interactive,
    }));
    if (rest > 0) {
        list.push({
            label: t('Andere'),
            value: '__andere__',
            count: rest,
            color: 'var(--color-ash-light)',
            selectable: false,
        });
    }
    let before = 0;

    return list.map((segment) => {
        const pct = total.value > 0 ? (segment.count / total.value) * 100 : 0;
        const out = { ...segment, pct, offset: -before };
        before += pct;

        return out;
    });
});

function percent(count: number): string {
    return total.value === 0
        ? '0 %'
        : `${Math.round((count / total.value) * 100)} %`;
}
</script>

<template>
    <section class="rounded-lg border border-ash-light bg-white p-4">
        <h3 class="mb-3 text-[13px] font-semibold text-basalt">{{ title }}</h3>
        <p v-if="rows.length === 0" class="text-[12px] text-basalt-light">
            {{ $t('Noch keine Daten.') }}
        </p>
        <div v-else class="flex items-center gap-4">
            <div class="relative size-28 shrink-0">
                <svg
                    viewBox="0 0 42 42"
                    class="size-full -rotate-90"
                    aria-hidden="true"
                >
                    <circle
                        cx="21"
                        cy="21"
                        r="15.915"
                        fill="none"
                        stroke="var(--color-ash-light)"
                        stroke-width="5"
                    />
                    <circle
                        v-for="segment in segments"
                        :key="segment.value"
                        cx="21"
                        cy="21"
                        r="15.915"
                        fill="none"
                        :stroke="segment.color"
                        stroke-width="5"
                        pathLength="100"
                        :stroke-dasharray="`${segment.pct} ${100 - segment.pct}`"
                        :stroke-dashoffset="segment.offset"
                    />
                </svg>
                <div
                    class="absolute inset-0 grid place-content-center text-center"
                >
                    <span class="text-lg leading-none font-bold tabular-nums">{{
                        formatNumber(total, locale)
                    }}</span>
                    <span class="text-[10px] text-basalt-light">{{
                        $t('gesamt')
                    }}</span>
                </div>
            </div>
            <ul class="m-0 grid min-w-0 flex-1 list-none gap-1 p-0">
                <li v-for="segment in segments" :key="segment.value">
                    <component
                        :is="segment.selectable ? 'button' : 'div'"
                        :type="segment.selectable ? 'button' : undefined"
                        class="flex w-full items-center gap-2 rounded px-1.5 py-1 text-left text-[12px]"
                        :class="[
                            segment.selectable
                                ? 'cursor-pointer hover:bg-paper'
                                : '',
                            activeValue === segment.value
                                ? 'bg-lava/10 ring-1 ring-lava/40'
                                : '',
                        ]"
                        :title="
                            segment.selectable
                                ? $t('Klicken zum Eingrenzen')
                                : undefined
                        "
                        @click="
                            segment.selectable && emit('select', segment.value)
                        "
                    >
                        <span
                            class="size-2.5 shrink-0 rounded-full"
                            :style="{ backgroundColor: segment.color }"
                        />
                        <span class="min-w-0 flex-1 break-words">{{
                            segment.label
                        }}</span>
                        <span class="shrink-0 text-basalt-light tabular-nums">{{
                            percent(segment.count)
                        }}</span>
                    </component>
                </li>
            </ul>
        </div>
    </section>
</template>
