<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useId } from 'vue';
import { t } from '../i18n';
import type { SchemaBlock } from '../types';
import BlockSketch from './BlockSketch.vue';
import { blockGroup, blockHint } from './blockSketch';

/**
 * TB 28: Die Palette der Blöcke — statt einer Knopfreihe ein Katalog mit Schema: links die Gruppen (aus dem Theme,
 * sonst nach den Feldern) mit Zahl, rechts Karten mit Zeichnung, Name und einem Satz. Suche über Name, Satz und
 * Gruppe; Enter fügt den ersten Treffer ein. Gesperrte Blöcke bleiben sichtbar, gestrichelt, mit Grund. Bausteine
 * und Lava-Teile stehen in der Gruppe «Wiederverwendbar». Schmal (Container unter 34rem) stehen die Gruppen als
 * Reihe über den Karten.
 */
type Item = SchemaBlock & { reason: string | null };

const props = withDefaults(
    defineProps<{
        items: Item[];
        offers: 'all' | 'parts' | 'none';
        /** Mit Knopf «Schliessen» (wenn die Liste schon Blöcke hat) */
        closable?: boolean;
        /** Suchfeld beim Öffnen fokussieren */
        autofocus?: boolean;
    }>(),
    { closable: false, autofocus: false },
);

const emit = defineEmits<{
    (e: 'add', block: SchemaBlock): void;
    (e: 'pick', kind: 'snippet' | 'part'): void;
    (e: 'close'): void;
}>();

const REUSE = '__reuse';
const query = ref('');
const group = ref<string>('');
const search = ref<HTMLInputElement | null>(null);
const searchId = `block-suche-${useId()}`;

const enriched = computed(() =>
    props.items.map((b) => ({
        ...b,
        groupLabel: blockGroup(b),
        hintText: blockHint(b),
    })),
);

const reuse = computed(() => {
    const list: { kind: 'snippet' | 'part'; label: string; hint: string }[] =
        [];
    if (props.offers === 'all') {
        list.push({
            kind: 'snippet',
            label: t('Baustein'),
            hint: t(
                'Eine Gruppe von Blöcken, die auf mehreren Seiten gleich steht — zentral änderbar.',
            ),
        });
    }
    if (props.offers !== 'none') {
        list.push({
            kind: 'part',
            label: t('Lava-Teil'),
            hint: t(
                'Ein Teil des Themes mit Einstellungen, z. B. Navigation oder Suche.',
            ),
        });
    }

    return list;
});

/** Gruppen in der Reihenfolge, in der das Theme sie zum ersten Mal nennt; «Wiederverwendbar» zuletzt. */
const groups = computed(() => {
    const out: { key: string; label: string; count: number }[] = [];
    for (const b of enriched.value) {
        const found = out.find((g) => g.key === b.groupLabel);
        if (found) {
            found.count++;
        } else {
            out.push({ key: b.groupLabel, label: b.groupLabel, count: 1 });
        }
    }
    if (reuse.value.length > 0) {
        out.push({
            key: REUSE,
            label: t('Wiederverwendbar'),
            count: reuse.value.length,
        });
    }

    return out;
});

const needle = computed(() => query.value.trim().toLocaleLowerCase());

function matches(...texts: string[]): boolean {
    return (
        needle.value === '' ||
        texts.some((x) => x.toLocaleLowerCase().includes(needle.value))
    );
}

const visible = computed(() =>
    enriched.value.filter(
        (b) =>
            (group.value === '' || group.value === b.groupLabel) &&
            matches(b.label, b.hintText, b.groupLabel, b.name),
    ),
);
const visibleReuse = computed(() =>
    reuse.value.filter(
        (r) =>
            (group.value === '' || group.value === REUSE) &&
            matches(r.label, r.hint, t('Wiederverwendbar')),
    ),
);

/** Ohne Gruppenwahl nach Gruppen gegliedert, mit Zwischentitel. */
const sections = computed(() => {
    const out: { label: string; items: typeof visible.value }[] = [];
    for (const b of visible.value) {
        const last = out.find((s) => s.label === b.groupLabel);
        if (last) {
            last.items.push(b);
        } else {
            out.push({ label: b.groupLabel, items: [b] });
        }
    }

    return out;
});

function addFirst(): void {
    const first = visible.value.find((b) => !b.reason);
    if (first) {
        emit('add', first);
    } else if (visibleReuse.value[0]) {
        emit('pick', visibleReuse.value[0].kind);
    }
}

onMounted(() => {
    if (props.autofocus) {
        void nextTick(() => search.value?.focus());
    }
});

const groupButton = (active: boolean): string =>
    active
        ? 'bg-basalt text-paper'
        : 'text-basalt-light hover:bg-paper hover:text-basalt';
</script>

