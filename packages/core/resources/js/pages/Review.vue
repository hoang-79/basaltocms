<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { computed, ref } from 'vue';
import Button from '../components/Button.vue';
import Card from '../components/Card.vue';
import { controlClasses } from '../components/control';
import EmptyState from '../components/EmptyState.vue';
import Field from '../components/Field.vue';
import FieldDiff from '../components/FieldDiff.vue';
import Notice from '../components/Notice.vue';
import PageHeader from '../components/PageHeader.vue';
import StatusHex from '../components/StatusHex.vue';
import WorkflowLine from '../components/WorkflowLine.vue';
import { t, tc } from '../i18n';
import { heartbeat as heartbeatText, pathOf, versionStatus } from '../support';
import type { BackendProps } from '../types';
import type {
    RequiredLocale,
    ReviewActions,
    ReviewBlocked,
    ReviewChanges,
    ReviewCompare,
    ReviewEntry,
    ReviewHeartbeat,
    ReviewLive,
    ReviewSite,
    ReviewUrls,
    ReviewVersion,
    ReviewVersionRow,
    Stage,
} from '../types-workflow';

/**
 * Die Freigabe einer Sprachfassung (Bereich 13): Bahn durch das Statusmodell, Sperrgründe sichtbar neben den Knöpfen,
 * Pflichtsprachen, Diff gegen die live Fassung mit Wort-Diff; rechts Planen in der Zeitzone der Site und die Fassungen
 * derselben Sprache mit Vergleichen, Vorschau und Wiederherstellen, archivierte unter «Archiv».
 * Fehler eines Übergangs kommen als `errors.workflow`. Auf Mobil stehen die Übergänge in einer Leiste unten.
 */
const props = defineProps<{
    entry: ReviewEntry;
    site: ReviewSite;
    locale: string;
    version: ReviewVersion | null;
    live: ReviewLive | null;
    stage: Stage;
    actions: ReviewActions;
    blocked: ReviewBlocked;
    required_locales: RequiredLocale[];
    changes: ReviewChanges;
    versions: ReviewVersionRow[];
    compare: ReviewCompare | null;
    heartbeat: ReviewHeartbeat;
    urls: ReviewUrls;
    status: string | null;
}>();

const page = usePage<BackendProps>();
const isDesktop = useMediaQuery('(min-width: 768px)');

const noActions: ReviewActions = {
    submit: false,
    withdraw: false,
    approve: false,
    reject: false,
    schedule: false,
    unschedule: false,
    publish: false,
    unpublish: false,
};
const actions = computed<ReviewActions>(() => ({
    ...noActions,
    ...props.actions,
}));
const blocked = computed<ReviewBlocked>(() => ({
    approve: props.blocked?.approve ?? null,
    publish: props.blocked?.publish ?? null,
}));
const versions = computed(() => props.versions ?? []);
const requiredLocales = computed(() => props.required_locales ?? []);
const changes = computed<ReviewChanges>(
    () => props.changes ?? { against: null, fields: [], unchanged: 0 },
);

const code = computed(() => props.locale.toUpperCase());
const title = computed(() =>
    props.version
        ? `${props.entry.title} · ${t('Fassung :number (:locale)', { number: props.version.number, locale: code.value })}`
        : `${props.entry.title} · ${code.value}`,
);
const backLabel = computed(() =>
    props.entry.is_page ? t('Seiten') : props.entry.type_label,
);

const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);
const workflowError = computed(() => errors.value.workflow ?? null);
/** Der Seitenstatus nur, wenn der Rahmen ihn nicht schon als Flash zeigt. */
const ownStatus = computed(() =>
    props.status && props.status !== page.props.backend.flash?.status
        ? props.status
        : null,
);

/** Wer zurückgewiesen hat: `commented_by`, sonst die Person der Freigabe. */
const rejectedBy = computed(
    () => props.version?.commented_by ?? props.version?.approved_by ?? null,
);

/* ── Übergänge ── */

const busy = ref(false);
const rejecting = ref(false);
const comment = ref('');
const confirmingUnpublish = ref(false);

