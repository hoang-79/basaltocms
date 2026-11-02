<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '../../components/Button.vue';
import { controlClasses } from '../../components/control';
import EmptyState from '../../components/EmptyState.vue';
import Field from '../../components/Field.vue';
import Modal from '../../components/Modal.vue';
import Notice from '../../components/Notice.vue';
import PageHeader from '../../components/PageHeader.vue';
import StatusHex from '../../components/StatusHex.vue';
import Table from '../../components/Table.vue';
import { t, tc } from '../../i18n';
import { siteTimezoneHelp, tomorrowIn, versionStatus } from '../../support';
import type { BackendProps } from '../../types';
import type {
    ReviewCounts,
    ReviewFilter,
    ReviewFilters,
    ReviewRow,
} from '../../types-workflow';

/**
 * Die Station «Freigabe» (Modus Inhalte): alle Sprachfassungen in Prüfung, freigegeben oder geplant — Titel und Art,
 * Sprache, Fassung, Status, wer wann eingereicht hat bzw. wann geplant ist, und der Weg zur Freigabe. Eigene Fassungen
 * tragen die Marke «eigene» (Vier-Augen-Prinzip). Filter als Knopfgruppe mit Zahl. TB 95: mehrere Fassungen auf
 * einmal freigeben, veröffentlichen, planen oder zurückweisen — was nicht geht, steht danach mit Grund oben.
 */
const props = defineProps<{
    rows: ReviewRow[];
    counts: ReviewCounts;
    filters: ReviewFilters;
    site?: { timezone: string };
    skipped?: string[];
    urls: { index: string; bulk?: string | null };
}>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);

const counts = computed<ReviewCounts>(
    () => props.counts ?? { in_review: 0, approved: 0, scheduled: 0 },
);
const active = computed<ReviewFilter>(() => props.filters?.status ?? '');
const rows = computed(() => props.rows ?? []);

const lead = computed(() => {
    const c = counts.value;

    return [
        tc(':count wartet auf Prüfung|:count warten auf Prüfung', c.in_review),
        t(':count freigegeben', { count: c.approved }),
        t(':count geplant', { count: c.scheduled }),
    ].join(' · ');
});

const filterOptions = computed<
    { value: ReviewFilter; label: string; count: number | null }[]
>(() => [
    { value: '', label: t('Alle'), count: null },
    {
        value: 'in_review',
        label: t('In Prüfung'),
        count: counts.value.in_review,
    },
    {
        value: 'approved',
        label: t('Freigegeben'),
        count: counts.value.approved,
    },
    { value: 'scheduled', label: t('Geplant'), count: counts.value.scheduled },
]);

function filter(value: ReviewFilter): void {
    router.get(props.urls?.index ?? page.url, value ? { status: value } : {}, {
        preserveState: true,
        replace: true,
    });
}

/* ── Auswahl und Sammelaktionen (TB 95) ── */

type BulkAction = 'approve' | 'publish' | 'schedule' | 'reject';

const chosen = ref<number[]>([]);
watch(
    () => props.rows,
    (list) => {
        chosen.value = chosen.value.filter((id) =>
            (list ?? []).some((r) => r.version_id === id),
        );
    },
);
const allChosen = computed(
    () =>
        rows.value.length > 0 &&
        rows.value.every((r) => chosen.value.includes(r.version_id)),
);
const chosenRows = computed(() =>
    rows.value.filter((r) => chosen.value.includes(r.version_id)),
);
const inReviewIds = computed(() =>
    chosenRows.value
        .filter((r) => r.status === 'in_review')
        .map((r) => r.version_id),
);
const inReviewChosen = computed(() => inReviewIds.value.length);
/** Sind Fassungen in Prüfung dabei, gibt «Veröffentlichen» sie zuerst frei — der Knopf sagt es. */
const publishLabel = computed(() =>
    inReviewChosen.value > 0
        ? t('Freigeben und veröffentlichen')
        : t('Veröffentlichen'),
);

