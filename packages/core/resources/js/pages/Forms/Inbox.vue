<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Button from '../../components/Button.vue';
import { controlClasses } from '../../components/control';
import EmptyState from '../../components/EmptyState.vue';
import {
    submissionStatusTone,
    submissionStatusWord,
} from '../../components/forms';
import PageHeader from '../../components/PageHeader.vue';
import StateTag from '../../components/StateTag.vue';
import Table from '../../components/Table.vue';
import { t } from '../../i18n';
import type { BackendProps } from '../../types';
import type {
    InboxPageProps,
    SubmissionRow,
    SubmissionStatus,
} from '../../types-forms';

/**
 * Eingänge eines Formulars (Bereich 16/2): Status-Filter mit Zahlen, Suche, Liste mit Auswahl und Sammelaktionen
 * (gelesen, beantwortet, endgültig löschen), Warnung bei nicht zugestellter Mail, Export CSV mit denselben Filtern.
 * Unter 768 px Karten statt Tabelle — nichts scrollt waagrecht.
 */
const props = defineProps<InboxPageProps>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);
const baseUrl = computed(() => page.url.split('?')[0] ?? page.url);

const statuses = computed<
    { value: '' | SubmissionStatus; label: string; count: number }[]
>(() => [
    { value: '', label: t('Alle'), count: props.counts.all },
    { value: 'new', label: t('neu'), count: props.counts.new },
    { value: 'read', label: t('gelesen'), count: props.counts.read },
    {
        value: 'answered',
        label: t('beantwortet'),
        count: props.counts.answered,
    },
]);

/* ── Filter ── */

const q = ref(props.filters.q);
watch(
    () => props.filters.q,
    (value) => {
        if (document.activeElement?.id !== 'inbox-q') {
            q.value = value;
        }
    },
);

