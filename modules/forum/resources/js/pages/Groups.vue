<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { t } from '@core/i18n';
import Button from '@core/components/Button.vue';
import { controlClasses, labelClasses } from '@core/components/control';
import Modal from '@core/components/Modal.vue';
import Notice from '@core/components/Notice.vue';
import PageHeader from '@core/components/PageHeader.vue';
import StateTag from '@core/components/StateTag.vue';
import type {
    ForumGroupsProps,
    GroupRow,
    PermissionCell,
    PermissionRow,
} from '../types';

/**
 * Extranet › Community › Gruppen und Rechte (CR-006 FO-03, Vorbild ACP «Benutzergruppen»). Oben die Gruppen mit
 * Abzeichen und Mitgliedern, darunter die Rechte — global oder für ein Forum. Je Gruppe: erben, Ja, Nein oder Nie;
 * «Nie» schlägt jedes «Ja» einer anderen Gruppe. Daneben steht, was gerade gilt und woher es kommt. Ohne Tabelle,
 * damit nichts waagrecht scrollt: je Recht eine Karte mit den Gruppen.
 */
const props = defineProps<ForumGroupsProps>();

const VALUES: { value: number | null; label: string }[] = [
    { value: null, label: t('erben') },
    { value: 1, label: t('Ja') },
    { value: 0, label: t('Nein') },
    { value: -1, label: t('Nie') },
];

function valueLabel(value: number | null): string {
    return VALUES.find((v) => v.value === value)?.label ?? t('nicht gesetzt');
}

function groupName(id: number): string {
    return props.groups.find((g) => g.id === id)?.name ?? '';
}

function setCell(row: PermissionRow, cell: PermissionCell, raw: string): void {
    router.post(
        props.urls.permission,
        {
            group: cell.group,
            permission: row.key,
            value: raw === '' ? null : Number(raw),
            forum: props.forum?.id ?? null,
        },
        { preserveScroll: true },
    );
}

function chooseForum(raw: string): void {
    router.get(props.urls.self, raw === '' ? {} : { forum: raw });
}

/* Neue Gruppe */
const creating = ref(false);
const groupForm = useForm({ name: '', badge: '' });
function storeGroup(): void {
    groupForm.post(props.urls.store, {
        preserveScroll: true,
        onSuccess: () => {
            creating.value = false;
            groupForm.reset();
        },
    });
}

/* Gruppe bearbeiten */
const editing = ref<GroupRow | null>(null);
const editForm = useForm({ name: '', badge: '' });
function edit(group: GroupRow): void {
    editing.value = group;
    editForm.name = group.name;
    editForm.badge = group.badge ?? '';
    editForm.clearErrors();
}
function updateGroup(): void {
    if (editing.value) {
        editForm.put(editing.value.update_url, {
            preserveScroll: true,
            onSuccess: () => {
                editing.value = null;
            },
        });
    }
}
function destroyGroup(group: GroupRow): void {
    if (
        group.destroy_url &&
        window.confirm(t('Gruppe «:name» löschen?', { name: group.name }))
    ) {
        router.delete(group.destroy_url, { preserveScroll: true });
    }
}

/* Mitglieder: Konto suchen und hinzufügen */
const search = ref<Record<number, string>>({});
const found = ref<
    Record<number, { id: number; email: string; name: string }[]>
