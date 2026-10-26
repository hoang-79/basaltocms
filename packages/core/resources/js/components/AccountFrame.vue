<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { t } from '../i18n';
import type { AccountHeader } from '../types-saas';
import PageHeader from './PageHeader.vue';

/**
 * TB 12 Scheibe 1 (Mockup 23): Rahmen des Konto-Bereichs — Kopf mit Konto, Rolle, Plan und Testphase, darunter die
 * Reiter als Links (jeder Reiter ist eine eigene Seite, damit die weiteren Scheiben ihren Reiter allein füllen).
 * Die Reiter brechen um statt zu scrollen.
 */
const props = defineProps<{ account: AccountHeader; tab: string }>();

const lead = computed(() => {
    const parts: string[] = [];
    if (props.account.role) {
        parts.push(props.account.role);
    }
    if (props.account.plan) {
        parts.push(t('Plan :plan', { plan: props.account.plan.label }));
    }
    const trial = props.account.trial;
    if (trial) {
        parts.push(
            trial.expired
                ? t('Testphase abgelaufen am :date', { date: trial.ends ?? '' })
                : t('Testphase bis :date', { date: trial.ends ?? '' }),
        );
    }

    return parts.join(' · ');
});
</script>

<template>
    <PageHeader
        :title="$t('Konto · :name', { name: account.name })"
        :crumb="$t('Verwaltung › Konto')"
        :lead="lead"
    >
        <template v-if="$slots.actions" #actions>
            <slot name="actions" />
        </template>
    </PageHeader>
    <nav
        class="mb-4 flex flex-wrap gap-x-1 border-b border-ash-light"
        :aria-label="$t('Konto')"
    >
        <Link
            v-for="item in account.tabs"
            :key="item.key"
            :href="item.href"
            class="-mb-px border-b-2 px-2.5 py-2 text-[12.5px] whitespace-nowrap"
            :class="
                item.key === tab
                    ? 'border-lava font-semibold text-basalt'
                    : 'border-transparent text-basalt-light hover:text-basalt'
            "
            :aria-current="item.key === tab ? 'page' : undefined"
            >{{ item.label }}</Link
        >
    </nav>
    <slot />
</template>
