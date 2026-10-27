<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { useDraft } from '@core/components/autosave';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { t, tc } from '@core/i18n';
import Button from '@core/components/Button.vue';
import Card from '@core/components/Card.vue';
import { controlClasses, labelClasses } from '@core/components/control';
import EmptyState from '@core/components/EmptyState.vue';
import Hex from '@core/components/Hex.vue';
import PageHeader from '@core/components/PageHeader.vue';
import StateTag from '@core/components/StateTag.vue';
import Table from '@core/components/Table.vue';
import type { BackendProps } from '@core/types';
import { commentTone } from '../comments';
import type {
    CommentFilter,
    CommentRow,
    CommentStatus,
    ModerationPageProps,
} from '../types';

/**
 * Die Moderation (M-27, Bereich 30): Status-Filter mit Zahlen, Ursprung und Suche, Liste mit Autor, Kommentar,
 * Ursprung und Status; freigeben, antworten (als Redaktion, auf Wunsch zugleich freigeben), als Spam markieren,
 * löschen — einzeln und gesammelt. Antworten stehen eingerückt unter ihrem Kommentar. Unter 768 px Karten statt
 * Tabelle — nichts scrollt waagrecht.
 */
const props = defineProps<ModerationPageProps>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);

const filters = computed<
    { value: CommentFilter; label: string; count: number }[]
>(() => [
    { value: 'pending', label: t('Wartend'), count: props.counts.pending },
    {
        value: 'approved',
        label: t('Freigegeben'),
        count: props.counts.approved,
    },
    { value: 'spam', label: t('Spam'), count: props.counts.spam },
    { value: 'deleted', label: t('Gelöscht'), count: props.counts.deleted },
    { value: 'all', label: t('Alle'), count: props.counts.all },
]);

/* ── Filter ── */

const q = ref(props.filters.q);
const origin = ref<string>(
    props.filters.ursprung === null ? '' : String(props.filters.ursprung),
);
watch(
    () => props.filters.q,
    (value) => {
        if (document.activeElement?.id !== 'comments-q') {
            q.value = value;
        }
    },
);

