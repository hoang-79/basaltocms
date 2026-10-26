<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useId,
    watch,
} from 'vue';
import type { Journey, JourneyStation, ThemeInfo } from '../types';
import { cardAlign, progress, statusMark } from './journey';

/**
 * Der Fahrplan der Einrichtung (TB 23): neun Stationen als Linie — jede mit der Frage, die sie beantwortet, und dem
 * Fachbegriff klein darunter. Ein Klick öffnet die Kontextkarte mit Erklärung, Stand aus der roten Linie und den
 * Seiten dazu — auch für Stationen ohne eigene Seite. Am Ende zweigt «Code» (Theme-Editor) ab; an «Wie sieht sie aus?»
 * steigt man zu den Themes um. Unter 768 px eine aufklappbare Liste.
 */
const props = defineProps<{ journey: Journey; theme: ThemeInfo | null }>();

const uid = useId();
const isDesktop = useMediaQuery('(min-width: 768px)');
const open = ref<string | null>(null);
const root = ref<HTMLElement | null>(null);
const buttons: Record<string, HTMLElement | null> = {};

const stations = computed(() =>
    props.journey.stations.map((station, i, all) => ({
        ...station,
        isActive: props.journey.active === station.key,
        segmentWaits:
            station.status === 'waiting' ||
            (all[i + 1]?.status ?? 'success') === 'waiting',
        isLast: i === all.length - 1,
        align: cardAlign(i, all.length),
    })),
);
const done = computed(() => progress(props.journey.stations));
const current = computed(() => stations.value.find((s) => s.isActive) ?? null);

function toggle(station: JourneyStation): void {
    open.value = open.value === station.key ? null : station.key;
}

function close(returnFocus = false): void {
    const key = open.value;
    open.value = null;
    if (returnFocus && key) {
        void nextTick(() => buttons[key]?.focus());
    }
}

function onDocument(event: MouseEvent): void {
    if (
        open.value &&
        root.value &&
        !root.value.contains(event.target as Node)
    ) {
        close();
    }
}

function onKey(event: KeyboardEvent): void {
    if (event.key === 'Escape' && open.value) {
        close(true);
    }
}

onMounted(() => {
    document.addEventListener('click', onDocument);
    document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocument);
    document.removeEventListener('keydown', onKey);
});
/* Beim Seitenwechsel schliesst die Karte */
watch(
    () => props.journey.active,
    () => (open.value = null),
);

const markClass: Record<JourneyStation['status'], string> = {
    success: '',
    warning: 'bg-warning',
    danger: 'bg-danger',
    waiting: '',
};
const findingTone: Record<JourneyStation['status'], string> = {
    success: 'text-success',
    warning: 'text-warning',
    danger: 'text-danger',
    waiting: 'text-ash-dark',
};
function findingState(status: string): JourneyStation['status'] {
    return status === '✓'
        ? 'success'
        : status === '✗'
          ? 'danger'
          : status === '!'
            ? 'warning'
            : 'waiting';
}
</script>

