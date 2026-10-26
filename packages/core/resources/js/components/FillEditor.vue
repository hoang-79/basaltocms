<script setup lang="ts">
import { computed, ref } from 'vue';
import { fillGroupLabel, fillOptions, suggestionsFor } from './fillPalette';
import type { FillOption } from './fillPalette';
import { t } from '../i18n';
import type { PartInfo } from '../types';
import Toggle from './Toggle.vue';
import { moveItem, partSummary, wordLabel } from './layoutEditor';
import type { FillItem, FillWord, Sources } from './layoutEditor';

/**
 * Die Füllung einer Region (Mockup 27 Bild 2): Lava-Teile, Navigationen, globale Inhalte und die Blöcke der Seite in
 * Reihenfolge — je Zeile Ein/Aus (ausgeschaltet bleibt die Instanz, ausgegeben wird nichts), ↑ ↓ und Entfernen;
 * darunter «+ Teil» mit allem, was das Theme anbietet. Ein Klick auf einen Teil öffnet seine Einstellungen.
 */
const props = withDefaults(
    defineProps<{
        modelValue: FillItem[];
        parts: PartInfo[];
        sources: Sources;
        editable: boolean;
        /** Für Beschriftungen: «Region Kopf» */
        label: string;
        error?: string;
        /** Was eine leere Region bedeutet — im Layout füllen sie oft die Templates */
        emptyText?: string;
        /** TB 33: Name der Region («header») — für Vorschläge, was hineingehört */
        region?: string;
    }>(),
    {
        error: '',
        region: '',
        emptyText: () =>
            t(
                'Noch kein Teil in dieser Region. Die Region wird gerendert, aber leer.',
            ),
    },
);
const emit = defineEmits<{
    (e: 'update:modelValue', value: FillItem[]): void;
    (e: 'part', name: string): void;
}>();

/* ── TB 33: «+ Teil» als Palette — Vorschläge je Region zuerst, Satz je Eintrag, Suche ── */
const paletteOpen = ref(false);
const query = ref('');
const all = computed(() => fillOptions(props.parts, props.sources));
const suggestions = computed(() =>
    suggestionsFor(props.region, all.value, props.parts, props.modelValue),
);
const visibleGroups = computed(() => {
    const q = query.value.trim().toLocaleLowerCase();
    const match = (o: FillOption): boolean =>
        q === '' ||
        [o.label, o.name ?? '', o.hint, o.item.word].some((x) =>
            x.toLocaleLowerCase().includes(q),
        );
    const groups: { key: FillOption['group']; items: FillOption[] }[] = [];
    if (q === '' && suggestions.value.length > 0) {
        groups.push({ key: 'suggest', items: suggestions.value });
    }
    for (const key of ['part', 'nav', 'global', 'page'] as const) {
        const items = all.value.filter((o) => o.group === key && match(o));
        if (items.length > 0) {
            groups.push({ key, items });
        }
    }

    return groups;
});

function addOption(option: FillOption): void {
    emit('update:modelValue', [...props.modelValue, { ...option.item }]);
    paletteOpen.value = false;
    query.value = '';
}

function update(index: number, item: FillItem | null): void {
    const next = [...props.modelValue];
    if (item === null) {
        next.splice(index, 1);
    } else {
        next[index] = item;
    }
    emit('update:modelValue', next);
}

function title(item: FillItem): string {
    if (item.word === 'part') {
        return (
            props.parts.find((p) => p.name === item.name)?.kind_label ??
            t('Lava-Teil')
        );
    }

    return wordLabel(item.word);
}

function detail(item: FillItem): string {
    if (item.word === 'part') {
        const part = props.parts.find((p) => p.name === item.name);

        return part ? partSummary(part.form) : t('fehlt im Theme');
    }

    return item.word.startsWith('content') ? t('aus dem Editor') : '';
}

const op =
    'inline-flex min-h-6 min-w-6 items-center justify-center rounded px-1 text-[12px] text-basalt-light hover:bg-ash-light hover:text-basalt disabled:cursor-not-allowed disabled:opacity-35';
</script>

