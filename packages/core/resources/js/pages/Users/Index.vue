<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import { roleHelp, usersLead } from '../../components/admin';
import {
    announceError,
    announceSaved,
    announceSaving,
} from '../../components/autosave';
import Button from '../../components/Button.vue';
import Card from '../../components/Card.vue';
import { controlClasses, labelClasses } from '../../components/control';
import EmptyState from '../../components/EmptyState.vue';
import Field from '../../components/Field.vue';
import Hex from '../../components/Hex.vue';
import Notice from '../../components/Notice.vue';
import PageHeader from '../../components/PageHeader.vue';
import PairRow from '../../components/PairRow.vue';
import StateTag from '../../components/StateTag.vue';
import Table from '../../components/Table.vue';
import Tabs from '../../components/Tabs.vue';
import { t } from '../../i18n';
import { initials } from '../../support';
import type { BackendProps, TabDef } from '../../types';
import type {
    AdminInvitation,
    AdminUser,
    UsersPageProps,
} from '../../types-admin';

/**
 * Die Benutzer (Bereich 18, Mockup 18/2): Konten und Einladungen in einer Liste, Avatar im Sechseck.
 * Ein Klick auf eine Person öffnet ihre Karte (Rolle, Sitzungen, Sitzungen beenden, Deaktivieren) — ab 1024 px
 * rechts, sonst darunter. «Einladen» öffnet das Formular mit den Reitern Konto · Rolle.
 */
const props = defineProps<UsersPageProps>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);
const flash = computed(() => page.props.backend.flash?.status ?? null);

const users = computed<AdminUser[]>(() => props.users ?? []);
const invitations = computed<AdminInvitation[]>(() => props.invitations ?? []);
const hours = computed(() => props.invitation_hours ?? 72);
const lead = computed(() => usersLead(users.value, invitations.value));

const selectedId = ref<number | null>(null);
const selected = computed(
    () => users.value.find((u) => u.id === selectedId.value) ?? null,
);
const confirming = ref(false);
const roleError = ref('');
const actionError = ref('');
const aside = ref<HTMLElement | null>(null);

async function reveal(): Promise<void> {
    await nextTick();
    if (window.matchMedia('(max-width: 1023px)').matches) {
        aside.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function select(user: AdminUser): void {
    selectedId.value = selectedId.value === user.id ? null : user.id;
    confirming.value = false;
    actionError.value = '';
    if (selectedId.value !== null) {
        void reveal();
    }
}

/** Die Rolle speichert sich beim Wählen — Rückmeldung unten rechts, die Liste lädt nur die Personen neu. */
function changeRole(user: AdminUser, event: Event): void {
    const select = event.target as HTMLSelectElement;
    const role = select.value;
    roleError.value = '';
    announceSaving();
    router.put(
        props.update_url.replace('__ID__', String(user.id)),
        { role },
        {
            preserveScroll: true,
            preserveState: true,
            async: true,
            only: ['users', 'errors'],
            onSuccess: () => {
                announceSaved(t('Rolle von :name', { name: user.name }));
            },
            onError: (errors) => {
                roleError.value = errors.role ?? Object.values(errors)[0] ?? '';
                announceError(roleError.value);
                // Die Auswahl zeigt wieder die gespeicherte Rolle
                select.value = user.role ?? '';
            },
        },
    );
}

function visitError(errors: Record<string, string>): void {
    actionError.value = Object.values(errors)[0] ?? '';
}

function endSessions(user: AdminUser): void {
    actionError.value = '';
    router.delete(user.urls.sessions_end, {
        preserveScroll: true,
        onError: visitError,
    });
}

/** Löschen (N-05): Konto und Formulareingänge endgültig, Protokoll anonymisiert — mit Rückfrage. */
function destroy(user: AdminUser): void {
    if (
        !window.confirm(
            t(
                ':name endgültig löschen? Das Konto und die Formulareingänge mit dieser Adresse verschwinden, im Protokoll bleibt kein Name. Das lässt sich nicht rückgängig machen.',
                { name: user.name },
            ),
        )
    ) {
        return;
    }
    actionError.value = '';
    router.delete(user.urls.delete, {
        preserveScroll: true,
        onError: visitError,
    });
}

function deactivate(user: AdminUser): void {
    actionError.value = '';
    router.post(
        user.urls.deactivate,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                confirming.value = false;
            },
            onError: visitError,
        },
    );
}