>({});
async function find(group: GroupRow): Promise<void> {
    const q = (search.value[group.id] ?? '').trim();
    if (q.length < 2) {
        found.value[group.id] = [];

        return;
    }
    const response = await fetch(
        `${props.urls.customers}?q=${encodeURIComponent(q)}`,
        { headers: { Accept: 'application/json' } },
    );
    found.value[group.id] = response.ok
        ? ((await response.json()) as {
              id: number;
              email: string;
              name: string;
          }[])
        : [];
}
function addMember(group: GroupRow, customer: number): void {
    if (group.add_member_url) {
        router.post(
            group.add_member_url,
            { customer },
            {
                preserveScroll: true,
                onSuccess: () => {
                    search.value[group.id] = '';
                    found.value[group.id] = [];
                },
            },
        );
    }
}
function removeMember(url: string): void {
    router.delete(url, { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('Gruppen und Rechte')" />
    <PageHeader
        :title="$t('Gruppen und Rechte')"
        :crumb="$t('Community')"
        :lead="
            $t(
                'Wer sieht, liest und schreibt wo? Rechte gelten global und lassen sich je Forum überschreiben — Unterforen erben.',
            )
        "
    >
        <template #actions>
            <Button :href="urls.overview" variant="secondary">{{
                $t('Übersicht')
            }}</Button>
            <Button @click="creating = true">{{ $t('Neue Gruppe') }}</Button>
        </template>
    </PageHeader>

    <section class="mb-5">
        <h2 class="mb-2 text-sm font-semibold text-basalt">
            {{ $t('Gruppen') }}
        </h2>
        <ul class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <li
                v-for="group in groups"
                :key="group.id"
                class="flex flex-col gap-2 rounded-lg border border-ash-light bg-white p-3"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <b class="font-semibold text-basalt">{{ group.name }}</b>
                    <StateTag v-if="group.badge" tone="lava">{{
                        group.badge
                    }}</StateTag>
                    <StateTag v-if="group.system" tone="grey">{{
                        $t('System')
                    }}</StateTag>
                </div>
                <p
                    v-if="!group.assignable"
                    class="text-[11.5px] text-basalt-light"
                >
                    {{
                        group.key === 'guests'
                            ? $t('Alle, die nicht angemeldet sind.')
                            : group.key === 'everyone'
                              ? $t('Gäste und alle Konten.')
                              : group.key === 'members'
                                ? $t('Jedes angemeldete Konto.')
                                : $t(
                                      'Konten, die mit dem Extranet verknüpft sind.',
                                  )
                    }}
                </p>
                <template v-else>
                    <p class="text-[11.5px] text-basalt-light">
                        {{
                            $tc(
                                ':count Mitglied|:count Mitglieder',
                                group.members ?? 0,
                            )
                        }}
                    </p>
                    <ul
                        v-if="group.member_list.length"
                        class="flex flex-col gap-1"
                    >
                        <li
                            v-for="m in group.member_list"
                            :key="m.id"
                            class="flex items-center justify-between gap-2 text-[12px]"
                        >
                            <span class="min-w-0 break-words"
                                >{{ m.name || m.email }}
                                <span v-if="m.name" class="text-basalt-light"
                                    >· {{ m.email }}</span
                                ></span
                            >
                            <Button
                                size="sm"
                                variant="link"
                                :aria-label="
                                    $t(':name aus der Gruppe entfernen', {
                                        name: m.name || m.email,
                                    })
                                "
                                @click="removeMember(m.remove_url)"
                                >{{ $t('entfernen') }}</Button
                            >
                        </li>
                    </ul>
                    <label class="block">
                        <span :class="labelClasses">{{
                            $t('Konto hinzufügen')
                        }}</span>
                        <input
                            v-model="search[group.id]"
                            type="search"
                            :placeholder="$t('E-Mail oder Name')"
                            :class="controlClasses({ small: true })"
                            @input="find(group)"
                        />
                    </label>
                    <ul
                        v-if="found[group.id]?.length"
                        class="flex flex-col gap-1"
                    >
                        <li v-for="c in found[group.id]" :key="c.id">
                            <button
                                type="button"
                                class="w-full rounded-md px-2 py-1 text-left text-[12px] hover:bg-paper"
                                @click="addMember(group, c.id)"
                            >
                                + {{ c.name || c.email }}
                                <span v-if="c.name" class="text-basalt-light"
                                    >· {{ c.email }}</span
                                >
                            </button>
                        </li>
                    </ul>
                </template>
                <div class="mt-auto flex gap-1">
                    <Button
                        size="sm"
                        variant="secondary"
                        @click="edit(group)"
                        >{{ $t('Bearbeiten') }}</Button
                    >
                    <Button
                        v-if="group.destroy_url"
                        size="sm"
                        variant="danger"
                        @click="destroyGroup(group)"
                        >{{ $t('Löschen') }}</Button
                    >
                </div>
            </li>
        </ul>
    </section>

    <section>
        <div class="mb-2 flex flex-wrap items-end justify-between gap-2">
            <h2 class="text-sm font-semibold text-basalt">
                {{
                    forum
                        ? $t('Rechte im Forum «:title»', { title: forum.title })
                        : $t('Rechte global')
                }}
            </h2>
            <label class="block min-w-[240px]">
                <span :class="labelClasses">{{ $t('Gilt für') }}</span>
                <select
                    :value="forum?.id ?? ''"
                    :class="controlClasses({ small: true })"
                    @change="
                        chooseForum(($event.target as HTMLSelectElement).value)
                    "
                >
                    <option value="">{{ $t('alle Foren (global)') }}</option>
                    <option v-for="f in forums" :key="f.id" :value="f.id">
                        {{ '— '.repeat(f.depth) }}{{ f.title }}
                    </option>
                </select>
            </label>
        </div>
        <Notice v-if="forum" tone="grey" class="mb-3">{{
            $t(
                'Leere Felder erben vom übergeordneten Forum und zuletzt von den globalen Rechten.',
            )
        }}</Notice>
        <ul class="flex flex-col gap-3">
            <li
                v-for="row in permissions"
                :key="row.key"
                class="rounded-lg border border-ash-light bg-white p-3"
            >
                <p class="font-semibold text-basalt">
                    {{ row.label }}
                    <span class="text-[11.5px] font-normal text-basalt-light"
                        >· {{ row.group }}</span
                    >
                </p>
                <p v-if="row.help" class="text-[11.5px] text-basalt-light">
                    {{ row.help }}
                </p>
                <div
                    class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-5"
                >
                    <label
                        v-for="cell in row.cells"
                        :key="cell.group"
                        class="block"
                    >
                        <span :class="labelClasses">{{
                            groupName(cell.group)
                        }}</span>
                        <select
                            :value="cell.value ?? ''"
                            :class="controlClasses({ small: true })"
                            @change="
                                setCell(
                                    row,
                                    cell,
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option
                                v-for="v in VALUES"
                                :key="String(v.value)"
                                :value="v.value ?? ''"
                            >
                                {{ v.label }}
                            </option>
                        </select>
                        <span
                            class="mt-0.5 block text-[11px]"
                            :class="
                                cell.effective === -1
                                    ? 'text-danger font-semibold'
                                    : cell.effective === 1
                                      ? 'text-success font-semibold'
                                      : 'text-basalt-light'
                            "
                            >{{ $t('gilt:') }} {{ valueLabel(cell.effective)
                            }}<template v-if="cell.from">
                                ({{ cell.from }})</template
                            ></span
                        >
                    </label>
                </div>
            </li>
        </ul>
    </section>

    <Modal
        :open="creating"
        :title="$t('Neue Gruppe')"
        :wide="false"
        @close="creating = false"
    >
        <form class="flex flex-col gap-3" @submit.prevent="storeGroup">
            <label class="block">
                <span :class="labelClasses">{{ $t('Name') }}</span>
                <input
                    v-model="groupForm.name"
                    type="text"
                    maxlength="60"
                    required
                    :class="controlClasses({ error: !!groupForm.errors.name })"
                />
                <span
                    v-if="groupForm.errors.name"
                    class="text-danger mt-1 block text-[11.5px] font-semibold"
                    >{{ groupForm.errors.name }}</span
                >
            </label>
            <label class="block">
                <span :class="labelClasses">{{ $t('Abzeichen') }}</span>
                <input
                    v-model="groupForm.badge"
                    type="text"
                    maxlength="30"
                    :placeholder="$t('z. B. Lizenznehmer')"
                    :class="controlClasses()"
                />
                <span class="mt-1 block text-[11.5px] text-basalt-light">{{
                    $t(
                        'Erscheint neben dem Namen in Beiträgen. Leer = kein Abzeichen.',
                    )
                }}</span>
            </label>
            <div class="flex justify-end gap-2">
                <Button variant="secondary" @click="creating = false">{{
                    $t('Abbrechen')
                }}</Button>
                <Button type="submit" :disabled="groupForm.processing">{{
                    $t('Anlegen')
                }}</Button>
            </div>
        </form>
    </Modal>

    <Modal
        :open="editing !== null"
        :title="$t('Gruppe bearbeiten')"
        :wide="false"
        @close="editing = null"
    >
        <form class="flex flex-col gap-3" @submit.prevent="updateGroup">
            <label class="block">
                <span :class="labelClasses">{{ $t('Name') }}</span>
                <input
                    v-model="editForm.name"
                    type="text"
                    maxlength="60"
                    :disabled="editing?.system"
                    :class="controlClasses({ error: !!editForm.errors.name })"
                />
                <span
                    v-if="editing?.system"
                    class="mt-1 block text-[11.5px] text-basalt-light"
                    >{{ $t('Systemgruppen behalten ihren Namen.') }}</span
                >
            </label>
            <label class="block">
                <span :class="labelClasses">{{ $t('Abzeichen') }}</span>
                <input
                    v-model="editForm.badge"
                    type="text"
                    maxlength="30"
                    :class="controlClasses()"
                />
            </label>
            <div class="flex justify-end gap-2">
                <Button variant="secondary" @click="editing = null">{{
                    $t('Abbrechen')
                }}</Button>
                <Button type="submit" :disabled="editForm.processing">{{
                    $t('Speichern')
                }}</Button>
            </div>
        </form>
    </Modal>
</template>
