<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import FieldSketch from './FieldSketch.vue';
import { groupLabel, paletteItems } from './formPalette';
import type { PaletteItem, PaletteKind } from './formPalette';

/**
 * TB 31: Felder hinzufügen wie Blöcke (TB 28) — statt einer Knopfreihe ein Katalog: zuerst «Häufige Felder» mit
 * fertiger Beschriftung (Name, E-Mail, Nachricht …), dann die Typen nach Gruppen, je Karte eine Zeichnung und ein
 * Satz. Suche über Name, Satz und Gruppe; Enter fügt den ersten Treffer ein.
 */
const props = withDefaults(
    defineProps<{
        types: { value: string; label: string }[];
        /** Sprache für die Beschriftung der häufigen Felder (Grundsprache der Site) */
        locale: string;
        /** Häufige Felder anbieten — in einer Gruppe nicht */
        presets?: boolean;
        title?: string;
        /** Formularfelder (TB 31) oder Felder eines Inhaltstyps (TB 32) */
        kind?: PaletteKind;
    }>(),
    { presets: true, title: '', kind: 'form' },
);
const emit = defineEmits<{ (e: 'add', item: PaletteItem): void }>();

const query = ref('');
const group = ref('');
const searchId = `feld-suche-${useId()}`;

const items = computed(() =>
    paletteItems(props.types, props.locale, props.presets, props.kind),
);
const groups = computed(() => {
    const out: { key: string; count: number }[] = [];
    for (const item of items.value) {
        const found = out.find((g) => g.key === item.group);
        if (found) {
            found.count++;
        } else {
            out.push({ key: item.group, count: 1 });
        }
    }

    return out;
});
const needle = computed(() => query.value.trim().toLocaleLowerCase());
const visible = computed(() =>
    items.value.filter(
        (item) =>
            (group.value === '' || group.value === item.group) &&
            (needle.value === '' ||
                [item.label, item.hint, groupLabel(item.group), item.type].some(
                    (x) => x.toLocaleLowerCase().includes(needle.value),
                )),
    ),
);
const sections = computed(() => {
    const out: { key: string; items: PaletteItem[] }[] = [];
    for (const item of visible.value) {
        const found = out.find((s) => s.key === item.group);
        if (found) {
            found.items.push(item);
        } else {
            out.push({ key: item.group, items: [item] });
        }
    }

    return out;
});

function addFirst(): void {
    const first = visible.value[0];
    if (first) {
        emit('add', first);
    }
}

const groupButton = (active: boolean): string =>
    active
        ? 'bg-basalt text-paper'
        : 'text-basalt-light hover:bg-paper hover:text-basalt';
</script>

<template>
    <div
        class="@container rounded-lg border border-ash-light bg-white"
        role="group"
        :aria-label="title || $t('Feld hinzufügen')"
    >
        <div
            class="flex flex-wrap items-center gap-2 border-b border-ash-light px-3 py-2"
        >
            <span class="text-[13px] font-semibold">{{
                title || $t('Feld hinzufügen')
            }}</span>
            <label class="sr-only" :for="searchId">{{
                $t('Feld suchen')
            }}</label>
            <input
                :id="searchId"
                v-model="query"
                type="search"
                :placeholder="`⌕ ${$t('Feld suchen …')}`"
                class="focus:ring-lava-light ml-auto w-40 rounded-md border border-ash bg-white px-2.5 py-1 text-[12.5px] focus:ring-2 focus:outline-none"
                @keydown.enter.prevent="addFirst"
            />
        </div>
        <nav
            class="flex flex-wrap gap-1 border-b border-ash-light p-2"
            :aria-label="$t('Gruppen')"
        >
            <button
                type="button"
                class="flex items-baseline gap-1.5 rounded-md px-2 py-1 text-[12.5px]"
                :class="groupButton(group === '')"
                :aria-pressed="group === ''"
                @click="group = ''"
            >
                {{ $t('Alle') }}
                <span class="font-mono text-[10.5px] opacity-70">{{
                    items.length
                }}</span>
            </button>
            <button
                v-for="g in groups"
                :key="g.key"
                type="button"
                class="flex items-baseline gap-1.5 rounded-md px-2 py-1 text-[12.5px]"
                :class="[
                    groupButton(group === g.key),
                    g.key === 'common' && group !== g.key
                        ? 'text-lava-dark'
                        : '',
                ]"
                :aria-pressed="group === g.key"
                @click="group = group === g.key ? '' : g.key"
            >
                {{ groupLabel(g.key) }}
                <span class="font-mono text-[10.5px] opacity-70">{{
                    g.count
                }}</span>
            </button>
        </nav>
        <div class="scroll-y max-h-[26rem] min-w-0 overflow-y-auto p-3">
            <p
                v-if="visible.length === 0"
                class="py-6 text-center text-[12.5px] text-basalt-light"
            >
                {{ $t('Kein Feld passt zu «:q».', { q: query }) }}
            </p>
            <section
                v-for="s in sections"
                :key="s.key"
                class="mb-3 last:mb-0"
                :aria-label="groupLabel(s.key)"
            >
                <h4
                    v-if="group === ''"
                    class="mb-1.5 font-mono text-[10.5px] tracking-[.08em] uppercase"
                    :class="
                        s.key === 'common' ? 'text-lava-dark' : 'text-ash-dark'
                    "
                >
                    {{ groupLabel(s.key) }}
                </h4>
                <div
                    class="grid grid-cols-[repeat(auto-fill,minmax(7.5rem,1fr))] gap-2"
                >
                    <button
                        v-for="item in s.items"
                        :key="item.key"
                        type="button"
                        class="group flex flex-col gap-1 rounded-lg border p-1.5 text-left hover:border-lava focus-visible:border-lava"
                        :class="
                            item.preset
                                ? 'border-lava-dark/30'
                                : 'border-ash-light'
                        "
                        :title="
                            $t('Feld «:label» hinzufügen', {
                                label: item.label,
                            })
                        "
                        @click="emit('add', item)"
                    >
                        <span
                            class="rounded-md p-1 transition-colors group-hover:bg-lava/5"
                            :class="item.preset ? 'bg-lava/5' : 'bg-paper'"
                        >
                            <FieldSketch :type="item.type" />
                        </span>
                        <span class="px-0.5 text-[12.5px] font-semibold">{{
                            item.label
                        }}</span>
                        <span
                            class="line-clamp-2 px-0.5 text-[11px] leading-snug text-basalt-light"
                            >{{ item.hint }}</span
                        >
                    </button>
                </div>
            </section>
        </div>
    </div>
</template>