function activate(user: AdminUser): void {
    actionError.value = '';
    router.post(
        user.urls.activate,
        {},
        { preserveScroll: true, onError: visitError },
    );
}

/* ── Einladungen ── */

const revoking = ref<number | null>(null);

function resend(invitation: AdminInvitation): void {
    router.post(invitation.urls.resend, {}, { preserveScroll: true });
}

function revoke(invitation: AdminInvitation): void {
    router.delete(invitation.urls.revoke, {
        preserveScroll: true,
        onFinish: () => {
            revoking.value = null;
        },
    });
}

const inviting = ref(false);
const inviteTab = ref('account');
const defaultRole = computed(
    () =>
        (props.roles ?? []).find((r) => r.value === 'editor')?.value ??
        props.roles?.[0]?.value ??
        '',
);
const form = useForm({ email: '', role: defaultRole.value });

const inviteTabs = computed<TabDef[]>(() => [
    { key: 'account', label: t('Konto'), errors: form.errors.email ? 1 : 0 },
    { key: 'role', label: t('Rolle'), errors: form.errors.role ? 1 : 0 },
]);

function openInvite(): void {
    inviting.value = true;
    inviteTab.value = 'account';
    void reveal();
}

function closeInvite(): void {
    inviting.value = false;
    form.reset();
    form.clearErrors();
}

function sendInvite(): void {
    form.post(props.urls?.invite ?? '', {
        preserveScroll: true,
        onSuccess: () => {
            closeInvite();
        },
        onError: (errors) => {
            if (errors.email) {
                inviteTab.value = 'account';
            } else if (errors.role) {
                inviteTab.value = 'role';
            }
        },
    });
}

const selectClass = controlClasses({ small: true });
const hasAside = computed(() => inviting.value || selected.value !== null);
</script>

