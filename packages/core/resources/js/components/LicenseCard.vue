<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import type { BackendProps, LicenseInfo } from '../types';
import Card from './Card.vue';
import PairRow from './PairRow.vue';

/** Die Karte «Lizenz»: Stufe, Stand (registriert und regelkonform sind zwei Wörter, E-17), Benutzer / Sites, Updates. */
defineProps<{ license: LicenseInfo }>();
const vendor = usePage<BackendProps>().props.backend.vendor;
</script>

<template>
    <Card :title="$t('Lizenz')" :status="license.compliant ? null : 'danger'">
        <dl>
            <PairRow :label="$t('Stufe')">
                {{ license.plan_label
                }}<span v-if="license.licensee"> · {{ license.licensee }}</span>
            </PairRow>
            <PairRow :label="$t('Stand')">
                <span
                    :class="license.compliant ? 'text-success' : 'text-danger'"
                    >{{ license.stand }}</span
                >
            </PairRow>
            <PairRow :label="$t('Benutzer / Sites')"
                >{{ license.users }} / {{ license.sites }}</PairRow
            >
            <PairRow v-if="license.updates_until" :label="$t('Updates bis')">{{
                license.updates_until
            }}</PairRow>
            <PairRow v-if="license.banner_from" :label="$t('Fussleiste')">
                <span v-if="license.banner_active" class="text-danger">{{
                    $t('seit :date', { date: license.banner_from })
                }}</span>
                <span v-else>{{
                    $t('ab :date (:day. Tag)', {
                        date: license.banner_from,
                        day: license.banner_days + 1,
                    })
                }}</span>
            </PairRow>
        </dl>
        <ul
            v-if="license.hints.length > 0"
            class="mt-2 space-y-1 text-xs text-basalt-light"
        >
            <li v-for="hint in license.hints" :key="hint">{{ hint }}</li>
        </ul>
        <p v-if="!license.compliant" class="mt-2 text-xs">
            <a
                :href="vendor.license_url"
                class="text-lava-dark underline hover:text-basalt"
                >{{ $t('Lizenz auf :vendor', { vendor: vendor.host }) }}</a
            >
        </p>
    </Card>
</template>