function apply(patch: Partial<ModerationPageProps['filters']> = {}): void {
    const next = {
        ...props.filters,
        q: q.value.trim(),
        ursprung: origin.value === '' ? null : Number(origin.value),
        ...patch,
    };
    const params: Record<string, string> = { status: next.status };
    if (next.q) {
        params.q = next.q;
    }
    if (next.ursprung !== null) {
        params.ursprung = String(next.ursprung);
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

const filtered = computed(
    () => props.filters.q !== '' || props.filters.ursprung !== null,
);

function reset(): void {
    q.value = '';
    origin.value = '';
    apply({ q: '', ursprung: null });
}

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

const busy = ref(false);
const visit = {
    preserveScroll: true,
    onStart: () => {
        busy.value = true;
    },
    onFinish: () => {
        busy.value = false;
    },
};

function bulk(action: 'approve' | 'spam' | 'delete'): void {
    if (chosen.value.length === 0) {
        return;
    }
    router.post(
        props.urls.bulk,
        { action, ids: chosen.value },
        {
            ...visit,
            onSuccess: () => {
                chosen.value = [];
            },
        },
    );
}

/* ── Einzelne Kommentare ── */

function setStatus(row: CommentRow, status: CommentStatus): void {
    router.post(row.urls.status, { status }, visit);
}

function remove(row: CommentRow): void {
    router.delete(row.urls.destroy, visit);
}

const replying = ref<number | null>(null);
const reply = useForm({ body: '', approve: false });
// CR-013: Die Antwort erscheint mit dem Knopf; bis dahin bleibt sie je Kommentar als Entwurf im Browser
const replyDraft = useDraft(
    reply,
    () => `kommentar.antwort.${replying.value ?? 0}`,
    ['body'],
);

function openReply(row: CommentRow): void {
    replying.value = row.id;
    reply.reset();
    reply.clearErrors();
    replyDraft.reload();
}

function sendReply(row: CommentRow, approve: boolean): void {
    reply.approve = approve;
    reply.post(row.urls.reply, {
        preserveScroll: true,
        onSuccess: () => {
            replying.value = null;
            reply.reset();
            replyDraft.clear();
        },
    });
}

const lead = computed(() =>
    props.counts.pending > 0
        ? tc(
              ':count Kommentar wartet|:count Kommentare warten',
              props.counts.pending,
          )
        : t('Kein Kommentar wartet'),
);

const small = controlClasses({ small: true });
const checkbox = 'h-4 w-4 accent-lava-dark';
</script>

<template>
    <Head :title="$t('Kommentare')" />
    <PageHeader
        :title="$t('Kommentare')"
        :crumb="$t(':site › Inhalte', { site: siteName })"
    >
        <template #lead>
            <b class="font-semibold text-basalt">{{ lead }}</b>
            {{ $t('auf Freigabe.') }}
            {{
                $t(
                    'Zentrale Moderation für alle Beiträge und Seiten dieser Site; Spam wird gezählt, nie gespeichert.',
                )
            }}
        </template>
    </PageHeader>

    <nav
        class="flex flex-wrap gap-x-[18px] border-b border-ash-light"
        :aria-label="$t('Status')"
    >
        <button
            v-for="f in filters"
            :key="f.value"
            type="button"
            class="-mb-px flex min-h-6 items-baseline gap-1.5 border-b-2 pb-[7px] text-[13px]"
            :class="
                f.value === props.filters.status
                    ? 'border-lava font-semibold text-basalt'
                    : 'border-transparent text-basalt-light hover:text-basalt'
            "
            :aria-current="
                f.value === props.filters.status ? 'true' : undefined
            "
            @click="apply({ status: f.value })"
        >
            {{ f.label }}
            <span class="font-mono text-[11.5px] font-normal">{{
                f.count
            }}</span>
        </button>
        <span
            class="ml-auto self-center pb-[7px] text-[11.5px] text-basalt-light"
            >{{
                $t(':count Spam gezählt (:days Tage, nie gespeichert)', {
                    count: spam.recent,
                    days: spam.window,
                })
            }}</span
        >
    </nav>

    <form
        class="mt-2.5 mb-2.5 flex flex-wrap items-center gap-2 text-[12.5px]"
        role="search"
        :aria-label="$t('Kommentare filtern')"
        @submit.prevent="apply()"
    >
        <label class="sr-only" for="comments-origin">{{
            $t('Ursprung')
        }}</label>
        <select
            id="comments-origin"
            v-model="origin"
            :class="[small, 'w-full! sm:w-60!']"
            @change="apply()"
        >
            <option value="">{{ $t('Ursprung: alle') }}</option>
            <option v-for="o in origins" :key="o.id" :value="String(o.id)">
                {{ o.label }}
            </option>
        </select>
        <label class="sr-only" for="comments-q">{{ $t('Suchen') }}</label>
        <input
            id="comments-q"
            v-model="q"
            type="search"
            :placeholder="`⌕ ${$t('Name, E-Mail oder Text')}`"
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
        <label class="flex min-h-6 items-center gap-1.5">
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
            @click="bulk('approve')"
            >{{ $t('Freigeben') }}</Button
        >
        <Button
            variant="secondary"
            size="sm"
            :disabled="chosen.length === 0 || busy"
            @click="bulk('spam')"
            >{{ $t('Als Spam') }}</Button
        >
        <Button
            variant="danger"
            size="sm"
            :disabled="chosen.length === 0 || busy"
            @click="bulk('delete')"
            >{{ $t('Löschen') }}</Button
        >
    </div>

    <div
        v-if="rows.length === 0"
        class="rounded-lg border border-ash-light bg-white"
    >
        <EmptyState
            :title="
                filtered || props.filters.status !== 'pending'
                    ? $t('Keine Kommentare')
                    : $t('Nichts wartet')
            "
            :text="
                filtered
                    ? $t('Mit diesem Filter gibt es keinen Kommentar.')
                    : $t(
                          'Sobald jemand einen Beitrag kommentiert, steht der Kommentar hier.',
                      )
            "
            icon="comments"
            :tone="filtered ? 'ash' : 'success'"
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
                    <th class="px-3 py-2">{{ $t('Autor · Kommentar') }}</th>
                    <th class="w-[190px] px-3 py-2">{{ $t('Ursprung') }}</th>
                    <th class="w-[100px] px-3 py-2">{{ $t('Status') }}</th>
                    <th class="w-[220px] px-3 py-2">
                        <span class="sr-only">{{ $t('Aktionen') }}</span>
                    </th>
                </tr>
            </template>
            <template v-for="row in rows" :key="row.id">
                <tr
                    class="align-top"
                    :class="row.status === 'pending' ? 'bg-lava/5' : ''"
                >
                    <td class="px-3 py-2.5">
                        <input
                            type="checkbox"
                            :class="checkbox"
                            :checked="chosen.includes(row.id)"
                            :aria-label="
                                $t('Kommentar von :author wählen', {
                                    author: row.author,
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
                    <td class="px-3 py-2.5 text-[12.5px]">
                        <div
                            class="flex items-start gap-2.5"
                            :class="row.reply_to !== null ? 'pl-6' : ''"
                        >
                            <Hex
                                :label="row.initials"
                                :tone="
                                    row.staff
                                        ? 'basalt'
                                        : row.status === 'pending'
                                          ? 'lava'
                                          : 'ash'
                                "
                                size="md"
                            />
                            <div class="min-w-0">
                                <p class="font-semibold text-basalt">
                                    {{ row.author }}
                                    <StateTag v-if="row.staff" class="ml-1">{{
                                        $t('Redaktion')
                                    }}</StateTag>
                                </p>
                                <p
                                    class="text-[11.5px] break-all text-basalt-light"
                                >
                                    <template v-if="row.email">{{
                                        row.email
                                    }}</template>
                                    <template v-if="row.website">
                                        · {{ row.website }}</template
                                    >
                                    ·
                                    <span class="font-mono">{{
                                        row.created_at
                                    }}</span>
                                    · {{ row.locale }}
                                </p>
                                <p class="mt-1 break-words whitespace-pre-line">
                                    {{ row.body }}
                                </p>
                            </div>
                        </div>
                    </td>
                    <td class="px-3 py-2.5 text-[12px] text-basalt-light">
                        <p v-if="row.reply_to" class="text-basalt">
                            ↳
                            {{
                                $t('Antwort auf :author', {
                                    author: row.reply_to,
                                })
                            }}
                        </p>
                        <a
                            v-if="row.origin.url"
                            :href="row.origin.url"
                            class="break-words text-lava-dark underline hover:text-basalt"
                            >{{ row.origin.title }}</a
                        >
                        <span v-else class="break-words">{{
                            row.origin.title
                        }}</span>
                        <span v-if="row.origin.type" class="block">{{
                            row.origin.type
                        }}</span>
                    </td>
                    <td class="px-3 py-2.5">
                        <StateTag :tone="commentTone(row.status)">{{
                            row.status_label
                        }}</StateTag>
                    </td>
                    <td class="px-3 py-2.5">
                        <div class="flex flex-wrap justify-end gap-1.5">
                            <Button
                                v-if="row.status !== 'approved'"
                                :variant="
                                    row.status === 'pending'
                                        ? 'primary'
                                        : 'secondary'
                                "
                                size="sm"
                                :disabled="busy"
                                @click="setStatus(row, 'approved')"
                                >{{ $t('Freigeben') }}</Button
                            >
                            <Button
                                v-if="
                                    row.status === 'pending' ||
                                    row.status === 'approved'
                                "
                                variant="secondary"
                                size="sm"
                                :disabled="busy"
                                :aria-expanded="replying === row.id"
                                @click="openReply(row)"
                                >{{ $t('Antworten') }}</Button
                            >
                            <Button
                                v-if="row.status !== 'spam' && !row.staff"
                                variant="secondary"
                                size="sm"
                                :disabled="busy"
                                @click="setStatus(row, 'spam')"
                                >{{ $t('Spam') }}</Button
                            >
                            <Button
                                v-if="row.status !== 'deleted'"
                                variant="danger"
                                size="sm"
                                :disabled="busy"
                                @click="remove(row)"
                                >{{ $t('Löschen') }}</Button
                            >
                        </div>
                    </td>
                </tr>
                <tr v-if="replying === row.id">
                    <td></td>
                    <td colspan="4" class="px-3 pt-1 pb-3">
                        <form
                            class="rounded-lg border border-ash-light bg-paper p-3"
                            @submit.prevent="
                                sendReply(row, row.status === 'pending')
                            "
                        >
                            <label
                                :class="labelClasses"
                                :for="`reply-${row.id}`"
                                >{{
                                    $t('Antwort an :author', {
                                        author: row.author,
                                    })
                                }}</label
                            >
                            <textarea
                                :id="`reply-${row.id}`"
                                v-model="reply.body"
                                rows="3"
                                required
                                :class="
                                    controlClasses({
                                        error: !!reply.errors.body,
                                    })
                                "
                                :aria-invalid="
                                    reply.errors.body ? 'true' : undefined
                                "
                                :aria-describedby="`reply-${row.id}-help${reply.errors.body ? ` reply-${row.id}-error` : ''}`"
                            ></textarea>
                            <p
                                v-if="reply.errors.body"
                                :id="`reply-${row.id}-error`"
                                class="text-danger mt-1 text-[11.5px]"
                            >
                                {{ reply.errors.body }}
                            </p>
                            <p
                                :id="`reply-${row.id}-help`"
                                class="mt-1 text-[11.5px] text-basalt-light"
                            >
                                {{
                                    $t(
                                        'Erscheint als Antwort der Redaktion unter dem Kommentar, sofort sichtbar.',
                                    )
                                }}
                            </p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <Button
                                    type="submit"
                                    size="sm"
                                    :disabled="reply.processing"
                                    >{{
                                        row.status === 'pending'
                                            ? $t('Freigeben und antworten')
                                            : $t('Antworten')
                                    }}</Button
                                >
                                <Button
                                    v-if="row.status === 'pending'"
                                    variant="secondary"
                                    size="sm"
                                    :disabled="reply.processing"
                                    @click="sendReply(row, false)"
                                    >{{ $t('Nur antworten') }}</Button
                                >
                                <Button
                                    variant="link"
                                    size="sm"
                                    @click="replying = null"
                                    >{{ $t('Abbrechen') }}</Button
                                >
                            </div>
                        </form>
                    </td>
                </tr>
            </template>
            <template #foot>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span
                        >{{
                            $tc(
                                ':count Kommentar|:count Kommentare',
                                counts.all,
                            )
                        }}
                        ·
                        {{ $tc(':count wartet|:count warten', counts.pending) }}
                        ·
                        {{
                            $t(
                                ':count Spam gezählt insgesamt · IP nur als Prüfsumme · Gelöschtes und Spam nach :days Tagen endgültig weg',
                                { count: spam.total, days: retention },
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
                            >‹ {{ $t('Neuere') }}</Button
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
                            >{{ $t('Ältere') }} ›</Button
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
                    class="px-3 py-2.5"
                    :class="[
                        row.status === 'pending'
                            ? 'shadow-[inset_3px_0_0_var(--color-lava)]'
                            : '',
                        row.reply_to !== null ? 'pl-7' : '',
                    ]"
                >
                    <div class="flex gap-2.5">
                        <input
                            type="checkbox"
                            :class="[checkbox, 'mt-1 shrink-0']"
                            :checked="chosen.includes(row.id)"
                            :aria-label="
                                $t('Kommentar von :author wählen', {
                                    author: row.author,
                                })
                            "
                            @change="
                                toggle(
                                    row.id,
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />
                        <div class="min-w-0 flex-1">
                            <p
                                class="flex flex-wrap items-baseline justify-between gap-x-2"
                            >
                                <strong
                                    class="text-[13px] font-semibold break-words"
                                    >{{ row.author }}</strong
                                >
                                <StateTag :tone="commentTone(row.status)">{{
                                    row.status_label
                                }}</StateTag>
                            </p>
                            <p class="text-[11px] break-all text-basalt-light">
                                <span class="font-mono">{{
                                    row.created_at
                                }}</span>
                                ·
                                <template v-if="row.reply_to"
                                    >↳ {{ row.reply_to }} ·
                                </template>
                                {{ row.origin.title }}
                            </p>
                            <p
                                class="mt-1 text-[12.5px] break-words whitespace-pre-line"
                            >
                                {{ row.body }}
                            </p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <Button
                                    v-if="row.status !== 'approved'"
                                    :variant="
                                        row.status === 'pending'
                                            ? 'primary'
                                            : 'secondary'
                                    "
                                    size="sm"
                                    :disabled="busy"
                                    @click="setStatus(row, 'approved')"
                                    >{{ $t('Freigeben') }}</Button
                                >
                                <Button
                                    v-if="
                                        row.status === 'pending' ||
                                        row.status === 'approved'
                                    "
                                    variant="secondary"
                                    size="sm"
                                    :disabled="busy"
                                    @click="openReply(row)"
                                    >{{ $t('Antworten') }}</Button
                                >
                                <Button
                                    v-if="row.status !== 'spam' && !row.staff"
                                    variant="secondary"
                                    size="sm"
                                    :disabled="busy"
                                    @click="setStatus(row, 'spam')"
                                    >{{ $t('Spam') }}</Button
                                >
                                <Button
                                    v-if="row.status !== 'deleted'"
                                    variant="danger"
                                    size="sm"
                                    :disabled="busy"
                                    @click="remove(row)"
                                    >{{ $t('Löschen') }}</Button
                                >
                            </div>
                            <form
                                v-if="replying === row.id"
                                class="mt-2 rounded-lg border border-ash-light bg-paper p-3"
                                @submit.prevent="
                                    sendReply(row, row.status === 'pending')
                                "
                            >
                                <label
                                    :class="labelClasses"
                                    :for="`reply-m-${row.id}`"
                                    >{{
                                        $t('Antwort an :author', {
                                            author: row.author,
                                        })
                                    }}</label
                                >
                                <textarea
                                    :id="`reply-m-${row.id}`"
                                    v-model="reply.body"
                                    rows="3"
                                    required
                                    :class="
                                        controlClasses({
                                            error: !!reply.errors.body,
                                        })
                                    "
                                    :aria-invalid="
                                        reply.errors.body ? 'true' : undefined
                                    "
                                    :aria-describedby="
                                        reply.errors.body
                                            ? `reply-m-${row.id}-error`
                                            : undefined
                                    "
                                ></textarea>
                                <p
                                    v-if="reply.errors.body"
                                    :id="`reply-m-${row.id}-error`"
                                    class="text-danger mt-1 text-[11.5px]"
                                >
                                    {{ reply.errors.body }}
                                </p>
                                <div class="mt-2 flex flex-col gap-1.5">
                                    <Button
                                        type="submit"
                                        size="sm"
                                        wide
                                        :disabled="reply.processing"
                                        >{{
                                            row.status === 'pending'
                                                ? $t('Freigeben und antworten')
                                                : $t('Antworten')
                                        }}</Button
                                    >
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        wide
                                        @click="replying = null"
                                        >{{ $t('Abbrechen') }}</Button
                                    >
                                </div>
                            </form>
                        </div>
                    </div>
                </li>
            </ul>
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-t border-ash-light bg-paper px-3 py-2 text-[11.5px] text-basalt-light"
            >
                <span
                    >{{
                        $tc(':count Kommentar|:count Kommentare', counts.all)
                    }}
                    · {{ $tc(':count wartet|:count warten', counts.pending) }} ·
                    {{
                        $t('Gelöschtes und Spam nach :days Tagen weg', {
                            days: retention,
                        })
                    }}</span
                >
                <span v-if="pagination.last > 1" class="flex gap-2">
                    <Button
                        v-if="pagination.prev"
                        variant="secondary"
                        size="sm"
                        :href="pagination.prev"
                        >‹ {{ $t('Neuere') }}</Button
                    >
                    <Button
                        v-if="pagination.next"
                        variant="secondary"
                        size="sm"
                        :href="pagination.next"
                        >{{ $t('Ältere') }} ›</Button
                    >
                </span>
            </div>
        </div>
    </template>

    <Card :title="$t('Datenschutz')" class="mt-4 max-w-2xl">
        <template #right>{{ $t('DSG · N-05') }}</template>
        <dl
            class="mt-2 grid gap-x-4 gap-y-1.5 text-[12.5px] sm:grid-cols-[9rem_1fr]"
        >
            <dt class="text-basalt-light">{{ $t('IP-Adresse') }}</dt>
            <dd>
                {{
                    $t(
                        'nur als Prüfsumme (HMAC mit dem App-Schlüssel) — für das Limit je IP',
                    )
                }}
            </dd>
            <dt class="text-basalt-light">{{ $t('E-Mail') }}</dt>
            <dd>{{ $t('gespeichert, nie veröffentlicht') }}</dd>
            <dt class="text-basalt-light">{{ $t('Löschung') }}</dt>
            <dd>
                {{
                    $t(
                        'gelöschte Kommentare und Spam nach :days Tagen · Einstellungen › Aufbewahrung',
                        { days: retention },
                    )
                }}
            </dd>
            <dt class="text-basalt-light">{{ $t('Auf Anfrage') }}</dt>
            <dd>
                {{
                    $t(
                        'Auskunft und Löschung je E-Mail-Adresse · Verwaltung › Benutzer',
                    )
                }}
            </dd>
        </dl>
    </Card>
</template>
