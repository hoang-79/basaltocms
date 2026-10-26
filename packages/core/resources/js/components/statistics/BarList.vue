<script setup lang="ts">
import { formatNumber, share, uiLocale } from './types';

const locale = uiLocale();

/**
 * TB 54: Liste mit Balken (Seiten, Verweise, Länder) — die Zahl rechts, der Balken im Verhältnis zur grössten Zeile.
 * Ein Klick grenzt die Auswertung ein (`select`).
 */
const props = withDefaults(
    defineProps<{
        title: string;
        rows: {
            label: string;
            value: string;
            count: number;
            visitors?: number;
        }[];
        activeValue?: string | null;
        interactive?: boolean;
        labels?: Record<string, string>;
        hint?: string;
        mono?: boolean;
    }>(),
    {
        activeValue: null,
        interactive: true,
        labels: () => ({}),
        hint: '',
        mono: false,
    },
);
const emit = defineEmits<{ (e: 'select', value: string): void }>();
</script>

<template>
    <section class="rounded-lg border border-ash-light bg-white p-4">
        <h3 class="mb-3 text-[13px] font-semibold text-basalt">{{ title }}</h3>
        <p v-if="rows.length === 0" class="text-[12px] text-basalt-light">
            {{ $t('Noch keine Daten.') }}
        </p>
        <ul v-else class="m-0 grid list-none gap-1 p-0">
            <li v-for="row in rows" :key="row.value">
                <component
                    :is="interactive ? 'button' : 'div'"
                    :type="interactive ? 'button' : undefined"
                    class="relative block w-full overflow-hidden rounded px-2 py-1.5 text-left text-[12.5px]"
                    :class="[
                        interactive ? 'cursor-pointer hover:bg-paper' : '',
                        activeValue === row.value ? 'ring-1 ring-lava/50' : '',
                    ]"
                    :title="
                        interactive ? $t('Klicken zum Eingrenzen') : undefined
                    "
                    @click="interactive && emit('select', row.value)"
                >
                    <span
                        class="absolute inset-y-0 left-0 bg-lava/12"
                        :style="{ width: `${share(row.count, props.rows)}%` }"
                        aria-hidden="true"
                    />
                    <span class="relative flex items-center gap-3">
                        <span
                            class="min-w-0 flex-1 break-all"
                            :class="mono ? 'font-mono text-[12px]' : ''"
                            >{{ labels[row.label] ?? row.label }}</span
                        >
                        <span
                            v-if="row.visitors !== undefined"
                            class="shrink-0 text-[11.5px] text-basalt-light tabular-nums"
                            >{{
                                $t(':count Besucher', { count: row.visitors })
                            }}</span
                        >
                        <span class="shrink-0 font-semibold tabular-nums">{{
                            formatNumber(row.count, locale)
                        }}</span>
                    </span>
                </component>
            </li>
        </ul>
        <p v-if="hint" class="mt-2 text-[11.5px] text-basalt-light">
            {{ hint }}
        </p>
    </section>
</template>