function post(
    url: string | undefined,
    data: Record<string, string> = {},
    onSuccess?: () => void,
): void {
    if (!url) {
        return;
    }
    router.post(url, data, {
        preserveScroll: true,
        onStart: () => {
            busy.value = true;
        },
        onFinish: () => {
            busy.value = false;
        },
        onSuccess,
    });
}

type TransitionButton = {
    key: string;
    label: string;
    variant: 'primary' | 'secondary' | 'danger';
    disabled: boolean;
    pressed?: boolean;
    run: () => void;
};

/** Nur die erlaubten Übergänge; Freigeben und Veröffentlichen stehen auch gesperrt da — mit Grund darunter. */
const buttons = computed<TransitionButton[]>(() => {
    const a = actions.value;
    const list: TransitionButton[] = [];
    const showApprove = a.approve || blocked.value.approve !== null;
    if (a.submit) {
        list.push({
            key: 'submit',
            label: t('Einreichen'),
            variant: 'primary',
            disabled: busy.value,
            run: () => post(props.urls.submit),
        });
    }
    if (a.withdraw) {
        list.push({
            key: 'withdraw',
            label: t('Zurückziehen'),
            variant: 'secondary',
            disabled: busy.value,
            run: () => post(props.urls.withdraw),
        });
    }
    if (a.reject) {
        list.push({
            key: 'reject',
            label: t('Zurückweisen'),
            variant: 'secondary',
            disabled: busy.value,
            pressed: rejecting.value,
            run: () => {
                rejecting.value = !rejecting.value;
                confirmingUnpublish.value = false;
            },
        });
    }
    if (showApprove) {
        list.push({
            key: 'approve',
            label: t('Freigeben'),
            variant: 'primary',
            disabled: busy.value || blocked.value.approve !== null,
            run: () => post(props.urls.approve),
        });
    }
    if (a.publish || blocked.value.publish !== null) {
        list.push({
            key: 'publish',
            label: t('Veröffentlichen'),
            variant: showApprove || a.submit ? 'secondary' : 'primary',
            disabled: busy.value || blocked.value.publish !== null,
            run: () => post(props.urls.publish),
        });
    }
    if (a.unpublish) {
        list.push({
            key: 'unpublish',
            label: t('Offline nehmen'),
            variant: 'danger',
            disabled: busy.value,
            pressed: confirmingUnpublish.value,
            run: () => {
                confirmingUnpublish.value = !confirmingUnpublish.value;
                rejecting.value = false;
            },
        });
    }

    return list;
});

/** Die Sperrgründe — derselbe Grund für beide Knöpfe steht nur einmal. */
const blockedNotices = computed(() => {
    const list: { label: string; reason: string }[] = [];
    const { approve, publish } = blocked.value;
    if (approve && publish && approve === publish) {
        list.push({
            label: t('Freigeben und Veröffentlichen'),
            reason: approve,
        });

        return list;
    }
    if (approve) {
        list.push({ label: t('Freigeben'), reason: approve });
    }
    if (publish) {
        list.push({ label: t('Veröffentlichen'), reason: publish });
    }

    return list;
});

function reject(): void {
    if (comment.value.trim() === '') {
        return;
    }
    post(props.urls.reject, { comment: comment.value.trim() }, () => {
        rejecting.value = false;
        comment.value = '';
    });
}

function unpublish(): void {
    post(props.urls.unpublish, {}, () => {
        confirmingUnpublish.value = false;
    });
}

/* ── Planen in der Zeitzone der Site ── */

/** Morgen in der Zeitzone der Site als «YYYY-MM-DD». */
function tomorrowIn(timezone: string): string {
    const date = new Date(Date.now() + 24 * 60 * 60 * 1000);
    try {
        return new Intl.DateTimeFormat('en-CA', { timeZone: timezone }).format(
            date,
        );
    } catch {
        return date.toISOString().slice(0, 10);
    }
}

