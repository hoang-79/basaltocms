<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Notice from '../components/Notice.vue';
import PageHeader from '../components/PageHeader.vue';
import StatisticsTabs from '../components/statistics/StatisticsTabs.vue';
import type { StatisticsView } from '../components/statistics/types';
import type { BackendProps } from '../types';

/**
 * Verwaltung › Statistik (TB 54, CR-019 TB 108) — wie im Extranet: Überblick, Website (Seitenaufrufe, gezählt ohne
 * Drittanbieter), Suchmaschinen und je ein Reiter für Formulare, Kundenkonten, Zahlungen, Module und Plugins.
 */
const props = defineProps<
    StatisticsView & {
        enabled: boolean;
        site: { name: string };
        settings_url: string;
    }
>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);
const view = computed<StatisticsView>(() => ({
    tab: props.tab,
    tabs: props.tabs,
    range: props.range,
    period: props.period,
    overview: props.overview,
    section: props.section,
    website: props.website,
    urls: props.urls,
}));
</script>

<template>
    <Head :title="$t('Statistik')" />
    <PageHeader
        :title="$t('Statistik')"
        :crumb="$t(':site › Verwaltung', { site: siteName })"
    >
        <template #lead>
            {{
                $t(
                    'Wie Ihre Website genutzt und gefunden wird — Besuche, Suchmaschinen, Formulare und was Module und Plugins zählen. Gezählt von Basalto selbst, ohne Dienste anderer Anbieter; Besucher und Sitzungen nur mit Einwilligung in den Cookie-Einstellungen.',
                )
            }}
        </template>
    </PageHeader>
    <Notice v-if="!enabled" tone="warning" class="mb-4">
        {{
            $t(
                'Die Statistik ist für diese Site ausgeschaltet — es kommen keine neuen Aufrufe dazu.',
            )
        }}
        <Link
            :href="settings_url"
            class="font-semibold text-lava-dark underline"
            >{{ $t('Einstellungen öffnen') }}</Link
        >
    </Notice>
    <StatisticsTabs v-bind="view" />
</template>
