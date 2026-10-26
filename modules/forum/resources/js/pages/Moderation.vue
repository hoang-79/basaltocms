<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { t } from '@core/i18n';
import Button from '@core/components/Button.vue';
import { controlClasses, labelClasses } from '@core/components/control';
import EmptyState from '@core/components/EmptyState.vue';
import PageHeader from '@core/components/PageHeader.vue';
import StateTag from '@core/components/StateTag.vue';
import type { ModerationProps, ModerationTab } from '../types';

/**
 * Extranet › Community › Moderation (CR-006 § 6, FO-15): Meldungen, Freischaltung, Papierkorb und Protokoll. Jede
 * Aktion ruft dieselbe Moderation wie das Menü an Ort und Stelle im Forum — mit dem Team als Moderator.
 */
const props = defineProps<ModerationProps>();

const tabs: { key: ModerationTab; label: string }[] = [
    { key: 'meldungen', label: t('Meldungen') },
    { key: 'freischaltung', label: t('Freischaltung') },
    { key: 'papierkorb', label: t('Papierkorb') },
    { key: 'protokoll', label: t('Protokoll') },
];
const count = (key: ModerationTab): number =>
    key === 'protokoll' ? 0 : props.counts[key];

function decide(id: number, art: string, entscheid: 'hidden' | 'kept'): void {
    router.post(
        props.urls.report,
        { id, art, entscheid },
        { preserveScroll: true },
    );
}
function onPosts(ids: number[], aktion: string): void {
    if (
        aktion === 'loeschen' &&
        !window.confirm(
            t('Endgültig löschen? Das lässt sich nicht rückgängig machen.'),
        )
    ) {
        return;
    }
    router.post(
        props.urls.posts,
        { beitraege: ids, aktion },
        { preserveScroll: true },
    );
}
function onThreads(ids: number[], aktion: string): void {
    if (
        aktion === 'loeschen' &&
        !window.confirm(
            t('Endgültig löschen? Das lässt sich nicht rückgängig machen.'),
        )
    ) {
        return;
    }
    router.post(
        props.urls.threads,
        { themen: ids, aktion },
        { preserveScroll: true },
    );
}
const filter = reactive({ ...props.filter });
function applyFilter(): void {
    router.get(
        props.urls.index,
        {
            tab: 'protokoll',
            aktion: filter.aktion || undefined,
            konto: filter.konto || undefined,
        },
        { preserveState: true, preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="$t('Moderation')" />
    <PageHeader
        :title="$t('Moderation')"
        :crumb="$t('Community')"
        :lead="
            $t(
                'Gemeldete und wartende Beiträge, der Papierkorb und wer was getan hat — dieselben Aktionen wie im Forum.',
            )
        "
    >
        <template #actions>
            <Button :href="urls.overview" variant="secondary">{{
                $t('Übersicht')
            }}</Button>
            <Button :href="urls.members" variant="secondary">{{
                $t('Mitglieder')
            }}</Button>
        </template>
    </PageHeader>

    <nav class="mb-4" :aria-label="$t('Moderation')">
        <ul class="flex flex-wrap gap-2">
            <li v-for="item in tabs" :key="item.key">
                <Link
                    :href="`${urls.index}?tab=${item.key}`"
                    :aria-current="tab === item.key ? 'page' : undefined"
                    preserve-scroll
                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-[13px] font-semibold"
                    :class="
                        tab === item.key
                            ? 'border-lava-dark bg-lava/10 text-lava-dark'
                            : 'border-ash-light bg-white text-basalt hover:border-ash'
                    "
                >
                    {{ item.label }}
                    <span
                        v-if="count(item.key) > 0"
                        class="rounded-full bg-lava px-1.5 font-mono text-[11px] text-white"
                        >{{ count(item.key) }}</span
                    >
                </Link>
            </li>
        </ul>
    </nav>

    <!-- Meldungen -->
    <template v-if="tab === 'meldungen'">
        <EmptyState
            v-if="!reports.length"
            :title="$t('Keine offenen Meldungen')"
            :text="
                $t(
                    'Gemeldete Beiträge erscheinen hier, die meistgemeldeten zuerst.',
                )
            "
        />
        <ul v-else class="flex flex-col gap-3">
            <li
                v-for="item in reports"
                :key="item.id"
                class="flex flex-col gap-2 rounded-lg border border-ash-light bg-white p-4"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <StateTag tone="danger">{{
                        $tc(':count Meldung|:count Meldungen', item.count, {
                            count: item.count,
                        })
                    }}</StateTag>
                    <StateTag v-if="item.hidden" tone="warning">{{
                        $t('vorläufig ausgeblendet')
                    }}</StateTag>
                    <StateTag v-if="item.trashed" tone="grey">{{
                        $t('im Papierkorb')
                    }}</StateTag>
                    <a
                        v-if="item.url"
                        :href="item.url"
                        target="_blank"
                        rel="noopener"
                        class="font-semibold text-basalt hover:underline"
                        >{{ item.title }}</a
                    >
                    <b v-else class="font-semibold text-basalt">{{
                        item.title
                    }}</b>
                    <span class="text-[12px] text-basalt-light"
                        >{{ item.place }} · {{ item.author }}</span
                    >
                </div>
                <blockquote
                    class="border-l-2 border-ash-light pl-3 text-[13px] break-words text-basalt-light"
                >
                    {{ item.excerpt }}
                </blockquote>
                <ul class="flex flex-col gap-1 text-[12.5px]">
                    <li v-for="(r, i) in item.reports" :key="i">
                        <b class="font-semibold">{{ r.reason }}</b>
                        <span class="text-basalt-light">
                            · {{ r.by }} · {{ r.date }}</span
                        >
                        <span v-if="r.note" class="block text-basalt">{{
                            r.note
                        }}</span>
                    </li>
                </ul>
                <div class="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        variant="danger"
                        @click="decide(item.id, item.type, 'hidden')"
                        >{{
                            item.type === 'conversation_message'
                                ? $t('Nachricht entfernen')
                                : $t('In den Papierkorb')
                        }}</Button
                    >
                    <Button
                        size="sm"
                        variant="secondary"
                        @click="decide(item.id, item.type, 'kept')"
                        >{{ $t('Meldungen zurückweisen') }}</Button
                    >
                    <Button
                        v-if="item.author_id"
                        size="sm"
                        variant="link"
                        :href="`${urls.members}?q=${encodeURIComponent(item.author)}`"
                        >{{ $t('Verwarnen oder sperren') }}</Button
                    >
                </div>
            </li>
        </ul>
    </template>

    <!-- Freischaltung -->
    <template v-else-if="tab === 'freischaltung'">
        <EmptyState
            v-if="!pending.length"
            :title="$t('Nichts wartet auf die Freischaltung')"
            :text="
                $t(
                    'Steht «Ohne Freischaltung schreiben» für eine Gruppe auf «Nein», landen ihre Beiträge hier — ebenso Beiträge, die nach Meldungen vorläufig ausgeblendet sind.',
                )
            "
        />
        <ul v-else class="flex flex-col gap-3">
            <li
                v-for="item in pending"
                :key="item.id"
                class="flex flex-col gap-2 rounded-lg border border-ash-light bg-white p-4"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <StateTag tone="warning">{{
                        item.kind === 'thread'
                            ? $t('Neues Thema')
                            : $t('Antwort')
                    }}</StateTag>
                    <StateTag v-if="item.reported" tone="danger">{{
                        $t('gemeldet')
                    }}</StateTag>
                    <a
                        v-if="item.url"
                        :href="item.url"
                        target="_blank"
                        rel="noopener"
                        class="font-semibold text-basalt hover:underline"
                        >{{ item.title }}</a
                    >
                    <span class="text-[12px] text-basalt-light"
                        >{{ item.place }} · {{ item.author }} ·
                        {{ item.date }}</span
                    >
                </div>
                <blockquote
                    class="border-l-2 border-ash-light pl-3 text-[13px] break-words text-basalt-light"
                >
                    {{ item.excerpt }}
                </blockquote>
                <div class="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        @click="onPosts([item.id], 'freischalten')"
                        >{{ $t('Freischalten') }}</Button
                    >
                    <Button
                        size="sm"
                        variant="secondary"
                        @click="onPosts([item.id], 'papierkorb')"
                        >{{ $t('In den Papierkorb') }}</Button
                    >
                </div>
            </li>
        </ul>
    </template>

    <!-- Papierkorb -->
    <template v-else-if="tab === 'papierkorb'">
        <EmptyState
            v-if="!trash.length"
            :title="$t('Der Papierkorb ist leer')"
            :text="
                $t(
                    'Themen und Beiträge im Papierkorb sehen nur Moderation und Team.',
                )
            "
        />
        <ul v-else class="flex flex-col gap-3">
            <li
                v-for="item in trash"
                :key="item.kind + item.id"
                class="flex flex-col gap-2 rounded-lg border border-ash-light bg-white p-4"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <StateTag tone="grey">{{
                        item.kind === 'thread' ? $t('Thema') : $t('Beitrag')
                    }}</StateTag>
                    <b class="font-semibold text-basalt">{{ item.title }}</b>
                    <span class="text-[12px] text-basalt-light"
                        >{{ item.author }} · {{ item.date }}</span
                    >
                </div>
                <p v-if="item.reason" class="text-[12.5px] text-basalt">
                    {{ $t('Grund: :reason', { reason: item.reason }) }}
                </p>
                <blockquote
                    class="border-l-2 border-ash-light pl-3 text-[13px] break-words text-basalt-light"
                >
                    {{ item.excerpt }}
                </blockquote>
                <div class="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        variant="secondary"
                        @click="
                            item.kind === 'thread'
                                ? onThreads([item.id], 'wiederherstellen')
                                : onPosts([item.id], 'wiederherstellen')
                        "
                        >{{ $t('Wiederherstellen') }}</Button
                    >
                    <Button
                        size="sm"
                        variant="danger"
                        @click="
                            item.kind === 'thread'
                                ? onThreads([item.id], 'loeschen')
                                : onPosts([item.id], 'loeschen')
                        "
                        >{{ $t('Endgültig löschen') }}</Button
                    >
                </div>
            </li>
        </ul>
    </template>

    <!-- Protokoll -->
    <template v-else>
        <form
            class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-ash-light bg-white p-3"
            @submit.prevent="applyFilter"
        >
            <label class="block min-w-[14rem] flex-1">
                <span :class="labelClasses">{{ $t('Aktion') }}</span>
                <select v-model="filter.aktion" :class="controlClasses({})">
                    <option value="">{{ $t('Alle Aktionen') }}</option>
                    <option
                        v-for="a in actions"
                        :key="a.value"
                        :value="a.value"
                    >
                        {{ a.label }}
                    </option>
                </select>
            </label>
            <label class="block min-w-[14rem] flex-1">
                <span :class="labelClasses">{{
                    $t('Mitglied (Anzeigename)')
                }}</span>
                <input
                    v-model="filter.konto"
                    type="search"
                    :class="controlClasses({})"
                />
            </label>
            <Button type="submit" variant="secondary">{{
                $t('Filtern')
            }}</Button>
        </form>
        <EmptyState
            v-if="!log.length"
            :title="$t('Noch keine Einträge')"
            :text="
                $t(
                    'Jede Moderationsaktion steht hier — aus dem Forum und aus dem Extranet.',
                )
            "
        />
        <ol
            v-else
            class="flex flex-col divide-y divide-ash-light rounded-lg border border-ash-light bg-white"
        >
            <li
                v-for="row in log"
                :key="row.id"
                class="flex flex-col gap-0.5 px-4 py-2 text-[13px]"
            >
                <p>
                    <b class="font-semibold text-basalt">{{ row.action }}</b>
                    <span v-if="row.subject"> · {{ row.subject }}</span>
                    <span v-if="row.target" class="text-basalt-light">
                        · {{ $t('betrifft :name', { name: row.target }) }}</span
                    >
                </p>
                <p class="text-[12px] text-basalt-light">
                    {{ row.date }} · {{ row.by
                    }}<span v-if="row.reason"> · {{ row.reason }}</span>
                </p>
            </li>
        </ol>
    </template>
</template>
