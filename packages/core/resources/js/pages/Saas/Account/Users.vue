<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AccountFrame from '../../../components/AccountFrame.vue';
import Button from '../../../components/Button.vue';
import Card from '../../../components/Card.vue';
import { controlClasses } from '../../../components/control';
import Field from '../../../components/Field.vue';
import Notice from '../../../components/Notice.vue';
import Table from '../../../components/Table.vue';
import type { AccountHeader } from '../../../types-saas';

/**
 * TB 12 Scheibe 1 (Mockup 23 · Benutzer des Kontos): Konto-Rollen Besitzer, Verwaltung, Abrechnung — getrennt von den
 * Rollen auf den Sites. Eine Konto-Rolle bekommt, wer schon Mitglied einer Site ist; neue Personen lädt man über
 * Verwaltung › Benutzer ein.
 */
type AccountUser = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    role_label: string | null;
    self: boolean;
    deactivated: boolean;
    url: string;
    last_owner: boolean;
};

const props = defineProps<{
    account: AccountHeader;
    tab: string;
    users: AccountUser[];
    candidates: { id: number; name: string; email: string }[];
    roles: { value: string; label: string }[];
    can_owner: boolean;
    urls: { store: string; users: string };
}>();

const page = usePage<{ errors: Record<string, string> }>();
const roleError = computed(() => page.props.errors.role ?? '');

const form = useForm({
    user: props.candidates[0]?.id ?? 0,
    role: 'admin',
});
const candidateOptions = computed(() =>
    props.candidates.map((c) => ({
        value: String(c.id),
        label: `${c.name} · ${c.email}`,
    })),
);
const roleOptions = computed(() =>
    props.roles.filter((r) => props.can_owner || r.value !== 'owner'),
);

function changeRole(user: AccountUser, role: string): void {
    router.put(user.url, { role }, { preserveScroll: true });
}

function remove(user: AccountUser): void {
    router.delete(user.url, { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('Konto · Benutzer')" />
    <AccountFrame :account="account" :tab="tab">
        <Notice v-if="roleError" tone="danger" class="mb-3">{{
            roleError
        }}</Notice>
        <Table>
            <template #head>
                <tr>
                    <th class="px-3 py-2">{{ $t('Benutzer') }}</th>
                    <th class="px-3 py-2">{{ $t('Konto-Rolle') }}</th>
                    <th class="px-3 py-2">
                        <span class="sr-only">{{ $t('Aktionen') }}</span>
                    </th>
                </tr>
            </template>
            <tr v-for="user in users" :key="user.id" class="align-top">
                <td class="px-3 py-2.5">
                    <span class="text-[13px] font-semibold">{{
                        user.name
                    }}</span>
                    <span
                        v-if="user.self"
                        class="ml-1.5 text-[11px] text-basalt-light"
                        >{{ $t('Sie') }}</span
                    >
                    <span
                        class="block text-[12px] break-all text-basalt-light"
                        >{{ user.email }}</span
                    >
                </td>
                <td :data-label="$t('Konto-Rolle')" class="px-3 py-2.5">
                    <template v-if="account.can_manage">
                        <label class="sr-only" :for="`role-${user.id}`">{{
                            $t('Konto-Rolle von :name', { name: user.name })
                        }}</label>
                        <select
                            :id="`role-${user.id}`"
                            :value="user.role ?? ''"
                            :class="[controlClasses({ small: true }), 'w-auto']"
                            :disabled="
                                (user.role === 'owner' && !can_owner) ||
                                user.last_owner
                            "
                            @change="
                                changeRole(
                                    user,
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option
                                v-for="r in roles"
                                :key="r.value"
                                :value="r.value"
                                :disabled="r.value === 'owner' && !can_owner"
                            >
                                {{ r.label }}
                            </option>
                        </select>
                    </template>
                    <b v-else class="text-[12.5px]">{{ user.role_label }}</b>
                </td>
                <td class="px-3 py-2.5 text-right">
                    <Button
                        v-if="
                            account.can_manage &&
                            !user.last_owner &&
                            (user.role !== 'owner' || can_owner)
                        "
                        variant="link"
                        size="sm"
                        @click="remove(user)"
                        >{{ $t('Entfernen') }}</Button
                    >
                </td>
            </tr>
            <template #foot>
                {{
                    $t(
                        'Konto-Rollen (Besitzer, Verwaltung, Abrechnung) sind getrennt von den Rollen auf den Sites.',
                    )
                }}
                <Link
                    :href="urls.users"
                    class="text-lava-dark underline hover:text-basalt"
                    >{{ $t('Rollen auf der Site') }}</Link
                >
            </template>
        </Table>

        <Card
            v-if="account.can_manage"
            :title="$t('Konto-Rolle vergeben')"
            class="mt-4 max-w-[560px]"
        >
            <p
                v-if="candidates.length === 0"
                class="text-[12.5px] text-basalt-light"
            >
                {{
                    $t(
                        'Alle Mitglieder Ihrer Sites haben schon eine Konto-Rolle. Neue Personen laden Sie über Verwaltung › Benutzer ein.',
                    )
                }}
            </p>
            <form
                v-else
                novalidate
                @submit.prevent="
                    form.post(urls.store, { preserveScroll: true })
                "
            >
                <div class="mt-2.5 grid gap-x-4 gap-y-2.5 sm:grid-cols-2">
                    <Field
                        class="mt-0!"
                        :model-value="String(form.user)"
                        :label="$t('Mitglied einer Site')"
                        type="select"
                        :options="candidateOptions"
                        :error="form.errors.user"
                        @update:model-value="form.user = Number($event)"
                    />
                    <Field
                        class="mt-0!"
                        v-model="form.role"
                        :label="$t('Konto-Rolle')"
                        type="select"
                        :options="roleOptions"
                        :error="form.errors.role"
                    />
                </div>
                <div class="mt-3">
                    <Button type="submit" :disabled="form.processing">{{
                        $t('Rolle vergeben')
                    }}</Button>
                </div>
            </form>
        </Card>
    </AccountFrame>
</template>
