<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import type { HttpExceptionResponse } from '@inertiajs/core';
import { computed, ref } from 'vue';
import {
    announceError,
    announceSaved,
    announceSaving,
} from '../../components/autosave';
import Button from '../../components/Button.vue';
import Card from '../../components/Card.vue';
import { controlClasses } from '../../components/control';
import EmptyState from '../../components/EmptyState.vue';
import Field from '../../components/Field.vue';
import Hex from '../../components/Hex.vue';
import Notice from '../../components/Notice.vue';
import PageHeader from '../../components/PageHeader.vue';
import StateTag from '../../components/StateTag.vue';
import Table from '../../components/Table.vue';
import { t } from '../../i18n';
import { initials } from '../../support';
import type { BackendProps } from '../../types';
import type { TeamProps } from '../../types-extranet';

/**
 * Das Team des Hersteller-Extranets (CR-004 P-09, CR-005 R-12): oben die Inhaber (Besitz und Verwaltung des
 * Installationskontos, dürfen alles, nicht änderbar), darunter die weiteren Personen mit ihrer Rolle — dieselben
 * Rollen wie im CMS. Die Rolle speichert sich beim Wählen; Entfernen fragt nach. «Person hinzufügen» legt das Konto
 * an und schickt die Mail mit dem Zugang. Die Rollen selbst stellt man im CMS ein (anderer Host, daher `<a href>`).
 */
type Member = TeamProps['members'][number];

const props = defineProps<TeamProps>();

const page = usePage<BackendProps>();
const pageErrors = computed(() => {
    const errors = (page.props.errors ?? {}) as Record<string, string>;
    const own = ['name', 'email', 'role'];

    return Object.entries(errors)
        .filter(([key]) => !own.includes(key))
        .map(([, message]) => message);
});

const selectClass = controlClasses({ small: true });
const rowError = ref('');

function exceptionMessage(response: HttpExceptionResponse): string {
    const data = response.data;
    if (typeof data === 'object' && typeof data.message === 'string') {
        return data.message;
    }

    return response.status === 403
        ? t('Dafür fehlen Ihnen die Rechte.')
        : t('Das hat nicht geklappt. Bitte noch einmal versuchen.');
}

/** Die Rolle speichert sich beim Wählen; bei einem Fehler zeigt die Auswahl wieder die gespeicherte Rolle. */
function changeRole(member: Member, event: Event): void {
    const select = event.target as HTMLSelectElement;
    const role = select.value;
    const fail = (message: string): void => {
        rowError.value = message;
        announceError(message);
        select.value = member.role;
    };
    rowError.value = '';
    announceSaving();
    router.put(
        member.urls.update,
        { role },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                announceSaved(t('Rolle von :name', { name: member.name }));
            },
            onError: (errors) => {
                fail(errors.role ?? Object.values(errors)[0] ?? '');
            },
            onHttpException: (response) => {
                fail(exceptionMessage(response));

                return false;
            },
        },
    );
}

function remove(member: Member): void {
    if (
        !window.confirm(
            t(
                ':name aus dem Team entfernen? Das Konto bleibt bestehen, der Zugang zum Extranet endet sofort.',
                { name: member.name },
            ),
        )
    ) {
        return;
    }
    rowError.value = '';
    router.delete(member.urls.destroy, {
        preserveScroll: true,
        onError: (errors) => {
            rowError.value = Object.values(errors)[0] ?? '';
        },
        onHttpException: (response) => {
            rowError.value = exceptionMessage(response);

            return false;
        },
    });
}

const defaultRole = computed(
    () =>
        props.roles.find((r) => r.value === 'staff')?.value ??
        props.roles[0]?.value ??
        '',
);
const form = useForm({ name: '', email: '', role: defaultRole.value });

function add(): void {
    form.post(props.urls.store, {
        preserveScroll: true,
        onSuccess: () => form.reset('name', 'email'),
        onHttpException: (response) => {
            form.setError('role', exceptionMessage(response));

            return false;
        },
    });
}
</script>

