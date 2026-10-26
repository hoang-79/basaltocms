<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { subjectTitle } from '../../components/admin';
import Button from '../../components/Button.vue';
import { controlClasses } from '../../components/control';
import EmptyState from '../../components/EmptyState.vue';
import PageHeader from '../../components/PageHeader.vue';
import Table from '../../components/Table.vue';
import { t, tc } from '../../i18n';
import { intlLocale } from '../../support';
import type { BackendProps } from '../../types';
import type {
    ActivityFilters,
    ActivityPageProps,
    ActivityRow,
} from '../../types-admin';

/**
 * Das Protokoll (K-15, Mockup 18/4): wer, wann, was, Objekt, alt → neu; Filter nach Art (mit Zahl), Person,
 * Zeitraum und Suche, ein Inhaltsfilter als entfernbare Marke; Export CSV mit denselben Filtern.
 */
const props = defineProps<ActivityPageProps>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);

const empty: ActivityFilters = {
    kind: '',
    user: '',
    days: 30,
    q: '',
    subject: '',
};
const filters = computed<ActivityFilters>(() => ({
    ...empty,
    ...props.filters,
}));
const entries = computed<ActivityRow[]>(() => props.entries ?? []);
const total = computed(() => props.total ?? entries.value.length);

const kinds = computed(() => {
    const list = props.kinds ?? [];

    return list.some((k) => k.value === '')
        ? list
        : [{ value: '', label: t('Alle'), count: total.value }, ...list];
});

const user = ref(filters.value.user);
const days = ref(filters.value.days);
const q = ref(filters.value.q);
watch(filters, (f) => {
    user.value = f.user;
    days.value = f.days;
    if (document.activeElement?.id !== 'activity-q') {
        q.value = f.q;
    }
});

const periods = computed(() =>
    [7, 30, 90, 365].includes(filters.value.days)
        ? [7, 30, 90, 365]
        : [7, 30, 90, 365, filters.value.days].sort((a, b) => a - b),
);

function periodLabel(d: number): string {
    return d >= 3650 ? t('gesamt') : tc(':count Tag|:count Tage', d);
}

