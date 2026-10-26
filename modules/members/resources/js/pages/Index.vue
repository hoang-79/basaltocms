<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { t, tc } from '@core/i18n';
import Button from '@core/components/Button.vue';
import Card from '@core/components/Card.vue';
import { controlClasses, labelClasses } from '@core/components/control';
import EmptyState from '@core/components/EmptyState.vue';
import Field from '@core/components/Field.vue';
import Hex from '@core/components/Hex.vue';
import PageHeader from '@core/components/PageHeader.vue';
import StateTag from '@core/components/StateTag.vue';
import Table from '@core/components/Table.vue';
import type { BackendProps } from '@core/types';
import { memberTone } from '../types';
import type { MemberFilter, MemberRow, MembersPageProps } from '../types';

/**
 * Die Mitglieder einer Site (M-23): Stand als Reiter mit Zahlen, Suche, Liste mit Name, E-Mail, Sprache, seit wann
 * und letzter Anmeldung; freigeben, sperren, löschen — einzeln und gesammelt. Darunter einladen, die Regel für neue
 * Mitglieder, die geschützten Seiten und der Datenschutz. Unter 1024 px Karten statt Tabelle — nichts scrollt waagrecht.
 */
const props = defineProps<MembersPageProps>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);

const filters = computed<
    { value: MemberFilter; label: string; count: number }[]
>(() => [
    { value: 'pending', label: t('Wartend'), count: props.counts.pending },
    { value: 'active', label: t('Aktiv'), count: props.counts.active },
    { value: 'blocked', label: t('Gesperrt'), count: props.counts.blocked },
    { value: 'all', label: t('Alle'), count: props.counts.all },
]);

/* ── Filter ── */

const q = ref(props.filters.q);
watch(
    () => props.filters.q,
    (value) => {
        if (document.activeElement?.id !== 'members-q') {
            q.value = value;
        }
    },
);

