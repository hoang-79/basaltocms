<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Notice from '../components/Notice.vue';
import PageHeader from '../components/PageHeader.vue';
import StatisticsBoard from '../components/statistics/StatisticsBoard.vue';
import type { StatisticsPayload } from '../components/statistics/types';
import type { BackendProps } from '../types';

/** TB 54: Verwaltung › Statistik — die Seitenaufrufe der gewählten Site (wie Fristo), gezählt ohne Drittanbieter. */
defineProps<{
    stats: StatisticsPayload;
    enabled: boolean;
    site: { name: string };
    url: string;
    settings_url: string;
}>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);
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
                    'Wie die Website genutzt wird — gezählt von Basalto selbst, ohne Dienste anderer Anbieter. Besucher und Sitzungen nur mit Einwilligung in den Cookie-Einstellungen; sonst zählt ein Aufruf anonym.',
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
    <StatisticsBoard :stats="stats" :url="url" />
</template>