function toggleAll(on: boolean): void {
    chosen.value = on ? rows.value.map((r) => r.version_id) : [];
}

function toggle(id: number, on: boolean): void {
    chosen.value = on
        ? [...new Set([...chosen.value, id])]
        : chosen.value.filter((x) => x !== id);
}

const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const skipped = computed(() => props.skipped ?? []);
const busy = ref(false);
const rejecting = ref(false);
const comment = ref('');
const scheduling = ref(false);
const timezone = computed(() => props.site?.timezone ?? 'UTC');
const scheduleDate = ref(tomorrowIn(timezone.value));
const scheduleTime = ref('08:00');

function bulk(action: BulkAction, extra: Record<string, string> = {}): void {
    if (chosen.value.length === 0 || !props.urls?.bulk) {
        return;
    }
    router.post(
        props.urls.bulk,
        {
            action,
            /** Freigeben und Zurückweisen gelten nur für Fassungen in Prüfung — die übrigen bleiben, wie sie sind. */
            versions:
                action === 'approve' || action === 'reject'
                    ? inReviewIds.value
                    : chosen.value,
            ...extra,
        },
        {
            preserveScroll: true,
            onStart: () => {
                busy.value = true;
            },
            onFinish: () => {
                busy.value = false;
            },
            onSuccess: () => {
                if (Object.keys(errors.value).length > 0) {
                    return;
                }
                chosen.value = [];
                rejecting.value = false;
                scheduling.value = false;
                comment.value = '';
            },
        },
    );
}

const checkbox = 'h-4 w-4 accent-lava-dark';

/** «Prüfen» nur für fremde Fassungen in Prüfung; alles andere ansehen. */
function actionOf(row: ReviewRow): { label: string; primary: boolean } {
    return row.status === 'in_review' && !row.own
        ? { label: t('Prüfen'), primary: true }
        : { label: t('Ansehen'), primary: false };
}
</script>

