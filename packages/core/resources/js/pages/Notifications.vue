<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '../components/Button.vue';
import EmptyState from '../components/EmptyState.vue';
import Hex from '../components/Hex.vue';
import Notice from '../components/Notice.vue';
import PageHeader from '../components/PageHeader.vue';
import { t } from '../i18n';
import { intlLocale } from '../support';
import type { BackendProps, NotificationItem } from '../types';

/**
 * Das Center der Benachrichtigungen (TB 27), im Backend und im Extranet: nach Tagen gruppiert, ungelesene mit der
 * roten Linie, Filter «Alle» und «Ungelesen» sowie nach Gruppe (mit Zahl). Je Zeile öffnen, gelesen ↔ ungelesen,
 * entfernen; oben «Alle als gelesen» und «Gelesene entfernen».
 */
const props = defineProps<{
    items: NotificationItem[];
    pagination: {
        current: number;
        last: number;
        total: number;
        prev: string | null;
        next: string | null;
    };
    filter: 'all' | 'unread';
    group: string | null;
    groups: { value: string; label: string; count: number }[];
    unread: number;
    enabled: boolean;
    urls: {
        index: string;
        read_all: string;
        destroy_read: string;
        settings: string;
    };
}>();

const page = usePage<BackendProps>();
const crumb = computed(() =>
    page.props.extranet
        ? t('Extranet')
        : (page.props.backend.site?.name ?? page.props.backend.name),
);

/** Nach Tagen gruppiert, in der Reihenfolge der Liste (neuste zuerst). */
const days = computed(() => {
    const out: { day: string; items: NotificationItem[] }[] = [];
    for (const item of props.items) {
        const last = out[out.length - 1];
        if (last && last.day === item.day) {
            last.items.push(item);
        } else {
            out.push({ day: item.day, items: [item] });
        }
    }

    return out;
});

function visit(query: Record<string, string | undefined>): void {
    router.get(
        props.urls.index,
        {
            filter: props.filter === 'unread' ? 'ungelesen' : undefined,
            gruppe: props.group ?? undefined,
            ...query,
        },
        { preserveScroll: true, preserveState: true },
    );
}

const options = { preserveScroll: true, preserveState: true };

function toggleRead(item: NotificationItem): void {
    router.post(item.read_url, {}, options);
}

function remove(item: NotificationItem): void {
    router.delete(item.destroy_url, options);
}

function readAll(): void {
    router.post(props.urls.read_all, {}, options);
}

function removeRead(): void {
    router.delete(props.urls.destroy_read, options);
}

const tabClass = (active: boolean): string =>
    active
        ? 'border-lava font-semibold text-basalt'
        : 'border-transparent text-basalt-light hover:text-basalt';
</script>