<template>
    <Head :title="$t('Benutzer')" />
    <PageHeader
        :title="$t('Benutzer')"
        :crumb="$t(':site › Verwaltung', { site: siteName })"
        :lead="lead"
    >
        <template v-if="can_manage || roles_url" #actions>
            <Button v-if="roles_url" variant="secondary" :href="roles_url">{{
                $t('Rollen')
            }}</Button>
            <Button
                v-if="can_manage"
                :disabled="inviting"
                @click="openInvite"
                >{{ $t('Einladen') }}</Button
            >
        </template>
    </PageHeader>

    <Notice v-if="status && status !== flash" tone="success" class="mb-3.5">{{
        status
    }}</Notice>
    <Notice v-if="roleError" tone="danger" class="mb-3.5">{{
        roleError
    }}</Notice>

    <div
        class="grid items-start gap-4"
        :class="hasAside ? 'lg:grid-cols-[minmax(0,1fr)_360px]' : ''"
    >
        <div class="min-w-0">
            <div
                v-if="users.length === 0 && invitations.length === 0"
                class="rounded-lg border border-ash-light bg-white"
            >
                <EmptyState
                    :title="$t('Noch kein Konto')"
                    :text="$t('Der Installer legt das erste Konto an.')"
                    icon="users"
                    tone="ash"
                />
            </div>
            <Table v-else>
                <template #head>
                    <tr>
                        <th class="px-3 py-2">{{ $t('Person') }}</th>
                        <th class="px-3 py-2">{{ $t('Rolle') }}</th>
                        <th class="px-3 py-2">{{ $t('Zuletzt') }}</th>
                        <th class="px-3 py-2">{{ $t('Passkey') }}</th>
                        <th class="px-3 py-2">{{ $t('Status') }}</th>
                    </tr>
                </template>
                <tr
                    v-for="user in users"
                    :key="`u${user.id}`"
                    class="cursor-pointer align-top hover:bg-paper/60"
                    :class="[
                        selectedId === user.id
                            ? 'bg-paper shadow-[inset_3px_0_0_var(--color-lava)]'
                            : '',
                        user.deactivated ? 'text-basalt-light' : '',
                    ]"
                    @click="select(user)"
                >
                    <td class="px-3 py-2.5">
                        <div class="flex items-center gap-2.5">
                            <Hex
                                :label="initials(user.name)"
                                size="md"
                                :tone="user.deactivated ? 'white' : 'ash'"
                                :image="user.avatar_url ?? null"
                            />
                            <div class="min-w-0">
                                <button
                                    type="button"
                                    class="text-left text-[13.5px] font-semibold hover:text-lava-dark"
                                    :aria-expanded="selectedId === user.id"
                                    @click.stop="select(user)"
                                >
                                    {{ user.name }}
                                </button>
                                <span
                                    v-if="user.is_self"
                                    class="ml-1 rounded-full border border-ash px-2 text-[10px] font-semibold text-basalt-light"
                                    >{{ $t('Sie') }}</span
                                >
                                <p
                                    class="text-[11.5px] break-all text-basalt-light"
                                >
                                    {{ user.email }}
                                </p>
                            </div>
                        </div>
                    </td>
                    <td
                        :data-label="$t('Rolle')"
                        class="px-3 py-2.5 text-[12.5px]"
                        @click.stop
                    >
                        <label
                            v-if="can_manage && !user.deactivated"
                            class="block"
                        >
                            <span class="sr-only">{{
                                $t('Rolle von :name', { name: user.name })
                            }}</span>
                            <select
                                :value="user.role ?? ''"
                                :class="[selectClass, 'w-40 min-w-36']"
                                @change="changeRole(user, $event)"
                            >
                                <option
                                    v-for="r in roles"
                                    :key="r.value"
                                    :value="r.value"
                                >
                                    {{ r.label }}
                                </option>
                            </select>
                        </label>
                        <strong v-else class="font-semibold">{{
                            user.role_label ?? '—'
                        }}</strong>
                    </td>
                    <td
                        :data-label="$t('Zuletzt')"
                        class="px-3 py-2.5 text-xs md:whitespace-nowrap"
                    >
                        {{ user.last_login ?? '—' }}
                    </td>
                    <td :data-label="$t('Passkey')" class="px-3 py-2.5 text-xs">
                        <span
                            v-if="user.passkeys > 0"
                            class="text-success"
                            :title="
                                $tc(
                                    ':count Passkey|:count Passkeys',
                                    user.passkeys,
                                )
                            "
                            >✓</span
                        >
                        <span v-else class="text-ash-dark">—</span>
                    </td>
                    <td :data-label="$t('Status')" class="px-3 py-2.5">
                        <StateTag v-if="user.deactivated">{{
                            $t('deaktiviert')
                        }}</StateTag>
                        <StateTag v-else tone="success">{{
                            $t('aktiv')
                        }}</StateTag>
                    </td>
                </tr>
                <tr
                    v-for="inv in invitations"
                    :key="`i${inv.id}`"
                    class="align-top"
                    :class="
                        inv.expired
                            ? 'bg-warning/5 shadow-[inset_3px_0_0_var(--color-warning)]'
                            : ''
                    "
                >
                    <td class="px-3 py-2.5">
                        <div class="flex items-center gap-2.5">
                            <Hex
                                icon="user"
                                size="md"
                                :tone="inv.expired ? 'warning' : 'ash'"
                            />
                            <div class="min-w-0">
                                <p
                                    class="text-[13.5px] font-semibold break-all"
                                >
                                    {{ inv.email }}
                                </p>
                                <p class="text-[11.5px] text-basalt-light">
                                    {{
                                        inv.invited_by
                                            ? $t('eingeladen von :person', {
                                                  person: inv.invited_by,
                                              })
                                            : $t('eingeladen')
                                    }}<template v-if="inv.expires_at">
                                        ·
                                        {{
                                            inv.expired
                                                ? $t('abgelaufen :date', {
                                                      date: inv.expires_at,
                                                  })
                                                : $t('gültig bis :date', {
                                                      date: inv.expires_at,
                                                  })
                                        }}</template
                                    >
                                </p>
                            </div>
                        </div>
                    </td>
                    <td
                        :data-label="$t('Rolle')"
                        class="px-3 py-2.5 text-[12.5px] font-semibold"
                    >
                        {{ inv.role_label }}
                    </td>
                    <td :data-label="$t('Zuletzt')" class="px-3 py-2.5 text-xs">
                        —
                    </td>
                    <td :data-label="$t('Passkey')" class="px-3 py-2.5 text-xs">
                        <span class="text-ash-dark">—</span>
                    </td>
                    <td :data-label="$t('Status')" class="px-3 py-2.5">
                        <StateTag :tone="inv.expired ? 'warning' : 'lava'">{{
                            inv.expired
                                ? $t('eingeladen · abgelaufen')
                                : $t('eingeladen')
                        }}</StateTag>
                        <div
                            v-if="can_manage"
                            class="mt-1 flex flex-wrap items-center gap-x-1"
                        >
                            <template v-if="revoking === inv.id">
                                <span class="text-[11.5px] text-basalt-light">{{
                                    $t('Zurückziehen?')
                                }}</span>
                                <Button
                                    variant="link"
                                    size="sm"
                                    @click="revoke(inv)"
                                    >{{ $t('Ja') }}</Button
                                >
                                <Button
                                    variant="link"
                                    size="sm"
                                    @click="revoking = null"
                                    >{{ $t('Nein') }}</Button
                                >
                            </template>
                            <template v-else>
                                <Button
                                    variant="link"
                                    size="sm"
                                    @click="resend(inv)"
                                    >{{ $t('Erneut senden') }}</Button
                                >
                                <Button
                                    variant="link"
                                    size="sm"
                                    @click="revoking = inv.id"
                                    >{{ $t('Zurückziehen') }}</Button
                                >
                            </template>
                        </div>
                    </td>
                </tr>
                <template #foot>
                    {{ lead }} ·
                    {{
                        $t(
                            'Ein gelöschter Benutzer erscheint im Protokoll anonymisiert, nie entfernt.',
                        )
                    }}
                </template>
            </Table>
        </div>

        <div
            v-if="hasAside"
            ref="aside"
            class="flex min-w-0 scroll-mt-4 flex-col gap-4"
        >
            <section
                v-if="inviting"
                class="rounded-lg border border-ash-light bg-white"
                :aria-label="$t('Benutzer einladen')"
            >
                <div class="px-3.5 pt-3">
                    <h2 class="text-sm font-semibold">
                        {{ $t('Benutzer einladen') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-basalt-light">
                        {{
                            $t(
                                'Für :site · der Link geht per Mail an die Adresse',
                                { site: siteName },
                            )
                        }}
                    </p>
                </div>
                <form
                    class="px-3.5 pt-2 pb-3"
                    novalidate
                    @submit.prevent="sendInvite"
                >
                    <Tabs v-model="inviteTab" :tabs="inviteTabs">
                        <template #account>
                            <Field
                                v-model="form.email"
                                :label="$t('E-Mail')"
                                type="email"
                                autocomplete="off"
                                required
                                :error="form.errors.email"
                                :help="
                                    $tc(
                                        'Link :count Stunde gültig, einmal verwendbar|Link :count Stunden gültig, einmal verwendbar',
                                        hours,
                                    )
                                "
                            />
                        </template>
                        <template #role>
                            <fieldset>
                                <legend :class="labelClasses">
                                    {{ $t('Rolle') }}
                                </legend>
                                <div class="flex flex-col gap-1.5">
                                    <label
                                        v-for="r in roles"
                                        :key="r.value"
                                        class="flex cursor-pointer items-start gap-2 rounded-md border px-2.5 py-2 text-[12.5px]"
                                        :class="
                                            form.role === r.value
                                                ? 'border-lava-dark bg-lava/10'
                                                : 'border-ash-light hover:border-ash'
                                        "
                                    >
                                        <input
                                            v-model="form.role"
                                            type="radio"
                                            name="invite-role"
                                            :value="r.value"
                                            class="mt-0.5"
                                        />
                                        <span>
                                            <span
                                                class="block font-semibold"
                                                :class="
                                                    form.role === r.value
                                                        ? 'text-lava-dark'
                                                        : ''
                                                "
                                                >{{ r.label }}</span
                                            >
                                            <span
                                                v-if="roleHelp[r.value]"
                                                class="block text-[11.5px] text-basalt-light"
                                                >{{ roleHelp[r.value] }}</span
                                            >
                                        </span>
                                    </label>
                                </div>
                                <p
                                    v-if="form.errors.role"
                                    class="text-danger mt-1 text-[11.5px] font-semibold"
                                    role="alert"
                                >
                                    {{ form.errors.role }}
                                </p>
                            </fieldset>
                        </template>
                    </Tabs>
                    <div
                        class="mt-3 flex flex-wrap justify-end gap-2 border-t border-ash-light pt-3"
                    >
                        <Button variant="secondary" @click="closeInvite">{{
                            $t('Abbrechen')
                        }}</Button>
                        <Button type="submit" :disabled="form.processing">{{
                            $t('Einladung senden')
                        }}</Button>
                    </div>
                </form>
            </section>

            <Card
                v-if="selected"
                :sub="
                    $t(':role · zuletzt :date', {
                        role: selected.role_label ?? $t('ohne Rolle'),
                        date: selected.last_login ?? '—',
                    })
                "
            >
                <template #title>
                    <span class="flex items-center gap-2">
                        <Hex :label="initials(selected.name)" size="md" />
                        {{ selected.name }}
                    </span>
                </template>
                <template #right>
                    <button
                        type="button"
                        class="text-basalt-light hover:text-basalt"
                        :aria-label="$t('Karte schliessen')"
                        @click="selectedId = null"
                    >
                        ✕
                    </button>
                </template>

                <dl>
                    <PairRow :label="$t('E-Mail')">
                        <span class="break-all">{{ selected.email }}</span>
                    </PairRow>
                    <PairRow :label="$t('Rolle')">
                        <label
                            v-if="can_manage && !selected.deactivated"
                            class="inline-block"
                        >
                            <span class="sr-only">{{ $t('Rolle') }}</span>
                            <select
                                :value="selected.role ?? ''"
                                :class="[selectClass, 'w-40 font-normal']"
                                @change="changeRole(selected, $event)"
                            >
                                <option
                                    v-for="r in roles"
                                    :key="r.value"
                                    :value="r.value"
                                >
                                    {{ r.label }}
                                </option>
                            </select>
                        </label>
                        <template v-else>{{
                            selected.role_label ?? '—'
                        }}</template>
                    </PairRow>
                    <PairRow :label="$t('Passkey')">{{
                        selected.passkeys > 0
                            ? $tc(
                                  ':count registriert|:count registriert',
                                  selected.passkeys,
                              )
                            : $t('keiner')
                    }}</PairRow>
                    <PairRow :label="$t('Status')">{{
                        selected.deactivated ? $t('deaktiviert') : $t('aktiv')
                    }}</PairRow>
                </dl>

                <h3 :class="[labelClasses, 'mt-3']">
                    {{ $t('Sitzungen') }} ·
                    {{ (selected.sessions ?? []).length }}
                </h3>
                <p
                    v-if="(selected.sessions ?? []).length === 0"
                    class="text-[12.5px] text-basalt-light"
                >
                    {{ $t('Keine aktive Sitzung.') }}
                </p>
                <ul v-else class="divide-y divide-ash-light text-[12.5px]">
                    <li
                        v-for="s in selected.sessions"
                        :key="s.id"
                        class="py-1.5"
                    >
                        <span class="font-semibold">{{ s.agent }}</span>
                        <StateTag v-if="s.current" tone="lava" class="ml-1">{{
                            $t('diese Sitzung')
                        }}</StateTag>
                        <span class="block text-[11.5px] text-basalt-light">
                            <span class="font-mono">{{ s.ip ?? '—' }}</span>
                            ·
                            {{
                                $t('zuletzt aktiv :date', {
                                    date: s.last_active ?? '—',
                                })
                            }}
                        </span>
                    </li>
                </ul>

                <p
                    v-if="actionError"
                    class="text-danger mt-2 text-[11.5px] font-semibold"
                    role="alert"
                >
                    {{ actionError }}
                </p>

                <template v-if="can_manage">
                    <div
                        v-if="!confirming && !selected.is_self"
                        class="mt-3 flex flex-wrap gap-2"
                    >
                        <Button
                            variant="secondary"
                            size="sm"
                            :disabled="(selected.sessions ?? []).length === 0"
                            @click="endSessions(selected)"
                            >{{ $t('Sitzungen beenden') }}</Button
                        >
                        <Button
                            v-if="selected.deactivated"
                            variant="secondary"
                            size="sm"
                            @click="activate(selected)"
                            >{{ $t('Aktivieren') }}</Button
                        >
                        <Button
                            v-else
                            variant="danger"
                            size="sm"
                            @click="confirming = true"
                            >{{ $t('Deaktivieren') }}</Button
                        >
                        <a
                            :href="selected.urls.export"
                            class="inline-flex items-center rounded-md border border-ash px-2.5 py-1 text-[12px] font-semibold hover:border-basalt"
                            >{{ $t('Auskunft (JSON)') }}</a
                        >
                        <Button
                            variant="danger"
                            size="sm"
                            @click="destroy(selected)"
                            >{{ $t('Löschen') }}</Button
                        >
                    </div>
                    <div
                        v-else-if="confirming"
                        class="border-danger/35 mt-3 rounded-md border px-3 py-2.5"
                        role="alertdialog"
                        :aria-label="$t('Deaktivieren bestätigen')"
                    >
                        <p class="text-[12.5px] font-semibold">
                            {{
                                $t(':name deaktivieren?', {
                                    name: selected.name,
                                })
                            }}
                        </p>
                        <p class="mt-0.5 text-[11.5px] text-basalt-light">
                            {{
                                $t(
                                    'Alle Sitzungen enden sofort, Anmelden ist gesperrt.',
                                )
                            }}
                        </p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <Button
                                variant="danger"
                                size="sm"
                                @click="deactivate(selected)"
                                >{{ $t('Ja, deaktivieren') }}</Button
                            >
                            <Button
                                variant="secondary"
                                size="sm"
                                @click="confirming = false"
                                >{{ $t('Abbrechen') }}</Button
                            >
                        </div>
                    </div>
                    <p
                        v-if="selected.is_self"
                        class="mt-2 text-[11.5px] text-basalt-light"
                    >
                        {{
                            $t(
                                'Das eigene Konto lässt sich nicht deaktivieren; eigene Sitzungen beenden Sie im Profil.',
                            )
                        }}
                    </p>
                    <p v-else class="mt-2 text-[11.5px] text-basalt-light">
                        {{
                            $t(
                                'Deaktivierte Konten sind sofort ausgesperrt; Protokoll-Einträge bleiben. «Auskunft» zeigt alles, was Basalto zu dieser Person speichert; «Löschen» entfernt Konto und Formulareingänge endgültig und anonymisiert das Protokoll (DSG/DSGVO).',
                            )
                        }}
                    </p>
                </template>
            </Card>
        </div>
    </div>
</template>