function apply(patch: Partial<InboxPageProps['filters']> = {}): void {
    const next = { ...props.filters, q: q.value.trim(), ...patch };
    const params: Record<string, string> = {};
    if (next.status) {
        params.status = next.status;
    }
    if (next.q) {
        params.q = next.q;
    }
    router.get(baseUrl.value, params, {
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

const filtered = computed(
    () => props.filters.status !== '' || props.filters.q !== '',
);

function reset(): void {
    q.value = '';
    apply({ status: '', q: '' });
}

/** Export mit denselben Filtern wie die Liste. */
const exportUrl = computed(() => {
    const params = new URLSearchParams();
    if (props.filters.status) {
        params.set('status', props.filters.status);
    }
    if (props.filters.q) {
        params.set('q', props.filters.q);
    }
    const query = params.toString();

    return query ? `${props.urls.export}?${query}` : props.urls.export;
});

/* ── Auswahl und Sammelaktionen ── */

const chosen = ref<number[]>([]);
watch(
    () => props.rows,
    (rows) => {
        chosen.value = chosen.value.filter((id) =>
            rows.some((r) => r.id === id),
        );
    },
);

const allChosen = computed(
    () =>
        props.rows.length > 0 &&
        props.rows.every((r) => chosen.value.includes(r.id)),
);

function toggleAll(on: boolean): void {
    chosen.value = on ? props.rows.map((r) => r.id) : [];
}

function toggle(id: number, on: boolean): void {
    chosen.value = on
        ? [...new Set([...chosen.value, id])]
        : chosen.value.filter((x) => x !== id);
}

const confirmingDelete = ref(false);
const busy = ref(false);

function bulk(action: 'read' | 'answered' | 'delete'): void {
    if (chosen.value.length === 0) {
        return;
    }
    router.post(
        props.urls.bulk,
        { action, ids: chosen.value },
        {
            preserveScroll: true,
            onStart: () => {
                busy.value = true;
            },
            onSuccess: () => {
                chosen.value = [];
                confirmingDelete.value = false;
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}

const failedOnPage = computed(
    () => props.rows.filter((r) => r.mail_failed).length,
);

function open(row: SubmissionRow): void {
    router.visit(row.url);
}

const small = controlClasses({ small: true });
const exportClasses =
    'inline-flex items-center justify-center whitespace-nowrap rounded-md border border-ash bg-white px-2.5 py-[5px] text-xs font-semibold leading-tight text-basalt hover:bg-paper';
const checkbox = 'h-3.5 w-3.5 accent-lava-dark';
</script>

<template>
    <Head :title="$t('Eingänge · :form', { form: form.label })" />
    <PageHeader
        :title="$t('Formular «:form» · Eingänge', { form: form.label })"
        :crumb="$t(':site › Formulare', { site: siteName })"
    >
        <template #lead>
            <b class="font-semibold text-basalt">{{
                $tc(':count neuer Eingang|:count neue Eingänge', counts.new)
            }}</b
            ><template v-if="failedOnPage > 0"
                >,
                {{
                    $tc(
                        'einer davon mit nicht zugestellter Mail|:count davon mit nicht zugestellter Mail',
                        failedOnPage,
                    )
                }}</template
            >.
        </template>
        <template #actions>
            <a :href="exportUrl" :class="exportClasses" download>{{
                $t('Export CSV')
            }}</a>
            <Button variant="secondary" size="sm" :href="urls.edit">{{
                form.source === 'theme' ? $t('Ansehen') : $t('Bearbeiten')
            }}</Button>
            <Button variant="link" :href="urls.index">{{
                $t('← Formulare')
            }}</Button>
        </template>
    </PageHeader>

    <nav
        class="flex flex-wrap gap-x-[18px] border-b border-ash-light"
        :aria-label="$t('Status')"
    >
        <button
            v-for="s in statuses"
            :key="s.value"
            type="button"
            class="-mb-px flex items-baseline gap-1.5 border-b-2 pb-[7px] text-[13px]"
            :class="
                s.value === filters.status
                    ? 'border-lava font-semibold text-basalt'
                    : 'border-transparent text-basalt-light hover:text-basalt'
            "
            :aria-current="s.value === filters.status ? 'true' : undefined"
            @click="apply({ status: s.value })"
        >
            {{ s.label }}
            <span class="font-mono text-[11.5px] font-normal">{{
                s.count
            }}</span>
        </button>
    </nav>

    <form
        class="mt-2.5 mb-2.5 flex flex-wrap items-center gap-2 text-[12.5px]"
        role="search"
        :aria-label="$t('Suchen')"
        @submit.prevent="apply()"
    >
        <label class="sr-only" for="inbox-q">{{ $t('Suchen') }}</label>
        <input
            id="inbox-q"
            v-model="q"
            type="search"
            :placeholder="$t('⌕ Suchen in allen Feldern')"
            :class="[small, 'w-full! sm:w-60!']"
            @input="search"
        />
        <Button v-if="filtered" variant="link" size="sm" @click="reset">{{
            $t('Filter zurücksetzen')
        }}</Button>
        <span class="ml-auto text-[11.5px] text-basalt-light">{{
            $tc(':count Treffer|:count Treffer', pagination.total)
        }}</span>
    </form>

    <div
        v-if="rows.length > 0"
        class="mb-2 flex flex-wrap items-center gap-2 text-[12.5px]"
    >
        <label class="flex items-center gap-1.5">
            <input
                type="checkbox"
                :class="checkbox"
                :checked="allChosen"
                :aria-label="$t('Alle auf dieser Seite wählen')"
                @change="toggleAll(($event.target as HTMLInputElement).checked)"
            />
            {{ $t(':count ausgewählt:', { count: chosen.length }) }}
        </label>
        <Button
            variant="secondary"
            size="sm"
            :disabled="chosen.length === 0 || busy"
            @click="bulk('read')"
            >{{ $t('Als gelesen') }}</Button
        >
        <Button
            variant="secondary"
            size="sm"
            :disabled="chosen.length === 0 || busy"
            @click="bulk('answered')"
            >{{ $t('Als beantwortet') }}</Button
        >
        <Button
            v-if="!confirmingDelete"
            variant="danger"
            size="sm"
            :disabled="chosen.length === 0 || busy"
            @click="confirmingDelete = true"
            >{{ $t('Löschen') }}</Button
        >
        <span
            v-else
            class="inline-flex flex-wrap items-center gap-2"
            role="alertdialog"
        >
            <span>{{
                $tc(
                    ':count Eingang samt Dateien endgültig löschen?|:count Eingänge samt Dateien endgültig löschen?',
                    chosen.length,
                )
            }}</span>
            <Button
                variant="danger"
                size="sm"
                :disabled="busy"
                @click="bulk('delete')"
                >{{ $t('Ja, endgültig löschen') }}</Button
            >
            <Button
                variant="secondary"
                size="sm"
                @click="confirmingDelete = false"
                >{{ $t('Nein') }}</Button
            >
        </span>
    </div>

    <div
        v-if="rows.length === 0"
        class="rounded-lg border border-ash-light bg-white"
    >
        <EmptyState
            :title="filtered ? $t('Keine Treffer') : $t('Noch kein Eingang')"
            :text="
                filtered
                    ? $t('Mit diesem Filter gibt es keinen Eingang.')
                    : $t(
                          'Sobald jemand das Formular sendet, steht der Eingang hier.',
                      )
            "
            icon="forms"
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
                    <th class="w-9 px-3 py-2">
                        <span class="sr-only">{{ $t('Auswahl') }}</span>
                    </th>
                    <th class="w-[110px] px-3 py-2">{{ $t('Datum') }}</th>
                    <th class="w-[200px] px-3 py-2">{{ $t('Absender') }}</th>
                    <th class="px-3 py-2">{{ $t('Nachricht') }}</th>
                    <th class="w-[110px] px-3 py-2 text-right">
                        {{ $t('Status') }}
                    </th>
                </tr>
            </template>
            <tr
                v-for="row in rows"
                :key="row.id"
                class="cursor-pointer align-top hover:bg-paper"
                :class="[
                    row.status === 'new' ? 'bg-lava/5' : '',
                    row.mail_failed ? 'bg-danger/5' : '',
                ]"
                @click="open(row)"
            >
                <td class="px-3 py-2.5" @click.stop>
                    <input
                        type="checkbox"
                        :class="checkbox"
                        :checked="chosen.includes(row.id)"
                        :aria-label="
                            $t('Eingang von :sender wählen', {
                                sender: row.sender,
                            })
                        "
                        @change="
                            toggle(
                                row.id,
                                ($event.target as HTMLInputElement).checked,
                            )
                        "
                    />
                </td>
                <td class="px-3 py-2.5 font-mono text-[11px] whitespace-nowrap">
                    {{ row.created_at ?? '—' }}
                </td>
                <td class="px-3 py-2.5 text-[12.5px]">
                    <Link
                        :href="row.url"
                        class="font-semibold break-words hover:underline"
                        :class="row.status === 'new' ? 'text-basalt' : ''"
                        @click.stop
                        >{{ row.sender }}</Link
                    >
                    <p
                        v-if="row.email && row.email !== row.sender"
                        class="text-[11.5px] break-all text-basalt-light"
                    >
                        {{ row.email }}
                    </p>
                </td>
                <td class="px-3 py-2.5 text-[12.5px] break-words">
                    {{ row.excerpt || '—' }}
                    <span
                        v-if="row.files > 0"
                        class="ml-1 text-[11px] whitespace-nowrap text-basalt-light"
                        >·
                        {{
                            $tc(':count Datei|:count Dateien', row.files)
                        }}</span
                    >
                    <p
                        v-if="row.mail_failed"
                        class="text-danger mt-0.5 text-[11.5px] font-semibold"
                    >
                        {{ $t('Mail nicht zugestellt')
                        }}<template v-if="row.mail_error">
                            ({{ row.mail_error }})</template
                        >
                        — {{ $t('im Eingang noch einmal senden') }}
                    </p>
                </td>
                <td class="px-3 py-2.5 text-right">
                    <StateTag :tone="submissionStatusTone(row.status)">{{
                        submissionStatusWord(row.status)
                    }}</StateTag>
                </td>
            </tr>
            <template #foot>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span
                        >{{ $tc(':count Eingang|:count Eingänge', counts.all) }}
                        ·
                        {{
                            $t(
                                'Spam :spam verworfen · Aufbewahrung :days Tage, danach Löschlauf',
                                { spam: form.spam, days: form.retention },
                            )
                        }}</span
                    >
                    <span
                        v-if="pagination.last > 1"
                        class="flex items-center gap-2"
                    >
                        <Button
                            v-if="pagination.prev"
                            variant="secondary"
                            size="sm"
                            :href="pagination.prev"
                            >{{ $t('‹ Neuere') }}</Button
                        >
                        {{
                            $t('Seite :current von :last', {
                                current: pagination.current,
                                last: pagination.last,
                            })
                        }}
                        <Button
                            v-if="pagination.next"
                            variant="secondary"
                            size="sm"
                            :href="pagination.next"
                            >{{ $t('Ältere ›') }}</Button
                        >
                    </span>
                </div>
            </template>
        </Table>

        <div class="rounded-lg border border-ash-light bg-white md:hidden">
            <ul class="divide-y divide-ash-light">
                <li
                    v-for="row in rows"
                    :key="row.id"
                    class="flex gap-2.5 px-3 py-2.5"
                    :class="[
                        row.status === 'new'
                            ? 'shadow-[inset_3px_0_0_var(--color-lava)]'
                            : '',
                        row.mail_failed ? 'bg-danger/5' : '',
                    ]"
                >
                    <input
                        type="checkbox"
                        :class="[checkbox, 'mt-1 shrink-0']"
                        :checked="chosen.includes(row.id)"
                        :aria-label="
                            $t('Eingang von :sender wählen', {
                                sender: row.sender,
                            })
                        "
                        @change="
                            toggle(
                                row.id,
                                ($event.target as HTMLInputElement).checked,
                            )
                        "
                    />
                    <Link :href="row.url" class="block min-w-0 flex-1">
                        <span
                            class="flex flex-wrap items-baseline justify-between gap-x-2"
                        >
                            <strong
                                class="text-[13px] font-semibold break-words"
                                >{{ row.sender }}</strong
                            >
                            <StateTag
                                :tone="submissionStatusTone(row.status)"
                                >{{
                                    submissionStatusWord(row.status)
                                }}</StateTag
                            >
                        </span>
                        <span
                            class="block text-[11px] break-all text-basalt-light"
                            ><span class="font-mono">{{ row.created_at }}</span
                            ><template v-if="row.email">
                                · {{ row.email }}</template
                            ><template v-if="row.files > 0">
                                ·
                                {{
                                    $tc(
                                        ':count Datei|:count Dateien',
                                        row.files,
                                    )
                                }}</template
                            ></span
                        >
                        <span
                            v-if="row.excerpt"
                            class="mt-1 block text-[12.5px] break-words"
                            >{{ row.excerpt }}</span
                        >
                        <span
                            v-if="row.mail_failed"
                            class="text-danger mt-0.5 block text-[11.5px] font-semibold"
                            >{{ $t('Mail nicht zugestellt') }} —
                            {{ $t('im Eingang noch einmal senden') }}</span
                        >
                    </Link>
                </li>
            </ul>
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-t border-ash-light bg-paper px-3 py-2 text-[11.5px] text-basalt-light"
            >
                <span
                    >{{ $tc(':count Eingang|:count Eingänge', counts.all) }} ·
                    {{
                        $t(
                            'Spam :spam verworfen · Aufbewahrung :days Tage, danach Löschlauf',
                            { spam: form.spam, days: form.retention },
                        )
                    }}</span
                >
                <span v-if="pagination.last > 1" class="flex gap-2">
                    <Button
                        v-if="pagination.prev"
                        variant="secondary"
                        size="sm"
                        :href="pagination.prev"
                        >{{ $t('‹ Neuere') }}</Button
                    >
                    <Button
                        v-if="pagination.next"
                        variant="secondary"
                        size="sm"
                        :href="pagination.next"
                        >{{ $t('Ältere ›') }}</Button
                    >
                </span>
            </div>
        </div>
    </template>
</template>