<template>
    <div ref="root" class="relative w-full">
        <nav
            v-if="isDesktop"
            class="flex w-full items-stretch"
            :aria-label="$t('Fahrplan der Einrichtung')"
        >
            <ol class="flex min-w-0 flex-1 items-start pt-2.5 pb-2">
                <li
                    v-for="s in stations"
                    :key="s.key"
                    class="relative flex min-w-0 flex-1 flex-col items-center before:absolute before:top-[10px] before:-right-1/2 before:left-1/2 before:h-[3px] before:content-['']"
                    :class="[
                        s.isLast ? 'before:hidden' : '',
                        s.segmentWaits
                            ? 'before:bg-ash-light'
                            : 'before:bg-lava',
                    ]"
                    :aria-current="s.isActive ? 'step' : undefined"
                >
                    <button
                        :ref="
                            (el) => (buttons[s.key] = el as HTMLElement | null)
                        "
                        type="button"
                        class="group relative z-[1] flex w-full flex-col items-center gap-1 rounded-sm px-1 text-center focus-visible:ring-2 focus-visible:ring-lava-dark focus-visible:outline-none"
                        :aria-expanded="open === s.key"
                        :aria-controls="`${uid}-${s.key}`"
                        @click.stop="toggle(s)"
                    >
                        <span
                            class="relative flex h-[23px] w-[23px] items-center justify-center rounded-full border-[3px]"
                            :class="
                                s.isActive
                                    ? 'border-lava bg-lava ring-4 ring-lava/20'
                                    : s.status === 'waiting'
                                      ? 'border-dashed border-ash bg-white'
                                      : 'border-basalt bg-white group-hover:ring-4 group-hover:ring-lava/20'
                            "
                        >
                            <span
                                v-if="
                                    s.status === 'warning' ||
                                    s.status === 'danger'
                                "
                                class="absolute -top-1.5 -right-1.5 flex h-3.5 w-3.5 items-center justify-center rounded-full border-[1.5px] border-white text-[9px] leading-none font-bold text-white"
                                :class="markClass[s.status]"
                                aria-hidden="true"
                                >{{ statusMark(s.status) }}</span
                            >
                        </span>
                        <span
                            class="text-[12px] leading-[1.25] text-balance"
                            :class="
                                s.isActive
                                    ? 'font-semibold text-basalt'
                                    : 'text-basalt-light group-hover:text-basalt'
                            "
                            >{{ s.question }}</span
                        >
                        <span
                            class="text-ash-dark text-[10.5px] leading-tight"
                            >{{ s.term }}</span
                        >
                        <span class="sr-only">{{
                            s.status === 'success'
                                ? $t('in Ordnung')
                                : s.status === 'danger'
                                  ? $t('Fehler')
                                  : s.status === 'warning'
                                    ? $t('Warnung')
                                    : $t('wartet')
                        }}</span>
                    </button>
                    <Link
                        v-if="s.key === 'look' && journey.interchange.href"
                        :href="journey.interchange.href"
                        class="relative z-[1] mt-1 inline-flex items-center gap-1 rounded-full border border-ash-light bg-paper px-2 py-px text-[10.5px] text-basalt-light hover:border-basalt hover:text-basalt"
                        :title="$t('Zu den Themes umsteigen')"
                    >
                        <span aria-hidden="true">⇄</span>
                        {{ theme ? theme.name : journey.interchange.label }}
                    </Link>

                    <div
                        v-if="open === s.key"
                        :id="`${uid}-${s.key}`"
                        role="dialog"
                        :aria-labelledby="`${uid}-${s.key}-title`"
                        class="absolute top-full z-30 mt-2 w-[310px] rounded-lg border border-ash-light bg-white p-4 text-left shadow-lg"
                        :class="{
                            'left-0': s.align === 'start',
                            'left-1/2 -translate-x-1/2': s.align === 'center',
                            'right-0': s.align === 'end',
                        }"
                        @click.stop
                    >
                        <p
                            class="text-ash-dark mb-0.5 text-[10.5px] font-semibold tracking-[.08em] uppercase"
                        >
                            {{ s.term }}
                        </p>
                        <h2
                            :id="`${uid}-${s.key}-title`"
                            class="mb-1.5 text-[15px] font-semibold text-basalt"
                        >
                            {{ s.question }}
                        </h2>
                        <p
                            class="mb-3 text-[12.5px] leading-snug text-basalt-light"
                        >
                            {{ s.explain }}
                        </p>
                        <ul
                            v-if="s.findings.length > 0"
                            class="mb-3 space-y-1 border-t border-ash-light pt-2 text-[12px]"
                            :aria-label="$t('Stand')"
                        >
                            <li
                                v-for="f in s.findings"
                                :key="f.letter"
                                class="flex gap-2"
                            >
                                <span
                                    class="w-3 shrink-0 font-bold"
                                    :class="findingTone[findingState(f.status)]"
                                    aria-hidden="true"
                                    >{{
                                        f.status === '–' ? '·' : f.status
                                    }}</span
                                >
                                <span class="min-w-0 break-words">{{
                                    f.summary
                                }}</span>
                            </li>
                        </ul>
                        <div
                            class="flex flex-wrap items-center gap-x-3 gap-y-1.5"
                        >
                            <Link
                                v-if="s.href"
                                :href="s.href"
                                class="inline-flex items-center rounded-md bg-lava-dark px-3 py-1.5 text-[12.5px] font-semibold text-white hover:bg-lava"
                                >{{ s.action }}</Link
                            >
                            <Link
                                v-for="link in s.links"
                                :key="link.href"
                                :href="link.href"
                                class="text-[12.5px] text-basalt underline"
                                >{{ link.label }}</Link
                            >
                        </div>
                        <p
                            v-if="s.key === 'seed'"
                            class="mt-2 text-[12px] text-basalt-light"
                        >
                            {{
                                $t(
                                    'Fehlende Startinhalte spielt «Veröffentlichen» (Station Z) ein — ohne Konsole. In der Konsole:',
                                )
                            }}
                            <code
                                class="text-ash-dark block font-mono text-[11px]"
                                >php artisan basalto:seed &lt;site&gt;</code
                            >
                        </p>
                    </div>
                </li>
            </ol>

            <div
                v-if="journey.branch.href"
                class="relative ml-1 flex w-[124px] shrink-0 items-start pt-2.5 pb-2"
            >
                <span
                    class="absolute top-[20px] left-0 w-6 border-t-[3px] border-dashed border-ash"
                    aria-hidden="true"
                />
                <Link
                    :href="journey.branch.href"
                    class="group relative z-[1] ml-6 flex flex-col items-center gap-1 rounded-sm px-1 text-center focus-visible:ring-2 focus-visible:ring-lava-dark focus-visible:outline-none"
                    :aria-current="journey.branch.active ? 'page' : undefined"
                >
                    <span
                        class="flex h-[23px] w-[23px] items-center justify-center rounded-sm border-[3px] font-mono text-[9px] leading-none font-bold"
                        :class="
                            journey.branch.active
                                ? 'border-lava bg-lava text-white'
                                : 'border-basalt bg-white text-basalt group-hover:ring-4 group-hover:ring-lava/20'
                        "
                        aria-hidden="true"
                        >&lt;/&gt;</span
                    >
                    <span
                        class="text-[12px] leading-[1.25]"
                        :class="
                            journey.branch.active
                                ? 'font-semibold text-basalt'
                                : 'text-basalt-light group-hover:text-basalt'
                        "
                        >{{ journey.branch.question }}</span
                    >
                    <span class="text-ash-dark text-[10.5px] leading-tight">{{
                        $t('Code · Theme-Editor')
                    }}</span>
                </Link>
            </div>
        </nav>

        <details v-else class="w-full py-1.5">
            <summary
                class="flex cursor-pointer list-none items-center gap-2 py-1 text-[12.5px] [&::-webkit-details-marker]:hidden"
            >
                <span
                    class="inline-flex h-4 w-4 shrink-0 rounded-full border-[3px]"
                    :class="
                        current
                            ? 'border-lava bg-lava'
                            : 'border-basalt bg-white'
                    "
                    aria-hidden="true"
                />
                <span class="min-w-0 flex-1 font-semibold text-basalt">{{
                    current ? current.question : $t('Fahrplan der Einrichtung')
                }}</span>
                <span class="text-ash-dark shrink-0 font-mono text-[11px]">{{
                    $t(':done von :total', done)
                }}</span>
                <span aria-hidden="true" class="text-ash-dark">▾</span>
            </summary>
            <ol class="mt-1 space-y-0.5 pb-1">
                <li
                    v-for="s in stations"
                    :key="s.key"
                    class="flex items-start gap-2.5 rounded px-1 py-1.5"
                    :class="s.isActive ? 'bg-paper' : ''"
                >
                    <span
                        class="mt-0.5 inline-flex h-3.5 w-3.5 shrink-0 rounded-full border-[3px]"
                        :class="
                            s.isActive
                                ? 'border-lava bg-lava'
                                : s.status === 'waiting'
                                  ? 'border-dashed border-ash'
                                  : 'border-basalt'
                        "
                        aria-hidden="true"
                    />
                    <span class="min-w-0 flex-1">
                        <Link
                            v-if="s.href"
                            :href="s.href"
                            class="block text-[13px] text-basalt underline-offset-2 hover:underline"
                            >{{ s.question }}</Link
                        >
                        <span v-else class="block text-[13px] text-basalt">{{
                            s.question
                        }}</span>
                        <span class="text-ash-dark block text-[11px]">{{
                            s.term
                        }}</span>
                    </span>
                    <span
                        v-if="s.status === 'warning' || s.status === 'danger'"
                        class="shrink-0 text-[12px] font-bold"
                        :class="findingTone[s.status]"
                        :aria-label="
                            s.status === 'danger' ? $t('Fehler') : $t('Warnung')
                        "
                        >{{ statusMark(s.status) }}</span
                    >
                </li>
                <li v-if="journey.branch.href" class="px-1 pt-1">
                    <Link
                        :href="journey.branch.href"
                        class="text-[12.5px] underline"
                        >{{ $t('Code · Theme-Editor') }}</Link
                    >
                </li>
            </ol>
        </details>
    </div>
</template>