function apply(patch: Partial<MembersPageProps['filters']> = {}): void {
    const next = { ...props.filters, q: q.value.trim(), ...patch };
    const params: Record<string, string> = { status: next.status };
    if (next.q) {
        params.q = next.q;
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

function reset(): void {
    q.value = '';
    apply({ q: '' });
}

const exportUrl = computed(() => {
    const params = new URLSearchParams({ status: props.filters.status });
    if (props.filters.q) {
        params.set('q', props.filters.q);
    }

    return `${props.urls.export}?${params.toString()}`;
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

function bulk(action: 'approve' | 'block' | 'delete'): void {
    if (chosen.value.length === 0) {
        return;
    }
    if (
        action === 'delete' &&
        !window.confirm(
            tc(
                ':count Mitgliedschaft löschen? Die Frontend-Konten bleiben.|:count Mitgliedschaften löschen? Die Frontend-Konten bleiben.',
                chosen.value.length,
            ),
        )
    ) {
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

/* ── Einzelne Mitglieder ── */

function setStatus(row: MemberRow, status: 'active' | 'blocked'): void {
    router.post(row.urls.status, { status }, visit);
}

function remove(row: MemberRow): void {
    if (
        window.confirm(
            t(
                'Mitgliedschaft von «:email» löschen? Das Frontend-Konto bleibt.',
                { email: row.email },
            ),
        )
    ) {
        router.delete(row.urls.destroy, visit);
    }
}

/* ── Einladen und Einstellungen ── */

const invite = useForm({ email: '', name: '', locale: props.default_locale });
function sendInvite(): void {
    invite.post(props.urls.invite, {
        preserveScroll: true,
        onSuccess: () => invite.reset('email', 'name'),
    });
}

const settings = useForm({
    registration: props.settings.registration,
    login_path: props.settings.login_path,
});
function saveSettings(): void {
    settings.post(props.urls.settings, { preserveScroll: true });
}

const registrationHelp: Record<string, string> = {
    open: t(
        'Wer sich über die Anmeldung der Mitglieder mit Code anmeldet, ist sofort Mitglied.',
    ),
    approval: t(
        'Neue Mitglieder warten hier, bis die Redaktion sie freigibt — sie bekommen dann eine Mail.',
    ),
    invite: t(
        'Nur wen Sie einladen, bekommt einen Code. Andere Adressen erhalten nichts — die Antwort im Browser bleibt dieselbe.',
    ),
};

const localeOptions = computed(() =>
    props.locales.map((l) => ({ value: l, label: l.toUpperCase() })),
);

const lead = computed(() =>
    props.counts.pending > 0
        ? tc(
              ':count Mitglied wartet auf Freigabe.|:count Mitglieder warten auf Freigabe.',
              props.counts.pending,
          )
        : tc(':count Mitglied|:count Mitglieder', props.counts.all),
);

const small = controlClasses({ small: true });
const checkbox = 'h-4 w-4 accent-lava-dark';
</script>

<template>
    <Head :title="$t('Mitglieder')" />
    <PageHeader
        :title="$t('Mitglieder')"
        :crumb="$t(':site › Inhalte', { site: siteName })"
    >
        <template #lead>
            <b class="font-semibold text-basalt">{{ lead }}</b>
            {{
                $t(
                    'Konten des Frontends mit Zugang zu den geschützten Seiten dieser Site — Anmeldung per Code, ohne Passwort.',
                )
            }}
        </template>
        <template v-if="can.manage" #actions>
            <a
                :href="exportUrl"
                class="inline-flex min-h-7 items-center rounded-md border border-ash-light bg-white px-2.5 text-[12.5px] font-semibold text-basalt hover:border-lava"
                >{{ $t('Export (CSV)') }}</a
            >
        </template>
    </PageHeader>

    <nav
        class="flex flex-wrap gap-x-[18px] border-b border-ash-light"
        :aria-label="$t('Stand')"
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
    </nav>

    <form
        class="mt-2.5 mb-2.5 flex flex-wrap items-center gap-2 text-[12.5px]"
        role="search"
        :aria-label="$t('Mitglieder filtern')"
        @submit.prevent="apply()"
    >
        <label class="sr-only" for="members-q">{{ $t('Suchen') }}</label>
        <input
            id="members-q"
            v-model="q"
            type="search"
            :placeholder="`⌕ ${$t('Name oder E-Mail')}`"
            :class="[small, 'w-full! sm:w-72!']"
            @input="search"
        />
        <Button
            v-if="props.filters.q"
            variant="link"
            size="sm"
            @click="reset"
            >{{ $t('Filter zurücksetzen') }}</Button
        >
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
            @click="bulk('block')"
            >{{ $t('Sperren') }}</Button
        >
        <Button
            v-if="can.manage"
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
                props.filters.q || props.filters.status !== 'pending'
                    ? $t('Keine Mitglieder')
                    : $t('Nichts wartet')
            "
            :text="
                props.filters.q
                    ? $t('Mit diesem Filter gibt es kein Mitglied.')
                    : $t(
                          'Mitglieder entstehen mit der Anmeldung auf einer geschützten Seite oder mit einer Einladung (unten).',
                      )
            "
            icon="users"
            :tone="props.filters.q ? 'ash' : 'success'"
        />
    </div>

    <template v-else>
        <Table class="max-lg:hidden">
            <template #head>
                <tr>
                    <th class="w-9 px-3 py-2">
                        <span class="sr-only">{{ $t('Auswahl') }}</span>
                    </th>
                    <th class="px-3 py-2">{{ $t('Mitglied') }}</th>
                    <th class="w-[150px] px-3 py-2">{{ $t('Seit') }}</th>
                    <th class="w-[150px] px-3 py-2">
                        {{ $t('Letzte Anmeldung') }}
                    </th>
                    <th class="w-[150px] px-3 py-2">{{ $t('Stand') }}</th>
                    <th class="w-[210px] px-3 py-2">
                        <span class="sr-only">{{ $t('Aktionen') }}</span>
                    </th>
                </tr>
            </template>
            <tr
                v-for="row in rows"
                :key="row.id"
                class="align-top"
                :class="row.status === 'pending' ? 'bg-lava/5' : ''"
            >
                <td class="px-3 py-2.5">
                    <input
                        type="checkbox"
                        :class="checkbox"
                        :checked="chosen.includes(row.id)"
                        :aria-label="$t(':email wählen', { email: row.email })"
                        @change="
                            toggle(
                                row.id,
                                ($event.target as HTMLInputElement).checked,
                            )
                        "
                    />
                </td>
                <td class="px-3 py-2.5 text-[12.5px]">
                    <div class="flex items-start gap-2.5">
                        <Hex
                            :label="row.initials"
                            :tone="row.status === 'pending' ? 'lava' : 'ash'"
                            size="md"
                        />
                        <div class="min-w-0">
                            <p class="font-semibold break-words text-basalt">
                                {{ row.name ?? row.email }}
                                <StateTag v-if="row.invited" class="ml-1">{{
                                    $t('eingeladen')
                                }}</StateTag>
                            </p>
                            <p
                                class="text-[11.5px] break-all text-basalt-light"
                            >
                                <template v-if="row.name"
                                    >{{ row.email }} · </template
                                >{{ row.locale }}
                            </p>
                        </div>
                    </div>
                </td>
                <td class="px-3 py-2.5 font-mono text-[12px] text-basalt-light">
                    {{ row.since }}
                </td>
                <td class="px-3 py-2.5 font-mono text-[12px] text-basalt-light">
                    {{ row.last_login ?? '—' }}
                </td>
                <td class="px-3 py-2.5">
                    <StateTag :tone="memberTone(row.status)">{{
                        row.status_label
                    }}</StateTag>
                </td>
                <td class="px-3 py-2.5">
                    <div class="flex flex-wrap justify-end gap-1.5">
                        <Button
                            v-if="row.status !== 'active'"
                            :variant="
                                row.status === 'pending'
                                    ? 'primary'
                                    : 'secondary'
                            "
                            size="sm"
                            :disabled="busy"
                            @click="setStatus(row, 'active')"
                            >{{ $t('Freigeben') }}</Button
                        >
                        <Button
                            v-if="row.status !== 'blocked'"
                            variant="secondary"
                            size="sm"
                            :disabled="busy"
                            @click="setStatus(row, 'blocked')"
                            >{{ $t('Sperren') }}</Button
                        >
                        <Button
                            v-if="can.manage"
                            variant="danger"
                            size="sm"
                            :disabled="busy"
                            @click="remove(row)"
                            >{{ $t('Löschen') }}</Button
                        >
                    </div>
                </td>
            </tr>
            <template #foot>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span
                        >{{
                            $tc(':count Mitglied|:count Mitglieder', counts.all)
                        }}
                        ·
                        {{ $tc(':count wartet|:count warten', counts.pending) }}
                        ·
                        {{
                            $tc(
                                ':count gesperrt|:count gesperrt',
                                counts.blocked,
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

        <div class="rounded-lg border border-ash-light bg-white lg:hidden">
            <ul class="divide-y divide-ash-light">
                <li
                    v-for="row in rows"
                    :key="row.id"
                    class="px-3 py-2.5"
                    :class="
                        row.status === 'pending'
                            ? 'shadow-[inset_3px_0_0_var(--color-lava)]'
                            : ''
                    "
                >
                    <div class="flex gap-2.5">
                        <input
                            type="checkbox"
                            :class="[checkbox, 'mt-1 shrink-0']"
                            :checked="chosen.includes(row.id)"
                            :aria-label="
                                $t(':email wählen', { email: row.email })
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
                                    >{{ row.name ?? row.email }}</strong
                                >
                                <StateTag :tone="memberTone(row.status)">{{
                                    row.status_label
                                }}</StateTag>
                            </p>
                            <p class="text-[11px] break-all text-basalt-light">
                                <template v-if="row.name"
                                    >{{ row.email }} · </template
                                >{{ row.locale }} ·
                                <span class="font-mono">{{ row.since }}</span>
                            </p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <Button
                                    v-if="row.status !== 'active'"
                                    :variant="
                                        row.status === 'pending'
                                            ? 'primary'
                                            : 'secondary'
                                    "
                                    size="sm"
                                    :disabled="busy"
                                    @click="setStatus(row, 'active')"
                                    >{{ $t('Freigeben') }}</Button
                                >
                                <Button
                                    v-if="row.status !== 'blocked'"
                                    variant="secondary"
                                    size="sm"
                                    :disabled="busy"
                                    @click="setStatus(row, 'blocked')"
                                    >{{ $t('Sperren') }}</Button
                                >
                                <Button
                                    v-if="can.manage"
                                    variant="danger"
                                    size="sm"
                                    :disabled="busy"
                                    @click="remove(row)"
                                    >{{ $t('Löschen') }}</Button
                                >
                            </div>
                        </div>
                    </div>
                </li>
            </ul>
            <div
                v-if="pagination.last > 1"
                class="flex flex-wrap items-center justify-between gap-2 border-t border-ash-light bg-paper px-3 py-2 text-[11.5px] text-basalt-light"
            >
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
            </div>
        </div>
    </template>

    <div class="mt-4 grid gap-3 lg:grid-cols-2">
        <Card :title="$t('Einladen')">
            <form class="mt-2" @submit.prevent="sendInvite">
                <div class="grid gap-x-4 gap-y-2.5 *:mt-0! sm:grid-cols-2">
                    <Field
                        v-model="invite.email"
                        :label="$t('E-Mail')"
                        type="email"
                        required
                        autocomplete="off"
                        :error="invite.errors.email"
                    />
                    <Field
                        v-model="invite.name"
                        :label="$t('Name')"
                        optional
                        autocomplete="off"
                        :error="invite.errors.name"
                    />
                    <Field
                        v-model="invite.locale"
                        :label="$t('Sprache der Mail')"
                        type="select"
                        :options="localeOptions"
                        :error="invite.errors.locale"
                    />
                </div>
                <p class="mt-2 text-[11.5px] text-basalt-light">
                    {{
                        $t(
                            'Die Person ist sofort Mitglied und bekommt eine Mail mit dem Weg zur Anmeldung — ohne Passwort, mit Code.',
                        )
                    }}
                </p>
                <Button
                    type="submit"
                    size="sm"
                    class="mt-2"
                    :disabled="invite.processing"
                    >{{ $t('Einladung senden') }}</Button
                >
            </form>
        </Card>

        <Card v-if="can.manage" :title="$t('Einstellungen')">
            <form class="mt-2" @submit.prevent="saveSettings">
                <fieldset>
                    <legend :class="labelClasses">
                        {{ $t('Wie man Mitglied wird') }}
                    </legend>
                    <label
                        v-for="r in registrations"
                        :key="r.value"
                        class="flex items-start gap-2 py-1 text-[12.5px]"
                    >
                        <input
                            v-model="settings.registration"
                            type="radio"
                            name="registration"
                            :value="r.value"
                            class="mt-0.5 accent-lava-dark"
                        />
                        <span
                            >{{ r.label }}
                            <span
                                class="block text-[11.5px] text-basalt-light"
                                >{{ registrationHelp[r.value] }}</span
                            ></span
                        >
                    </label>
                    <p
                        v-if="settings.errors.registration"
                        class="text-danger mt-1 text-[11.5px] font-semibold"
                    >
                        {{ settings.errors.registration }}
                    </p>
                </fieldset>
                <Field
                    v-model="settings.login_path"
                    class="mt-2.5"
                    :label="$t('Anmeldeseite')"
                    mono
                    optional
                    :placeholder="props.settings.login_default"
                    :error="settings.errors.login_path"
                    :help="
                        $t(
                            'Pfad für «Anmelden» im Seitenkopf und in den Mails · leer = :path (die Anmeldeseite im Layout des Themes)',
                            { path: props.settings.login_default },
                        )
                    "
                />
                <Button
                    type="submit"
                    size="sm"
                    class="mt-2"
                    :disabled="settings.processing"
                    >{{ $t('Speichern') }}</Button
                >
            </form>
        </Card>

        <Card :title="$t('Geschützte Seiten')">
            <template #right>{{
                $tc(':count Seite|:count Seiten', props.protected.length)
            }}</template>
            <ul
                v-if="props.protected.length > 0"
                class="mt-2 space-y-1 text-[12.5px]"
            >
                <li v-for="p in props.protected" :key="p.id">
                    <a
                        :href="p.edit"
                        class="font-semibold text-lava-dark underline hover:text-basalt"
                        >{{ p.title }}</a
                    >
                    <span
                        v-if="p.path"
                        class="ml-1.5 font-mono text-[11.5px] break-all text-basalt-light"
                        >{{ p.path }}</span
                    >
                </li>
            </ul>
            <p v-else class="mt-2 text-[12.5px] text-basalt-light">
                {{
                    $t(
                        'Noch keine. Eine Seite schützen Sie in ihren Einstellungen, Reiter «Zugriff» — Unterseiten erben den Schutz.',
                    )
                }}
                <a :href="urls.pages" class="text-lava-dark underline">{{
                    $t('Zu den Seiten')
                }}</a>
            </p>
        </Card>

        <Card :title="$t('Datenschutz')">
            <template #right>{{ $t('DSG · N-05') }}</template>
            <dl
                class="mt-2 grid gap-x-4 gap-y-1.5 text-[12.5px] sm:grid-cols-[9rem_1fr]"
            >
                <dt class="text-basalt-light">{{ $t('Gespeichert') }}</dt>
                <dd>
                    {{
                        $t(
                            'E-Mail, Name, Sprache, Stand und Zeitpunkte — kein Passwort',
                        )
                    }}
                </dd>
                <dt class="text-basalt-light">{{ $t('Anmeldecodes') }}</dt>
                <dd>
                    {{ $t('nur als Prüfsumme, nach einem Tag gelöscht') }}
                </dd>
                <dt class="text-basalt-light">{{ $t('Export der Site') }}</dt>
                <dd>{{ $t('ohne Mitglieder — sie sind Personendaten') }}</dd>
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
    </div>
</template>
