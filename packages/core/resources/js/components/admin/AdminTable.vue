<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '../Button.vue';
import { controlClasses } from '../control';
import EmptyState from '../EmptyState.vue';
import StateTag from '../StateTag.vue';
import Table from '../Table.vue';
import { keepsTogether, numeric } from './table';
import { tagTone, withQuery } from './types';
import type { AdminTableSection, RunAction } from './types';

/**
 * Eine Liste aus Beschreibung (CR-018, TB 99): Suche, Filter und Seite über die Adresse (der Controller des Plugins
 * filtert), Häkchen mit Sammelaktionen wie in Freigabe und Kommentaren, Knöpfe je Zeile, die erste Spalte öffnet.
 */
const props = defineProps<{ table: AdminTableSection; run: RunAction }>();

const chosen = ref<string[]>([]);
const ids = computed(() => props.table.rows.map((row) => String(row.id)));
const allChosen = computed(
    () =>
        ids.value.length > 0 &&
        ids.value.every((id) => chosen.value.includes(id)),
);
const hasActions = computed(() =>
    props.table.rows.some((row) => row.actions.length > 0),
);
watch(
    () => props.table.rows,
    () => {
        chosen.value = chosen.value.filter((id) => ids.value.includes(id));
    },
);

function toggleAll(on: boolean): void {
    chosen.value = on ? [...ids.value] : [];
}
function toggle(id: string, on: boolean): void {
    chosen.value = on
        ? [...chosen.value, id]
        : chosen.value.filter((x) => x !== id);
}

function navigate(changes: Record<string, string | null>): void {
    router.get(
        window.location.pathname,
        withQuery(window.location.search, changes),
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const term = ref(props.table.search?.value ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;
watch(term, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        if (props.table.search) {
            navigate({ [props.table.search.param]: value.trim(), page: null });
        }
    }, 350);
});

const search = controlClasses({ small: true });
const checkbox = 'h-4 w-4 accent-lava-dark';
</script>