<template>
    <Head :title="$t('Team')" />
    <PageHeader
        :title="$t('Team')"
        :crumb="$t('Extranet › Team')"
        :lead="
            $t(
                'Wer im Extranet arbeitet und was er darf. Die Rollen sind dieselben wie im CMS.',
            )
        "
    />

    <Notice
        v-for="message in pageErrors"
        :key="message"
        tone="danger"
        class="mb-3.5"
        >{{ message }}</Notice
    >
    <Notice v-if="rowError" tone="danger" class="mb-3.5">{{ rowError }}</Notice>

    <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div class="flex min-w-0 flex-col gap-4">
            <section :aria-labelledby="'team-owners'">
                <h2 id="team-owners" class="mb-1 text-sm font-semibold">
                    {{ $t('Inhaber') }}
                </h2>
                <p class="mb-2 text-xs text-basalt-light">
                    {{
                        $t(
                            'Besitz und Verwaltung des Installationskontos — dürfen alles, die Rolle lässt sich hier nicht ändern.',
                        )
                    }}
                </p>
                <ul
                    class="divide-y divide-ash-light rounded-lg border border-ash-light bg-white"
                >
                    <li
                        v-for="owner in owners"
                        :key="owner.email"
                        class="flex flex-wrap items-center gap-2.5 px-3 py-2.5"
                    >
                        <Hex
                            :label="initials(owner.name)"
                            size="md"
                            tone="ash"
                        />
                        <div class="min-w-0 flex-1">
                            <p class="text-[13.5px] font-semibold break-words">
                                {{ owner.name }}
                            </p>
                            <p
                                class="text-[11.5px] break-all text-basalt-light"
                            >
                                {{ owner.email }}
                            </p>
                        </div>
                        <StateTag tone="lava">{{ $t('darf alles') }}</StateTag>
                    </li>
                    <li
                        v-if="owners.length === 0"
                        class="px-3 py-2.5 text-[12.5px] text-basalt-light"
                    >
                        {{ $t('Kein Inhaber eingetragen.') }}
                    </li>
                </ul>
            </section>

            <section :aria-labelledby="'team-members'">
                <h2 id="team-members" class="mb-2 text-sm font-semibold">
                    {{ $t('Team') }}
                </h2>
                <div
                    v-if="members.length === 0"
                    class="rounded-lg border border-ash-light bg-white"
                >
                    <EmptyState
                        :title="$t('Noch niemand im Team')"
                        :text="
                            $t(
                                'Fügen Sie Personen hinzu, die im Extranet mitarbeiten — mit genau den Rechten, die sie brauchen.',
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
                            <th class="px-3 py-2">{{ $t('Rolle') }}</th>
                            <th class="px-3 py-2">
                                <span class="sr-only">{{
                                    $t('Aktionen')
                                }}</span>
                            </th>
                        </tr>
                    </template>
                    <tr
                        v-for="member in members"
                        :key="member.id"
                        class="align-top"
                    >
                        <td class="px-3 py-2.5">
                            <span class="flex items-center gap-2.5">
                                <Hex
                                    :label="initials(member.name)"
                                    size="md"
                                    tone="ash"
                                />
                                <span
                                    class="min-w-0 text-[13.5px] font-semibold break-words"
                                    >{{ member.name }}</span
                                >
                            </span>
                        </td>
                        <td
                            :data-label="$t('E-Mail')"
                            class="px-3 py-2.5 text-[12.5px] break-all"
                        >
                            {{ member.email }}
                        </td>
                        <td :data-label="$t('Rolle')" class="px-3 py-2.5">
                            <label class="block">
                                <span class="sr-only">{{
                                    $t('Rolle von :name', { name: member.name })
                                }}</span>
                                <select
                                    :value="member.role"
                                    :class="[selectClass, 'w-full min-w-36']"
                                    @change="changeRole(member, $event)"
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
                        </td>
                        <td class="px-3 py-2.5 md:text-right">
                            <Button
                                variant="danger"
                                size="sm"
                                @click="remove(member)"
                                >{{ $t('Entfernen') }}</Button
                            >
                        </td>
                    </tr>
                </Table>
            </section>
        </div>

        <div class="flex min-w-0 flex-col gap-4">
            <Card :title="$t('Person hinzufügen')">
                <template #sub>{{
                    $t('Sie bekommt eine Mail mit dem Zugang zum Extranet.')
                }}</template>
                <form @submit.prevent="add">
                    <Field
                        v-model="form.name"
                        :label="$t('Name')"
                        autocomplete="off"
                        required
                        :error="form.errors.name ?? ''"
                    />
                    <Field
                        v-model="form.email"
                        type="email"
                        :label="$t('E-Mail')"
                        autocomplete="off"
                        required
                        :error="form.errors.email ?? ''"
                    />
                    <Field
                        v-model="form.role"
                        type="select"
                        :label="$t('Rolle')"
                        :options="roles"
                        required
                        :error="form.errors.role ?? ''"
                    />
                    <div class="mt-3 flex justify-end">
                        <Button type="submit" :disabled="form.processing">{{
                            $t('Hinzufügen')
                        }}</Button>
                    </div>
                </form>
            </Card>
            <Notice tone="grey">
                {{
                    $t(
                        'Was eine Rolle im Extranet darf, stellen Sie im CMS ein — je Bereich und Stufe.',
                    )
                }}
                <a
                    :href="urls.roles"
                    class="font-semibold text-lava-dark underline hover:text-basalt"
                    >{{ $t('Rollen einstellen') }}</a
                >
            </Notice>
        </div>
    </div>
</template>
