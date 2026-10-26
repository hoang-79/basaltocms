<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { t } from '@core/i18n';
import Button from '@core/components/Button.vue';
import { controlClasses, labelClasses } from '@core/components/control';
import EmptyState from '@core/components/EmptyState.vue';
import Modal from '@core/components/Modal.vue';
import PageHeader from '@core/components/PageHeader.vue';
import StateTag from '@core/components/StateTag.vue';
import type { MemberRow, MembersProps } from '../types';

/**
 * Extranet › Community › Mitglieder (CR-006 § 6, FO-17): suchen, filtern, verwarnen (Punkte mit Ablauf — die
 * Schwellen der Einstellungen lösen Schreibsperre oder Sperre aus), selbst sperren oder aufheben, als Spammer
 * entfernen. Dieselben Aktionen wie an Ort und Stelle im Forum.
 */
const props = defineProps<MembersProps>();

const search = ref(props.q);
const filters: { key: MembersProps['filter']; label: string }[] = [
    { key: 'alle', label: t('Alle') },
    { key: 'gesperrt', label: t('Gesperrt') },
    { key: 'schreibsperre', label: t('Schreibsperre') },
    { key: 'verwarnt', label: t('Verwarnt') },
];
function find(filter: MembersProps['filter'] = props.filter): void {
    router.get(
        props.urls.index,
        {
            q: search.value || undefined,
            filter: filter === 'alle' ? undefined : filter,
        },
        { preserveState: true, preserveScroll: true },
    );
}

type Mode = 'warn' | 'mute' | 'ban';
const dialog = ref<{ mode: Mode; member: MemberRow } | null>(null);
const form = useForm({
    grund: '',
    punkte: 1,
    tage: null as number | null,
    notiz: '',
    art: '',
});
function open(mode: Mode, member: MemberRow): void {
    form.reset();
    form.clearErrors();
    form.tage =
        mode === 'warn'
            ? props.defaults.warning_days
            : mode === 'mute'
              ? props.defaults.mute_days
              : props.defaults.ban_days;
    dialog.value = { mode, member };
}
function submit(): void {
    if (!dialog.value) {
        return;
    }
    const { mode, member } = dialog.value;
    const done = {
        preserveScroll: true,
        onSuccess: () => (dialog.value = null),
    };
    if (mode === 'warn') {
        form.post(member.urls.warn, done);
    } else {
        form.transform((data) => ({ ...data, art: mode })).post(
            member.urls.sanction,
            done,
        );
    }
}
function lift(member: MemberRow, art: 'unmute' | 'unban'): void {
    router.post(member.urls.sanction, { art }, { preserveScroll: true });
}
function spammer(member: MemberRow): void {
    if (
        window.confirm(
            t(
                '«:name» als Spammer entfernen? Das Konto wird ohne Ende gesperrt, alle Themen und Beiträge kommen in den Papierkorb.',
                { name: member.name },
            ),
        )
    ) {
        router.post(member.urls.spammer, {}, { preserveScroll: true });
    }
}
function revoke(url: string): void {
    router.delete(url, { preserveScroll: true });
}
const error = (key: string): string =>
    (form.errors as Record<string, string | undefined>)[key] ?? '';
const titles: Record<Mode, string> = {
    warn: t('Verwarnen'),
    mute: t('Schreibsperre setzen'),
    ban: t('Sperren'),
};
</script>