<template>
    <div>
        <ul v-if="modelValue.length" class="space-y-1">
            <li
                v-for="(item, i) in modelValue"
                :key="`${i}-${item.word}-${item.name}`"
                class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-md border bg-white px-2 py-1.5"
                :class="
                    item.off
                        ? 'border-dashed border-ash text-basalt-light'
                        : 'border-ash-light'
                "
            >
                <button
                    v-if="item.word === 'part' && item.name"
                    type="button"
                    class="min-w-0 flex-1 text-left"
                    :title="
                        $t('Einstellungen von «:name»', { name: item.name })
                    "
                    @click="emit('part', item.name)"
                >
                    <span
                        class="text-[13px] font-semibold"
                        :class="item.off ? 'line-through' : ''"
                        >{{ title(item) }}</span
                    >
                    <span class="ml-1.5 font-mono text-[11px]">{{
                        item.name
                    }}</span>
                    <span class="ml-1.5 text-[11.5px] text-basalt-light">{{
                        detail(item)
                    }}</span>
                </button>
                <span v-else class="min-w-0 flex-1">
                    <span
                        class="text-[13px] font-semibold"
                        :class="item.off ? 'line-through' : ''"
                        >{{ title(item) }}</span
                    >
                    <span class="ml-1.5 font-mono text-[11px]">{{
                        item.name ?? item.word
                    }}</span>
                    <span class="ml-1.5 text-[11.5px] text-basalt-light">{{
                        detail(item)
                    }}</span>
                </span>
                <span v-if="editable" class="flex items-center gap-0.5">
                    <button
                        type="button"
                        :class="op"
                        :disabled="i === 0"
                        :aria-label="
                            $t(':item nach oben', { item: title(item) })
                        "
                        @click="
                            emit(
                                'update:modelValue',
                                moveItem(modelValue, i, -1),
                            )
                        "
                    >
                        ↑
                    </button>
                    <button
                        type="button"
                        :class="op"
                        :disabled="i === modelValue.length - 1"
                        :aria-label="
                            $t(':item nach unten', { item: title(item) })
                        "
                        @click="
                            emit(
                                'update:modelValue',
                                moveItem(modelValue, i, 1),
                            )
                        "
                    >
                        ↓
                    </button>
                    <Toggle
                        :model-value="!item.off"
                        :label="
                            $t(':item :name ein', {
                                item: title(item),
                                name: item.name ?? '',
                            })
                        "
                        @update:model-value="
                            update(i, { ...item, off: !$event })
                        "
                    />
                    <button
                        type="button"
                        :class="[op, 'hover:text-danger']"
                        :aria-label="
                            $t(':item aus der Region entfernen', {
                                item: title(item),
                            })
                        "
                        @click="update(i, null)"
                    >
                        ✕
                    </button>
                </span>
                <span v-else class="text-[11px] text-basalt-light">{{
                    item.off ? $t('aus') : $t('an')
                }}</span>
            </li>
        </ul>
        <p
            v-else
            class="rounded-md border border-dashed border-ash px-2 py-2 text-[12px] text-basalt-light"
        >
            {{ emptyText }}
        </p>
        <p
            v-if="error"
            class="text-danger mt-1 text-[12px] font-semibold"
            role="alert"
        >
            {{ error }}
        </p>
        <div v-if="editable" class="mt-1.5">
            <div
                v-if="!paletteOpen && suggestions.length > 0"
                class="mb-1.5 flex flex-wrap items-center gap-1.5 text-[11.5px]"
            >
                <span class="text-basalt-light">{{ $t('Passt oft:') }}</span>
                <button
                    v-for="o in suggestions"
                    :key="o.key"
                    type="button"
                    class="rounded-full border border-lava-dark/30 bg-lava/5 px-2 py-0.5 text-lava-dark hover:border-lava"
                    :title="o.hint"
                    @click="addOption(o)"
                >
                    + {{ o.label
                    }}<template v-if="o.name">
                        · <span class="font-mono">{{ o.name }}</span></template
                    >
                </button>
            </div>
            <button
                type="button"
                class="rounded-md border border-ash bg-white px-2.5 py-1 text-xs font-semibold hover:border-lava"
                :aria-expanded="paletteOpen"
                @click="paletteOpen = !paletteOpen"
            >
                {{ paletteOpen ? $t('Schliessen') : $t('+ Teil hinzufügen …') }}
            </button>
            <div
                v-if="paletteOpen"
                class="mt-1.5 rounded-lg border border-ash-light bg-white p-2"
                role="group"
                :aria-label="$t('Teil für :region', { region: label })"
            >
                <label class="sr-only" :for="`add-${label}`">{{
                    $t('Teil suchen')
                }}</label>
                <input
                    :id="`add-${label}`"
                    v-model="query"
                    type="search"
                    :placeholder="`⌕ ${$t('Teil suchen …')}`"
                    class="mb-2 w-full rounded-md border border-ash bg-white px-2.5 py-1 text-[12.5px]"
                    @keydown.enter.prevent="
                        visibleGroups[0]?.items[0] &&
                        addOption(visibleGroups[0].items[0])
                    "
                />
                <p
                    v-if="visibleGroups.length === 0"
                    class="py-3 text-center text-[12px] text-basalt-light"
                >
                    {{ $t('Nichts passt zu «:q».', { q: query }) }}
                </p>
                <section
                    v-for="g in visibleGroups"
                    :key="g.key"
                    class="mb-2 last:mb-0"
                    :aria-label="fillGroupLabel(g.key, region)"
                >
                    <h4
                        class="mb-1 font-mono text-[10.5px] tracking-[.08em] uppercase"
                        :class="
                            g.key === 'suggest'
                                ? 'text-lava-dark'
                                : 'text-ash-dark'
                        "
                    >
                        {{ fillGroupLabel(g.key, region) }}
                    </h4>
                    <div
                        class="grid grid-cols-[repeat(auto-fill,minmax(11rem,1fr))] gap-1.5"
                    >
                        <button
                            v-for="o in g.items"
                            :key="`${g.key}-${o.key}`"
                            type="button"
                            class="rounded-md border px-2 py-1.5 text-left hover:border-lava"
                            :class="
                                g.key === 'suggest'
                                    ? 'border-lava-dark/30 bg-lava/5'
                                    : 'border-ash-light'
                            "
                            @click="addOption(o)"
                        >
                            <span class="block text-[12.5px] font-semibold"
                                >{{ o.label
                                }}<span
                                    v-if="o.name"
                                    class="ml-1 font-mono text-[11px] font-normal text-basalt-light"
                                    >{{ o.name }}</span
                                ></span
                            >
                            <span
                                class="line-clamp-2 block text-[11px] leading-snug text-basalt-light"
                                >{{ o.hint }}</span
                            >
                        </button>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
