<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '../../components/Button.vue';
import EmptyState from '../../components/EmptyState.vue';
import PageHeader from '../../components/PageHeader.vue';
import StatusHex from '../../components/StatusHex.vue';
import Table from '../../components/Table.vue';
import { t } from '../../i18n';
import type {
    BackendProps,
    SiteListRow,
    SiteLocale,
    StatusKind,
} from '../../types';

/** Die Sites (Bereich 18): Name und Handle, Sprachen als Statussechsecke, Domains mit Stand, Benutzer. */
defineProps<{
    sites: SiteListRow[];
    create_url: string;
    can_create: boolean;
}>();

const page = usePage<BackendProps>();
const currentSiteId = computed(() => page.props.backend.site?.id ?? null);
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);

function localeStatus(locale: SiteLocale): StatusKind {
    if (locale.is_default) {
        return 'live';
    }

    return locale.is_required ? 'scheduled' : 'draft';
}

function localeTitle(locale: SiteLocale): string {
    const parts = [locale.locale.toUpperCase()];
    if (locale.is_default) {
        parts.push(t('Standardsprache'));
    } else if (locale.is_required) {
        parts.push(t('Pflicht'));
    } else {
        parts.push(t('freiwillig'));
    }
    if (locale.fallback_locale) {
        parts.push(
            t('Fallback :locale', {
                locale: locale.fallback_locale.toUpperCase(),
            }),
        );
    }

    return parts.join(' · ');
}

const kindLabels: Record<string, string> = {
    get alias() {
        return t('Alias');
    },
    get custom() {
        return t('Domain');
    },
    get redirect() {
        return t('Weiterleitung');
    },
};
</script>

<template>
    <Head :title="$t('Sites')" />
    <PageHeader
        :title="$t('Sites')"
        :crumb="$t(':site › Verwaltung', { site: siteName })"
        :lead="
            $tc(
                ':count Site · Sprachen, Domains und Theme je Site|:count Sites · Sprachen, Domains und Theme je Site',
                sites.length,
            )
        "
    >
        <template v-if="can_create" #actions>
            <Button :href="create_url">{{ $t('Neue Site') }}</Button>
        </template>
    </PageHeader>

    <div
        v-if="sites.length === 0"
        class="rounded-lg border border-ash-light bg-white"
    >
        <EmptyState
            :title="$t('Noch keine Site')"
            :text="
                $t(
                    'Die erste Site bringt Name, Handle, Sprachen und Domains mit.',
                )
            "
            icon="sites"
            tone="ash"
        >
            <Button v-if="can_create" :href="create_url">{{
                $t('Neue Site')
            }}</Button>
        </EmptyState>
    </div>
    <Table v-else>
        <template #head>
            <tr>
                <th class="px-3 py-2">{{ $t('Site') }}</th>
                <th class="px-3 py-2">{{ $t('Sprachen') }}</th>
                <th class="px-3 py-2">{{ $t('Domains') }}</th>
                <th class="px-3 py-2 text-right">{{ $t('Benutzer') }}</th>
                <th class="px-3 py-2">
                    <span class="sr-only">{{ $t('Aktionen') }}</span>
                </th>
            </tr>
        </template>
        <tr v-for="site in sites" :key="site.id" class="align-top">
            <td class="px-3 py-2.5">
                <Link
                    :href="site.edit_url"
                    class="text-[13.5px] font-semibold hover:underline"
                    >{{ site.name }}</Link
                >
                <p
                    class="mt-0.5 flex items-center gap-1.5 text-[11.5px] text-basalt-light"
                >
                    <code
                        class="rounded border border-ash-light bg-paper px-1 font-mono text-[11px]"
                        >{{ site.handle }}</code
                    >
                    <span
                        v-if="site.id === currentSiteId"
                        class="rounded-full border border-ash px-2 text-[10px] font-semibold"
                        >{{ $t('gewählt') }}</span
                    >
                </p>
            </td>
            <td :data-label="$t('Sprachen')" class="px-3 py-2.5">
                <span class="inline-flex flex-wrap items-center gap-1.5">
                    <StatusHex
                        v-for="l in site.locales"
                        :key="l.locale"
                        :status="localeStatus(l)"
                        :label="l.locale.toUpperCase()"
                        :title="localeTitle(l)"
                    />
                    <span
                        v-if="site.locales.length === 0"
                        class="text-ash-dark text-xs"
                        >—</span
                    >
                </span>
            </td>
            <td :data-label="$t('Domains')" class="px-3 py-2.5 text-[12.5px]">
                <ul v-if="site.domains.length > 0" class="space-y-0.5">
                    <li
                        v-for="d in site.domains"
                        :key="d.host"
                        class="flex flex-wrap items-center gap-1.5"
                    >
                        <span class="font-mono text-xs">{{ d.host }}</span>
                        <span
                            class="rounded border border-ash bg-paper px-1 font-mono text-[10px] text-basalt-light"
                            >{{ kindLabels[d.kind] ?? d.kind }}</span
                        >
                        <span
                            v-if="d.is_primary"
                            class="text-[10px] text-basalt-light"
                            >{{ $t('primär') }}</span
                        >
                        <span
                            :class="
                                d.verified_at ? 'text-success' : 'text-warning'
                            "
                            class="text-[11px] font-semibold"
                            :title="
                                d.verified_at
                                    ? $t('geprüft :date', {
                                          date: d.verified_at,
                                      })
                                    : $t('noch nicht geprüft')
                            "
                            >{{
                                d.verified_at ? '✓' : $t('nicht geprüft')
                            }}</span
                        >
                    </li>
                </ul>
                <span v-else class="text-ash-dark text-xs">{{
                    $t('keine Domain')
                }}</span>
            </td>
            <td
                :data-label="$t('Benutzer')"
                class="px-3 py-2.5 text-right text-[12.5px]"
            >
                {{ site.users_count }}
            </td>
            <td class="px-3 py-2.5 text-right whitespace-nowrap">
                <Button variant="link" size="sm" :href="site.edit_url">{{
                    $t('Bearbeiten')
                }}</Button>
                <Button
                    v-if="site.id !== currentSiteId"
                    variant="link"
                    size="sm"
                    :href="site.switch_url"
                    method="post"
                    >{{ $t('Wechseln') }}</Button
                >
            </td>
        </tr>
        <template #foot>
            {{ $tc(':count Site|:count Sites', sites.length) }} ·
            {{
                $t(
                    'Eigene Domains werden erreichbar, sobald ihr DNS auf den Server zeigt — «geprüft» heisst, das stimmt.',
                )
            }}
        </template>
    </Table>
</template>