<template>
    <section :aria-label="table.title ?? undefined">
        <h2 v-if="table.title" class="mb-2 text-[14px] font-semibold">
            {{ table.title }}
        </h2>
        <div
            v-if="table.search || table.filters.length > 0"
            class="mb-2 flex flex-wrap items-center gap-2"
        >
            <div
                v-for="f in table.filters"
                :key="f.param"
                class="flex flex-wrap gap-1"
                role="group"
                :aria-label="f.label"
            >
                <Button
                    v-for="o in f.options"
                    :key="o.value"
                    size="sm"
                    :variant="f.value === o.value ? 'dark' : 'secondary'"
                    :aria-pressed="f.value === o.value"
                    @click="navigate({ [f.param]: o.value, page: null })"
                    >{{ o.label
                    }}<template v-if="o.count !== null">
                        · {{ o.count }}</template
                    ></Button
                >
            </div>
            <template v-if="table.search">
                <label class="sr-only" :for="`suche-${table.key}`">{{
                    table.search.placeholder || $t('Suchen')
                }}</label>
                <input
                    :id="`suche-${table.key}`"
                    v-model="term"
                    type="search"
                    :placeholder="`⌕ ${table.search.placeholder || $t('Suchen')}`"
                    :class="[search, 'w-full! sm:ml-auto sm:w-56!']"
                />
            </template>
        </div>

        <div
            v-if="table.bulk.length > 0 && table.rows.length > 0"
            class="mb-2 flex flex-wrap items-center gap-2 text-[12.5px]"
        >
            <label class="flex min-h-6 items-center gap-1.5">
                <input
                    type="checkbox"
                    :class="checkbox"
                    :checked="allChosen"
                    :aria-label="$t('Alle in der Liste wählen')"
                    @change="
                        toggleAll(($event.target as HTMLInputElement).checked)
                    "
                />
                {{ $t(':count ausgewählt:', { count: chosen.length }) }}
            </label>
            <Button
                v-for="b in table.bulk"
                :key="b.key ?? b.label"
                size="sm"
                :variant="b.variant"
                :disabled="chosen.length === 0"
                @click="
                    run(b, { action: b.key, ids: chosen }, () => (chosen = []))
                "
                >{{ b.label }}</Button
            >
        </div>

        <div
            v-if="table.rows.length === 0"
            class="rounded-lg border border-ash-light bg-white"
        >
            <EmptyState
                :title="table.empty.title || $t('Noch nichts da')"
                :text="table.empty.text ?? ''"
                icon="module"
                tone="ash"
            />
        </div>
        <Table v-else>
            <template #head>
                <tr>
                    <th v-if="table.bulk.length > 0" class="px-3 py-2">
                        <span class="sr-only">{{ $t('Auswahl') }}</span>
                    </th>
                    <th
                        v-for="c in table.columns"
                        :key="c.key"
                        class="px-3 py-2"
                        :class="[numeric(c.type) ? 'text-right' : '']"
                    >
                        {{ c.label }}
                    </th>
                    <th v-if="hasActions" class="px-3 py-2">
                        <span class="sr-only">{{ $t('Aktion') }}</span>
                    </th>
                </tr>
            </template>
            <tr v-for="row in table.rows" :key="row.id" class="align-top">
                <td v-if="table.bulk.length > 0" class="px-3 py-2.5">
                    <input
                        type="checkbox"
                        :class="[checkbox, 'mt-0.5']"
                        :checked="chosen.includes(String(row.id))"
                        :aria-label="
                            $t(':name wählen', {
                                name:
                                    row.cells[table.columns[0]?.key ?? '']
                                        ?.text ?? String(row.id),
                            })
                        "
                        @change="
                            toggle(
                                String(row.id),
                                ($event.target as HTMLInputElement).checked,
                            )
                        "
                    />
                </td>
                <td
                    v-for="(c, i) in table.columns"
                    :key="c.key"
                    class="px-3 py-2.5"
                    :class="[
                        numeric(c.type) ? 'text-right tabular-nums' : '',
                        c.type === 'mono' ? 'font-mono text-[12.5px]' : '',
                        c.type === 'date' ? 'md:whitespace-nowrap' : '',
                        keepsTogether(c.type, row.cells[c.key]?.text ?? '')
                            ? 'whitespace-nowrap'
                            : '',
                    ]"
                >
                    <template v-if="row.cells[c.key]">
                        <StateTag
                            v-if="c.type === 'badge'"
                            :tone="tagTone(row.cells[c.key].tone)"
                            >{{ row.cells[c.key].text }}</StateTag
                        >
                        <Link
                            v-else-if="i === 0 && row.url"
                            :href="row.url"
                            class="font-semibold break-words hover:underline"
                            >{{ row.cells[c.key].text }}</Link
                        >
                        <a
                            v-else-if="row.cells[c.key].url"
                            :href="row.cells[c.key].url"
                            class="break-words text-lava-dark hover:underline"
                            >{{ row.cells[c.key].text }}</a
                        >
                        <span v-else class="break-words">{{
                            row.cells[c.key].text
                        }}</span>
                    </template>
                </td>
                <td v-if="hasActions" class="px-3 py-2.5 md:whitespace-nowrap">
                    <span class="flex flex-wrap justify-end gap-1.5">
                        <Button
                            v-for="a in row.actions"
                            :key="a.label"
                            size="sm"
                            :variant="a.variant"
                            @click="run(a)"
                            >{{ a.label }}</Button
                        >
                    </span>
                </td>
            </tr>
            <template v-if="table.pagination" #foot>
                <div
                    class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-[12px] text-basalt-light"
                >
                    <span
                        >{{
                            $t('Seite :page von :last', {
                                page: table.pagination.page,
                                last: table.pagination.last,
                            })
                        }}
                        ·
                        {{
                            $tc(
                                ':count Eintrag|:count Einträge',
                                table.pagination.total,
                            )
                        }}</span
                    >
                    <span class="flex gap-1.5">
                        <Button
                            size="sm"
                            variant="secondary"
                            :disabled="table.pagination.page <= 1"
                            @click="
                                navigate({
                                    page: String(table.pagination.page - 1),
                                })
                            "
                            >‹ {{ $t('zurück') }}</Button
                        >
                        <Button
                            size="sm"
                            variant="secondary"
                            :disabled="
                                table.pagination.page >= table.pagination.last
                            "
                            @click="
                                navigate({
                                    page: String(table.pagination.page + 1),
                                })
                            "
                            >{{ $t('weiter') }} ›</Button
                        >
                    </span>
                </div>
            </template>
        </Table>
    </section>
</template>