const scheduleDate = ref(
    props.version?.scheduled_date ?? tomorrowIn(props.site?.timezone ?? 'UTC'),
);
const scheduleTime = ref(props.version?.scheduled_time ?? '08:00');
const showPlanning = computed(
    () => actions.value.schedule || props.stage === 'scheduled',
);
const browserTimezone = (() => {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone;
    } catch {
        return null;
    }
})();
const timezoneHelp = computed(() => {
    const site = props.site?.timezone ?? 'UTC';
    if (!browserTimezone) {
        return t('Zeitzone der Site (:timezone).', { timezone: site });
    }

    return browserTimezone === site
        ? t('Zeitzone der Site. Ihre Zeitzone (:timezone) ist dieselbe.', {
              timezone: browserTimezone,
          })
        : t(
              'Zeitzone der Site (:site). Achtung: Ihre Zeitzone ist :timezone.',
              { site, timezone: browserTimezone },
          );
});
const pulse = computed(() => ({
    text: heartbeatText(props.heartbeat?.age_seconds ?? null).text,
    ok: props.heartbeat?.ok ?? false,
}));

function schedule(): void {
    post(props.urls.schedule, {
        date: scheduleDate.value,
        time: scheduleTime.value,
    });
}

/* ── Diff und Fassungen ── */

const previousNumber = computed(() => {
    const current = props.version?.number ?? 0;
    const older = versions.value
        .filter((v) => v.number < current)
        .map((v) => v.number);

    return older.length > 0 ? Math.max(...older) : null;
});
const oldLabel = computed(() => {
    if (changes.value.against === 'live' && props.live) {
        return t('Fassung :number (live)', { number: props.live.number });
    }

    return previousNumber.value
        ? t('Fassung :number (vorige)', { number: previousNumber.value })
        : t('vorige Fassung');
});
const newLabel = computed(() =>
    props.version
        ? t('Fassung :number (:status)', {
              number: props.version.number,
              status: props.version.status_label,
          })
        : t('diese Fassung'),
);
const diffTitle = computed(() => {
    const n = changes.value.fields.length;
    const count = tc(
        ':count Feld geändert, :unchanged unverändert|:count Felder geändert, :unchanged unverändert',
        n,
        { unchanged: changes.value.unchanged },
    );
    if (changes.value.against === 'live') {
        return t('Änderungen gegenüber der live Fassung · :count', { count });
    }
    if (changes.value.against === 'previous') {
        return t('Änderungen gegenüber der vorigen Fassung · :count', {
            count,
        });
    }

    return t('Änderungen');
});

const activeVersions = computed(() =>
    versions.value.filter((v) => v.status !== 'archived'),
);
const archivedVersions = computed(() =>
    versions.value.filter((v) => v.status === 'archived'),
);

function canRestore(v: ReviewVersionRow): boolean {
    return (
        Boolean(v.restore_url) &&
        !v.is_current &&
        (v.status === 'archived' || v.number < (props.version?.number ?? 0))
    );
}

function versionMeta(v: ReviewVersionRow): string {
    return [
        v.author,
        v.approved_by ? t('Freigabe :name', { name: v.approved_by }) : null,
        v.published_at ?? v.created_at,
    ]
        .filter(Boolean)
        .join(' · ');
}

function restore(v: ReviewVersionRow): void {
    post(v.restore_url);
}

const label =
    'mb-1 block text-[9.5px] font-semibold tracking-[.1em] text-basalt-light uppercase';
const linkAction =
    'text-[11.5px] text-lava-dark underline hover:text-basalt disabled:text-ash disabled:no-underline';
const previewClasses =
    'inline-flex items-center justify-center whitespace-nowrap rounded-md border border-ash bg-white px-[13px] py-2 text-[13px] leading-tight font-semibold text-basalt transition-colors hover:bg-paper';
</script>