<template>
    <Head :title="$t('Freigabe')" />
    <PageHeader
        :title="$t('Freigabe')"
        :crumb="$t(':site › Inhalte', { site: siteName })"
        :lead="lead"
    />

    <div
        class="mb-2.5 flex flex-wrap gap-1.5"
        role="group"
        :aria-label="$t('Filter nach Status')"
    >
        <button
            v-for="o in filterOptions"
            :key="o.value"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-[5px] text-xs font-semibold transition-colors"
            :class="
                active === o.value
                    ? 'border-basalt bg-basalt text-paper'
                    : 'border-ash-light bg-white text-basalt-light hover:border-ash hover:text-basalt'
            "
            :aria-pressed="active === o.value"
            @click="filter(o.value)"
        >
            {{ o.label }}
            <span
                v-if="o.count !== null"
                class="font-mono text-[10.5px] font-normal"
                >{{ o.count }}</span
            >
        </button>
    </div>

    <Notice
        v-if="skipped.length > 0"
        tone="warning"
        class="mb-3"
        :title="
            $tc(
                ':count Fassung übersprungen|:count Fassungen übersprungen',
                skipped.length,
            )
        "
    >
        <ul class="list-disc pl-4 text-[12.5px]">
            <li v-for="(reason, i) in skipped" :key="i">{{ reason }}</li>
        </ul>
    </Notice>

    <div
        v-if="rows.length > 0 && urls.bulk"
        class="mb-2 flex flex-wrap items-center gap-2 text-[12.5px]"
    >
        <label class="flex min-h-6 items-center gap-1.5">
            <input
                type="checkbox"
                :class="checkbox"
                :checked="allChosen"
                :aria-label="$t('Alle in der Liste wählen')"
                @change="toggleAll(($event.target as HTMLInputElement).checked)"
            />
            {{ $t(':count ausgewählt:', { count: chosen.length }) }}
        </label>
        <Button
            variant="secondary"
            size="sm"
            :disabled="inReviewChosen === 0 || busy"
            @click="bulk('approve')"
            >{{ $t('Freigeben') }}</Button
        >
        <Button
            size="sm"
            :disabled="chosen.length === 0 || busy"
            @click="bulk('publish')"
            >{{ publishLabel }}</Button
        >
        <Button
            variant="secondary"
            size="sm"
            :disabled="chosen.length === 0 || busy"
            @click="scheduling = true"
            >{{ $t('Planen …') }}</Button
        >
        <Button
            variant="secondary"
            size="sm"
            :disabled="inReviewChosen === 0 || busy"
            @click="rejecting = true"
            >{{ $t('Zurückweisen …') }}</Button
        >
    </div>

    <div
        v-if="rows.length === 0"
        class="rounded-lg border border-ash-light bg-white"
    >
        <EmptyState
            :title="
                active
                    ? $t('Nichts in diesem Filter')
                    : $t('Nichts wartet auf Freigabe')
            "
            :text="
                active
                    ? $t('Andere Stufen zeigen «Alle».')
                    : $t(
                          'Eingereichte Fassungen erscheinen hier, bis sie veröffentlicht sind.',
                      )
            "
        >
            <Button
                v-if="active"
                variant="secondary"
                size="sm"
                @click="filter('')"
                >{{ $t('Alle zeigen') }}</Button
            >
        </EmptyState>
    </div>
    <Table v-else>
        <template #head>
            <tr>
                <th v-if="urls.bulk" class="px-3 py-2">
                    <span class="sr-only">{{ $t('Auswahl') }}</span>
                </th>
                <th class="w-full px-3 py-2">{{ $t('Titel · Art') }}</th>
                <th class="px-3 py-2">{{ $t('Sprache') }}</th>
                <th class="px-3 py-2">{{ $t('Fassung') }}</th>
                <th class="px-3 py-2">{{ $t('Status') }}</th>
                <th class="px-3 py-2 md:whitespace-nowrap">
                    {{ $t('Eingereicht · geplant') }}
                </th>
                <th class="px-3 py-2">
                    <span class="sr-only">{{ $t('Aktion') }}</span>
                </th>
            </tr>
        </template>
        <tr v-for="row in rows" :key="row.version_id" class="align-top">
            <td v-if="urls.bulk" class="px-3 py-2.5">
                <input
                    type="checkbox"
                    :class="[checkbox, 'mt-0.5']"
                    :checked="chosen.includes(row.version_id)"
                    :aria-label="
                        $t('«:title» (:locale) wählen', {
                            title: row.title,
                            locale: row.locale.toUpperCase(),
                        })
                    "
                    @change="
                        toggle(
                            row.version_id,
                            ($event.target as HTMLInputElement).checked,
                        )
                    "
                />
            </td>
            <td class="px-3 py-2.5">
                <Link
                    :href="row.url"
                    class="text-[13.5px] font-semibold break-words hover:underline"
                    >{{ row.title }}</Link
                >
                <span
                    v-if="row.own"
                    class="ml-1.5 rounded border border-ash-light bg-paper px-[5px] py-px text-[10px] text-basalt-light"
                    :title="$t('Sie haben diese Fassung erstellt')"
                    >{{ $t('eigene') }}</span
                >
                <p class="text-[11.5px] text-basalt-light">
                    {{ row.type_label }}
                </p>
            </td>
            <td
                :data-label="$t('Sprache')"
                class="px-3 py-2.5 font-mono text-[12px]"
            >
                {{ row.locale.toUpperCase() }}
            </td>
            <td
                :data-label="$t('Fassung')"
                class="px-3 py-2.5 text-[12.5px] md:whitespace-nowrap"
            >
                {{ $t('Nr. :number', { number: row.number }) }}
            </td>
            <td
                :data-label="$t('Status')"
                class="px-3 py-2.5 md:whitespace-nowrap"
            >
                <StatusHex
                    :status="versionStatus(row.status)"
                    :label="row.status_label"
                />
            </td>
            <td
                :data-label="$t('Eingereicht · geplant')"
                class="px-3 py-2.5 text-[12px] md:whitespace-nowrap"
            >
                <template v-if="row.status === 'scheduled'">
                    <span class="block text-basalt-light">{{
                        $t('geplant für')
                    }}</span>
                    <span class="block">{{ row.scheduled_for ?? '—' }}</span>
                </template>
                <template v-else>
                    <span class="block text-basalt-light">{{
                        row.author ?? '—'
                    }}</span>
                    <span
                        v-if="row.at"
                        class="text-ash-dark block text-[11px]"
                        >{{ row.at }}</span
                    >
                </template>
            </td>
            <td class="px-3 py-2.5 md:text-right">
                <Button
                    :href="row.url"
                    size="sm"
                    :variant="actionOf(row).primary ? 'primary' : 'secondary'"
                    >{{ actionOf(row).label }}</Button
                >
            </td>
        </tr>
        <template #foot>
            {{ $tc(':count Fassung|:count Fassungen', rows.length) }} ·
            {{
                $t(
                    '«Prüfen» (fremde Fassungen in Prüfung) bzw. «Ansehen» öffnet die Freigabe mit Diff gegen die live Fassung.',
                )
            }}
        </template>
    </Table>

    <Modal
        :open="rejecting"
        :title="
            $tc(
                ':count Fassung zurückweisen|:count Fassungen zurückweisen',
                inReviewChosen,
            )
        "
        :subtitle="
            $t('Nur Fassungen in Prüfung — die übrigen bleiben, wie sie sind.')
        "
        :wide="false"
        @close="rejecting = false"
    >
        <Field
            v-model="comment"
            type="textarea"
            :label="$t('Kommentar')"
            required
            :rows="4"
            :help="$t('Steht danach an jedem Entwurf.')"
            :error="errors.comment"
        />
        <div class="mt-4 flex gap-3">
            <Button
                variant="danger"
                :disabled="comment.trim() === '' || busy"
                @click="bulk('reject', { comment: comment.trim() })"
                >{{ $t('Zurückweisen') }}</Button
            >
            <Button variant="secondary" @click="rejecting = false">{{
                $t('Abbrechen')
            }}</Button>
        </div>
    </Modal>

    <Modal
        :open="scheduling"
        :title="
            $tc(':count Fassung planen|:count Fassungen planen', chosen.length)
        "
        :subtitle="
            $t(
                'Fassungen in Prüfung werden zuerst freigegeben, geplante umgeplant.',
            )
        "
        :wide="false"
        @close="scheduling = false"
    >
        <form
            @submit.prevent="
                bulk('schedule', { date: scheduleDate, time: scheduleTime })
            "
        >
            <div class="grid grid-cols-2 gap-2.5">
                <Field
                    v-slot="{ id, invalid, describedby }"
                    :label="$t('Datum')"
                    :error="errors.date"
                >
                    <input
                        :id="id"
                        v-model="scheduleDate"
                        :aria-describedby="describedby"
                        :aria-invalid="invalid ? 'true' : undefined"
                        type="date"
                        required
                        :class="controlClasses({ error: invalid })"
                    />
                </Field>
                <Field
                    v-slot="{ id, invalid, describedby }"
                    :label="$t('Zeit')"
                    :error="errors.time"
                    class="mt-0!"
                >
                    <input
                        :id="id"
                        v-model="scheduleTime"
                        :aria-describedby="describedby"
                        :aria-invalid="invalid ? 'true' : undefined"
                        type="time"
                        required
                        :class="controlClasses({ error: invalid })"
                    />
                </Field>
            </div>
            <p class="mt-1 text-[11.5px] text-basalt-light">
                {{ siteTimezoneHelp(timezone) }}
            </p>
            <div class="mt-4 flex gap-3">
                <Button
                    type="submit"
                    :disabled="busy || !scheduleDate || !scheduleTime"
                    >{{ $t('Planen') }}</Button
                >
                <Button variant="secondary" @click="scheduling = false">{{
                    $t('Abbrechen')
                }}</Button>
            </div>
        </form>
    </Modal>
</template>
