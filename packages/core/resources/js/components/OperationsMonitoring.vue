<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Card from './Card.vue';
import Notice from './Notice.vue';
import PairRow from './PairRow.vue';
import StateTag from './StateTag.vue';
import type { AlarmInfo, MonitoringInfo } from './monitoring';
import { alarmState, shortClass } from './monitoring';

/**
 * Betrieb › Kennzahlen (B-09): Ausnahmen, langsame Anfragen, Jobs aus Pulse über die letzte Stunde und der Stand des
 * Fehleralarms — für Betreiber der Installation, im Kartenraster der Seite (Mockup 19). Einzelheiten im Pulse-Dashboard.
 */
const props = defineProps<{ monitoring: MonitoringInfo; alarm: AlarmInfo }>();

const state = computed(() => alarmState(props.alarm));
</script>

<template>
    <section class="mt-5" aria-labelledby="betrieb-kennzahlen">
        <div class="mb-1.5 flex flex-wrap items-baseline justify-between gap-2">
            <h2
                id="betrieb-kennzahlen"
                class="text-[9.5px] font-semibold tracking-[.1em] text-basalt-light uppercase"
            >
                {{
                    $t('Kennzahlen aus Pulse · :period · alle Sites', {
                        period: monitoring.period,
                    })
                }}
            </h2>
            <a
                v-if="monitoring.url && monitoring.installed"
                :href="monitoring.url"
                class="text-[12.5px] font-semibold text-lava underline"
                >{{ $t('Pulse-Dashboard öffnen') }}</a
            >
        </div>

        <Notice v-if="!monitoring.installed" tone="grey" class="mb-3">
            {{ $t('Pulse ist nicht eingerichtet —') }}
            <code class="font-mono">composer require laravel/pulse</code>
            {{ $t('und') }}
            <code class="font-mono">php artisan migrate</code>.
            {{ $t('Der Fehleralarm zählt trotzdem.') }}
        </Notice>
        <Notice v-else-if="!monitoring.enabled" tone="warning" class="mb-3">
            {{ $t('Pulse zeichnet nicht auf') }} (<code class="font-mono"
                >PULSE_ENABLED=false</code
            >) — {{ $t('die Zahlen bleiben stehen.') }}
            {{ $t('Der Fehleralarm zählt trotzdem.') }}
        </Notice>
        <Notice v-if="monitoring.error" tone="danger" class="mb-3">{{
            monitoring.error
        }}</Notice>

        <ul class="grid grid-cols-[repeat(auto-fill,minmax(16rem,1fr))] gap-4">
            <li>
                <Card
                    :title="$t('Ausnahmen')"
                    :status="monitoring.exceptions.total > 0 ? 'warning' : null"
                >
                    <dl>
                        <PairRow :label="$t('Gemeldet')">{{
                            monitoring.exceptions.total
                        }}</PairRow>
                    </dl>
                    <ul
                        v-if="monitoring.exceptions.top.length > 0"
                        class="mt-2 grid gap-1 text-[12px]"
                    >
                        <li
                            v-for="item in monitoring.exceptions.top"
                            :key="`${item.class}|${item.location}`"
                            class="min-w-0"
                        >
                            <b :title="item.class">{{
                                shortClass(item.class)
                            }}</b>
                            · {{ item.count }}×
                            <span
                                v-if="item.location"
                                class="block truncate font-mono text-[11px] text-basalt-light"
                                :title="item.location"
                                >{{ item.location }}</span
                            >
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-[12px] text-basalt-light">
                        {{ $t('Keine in dieser Stunde.') }}
                    </p>
                </Card>
            </li>
            <li>
                <Card
                    :title="$t('Langsame Anfragen')"
                    :status="
                        monitoring.slow_requests.total > 0 ? 'warning' : null
                    "
                >
                    <dl>
                        <PairRow
                            :label="
                                monitoring.slow_requests.threshold_ms
                                    ? $t('über :ms ms', {
                                          ms: monitoring.slow_requests
                                              .threshold_ms,
                                      })
                                    : $t('Langsam')
                            "
                            >{{ monitoring.slow_requests.total }}</PairRow
                        >
                        <PairRow :label="$t('Langsame Abfragen')">{{
                            monitoring.slow_queries
                        }}</PairRow>
                    </dl>
                    <ul
                        v-if="monitoring.slow_requests.top.length > 0"
                        class="mt-2 grid gap-1 text-[12px]"
                    >
                        <li
                            v-for="item in monitoring.slow_requests.top"
                            :key="`${item.method} ${item.path}`"
                            class="flex min-w-0 justify-between gap-2"
                        >
                            <span class="truncate font-mono" :title="item.path"
                                >{{ item.method }} {{ item.path }}</span
                            >
                            <span class="shrink-0 text-basalt-light"
                                >{{ item.max_ms }} ms · {{ item.count }}×</span
                            >
                        </li>
                    </ul>
                </Card>
            </li>
            <li>
                <Card
                    :title="$t('Jobs')"
                    :status="monitoring.jobs.failed > 0 ? 'danger' : null"
                >
                    <dl>
                        <PairRow :label="$t('Verarbeitet')">{{
                            monitoring.jobs.processed
                        }}</PairRow>
                        <PairRow :label="$t('Fehlgeschlagen')">
                            <span
                                :class="
                                    monitoring.jobs.failed > 0
                                        ? 'text-danger'
                                        : ''
                                "
                                >{{ monitoring.jobs.failed }}</span
                            >
                        </PairRow>
                        <PairRow :label="$t('Erneut eingereiht')">{{
                            monitoring.jobs.released
                        }}</PairRow>
                        <PairRow :label="$t('Langsame Jobs')">{{
                            monitoring.slow_jobs
                        }}</PairRow>
                    </dl>
                </Card>
            </li>
            <li>
                <Card
                    :title="$t('Fehleralarm')"
                    :status="state === 'reached' ? 'danger' : null"
                >
                    <dl>
                        <PairRow :label="$t('Stand')">
                            <StateTag
                                :tone="
                                    state === 'reached'
                                        ? 'danger'
                                        : state === 'off'
                                          ? 'grey'
                                          : 'success'
                                "
                                >{{
                                    state === 'off'
                                        ? $t('aus')
                                        : $t(':current in :minutes Min.', {
                                              current: alarm.current,
                                              minutes: alarm.minutes,
                                          })
                                }}</StateTag
                            >
                        </PairRow>
                        <PairRow :label="$t('Schwelle')">{{
                            state === 'off'
                                ? $t('0 = kein Alarm')
                                : $t(
                                      ':threshold Ausnahmen in :minutes Minuten',
                                      {
                                          threshold: alarm.threshold,
                                          minutes: alarm.minutes,
                                      },
                                  )
                        }}</PairRow>
                        <PairRow :label="$t('Letzter Alarm')">{{
                            alarm.last
                                ? $t(':date · :count Ausnahmen', {
                                      date: alarm.last,
                                      count: alarm.last_count ?? 0,
                                  })
                                : $t('noch keiner')
                        }}</PairRow>
                        <PairRow :label="$t('Empfänger')">{{
                            alarm.recipients === 0
                                ? $t('keine — Betreiber ohne E-Mail')
                                : $t(':count Betreiber · Pause :pause Min.', {
                                      count: alarm.recipients,
                                      pause: alarm.pause,
                                  })
                        }}</PairRow>
                    </dl>
                    <Link
                        :href="alarm.settings_url"
                        class="mt-2 inline-block text-[12px] underline"
                        >{{ $t('Schwelle ändern') }}</Link
                    >
                </Card>
            </li>
        </ul>
    </section>
</template>