<template>
    <Head :title="$t('Freigabe · :title', { title: entry.title })" />
    <PageHeader :title="title">
        <template #crumb>
            <Link :href="urls.back" class="font-semibold hover:underline"
                >‹ {{ backLabel }}</Link
            >
            › {{ entry.title }} ›
            <Link :href="urls.review_list" class="hover:underline">{{
                $t('Freigabe')
            }}</Link>
        </template>
        <template v-if="version" #lead>
            <b class="font-semibold text-basalt">{{ version.status_label }}</b>
            <template v-if="stage === 'in_review'">
                ·
                {{
                    version.submitted_at
                        ? $t('eingereicht von :name, :date', {
                              name: version.author ?? $t('unbekannt'),
                              date: version.submitted_at,
                          })
                        : $t('eingereicht von :name', {
                              name: version.author ?? $t('unbekannt'),
                          })
                }}
            </template>
            <template v-else-if="stage === 'approved'">
                ·
                {{
                    version.approved_by
                        ? $t('freigegeben von :name', {
                              name: version.approved_by,
                          })
                        : $t('freigegeben')
                }}
            </template>
            <template v-else-if="stage === 'scheduled'">
                ·
                {{
                    $t('geplant für :date', {
                        date: version.scheduled_for ?? '—',
                    })
                }}
            </template>
            <template v-else-if="version.author">
                · {{ $t('von :name', { name: version.author }) }}
            </template>
            <template v-if="live && live.id === version.id">
                · {{ $t('diese Fassung ist live') }}</template
            >
            <template v-else-if="live">
                ·
                {{
                    live.published_at
                        ? $t('live ist Fassung :number vom :date', {
                              number: live.number,
                              date: live.published_at,
                          })
                        : $t('live ist Fassung :number', {
                              number: live.number,
                          })
                }}</template
            >
            <template v-else> · {{ $t('noch keine Fassung live') }}</template>
        </template>
        <template #actions>
            <a
                v-if="urls.preview"
                :href="urls.preview"
                target="_blank"
                rel="noopener"
                :class="previewClasses"
                >{{ $t('Vorschau') }}</a
            >
            <template v-if="isDesktop">
                <Button
                    v-for="b in buttons"
                    :key="b.key"
                    :variant="b.variant"
                    :disabled="b.disabled"
                    :aria-pressed="b.pressed"
                    @click="b.run"
                    >{{ b.label }}</Button
                >
            </template>
            <Button variant="link" :href="urls.editor">{{
                $t('Im Editor')
            }}</Button>
        </template>
    </PageHeader>

    <div class="space-y-2.5">
        <Notice v-if="workflowError" tone="danger">{{ workflowError }}</Notice>
        <Notice v-if="ownStatus" tone="success">{{ ownStatus }}</Notice>
    </div>

    <Card v-if="!version" :title="$t('Noch keine Fassung in dieser Sprache')">
        <EmptyState
            :title="
                $t(':title hat auf :locale noch keine Fassung', {
                    title: entry.title,
                    locale: code,
                })
            "
            :text="
                $t(
                    'Im Editor eine Fassung anlegen und einreichen — dann steht sie hier zur Freigabe.',
                )
            "
            icon="review"
            tone="ash"
        >
            <Button :href="urls.editor">{{ $t('Im Editor öffnen') }}</Button>
        </EmptyState>
    </Card>

    <template v-else>
        <section
            v-if="compare"
            class="mt-3 rounded-lg border border-lava/40 bg-white px-3.5 py-3"
            :aria-label="$t('Vergleich zweier Fassungen')"
        >
            <div
                class="mb-2 flex flex-wrap items-baseline justify-between gap-2"
            >
                <h2 class="text-sm font-semibold">
                    {{
                        $t('Vergleich Nr. :a ↔ Nr. :b', {
                            a: compare.a.number,
                            b: compare.b.number,
                        })
                    }}
                </h2>
                <Link
                    :href="pathOf(page.url)"
                    preserve-scroll
                    class="text-[11.5px] text-lava-dark underline hover:text-basalt"
                    >{{ $t('Vergleich schliessen') }}</Link
                >
            </div>
            <FieldDiff
                :fields="compare.fields"
                :old-label="$t('Fassung :number', { number: compare.a.number })"
                :new-label="$t('Fassung :number', { number: compare.b.number })"
            />
        </section>

        <div class="mt-3">
            <WorkflowLine :stage="stage" />
        </div>

        <div class="space-y-2.5">
            <Notice
                v-if="stage === 'draft' && version.comment"
                tone="warning"
                :title="$t('Zurückgewiesen')"
            >
                {{
                    rejectedBy
                        ? $t('Zurückgewiesen von :name — «:comment»', {
                              name: rejectedBy,
                              comment: version.comment,
                          })
                        : $t('Zurückgewiesen — «:comment»', {
                              comment: version.comment,
                          })
                }}
            </Notice>
            <Notice v-for="b in blockedNotices" :key="b.label" tone="warning">
                <strong>{{
                    $t(':action gesperrt.', { action: b.label })
                }}</strong>
                {{ b.reason }}
            </Notice>
            <Notice v-if="confirmingUnpublish" tone="danger">
                <span class="font-semibold">{{
                    $t('Fassung :number offline nehmen?', {
                        number: version.number,
                    })
                }}</span>
                {{
                    entry.is_page
                        ? $t(
                              'Die Seite ist in dieser Sprache danach nicht mehr erreichbar.',
                          )
                        : $t(
                              'Der Inhalt ist in dieser Sprache danach nicht mehr sichtbar.',
                          )
                }}
                <span class="mt-1.5 flex flex-wrap gap-2">
                    <Button
                        variant="danger"
                        size="sm"
                        :disabled="busy"
                        @click="unpublish"
                        >{{ $t('Ja, offline nehmen') }}</Button
                    >
                    <Button
                        variant="link"
                        size="sm"
                        @click="confirmingUnpublish = false"
                        >{{ $t('Abbrechen') }}</Button
                    >
                </span>
            </Notice>
            <Card v-if="rejecting" :title="$t('Zurückweisen')">
                <template #right>{{
                    version.author
                        ? $t('zurück an :name', { name: version.author })
                        : $t('zurück an den Entwurf')
                }}</template>
                <form @submit.prevent="reject">
                    <Field
                        v-model="comment"
                        :label="$t('Kommentar')"
                        type="textarea"
                        :rows="3"
                        required
                        :placeholder="$t('Was soll sich ändern?')"
                        :error="errors.comment"
                        :help="
                            $t(
                                'Pflicht — die Fassung geht als Entwurf zurück, der Kommentar steht im Editor.',
                            )
                        "
                    />
                    <div class="mt-2.5 flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            :disabled="busy || comment.trim() === ''"
                            >{{ $t('Zurückweisen') }}</Button
                        >
                        <Button variant="link" @click="rejecting = false">{{
                            $t('Abbrechen')
                        }}</Button>
                    </div>
                </form>
            </Card>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_330px]">
            <div class="min-w-0 space-y-4">
                <section v-if="requiredLocales.length > 0">
                    <span :class="label">{{
                        $t('Pflichtsprachen vor der Freigabe')
                    }}</span>
                    <ul class="flex flex-wrap gap-x-3.5 gap-y-1 text-[12.5px]">
                        <li
                            v-for="l in requiredLocales"
                            :key="l.locale"
                            class="inline-flex items-center gap-1.5"
                            :class="l.is_required ? '' : 'text-basalt-light'"
                        >
                            <StatusHex :status="versionStatus(l.status)" />
                            <span
                                >{{ l.locale.toUpperCase() }}
                                {{
                                    l.is_required
                                        ? $t('Pflicht')
                                        : $t('nicht Pflicht')
                                }}<template v-if="l.is_required">
                                    · {{ l.status_label ?? $t('fehlt') }}
                                    <span
                                        v-if="l.ok"
                                        class="text-success font-semibold"
                                        >✓</span
                                    ><span
                                        v-else
                                        class="text-danger font-semibold"
                                        >✗</span
                                    ></template
                                ></span
                            >
                        </li>
                    </ul>
                    <p
                        v-if="site.four_eyes"
                        class="mt-1 text-[11.5px] text-basalt-light"
                    >
                        {{
                            $t(
                                'Vier-Augen-Prinzip: Wer einreicht, gibt nicht selbst frei.',
                            )
                        }}
                    </p>
                </section>

                <section>
                    <span :class="label">{{ diffTitle }}</span>
                    <p
                        v-if="changes.against === null"
                        class="text-[12.5px] text-basalt-light"
                    >
                        {{
                            $t(
                                'Erste Fassung in :locale — es gibt nichts zum Vergleichen.',
                                { locale: code },
                            )
                        }}
                    </p>
                    <FieldDiff
                        v-else
                        :fields="changes.fields"
                        :old-label="oldLabel"
                        :new-label="newLabel"
                        :unchanged="changes.unchanged"
                    />
                </section>
            </div>

            <aside
                class="min-w-0 space-y-4"
                :aria-label="$t('Planen und Fassungen')"
            >
                <Card v-if="showPlanning" :title="$t('Planen')">
                    <template #sub>
                        <span>
                            {{
                                $t(
                                    'Der Scheduler veröffentlicht zur Minute; Herzschlag',
                                )
                            }}
                            <span
                                :class="
                                    pulse.ok
                                        ? 'text-success'
                                        : 'text-danger font-semibold'
                                "
                                >{{ pulse.text }}</span
                            >
                        </span>
                    </template>
                    <p v-if="stage === 'scheduled'" class="text-[12.5px]">
                        <template
                            v-for="(part, i) in $t(
                                'Geplant für :date (:timezone).',
                                { timezone: site.timezone },
                            ).split(':date')"
                            :key="i"
                            ><b v-if="i > 0" class="font-semibold">{{
                                version.scheduled_for ?? '—'
                            }}</b
                            >{{ part }}</template
                        >
                    </p>
                    <form v-if="actions.schedule" @submit.prevent="schedule">
                        <div class="grid grid-cols-2 gap-2.5">
                            <Field
                                v-slot="{ id, invalid, describedby }"
                                :label="$t('Datum')"
                                :error="errors.date"
                            >
                                <input
                                    :id="id"
                                    :aria-describedby="describedby"
                                    :aria-invalid="invalid ? 'true' : undefined"
                                    v-model="scheduleDate"
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
                                    :aria-describedby="describedby"
                                    :aria-invalid="invalid ? 'true' : undefined"
                                    v-model="scheduleTime"
                                    type="time"
                                    required
                                    :class="controlClasses({ error: invalid })"
                                />
                            </Field>
                        </div>
                        <p class="mt-1 text-[11.5px] text-basalt-light">
                            {{ timezoneHelp }}
                            <template v-if="live && live.id !== version.id">
                                {{
                                    $t(
                                        'Fassung :number (live) bleibt bis dahin online.',
                                        { number: live.number },
                                    )
                                }}</template
                            >
                        </p>
                        <div class="mt-2.5 flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                :variant="
                                    actions.approve || actions.submit
                                        ? 'secondary'
                                        : 'primary'
                                "
                                :disabled="
                                    busy || !scheduleDate || !scheduleTime
                                "
                                >{{
                                    stage === 'scheduled'
                                        ? $t('Neu planen')
                                        : $t('Veröffentlichung planen')
                                }}</Button
                            >
                        </div>
                    </form>
                    <div
                        v-if="stage === 'scheduled' && actions.unschedule"
                        class="mt-2.5"
                    >
                        <Button
                            variant="secondary"
                            :disabled="busy"
                            @click="post(urls.unschedule)"
                            >{{ $t('Planung aufheben') }}</Button
                        >
                    </div>
                </Card>

                <Card :title="$t('Fassungen · :locale', { locale: code })">
                    <template #right>{{
                        $tc(':count Fassung|:count Fassungen', versions.length)
                    }}</template>
                    <ul class="divide-y divide-ash-light text-[12.5px]">
                        <li
                            v-for="v in activeVersions"
                            :key="v.id"
                            class="py-2 first:pt-0 last:pb-0"
                        >
                            <div class="flex items-start gap-2">
                                <StatusHex
                                    class="mt-[3px]"
                                    :status="versionStatus(v.status)"
                                />
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold">
                                        {{
                                            $t('Nr. :number', {
                                                number: v.number,
                                            })
                                        }}
                                        ·
                                        {{ v.status_label }}
                                        <span
                                            v-if="v.is_current"
                                            class="ml-1 rounded border border-ash-light bg-paper px-1 font-mono text-[10px] font-normal text-basalt-light"
                                            >{{ $t('diese') }}</span
                                        >
                                        <span
                                            v-if="v.is_live"
                                            class="border-success/40 bg-success/5 text-success ml-1 rounded border px-1 font-mono text-[10px] font-normal"
                                            >{{ $t('live') }}</span
                                        >
                                    </p>
                                    <p
                                        v-if="versionMeta(v)"
                                        class="text-[11.5px] text-basalt-light"
                                    >
                                        {{ versionMeta(v) }}
                                    </p>
                                    <p class="mt-0.5 flex flex-wrap gap-x-2.5">
                                        <Link
                                            v-if="
                                                !v.is_current && v.compare_url
                                            "
                                            :href="v.compare_url"
                                            :class="linkAction"
                                            >{{ $t('Vergleichen') }}</Link
                                        >
                                        <a
                                            :href="v.preview_url"
                                            target="_blank"
                                            rel="noopener"
                                            :class="linkAction"
                                            >{{ $t('Vorschau') }}</a
                                        >
                                        <button
                                            v-if="canRestore(v)"
                                            type="button"
                                            :class="linkAction"
                                            :disabled="busy"
                                            @click="restore(v)"
                                        >
                                            {{ $t('Wiederherstellen') }}
                                        </button>
                                    </p>
                                </div>
                            </div>
                        </li>
                    </ul>
                    <template v-if="archivedVersions.length > 0">
                        <span :class="[label, 'mt-3']">{{ $t('Archiv') }}</span>
                        <ul class="divide-y divide-ash-light text-[12.5px]">
                            <li
                                v-for="v in archivedVersions"
                                :key="v.id"
                                class="flex items-start gap-2 py-2 first:pt-0 last:pb-0"
                            >
                                <StatusHex
                                    class="mt-[3px]"
                                    :status="versionStatus(v.status)"
                                />
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold">
                                        {{
                                            $t('Nr. :number', {
                                                number: v.number,
                                            })
                                        }}
                                        ·
                                        {{ v.status_label }}
                                    </p>
                                    <p
                                        v-if="versionMeta(v)"
                                        class="text-[11.5px] text-basalt-light"
                                    >
                                        {{ versionMeta(v) }}
                                    </p>
                                    <p class="mt-0.5 flex flex-wrap gap-x-2.5">
                                        <Link
                                            v-if="v.compare_url"
                                            :href="v.compare_url"
                                            :class="linkAction"
                                            >{{ $t('Vergleichen') }}</Link
                                        >
                                        <a
                                            :href="v.preview_url"
                                            target="_blank"
                                            rel="noopener"
                                            :class="linkAction"
                                            >{{ $t('Vorschau') }}</a
                                        >
                                        <button
                                            v-if="canRestore(v)"
                                            type="button"
                                            :class="linkAction"
                                            :disabled="busy"
                                            @click="restore(v)"
                                        >
                                            {{ $t('Wiederherstellen') }}
                                        </button>
                                    </p>
                                </div>
                            </li>
                        </ul>
                    </template>
                    <p class="mt-2.5 text-[11.5px] text-basalt-light">
                        {{
                            $t(
                                'Wiederherstellen erzeugt einen neuen Entwurf — die live Fassung bleibt, bis die Freigabe entscheidet.',
                            )
                        }}
                    </p>
                </Card>
            </aside>
        </div>

        <div
            v-if="!isDesktop && buttons.length > 0"
            class="sticky bottom-0 z-30 -mx-4 mt-4 flex flex-wrap gap-2 border-t border-ash-light bg-paper px-4 py-2.5"
        >
            <Button
                v-for="b in buttons"
                :key="b.key"
                :variant="b.variant"
                :disabled="b.disabled"
                :aria-pressed="b.pressed"
                class="flex-1"
                @click="b.run"
                >{{ b.label }}</Button
            >
        </div>
    </template>
</template>