<template>
    <div
        class="@container rounded-lg border border-ash-light bg-white"
        role="group"
        :aria-label="$t('Block hinzufügen')"
        @keydown.esc="closable && emit('close')"
    >
        <div
            class="flex flex-wrap items-center gap-2 border-b border-ash-light px-3 py-2"
        >
            <span class="text-[13px] font-semibold">{{
                $t('Block hinzufügen')
            }}</span>
            <label class="sr-only" :for="searchId">{{
                $t('Block suchen')
            }}</label>
            <input
                :id="searchId"
                ref="search"
                v-model="query"
                type="search"
                :placeholder="`⌕ ${$t('Block suchen …')}`"
                class="focus:ring-lava-light ml-auto w-44 rounded-md border border-ash bg-white px-2.5 py-1 text-[12.5px] focus:ring-2 focus:outline-none"
                @keydown.enter.prevent="addFirst"
            />
            <button
                v-if="closable"
                type="button"
                class="rounded-md px-2 py-1 text-[12px] text-basalt-light hover:bg-paper hover:text-basalt"
                @click="emit('close')"
            >
                {{ $t('Schliessen') }}
            </button>
        </div>

        <div class="grid @[34rem]:grid-cols-[9.5rem_1fr]">
            <nav
                class="flex flex-wrap gap-1 border-b border-ash-light p-2 @[34rem]:flex-col @[34rem]:flex-nowrap @[34rem]:border-r @[34rem]:border-b-0"
                :aria-label="$t('Gruppen')"
            >
                <button
                    type="button"
                    class="flex items-baseline justify-between gap-2 rounded-md px-2 py-1 text-left text-[12.5px]"
                    :class="groupButton(group === '')"
                    :aria-pressed="group === ''"
                    @click="group = ''"
                >
                    {{ $t('Alle') }}
                    <span class="font-mono text-[10.5px] opacity-70">{{
                        items.length + reuse.length
                    }}</span>
                </button>
                <button
                    v-for="g in groups"
                    :key="g.key"
                    type="button"
                    class="flex items-baseline justify-between gap-2 rounded-md px-2 py-1 text-left text-[12.5px]"
                    :class="[
                        groupButton(group === g.key),
                        g.key === '__reuse' && group !== g.key
                            ? 'text-lava-dark'
                            : '',
                    ]"
                    :aria-pressed="group === g.key"
                    @click="group = group === g.key ? '' : g.key"
                >
                    {{ g.label }}
                    <span class="font-mono text-[10.5px] opacity-70">{{
                        g.count
                    }}</span>
                </button>
            </nav>

            <div class="min-w-0 p-3">
                <p
                    v-if="visible.length === 0 && visibleReuse.length === 0"
                    class="py-6 text-center text-[12.5px] text-basalt-light"
                >
                    {{ $t('Kein Block passt zu «:q».', { q: query }) }}
                </p>
                <section
                    v-for="s in sections"
                    :key="s.label"
                    class="mb-3 last:mb-0"
                    :aria-label="s.label"
                >
                    <h4
                        v-if="group === ''"
                        class="text-ash-dark mb-1.5 font-mono text-[10.5px] tracking-[.08em] uppercase"
                    >
                        {{ s.label }}
                    </h4>
                    <div
                        class="grid grid-cols-[repeat(auto-fill,minmax(8.5rem,1fr))] gap-2"
                    >
                        <button
                            v-for="b in s.items"
                            :key="b.name"
                            type="button"
                            class="group flex flex-col gap-1 rounded-lg border p-1.5 text-left"
                            :class="
                                b.reason
                                    ? 'cursor-not-allowed border-dashed border-ash opacity-60'
                                    : 'border-ash-light hover:border-lava focus-visible:border-lava'
                            "
                            :disabled="Boolean(b.reason)"
                            :aria-disabled="Boolean(b.reason)"
                            :title="
                                b.reason ??
                                $t('Block «:label» hinzufügen', {
                                    label: b.label,
                                })
                            "
                            @click="emit('add', b)"
                        >
                            <span
                                class="rounded-md bg-paper p-1 transition-colors group-hover:bg-lava/5"
                            >
                                <BlockSketch :block="b" />
                            </span>
                            <span class="px-0.5 text-[12.5px] font-semibold">{{
                                b.label
                            }}</span>
                            <span
                                class="line-clamp-2 px-0.5 text-[11px] leading-snug text-basalt-light"
                                >{{ b.reason ?? b.hintText }}</span
                            >
                        </button>
                    </div>
                </section>
                <section
                    v-if="visibleReuse.length > 0"
                    class="mt-3 first:mt-0"
                    :aria-label="$t('Wiederverwendbar')"
                >
                    <h4
                        v-if="group === ''"
                        class="mb-1.5 font-mono text-[10.5px] tracking-[.08em] text-lava-dark uppercase"
                    >
                        {{ $t('Wiederverwendbar') }}
                    </h4>
                    <div
                        class="grid grid-cols-[repeat(auto-fill,minmax(8.5rem,1fr))] gap-2"
                    >
                        <button
                            v-for="r in visibleReuse"
                            :key="r.kind"
                            type="button"
                            class="group flex flex-col gap-1 rounded-lg border border-lava-dark/40 p-1.5 text-left hover:border-lava"
                            @click="emit('pick', r.kind)"
                        >
                            <span class="rounded-md bg-lava/5 p-1">
                                <BlockSketch :special="r.kind" />
                            </span>
                            <span
                                class="px-0.5 text-[12.5px] font-semibold text-lava-dark"
                                >+ {{ r.label }}</span
                            >
                            <span
                                class="line-clamp-2 px-0.5 text-[11px] leading-snug text-basalt-light"
                                >{{ r.hint }}</span
                            >
                        </button>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
