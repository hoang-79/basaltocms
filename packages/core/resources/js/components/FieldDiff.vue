<script setup lang="ts">
import { computed } from 'vue';
import { t } from '../i18n';
import type { Change } from '../types-workflow';
import { diffSides, wordDiff, type DiffPart } from './diff';

/**
 * Der Diff zweier Fassungen je Feld (Mockup 13): Feld, alt, neu nebeneinander; bei «geändert» ein Wort-Diff mit
 * gestrichenen und eingefügten Wörtern. Unter 768 px stehen alt und neu untereinander, jede Seite mit ihrem Wort —
 * die Farbe trägt nie allein. Lange Werte brechen um, nichts scrollt waagrecht.
 */
const props = withDefaults(
    defineProps<{
        fields: Change[];
        oldLabel: string;
        newLabel: string;
        unchanged?: number | null;
    }>(),
    { unchanged: null },
);

type Row = {
    change: Change;
    old: DiffPart[] | null;
    new: DiffPart[] | null;
};

const rows = computed<Row[]>(() =>
    props.fields.map((change) => {
        if (change.kind === 'added') {
            return {
                change,
                old: null,
                new: change.new ? [{ kind: 'ins', text: change.new }] : [],
            };
        }
        if (change.kind === 'removed') {
            return {
                change,
                old: change.old ? [{ kind: 'del', text: change.old }] : [],
                new: null,
            };
        }
        const sides = diffSides(wordDiff(change.old, change.new));

        return { change, old: sides.old, new: sides.new };
    }),
);

const kindLabels: Record<Change['kind'], string> = {
    added: t('neu'),
    removed: t('entfernt'),
    changed: t('geändert'),
};

const head =
    'px-2.5 py-1.5 text-[9.5px] font-semibold tracking-[.1em] text-basalt-light uppercase';
</script>

<template>
    <div>
        <div
            v-if="fields.length > 0"
            class="overflow-hidden rounded-lg border border-ash-light bg-white text-[12.5px]"
        >
            <div
                class="hidden border-b border-ash-light md:grid md:grid-cols-[150px_minmax(0,1fr)_minmax(0,1fr)]"
                aria-hidden="true"
            >
                <div :class="head">{{ $t('Feld') }}</div>
                <div :class="head">{{ oldLabel }}</div>
                <div :class="head">{{ newLabel }}</div>
            </div>
            <div
                v-for="row in rows"
                :key="row.change.path"
                class="grid border-b border-ash-light last:border-b-0 md:grid-cols-[150px_minmax(0,1fr)_minmax(0,1fr)]"
            >
                <div class="min-w-0 bg-paper px-2.5 py-1.5">
                    <span class="block font-semibold break-words">{{
                        row.change.label
                    }}</span>
                    <span
                        class="block font-mono text-[10.5px] [overflow-wrap:anywhere] text-basalt-light"
                        >{{ row.change.path }}
                        <span class="whitespace-nowrap"
                            >· {{ kindLabels[row.change.kind] }}</span
                        ></span
                    >
                </div>
                <div
                    class="bg-danger/[.06] min-w-0 px-2.5 py-1.5 break-words whitespace-pre-wrap"
                >
                    <span
                        class="mr-1 text-[9.5px] font-semibold tracking-[.1em] text-basalt-light uppercase md:hidden"
                        >{{ $t('alt') }}</span
                    >
                    <span v-if="row.old === null" class="text-ash-dark">—</span>
                    <template v-else
                        ><template v-for="(p, i) in row.old" :key="i"
                            ><del
                                v-if="p.kind === 'del'"
                                class="text-danger line-through"
                                >{{ p.text }}</del
                            ><template v-else>{{ p.text }}</template></template
                        ></template
                    >
                </div>
                <div
                    class="bg-success/[.07] min-w-0 px-2.5 py-1.5 break-words whitespace-pre-wrap"
                >
                    <span
                        class="mr-1 text-[9.5px] font-semibold tracking-[.1em] text-basalt-light uppercase md:hidden"
                        >{{ $t('neu') }}</span
                    >
                    <span v-if="row.new === null" class="text-ash-dark">—</span>
                    <template v-else
                        ><template v-for="(p, i) in row.new" :key="i"
                            ><ins
                                v-if="p.kind === 'ins'"
                                class="text-success font-semibold no-underline"
                                >{{ p.text }}</ins
                            ><template v-else>{{ p.text }}</template></template
                        ></template
                    >
                </div>
            </div>
        </div>
        <p v-else class="text-[12.5px] text-basalt-light">
            {{ $t('Keine Felder geändert.') }}
        </p>
        <p
            v-if="unchanged !== null && unchanged > 0"
            class="mt-1.5 text-[12px] text-basalt-light"
        >
            {{
                $tc(
                    ':count unverändertes Feld — gleich wie in :old.|:count unveränderte Felder — gleich wie in :old.',
                    unchanged,
                    { old: oldLabel },
                )
            }}
        </p>
    </div>
</template>