<template>
    <Head :title="$t('Mitglieder')" />
    <PageHeader
        :title="$t('Mitglieder')"
        :crumb="$t('Community')"
        :lead="
            $t(
                'Verwarnungen, Schreibsperren und Sperren der Community-Mitglieder — mit Punkten, die ablaufen.',
            )
        "
    >
        <template #actions>
            <Button :href="urls.overview" variant="secondary">{{
                $t('Übersicht')
            }}</Button>
            <Button :href="urls.moderation" variant="secondary">{{
                $t('Moderation')
            }}</Button>
        </template>
    </PageHeader>

    <form class="mb-3 flex flex-wrap items-end gap-3" @submit.prevent="find()">
        <label class="block min-w-[16rem] flex-1">
            <span :class="labelClasses">{{ $t('Name oder E-Mail') }}</span>
            <input v-model="search" type="search" :class="controlClasses({})" />
        </label>
        <Button type="submit" variant="secondary">{{ $t('Suchen') }}</Button>
    </form>
    <ul class="mb-4 flex flex-wrap gap-2">
        <li v-for="item in filters" :key="item.key">
            <button
                type="button"
                class="rounded-full border px-3 py-1 text-[13px] font-semibold"
                :class="
                    filter === item.key
                        ? 'border-lava-dark bg-lava/10 text-lava-dark'
                        : 'border-ash-light bg-white text-basalt hover:border-ash'
                "
                :aria-pressed="filter === item.key"
                @click="find(item.key)"
            >
                {{ item.label }}
            </button>
        </li>
    </ul>

    <EmptyState
        v-if="!members.length"
        tone="ash"
        icon="users"
        :title="$t('Keine Mitglieder gefunden')"
        :text="
            $t(
                'Mitglieder erscheinen, sobald sie im Forum ein Profil angelegt haben.',
            )
        "
    />
    <ul v-else class="flex flex-col gap-3">
        <li
            v-for="m in members"
            :key="m.id"
            class="flex flex-col gap-3 rounded-lg border border-ash-light bg-white p-4"
        >
            <div class="flex flex-wrap items-center gap-3">
                <img
                    v-if="m.avatar"
                    :src="m.avatar"
                    alt=""
                    class="h-10 w-10 rounded-full object-cover"
                />
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-2">
                        <b class="font-semibold text-basalt">{{ m.name }}</b>
                        <StateTag v-if="m.team" tone="lava">{{
                            $t('Team')
                        }}</StateTag>
                        <StateTag v-if="m.banned" tone="danger">{{
                            m.banned.until
                                ? $t('gesperrt bis :date', {
                                      date: m.banned.until,
                                  })
                                : $t('gesperrt')
                        }}</StateTag>
                        <StateTag v-if="m.muted" tone="warning">{{
                            m.muted.until
                                ? $t('Schreibsperre bis :date', {
                                      date: m.muted.until,
                                  })
                                : $t('Schreibsperre')
                        }}</StateTag>
                        <StateTag v-if="m.points > 0" tone="warning">{{
                            $tc(':count Punkt|:count Punkte', m.points, {
                                count: m.points,
                            })
                        }}</StateTag>
                    </p>
                    <p class="text-[12px] break-words text-basalt-light">
                        {{ m.email }} ·
                        {{
                            $tc(':count Beitrag|:count Beiträge', m.posts, {
                                count: m.posts,
                            })
                        }}
                        <template v-if="m.last">
                            ·
                            {{
                                $t('zuletzt :date', { date: m.last })
                            }}</template
                        >
                    </p>
                    <p v-if="m.banned?.reason" class="text-[12px] text-basalt">
                        {{ $t('Grund: :reason', { reason: m.banned.reason }) }}
                    </p>
                </div>
            </div>
            <div v-if="!m.team" class="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    variant="secondary"
                    @click="open('warn', m)"
                    >{{ $t('Verwarnen') }}</Button
                >
                <Button
                    v-if="m.muted"
                    size="sm"
                    variant="secondary"
                    @click="lift(m, 'unmute')"
                    >{{ $t('Schreibsperre aufheben') }}</Button
                >
                <Button
                    v-else-if="!m.banned"
                    size="sm"
                    variant="secondary"
                    @click="open('mute', m)"
                    >{{ $t('Schreibsperre') }}</Button
                >
                <Button
                    v-if="m.banned"
                    size="sm"
                    variant="secondary"
                    @click="lift(m, 'unban')"
                    >{{ $t('Sperre aufheben') }}</Button
                >
                <Button
                    v-else
                    size="sm"
                    variant="secondary"
                    @click="open('ban', m)"
                    >{{ $t('Sperren') }}</Button
                >
                <Button size="sm" variant="danger" @click="spammer(m)">{{
                    $t('Als Spammer entfernen')
                }}</Button>
            </div>
            <ul
                v-if="m.warnings.length"
                class="flex flex-col gap-1 border-t border-ash-light pt-2 text-[12.5px]"
            >
                <li
                    v-for="w in m.warnings"
                    :key="w.id"
                    class="flex flex-wrap items-center gap-2"
                >
                    <StateTag :tone="w.active ? 'warning' : 'grey'">{{
                        $tc(':count Punkt|:count Punkte', w.points, {
                            count: w.points,
                        })
                    }}</StateTag>
                    <span class="text-basalt">{{ w.reason }}</span>
                    <span class="text-basalt-light"
                        >· {{ w.date
                        }}<template v-if="w.by"> · {{ w.by }}</template>
                        <template v-if="w.revoked">
                            · {{ $t('zurückgenommen') }}</template
                        >
                        <template v-else-if="w.expires">
                            ·
                            {{
                                $t('läuft ab :date', { date: w.expires })
                            }}</template
                        ></span
                    >
                    <button
                        v-if="w.active"
                        type="button"
                        class="font-semibold text-lava-dark hover:underline"
                        @click="revoke(w.revoke_url)"
                    >
                        {{ $t('Zurücknehmen') }}
                    </button>
                </li>
            </ul>
        </li>
    </ul>

    <Modal
        :open="dialog !== null"
        :title="dialog ? titles[dialog.mode] : ''"
        :subtitle="dialog?.member.name ?? ''"
        :wide="false"
        @close="dialog = null"
    >
        <form class="flex flex-col gap-3" @submit.prevent="submit">
            <label class="block">
                <span :class="labelClasses">{{ $t('Grund') }}</span>
                <input
                    v-model="form.grund"
                    type="text"
                    maxlength="200"
                    :required="dialog?.mode === 'warn'"
                    :class="controlClasses({ error: !!error('grund') })"
                />
                <span
                    v-if="error('grund')"
                    class="text-danger mt-1 block text-[11.5px]"
                    >{{ error('grund') }}</span
                >
            </label>
            <label v-if="dialog?.mode === 'warn'" class="block">
                <span :class="labelClasses">{{ $t('Punkte') }}</span>
                <input
                    v-model.number="form.punkte"
                    type="number"
                    min="0"
                    max="100"
                    :class="controlClasses({ error: !!error('punkte') })"
                />
            </label>
            <label class="block">
                <span :class="labelClasses">{{
                    dialog?.mode === 'warn'
                        ? $t('Gültig (Tage, 0 = immer)')
                        : $t('Dauer (Tage, leer = ohne Ende)')
                }}</span>
                <input
                    v-model.number="form.tage"
                    type="number"
                    :min="dialog?.mode === 'warn' ? 0 : 1"
                    :class="controlClasses({ error: !!error('tage') })"
                />
            </label>
            <label v-if="dialog?.mode === 'warn'" class="block">
                <span :class="labelClasses">{{
                    $t('Notiz für das Team (freiwillig)')
                }}</span>
                <textarea
                    v-model="form.notiz"
                    rows="2"
                    maxlength="500"
                    :class="controlClasses({})"
                />
            </label>
            <p class="text-[12px] text-basalt-light">
                {{
                    dialog?.mode === 'warn'
                        ? $t(
                              'Die Person erfährt die Verwarnung in ihrer Glocke und per E-Mail.',
                          )
                        : $t('Die Sperre steht im Moderationsprotokoll.')
                }}
            </p>
            <div class="flex flex-wrap gap-2">
                <Button type="submit" :disabled="form.processing">{{
                    dialog ? titles[dialog.mode] : ''
                }}</Button>
                <Button variant="secondary" @click="dialog = null">{{
                    $t('Abbrechen')
                }}</Button>
            </div>
        </form>
    </Modal>
    <p class="mt-4 text-[12px] text-basalt-light">
        <Link :href="urls.overview" class="font-semibold hover:underline">{{
            $t('Schwellen der Verwarnungen in den Einstellungen')
        }}</Link>
    </p>
</template>