<template>
    <Head :title="$t('Benachrichtigungen')" />
    <PageHeader
        :title="$t('Benachrichtigungen')"
        :crumb="crumb"
        :lead="
            $t(
                'Was Sie betrifft — Freigaben, Einsendungen, Meldungen, Konto. Welche in der Glocke und welche per E-Mail kommen, stellen Sie im Profil ein.',
            )
        "
    >
        <template #actions>
            <Button
                v-if="unread > 0"
                variant="secondary"
                size="sm"
                @click="readAll"
                >{{ $t('Alle als gelesen') }}</Button
            >
            <Button variant="secondary" size="sm" @click="removeRead">{{
                $t('Gelesene entfernen')
            }}</Button>
            <Button variant="link" size="sm" :href="urls.settings">{{
                $t('Einstellungen')
            }}</Button>
        </template>
    </PageHeader>

    <Notice v-if="!enabled" tone="warning" class="mb-4">
        {{
            $t(
                'Benachrichtigungen sind ausgeschaltet — es kommen nur noch Pflicht-Mails (Rechnungen, Kündigung).',
            )
        }}
        <Link :href="urls.settings" class="font-semibold underline">{{
            $t('Einschalten')
        }}</Link>
    </Notice>

    <nav
        class="flex flex-wrap gap-x-[18px] border-b border-ash-light"
        :aria-label="$t('Filter')"
    >
        <button
            type="button"
            class="-mb-px flex items-baseline gap-1.5 border-b-2 pb-[7px] text-[13px]"
            :class="tabClass(filter === 'all' && group === null)"
            :aria-current="
                filter === 'all' && group === null ? 'true' : undefined
            "
            @click="
                visit({ filter: undefined, gruppe: undefined, page: undefined })
            "
        >
            {{ $t('Alle') }}
        </button>
        <button
            type="button"
            class="-mb-px flex items-baseline gap-1.5 border-b-2 pb-[7px] text-[13px]"
            :class="tabClass(filter === 'unread')"
            :aria-current="filter === 'unread' ? 'true' : undefined"
            @click="visit({ filter: 'ungelesen', page: undefined })"
        >
            {{ $t('Ungelesen') }}
            <span class="font-mono text-[11.5px] font-normal">{{
                unread.toLocaleString(intlLocale())
            }}</span>
        </button>
        <button
            v-for="g in groups"
            :key="g.value"
            type="button"
            class="-mb-px flex items-baseline gap-1.5 border-b-2 pb-[7px] text-[13px]"
            :class="tabClass(group === g.value)"
            :aria-current="group === g.value ? 'true' : undefined"
            @click="
                visit({
                    gruppe: group === g.value ? undefined : g.value,
                    page: undefined,
                })
            "
        >
            {{ g.label }}
            <span class="font-mono text-[11.5px] font-normal">{{
                g.count.toLocaleString(intlLocale())
            }}</span>
        </button>
    </nav>

    <EmptyState
        v-if="items.length === 0"
        class="mt-6"
        :title="
            filter === 'unread'
                ? $t('Alles gelesen.')
                : $t('Noch keine Benachrichtigungen.')
        "
        :text="
            $t(
                'Hier erscheint, was auf Sie wartet: Freigaben, neue Einsendungen, Meldungen, Änderungen am Konto.',
            )
        "
    />

    <section v-for="d in days" :key="d.day" class="mt-5" :aria-label="d.day">
        <h2
            class="text-ash-dark mb-1.5 font-mono text-[11px] tracking-[.08em] uppercase"
        >
            {{ d.day }}
        </h2>
        <ul class="overflow-hidden rounded-lg border border-ash-light bg-white">
            <li
                v-for="item in d.items"
                :key="item.id"
                class="relative flex flex-wrap items-start gap-x-3 gap-y-1.5 border-b border-ash-light px-4 py-3 last:border-b-0 sm:flex-nowrap"
            >
                <span
                    v-if="!item.read"
                    class="absolute inset-y-0 left-0 w-[3px] bg-lava"
                    aria-hidden="true"
                />
                <Hex
                    :icon="item.icon"
                    :tone="item.read ? 'ash' : 'lava-dark'"
                    size="md"
                />
                <a
                    :href="item.open_url"
                    class="min-w-0 flex-1 basis-[calc(100%-3rem)] sm:basis-auto"
                >
                    <span
                        class="block text-[13.5px] leading-snug hover:underline"
                        :class="
                            item.read
                                ? 'text-basalt-light'
                                : 'font-semibold text-basalt'
                        "
                        >{{ item.title }}</span
                    >
                    <span
                        v-if="item.body"
                        class="mt-0.5 block text-[12.5px] text-basalt-light"
                        >{{ item.body }}</span
                    >
                    <span
                        class="text-ash-dark mt-1 block font-mono text-[11px]"
                    >
                        {{ item.at }}
                        <template v-if="item.group"
                            >· {{ item.group }}</template
                        >
                        <template v-if="item.site">· {{ item.site }}</template>
                    </span>
                </a>
                <span
                    class="ml-auto flex shrink-0 items-center gap-1 pl-12 sm:pl-0"
                >
                    <button
                        type="button"
                        class="rounded-md px-2 py-1 text-[12px] text-basalt-light hover:bg-paper hover:text-basalt"
                        @click="toggleRead(item)"
                    >
                        {{
                            item.read ? $t('Als ungelesen') : $t('Als gelesen')
                        }}
                    </button>
                    <button
                        type="button"
                        class="hover:text-danger rounded-md px-2 py-1 text-[12px] text-basalt-light hover:bg-paper"
                        :aria-label="
                            $t('Entfernen: :title', { title: item.title })
                        "
                        @click="remove(item)"
                    >
                        {{ $t('Entfernen') }}
                    </button>
                </span>
            </li>
        </ul>
    </section>

    <nav
        v-if="pagination.last > 1"
        class="mt-5 flex items-center justify-between text-[12.5px]"
        :aria-label="$t('Seiten')"
    >
        <Link
            v-if="pagination.prev"
            :href="pagination.prev"
            class="underline"
            preserve-scroll
            >← {{ $t('Neuere') }}</Link
        >
        <span v-else />
        <span class="text-ash-dark font-mono"
            >{{ pagination.current }} / {{ pagination.last }}</span
        >
        <Link
            v-if="pagination.next"
            :href="pagination.next"
            class="underline"
            preserve-scroll
            >{{ $t('Ältere') }} →</Link
        >
        <span v-else />
    </nav>
</template>