function apply(patch: Partial<ActivityFilters> = {}): void {
    const next: ActivityFilters = {
        ...filters.value,
        user: user.value,
        days: Number(days.value),
        q: q.value.trim(),
        ...patch,
    };
    const params: Record<string, string | number> = {};
    for (const [key, value] of Object.entries(next)) {
        if (value !== '' && value !== null) {
            params[key] = value;
        }
    }
    router.get(props.urls?.index ?? '', params, {
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

const subjectLabel = computed(() => {
    const subject = filters.value.subject;
    if (!subject) {
        return '';
    }

    const first = entries.value.find((e) => e.subject)?.subject;

    return first ? subjectTitle(first) : subject;
});

const filtered = computed(
    () =>
        filters.value.kind !== '' ||
        filters.value.user !== '' ||
        filters.value.q !== '' ||
        filters.value.subject !== '',
);

function reset(): void {
    user.value = '';
    q.value = '';
    apply({ kind: '', user: '', q: '', subject: '' });
}

const small = controlClasses({ small: true });
const exportClasses =
    'inline-flex items-center justify-center whitespace-nowrap rounded-md border border-ash bg-white px-[13px] py-2 text-[13px] font-semibold leading-tight text-basalt hover:bg-paper';
</script>

<template>
    <Head :title="$t('Protokoll')" />
    <PageHeader
        :title="$t('Protokoll')"
        :crumb="$t(':site › Verwaltung', { site: siteName })"
        :lead="
            $t(
                'Jede Änderung mit wer, wann, was, alt → neu. Aufbewahrung :days Tage (Einstellung mit Verlauf).',
                { days: retention_days ?? 365 },
            )
        "
    >
        <template v-if="urls?.export" #actions>
            <a :href="urls.export" :class="exportClasses" download>{{
                $t('Export CSV')
            }}</a>
        </template>
    </PageHeader>

    <nav
        class="flex flex-wrap gap-x-[18px] border-b border-ash-light"
        :aria-label="$t('Arten')"
    >
        <button
            v-for="k in kinds"
            :key="k.value"
            type="button"
            class="-mb-px flex items-baseline gap-1.5 border-b-2 pb-[7px] text-[13px]"
            :class="
                k.value === filters.kind
                    ? 'border-lava font-semibold text-basalt'
                    : 'border-transparent text-basalt-light hover:text-basalt'
            "
            :aria-current="k.value === filters.kind ? 'true' : undefined"
            @click="apply({ kind: k.value as ActivityFilters['kind'] })"
        >
            {{ k.label }}
            <span class="font-mono text-[11.5px] font-normal">{{
                k.count.toLocaleString(intlLocale())
            }}</span>
        </button>
    </nav>

    <form
        class="mt-2.5 mb-3 flex flex-wrap items-center gap-2 text-[12.5px]"
        :aria-label="$t('Filter')"
        role="search"
        @submit.prevent="apply()"
    >
        <label class="sr-only" for="activity-user">{{ $t('Person') }}</label>
        <select
            id="activity-user"
            v-model="user"
            :class="[small, 'w-auto!']"
            @change="apply()"
        >
            <option value="">{{ $t('Person: alle') }}</option>
            <option v-for="p in people ?? []" :key="p.id" :value="String(p.id)">
                {{ p.name }}
            </option>
        </select>
        <label class="sr-only" for="activity-days">{{ $t('Zeitraum') }}</label>
        <select
            id="activity-days"
            v-model.number="days"
            :class="[small, 'w-auto!']"
            @change="apply()"
        >
            <option v-for="d in periods" :key="d" :value="d">
                {{ periodLabel(d) }}
            </option>
        </select>
        <label class="sr-only" for="activity-q">{{ $t('Suchen') }}</label>
        <input
            id="activity-q"
            v-model="q"
            type="search"
            :placeholder="`⌕ ${$t('Suchen')}`"
            :class="[small, 'w-44!']"
            @input="search"
        />
        <span
            v-if="filters.subject"
            class="inline-flex items-center gap-1 rounded-full border border-lava-dark bg-lava/10 py-px pr-1 pl-2 text-[11.5px] font-semibold text-lava-dark"
        >
            {{ $t('nur: :subject', { subject: subjectLabel }) }}
            <button
                type="button"
                class="rounded-full px-1 hover:bg-lava/15"
                :aria-label="$t('Inhaltsfilter entfernen')"
                @click="
                    apply({
                        subject: '',
                        days: filters.days >= 3650 ? 30 : filters.days,
                    })
                "
            >
                ✕
            </button>
        </span>
        <Button v-if="filtered" variant="link" size="sm" @click="reset">{{
            $t('Filter zurücksetzen')
        }}</Button>
        <span class="ml-auto text-[11.5px] text-basalt-light">{{
            $tc(':count Eintrag|:count Einträge', total)
        }}</span>
    </form>

    <div
        v-if="entries.length === 0"
        class="rounded-lg border border-ash-light bg-white"
    >
        <EmptyState
            :title="filtered ? $t('Keine Treffer') : $t('Noch kein Eintrag')"
            :text="
                filtered
                    ? $t(
                          'Mit diesen Filtern gibt es im Zeitraum keinen Eintrag.',
                      )
                    : $t('Die erste Änderung erscheint hier.')
            "
            icon="activity"
            tone="ash"
        >
            <Button v-if="filtered" variant="link" size="sm" @click="reset">{{
                $t('Filter zurücksetzen')
            }}</Button>
        </EmptyState>
    </div>

    <template v-else>
        <Table class="max-md:hidden">
            <template #head>
                <tr>
                    <th class="w-[110px] px-3 py-2">{{ $t('Zeit') }}</th>
                    <th class="w-[130px] px-3 py-2">{{ $t('Wer') }}</th>
                    <th class="w-[170px] px-3 py-2">{{ $t('Was') }}</th>
                    <th class="px-3 py-2">{{ $t('Objekt') }}</th>
                    <th class="w-[240px] px-3 py-2">{{ $t('alt → neu') }}</th>
                </tr>
            </template>
            <tr v-for="entry in entries" :key="entry.id" class="align-top">
                <td class="px-3 py-2.5 font-mono text-[11px]">
                    {{ entry.at ?? '—' }}
                </td>
                <td class="px-3 py-2.5 text-[12.5px]">
                    {{ entry.user ?? $t('System') }}
                </td>
                <td
                    class="px-3 py-2.5 text-[12.5px] font-semibold"
                    :title="entry.action"
                >
                    {{ entry.action_label || entry.action }}
                </td>
                <td class="px-3 py-2.5 text-[12.5px] break-words">
                    <Link
                        v-if="entry.subject_url"
                        :href="entry.subject_url"
                        class="text-lava-dark underline hover:text-basalt"
                        >{{ entry.subject ?? $t('öffnen') }}</Link
                    >
                    <template v-else>{{ entry.subject ?? '—' }}</template>
                </td>
                <td
                    class="px-3 py-2.5 font-mono text-[10.5px] break-words text-basalt-light"
                >
                    {{ entry.change ?? '—' }}
                </td>
            </tr>
            <template #foot>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span
                        >{{ $tc(':count Eintrag|:count Einträge', total) }} ·
                        {{
                            $t(
                                'Ein gelöschter Benutzer erscheint anonymisiert, nie entfernt.',
                            )
                        }}</span
                    >
                    <span class="flex gap-2">
                        <Button
                            v-if="pagination?.prev"
                            variant="secondary"
                            size="sm"
                            :href="pagination.prev"
                            >‹ {{ $t('Neuere') }}</Button
                        >
                        <Button
                            v-if="pagination?.next"
                            variant="secondary"
                            size="sm"
                            :href="pagination.next"
                            >{{ $t('Ältere') }} ›</Button
                        >
                    </span>
                </div>
            </template>
        </Table>

        <div class="rounded-lg border border-ash-light bg-white md:hidden">
            <ul class="divide-y divide-ash-light px-3">
                <li v-for="entry in entries" :key="entry.id" class="py-2">
                    <p class="text-[11px] text-basalt-light">
                        <span class="font-mono">{{ entry.at ?? '—' }}</span> ·
                        {{ entry.user ?? $t('System') }}
                    </p>
                    <p class="text-[13px] break-words">
                        <strong class="font-semibold">{{
                            entry.action_label || entry.action
                        }}</strong>
                        <template v-if="entry.subject">
                            ·
                            <Link
                                v-if="entry.subject_url"
                                :href="entry.subject_url"
                                class="text-lava-dark underline"
                                >{{ entry.subject }}</Link
                            >
                            <template v-else>{{ entry.subject }}</template>
                        </template>
                    </p>
                    <p
                        v-if="entry.change"
                        class="font-mono text-[10.5px] break-words text-basalt-light"
                    >
                        {{ entry.change }}
                    </p>
                </li>
            </ul>
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-t border-ash-light bg-paper px-3 py-2 text-[11.5px] text-basalt-light"
            >
                <span>{{ $tc(':count Eintrag|:count Einträge', total) }}</span>
                <span class="flex gap-2">
                    <Button
                        v-if="pagination?.prev"
                        variant="secondary"
                        size="sm"
                        :href="pagination.prev"
                        >‹ {{ $t('Neuere') }}</Button
                    >
                    <Button
                        v-if="pagination?.next"
                        variant="secondary"
                        size="sm"
                        :href="pagination.next"
                        >{{ $t('Ältere') }} ›</Button
                    >
                </span>
            </div>
        </div>
    </template>
</template>
