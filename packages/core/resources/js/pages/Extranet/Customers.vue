<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';
import { controlClasses } from '../../components/control';
import EmptyState from '../../components/EmptyState.vue';
import PageHeader from '../../components/PageHeader.vue';
import StateTag from '../../components/StateTag.vue';
import Table from '../../components/Table.vue';
import type { CustomersProps } from '../../types-extranet';

/**
 * Die Kundenliste des Hersteller-Extranets (CR-004 P-11): eine Liste über alle Quellen (SaaS, Lizenzverkauf,
 * Marktplatz) mit Suche und Filter-Chips. Ein Klick auf den Namen öffnet die Kundenakte. Die Suche läuft per GET
 * (`q`, `filter`) — mit Enter sofort, beim Tippen nach kurzer Pause.
 */
const props = defineProps<CustomersProps>();

const q = ref(props.current.q);
const small = controlClasses({ small: true });

function apply(filter: string | null = props.current.filter): void {
    clearTimeout(timer);
    const params: Record<string, string> = {};
    if (q.value.trim() !== '') {
        params.q = q.value.trim();
    }
    if (filter) {
        params.filter = filter;
    }
    router.get(props.urls.index, params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

let timer: ReturnType<typeof setTimeout> | undefined;
function search(): void {
    clearTimeout(timer);
    timer = setTimeout(() => apply(), 350);
}
onBeforeUnmount(() => clearTimeout(timer));

const chips = (active: boolean): string =>
    active
        ? 'border-lava-dark bg-lava/10 font-semibold text-lava-dark'
        : 'border-ash bg-white text-basalt-light hover:text-basalt';
</script>

<template>
    <Head :title="$t('Kunden')" />
    <PageHeader
        :title="$t('Kunden')"
        :crumb="$t('Extranet › Kunden')"
        :lead="$tc(':count Kunde|:count Kunden', rows.length)"
    />

    <form
        class="mb-3 flex flex-wrap items-center gap-2 text-[12.5px]"
        role="search"
        :aria-label="$t('Kunden suchen')"
        @submit.prevent="apply()"
    >
        <label class="sr-only" for="customers-q">{{ $t('Suchen') }}</label>
        <input
            id="customers-q"
            v-model="q"
            type="search"
            :placeholder="`⌕ ${$t('Name, E-Mail oder Firma')}`"
            :class="[small, 'w-full! sm:w-64!']"
            @input="search"
        />
        <div
            class="flex flex-wrap items-center gap-1.5"
            role="group"
            :aria-label="$t('Filter')"
        >
            <button
                type="button"
                class="rounded-full border px-2.5 py-0.5 text-[11.5px]"
                :class="chips(current.filter === null)"
                :aria-pressed="current.filter === null"
                @click="apply(null)"
            >
                {{ $t('Alle') }}
            </button>
            <button
                v-for="f in filters"
                :key="f.value"
                type="button"
                class="rounded-full border px-2.5 py-0.5 text-[11.5px]"
                :class="chips(current.filter === f.value)"
                :aria-pressed="current.filter === f.value"
                @click="apply(f.value)"
            >
                {{ f.label }}
            </button>
        </div>
    </form>

    <div
        v-if="rows.length === 0"
        class="rounded-lg border border-ash-light bg-white"
    >
        <EmptyState
            :title="
                current.q || current.filter
                    ? $t('Keine Kunden gefunden')
                    : $t('Noch keine Kunden')
            "
            :text="
                current.q || current.filter
                    ? $t('Suche oder Filter ändern, um mehr zu sehen.')
                    : $t(
                          'Kunden erscheinen hier, sobald jemand ein Konto anlegt, eine Lizenz kauft oder im Marktplatz bestellt.',
                      )
            "
            icon="users"
            tone="ash"
        />
    </div>
    <Table v-else>
        <template #head>
            <tr>
                <th class="px-3 py-2">{{ $t('Name') }}</th>
                <th class="px-3 py-2">{{ $t('E-Mail') }}</th>
                <th class="px-3 py-2">{{ $t('Art') }}</th>
                <th class="px-3 py-2">{{ $t('Details') }}</th>
                <th class="px-3 py-2">{{ $t('Merkmale') }}</th>
                <th class="px-3 py-2">{{ $t('seit') }}</th>
            </tr>
        </template>
        <tr
            v-for="row in rows"
            :key="`${row.source}-${row.id}`"
            class="align-top hover:bg-paper/60"
        >
            <td class="px-3 py-2.5">
                <Link
                    :href="row.url"
                    class="text-[13.5px] font-semibold break-words hover:text-lava-dark"
                    >{{ row.name }}</Link
                >
                <p
                    v-if="row.company"
                    class="text-[11.5px] break-words text-basalt-light"
                >
                    {{ row.company }}
                </p>
            </td>
            <td
                :data-label="$t('E-Mail')"
                class="px-3 py-2.5 text-[12.5px] break-all"
            >
                {{ row.email }}
            </td>
            <td :data-label="$t('Art')" class="px-3 py-2.5 text-[12.5px]">
                {{ row.source_label }}
            </td>
            <td
                :data-label="$t('Details')"
                class="px-3 py-2.5 text-xs text-basalt-light"
            >
                {{ row.meta || '—' }}
            </td>
            <td :data-label="$t('Merkmale')" class="px-3 py-2.5">
                <span v-if="row.tags.length > 0" class="flex flex-wrap gap-1">
                    <StateTag v-for="tag in row.tags" :key="tag">{{
                        tag
                    }}</StateTag>
                </span>
                <span v-else class="text-ash-dark text-xs">—</span>
            </td>
            <td
                :data-label="$t('seit')"
                class="px-3 py-2.5 text-xs md:whitespace-nowrap"
            >
                {{ row.created ?? '—' }}
            </td>
        </tr>
    </Table>
</template>
