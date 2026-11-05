<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageHeader from '../../components/PageHeader.vue';
import StatisticsTabs from '../../components/statistics/StatisticsTabs.vue';
import type { StatisticsProps } from '../../types-extranet';

/**
 * TB 55: Extranet › Statistik — wie gut Basalto läuft. Reiter «Überblick» mit den wichtigsten Zahlen jedes
 * Abschnitts, «Website» mit den Seitenaufrufen von basaltocms.com (dieselbe Auswertung wie Verwaltung › Statistik)
 * und je ein Reiter für Produkt, Lizenzen, Marktplatz, Community, Kundenkonten und SaaS.
 */
const props = defineProps<StatisticsProps>();
</script>

<template>
    <Head :title="$t('Statistik')" />
    <PageHeader
        :title="$t('Statistik')"
        :crumb="$t('Extranet › Statistik')"
        :lead="
            $t(
                'Wie Basalto ankommt: Website, Downloads und Installationen, Lizenzen, Marktplatz und Community — gezählt ohne Dienste anderer Anbieter.',
            )
        "
    />
    <StatisticsTabs v-bind="props" />
</template>
