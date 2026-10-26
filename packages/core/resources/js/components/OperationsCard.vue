<script setup lang="ts">
import { computed } from 'vue';
import { heartbeat } from '../support';
import type { OperationsInfo } from '../types';
import Card from './Card.vue';
import PairRow from './PairRow.vue';

/** Die Karte «Betrieb»: Herzschlag des Schedulers, Warteschlange, letzter Deploy, Version — nie still (K-16). */
const props = defineProps<{ operations: OperationsInfo }>();

const pulse = computed(() => heartbeat(props.operations.heartbeat_age_seconds));
const disturbed = computed(
    () => pulse.value.tone === 'danger' || props.operations.queue_failed > 0,
);
</script>

<template>
    <Card :title="$t('Betrieb')" :status="disturbed ? 'danger' : null">
        <dl>
            <PairRow :label="$t('Herzschlag')">
                <span
                    :class="
                        pulse.tone === 'success'
                            ? 'text-success'
                            : 'text-danger'
                    "
                    >{{ pulse.text }}</span
                >
            </PairRow>
            <PairRow :label="$t('Warteschlange')">
                {{
                    $tc(
                        ':count wartend|:count wartend',
                        operations.queue_waiting,
                    )
                }}
                ·
                <span
                    :class="
                        operations.queue_failed > 0
                            ? 'text-danger'
                            : 'text-basalt-light'
                    "
                    >{{
                        $tc(
                            ':count fehlgeschlagen|:count fehlgeschlagen',
                            operations.queue_failed,
                        )
                    }}</span
                >
            </PairRow>
            <PairRow :label="$t('Letzter Deploy')">{{
                operations.last_deploy ?? '—'
            }}</PairRow>
            <PairRow :label="$t('Version')">{{ operations.version }}</PairRow>
        </dl>
    </Card>
</template>
