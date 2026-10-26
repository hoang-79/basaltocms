<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import Button from '../components/Button.vue';
import Card from '../components/Card.vue';
import { tc } from '../i18n';
import { intlLocale } from '../support';
import type { AlarmInfo, MonitoringInfo } from '../components/monitoring';
import OperationsMonitoring from '../components/OperationsMonitoring.vue';
import PageHeader from '../components/PageHeader.vue';
import PairRow from '../components/PairRow.vue';
import StateTag from '../components/StateTag.vue';

/**
 * Verwaltung › Betrieb (K-17, Mockup 19): was `/up` prüft und was dahinter liegt — Herzschlag, Warteschlange,
 * Datenbank, Cache, Speicher, Backups und die Aufgaben des Schedulers mit letztem Lauf.
 */
const props = defineProps<{
    up: {
        ok: boolean;
        status: string;
        version: string;
        checks: Record<string, { ok: boolean; detail: string }>;
    };
    scheduler: {
        ok: boolean;
        detail: string;
        age_seconds: number | null;
        threshold_minutes: number;
    };
    queue: {
        driver: string;
        waiting: number;
        failed: number;
        via_scheduler: boolean;
    };
    live: {
        interval: number;
        rate: number;
        push: boolean;
        host: string | null;
        reachable: boolean | null;
    };
    failed: {
        uuid: string;
        name: string;
        message: string;
        failed_at: string;
        urls: { retry: string; forget: string };
    }[];
    database: {
        ok: boolean;
        driver: string;
        version: string | null;
        ms: number | null;
        size: number | null;
        pending: number | null;
    };
    cache: { driver: string; ms: number | null };
    storage: {
        disk: string;
        driver: string | null;
        media: number;
        bytes: number;
    };
    backup: {
        last: string | null;
        bytes: number | null;
        verified: string | null;
        available: boolean;
    };
    tasks: {
        command: string;
        expression: string;
        last_label: string | null;
        status: string | null;
        runtime: number | null;
    }[];
    deployed_at: string | null;
    can_edit: boolean;
    urls: { backup: string; up: string; live: string };
    /** TB 10 Scheibe 6 (B-09): nur für Betreiber der Installation, sonst null */
    monitoring: MonitoringInfo | null;
    alarm: AlarmInfo | null;
    /** TB 12 Scheibe 3 (G-11): Kundendomains gegen das Limit der Cloud-Organisation — Betreiber im SaaS-Modus, sonst null */
    cloud_domains?: {
        used: number;
        limit: number;
        percent: number;
        warning: boolean;
    } | null;
}>();

function size(bytes: number | null): string {
    if (bytes === null) {
        return '—';
    }
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toLocaleString(intlLocale(), { maximumFractionDigits: unit === 0 ? 0 : 1 })} ${units[unit]}`;
}

/** «3 offen» — offene Migrationen. */
function pendingText(count: number): string {
    return tc(':count offen|:count offen', count);
}

function backupNow(): void {
    router.post(props.urls.backup, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('Betrieb')" />
    <PageHeader :title="$t('Betrieb')" :crumb="$t('Verwaltung · Betrieb')">
        <template #lead>
            <a :href="urls.up" class="font-mono underline">/up</a>
            {{ $t('meldet') }}
            <span
                :class="up.ok ? 'text-success' : 'text-danger'"
                class="font-semibold"
                >{{ up.status }}</span
            >
            · {{ $t('Liveness ohne Datenbank') }} (<a
                :href="urls.live"
                class="font-mono underline"
                >/up/live</a
            >), {{ $t('Readiness mit') }} ·
            {{ $t('Version :version', { version: up.version })
            }}{{
                deployed_at
                    ? ` · ${$t('ausgeliefert :date', { date: deployed_at })}`
                    : ''
            }}
        </template>
        <template #actions>
            <Button
                v-if="can_edit && backup.available"
                variant="secondary"
                @click="backupNow"
                >{{ $t('Backup jetzt') }}</Button
            >
        </template>
    </PageHeader>

    <ul class="grid grid-cols-[repeat(auto-fill,minmax(16rem,1fr))] gap-4">
        <li>
            <Card
                :title="$t('Herzschlag')"
                :class="scheduler.ok ? '' : 'border-danger'"
            >
                <dl>
                    <PairRow :label="$t('Scheduler')">
                        <StateTag
                            :tone="scheduler.ok ? 'success' : 'danger'"
                            class="max-w-full break-words whitespace-normal"
                            >{{ scheduler.detail }}</StateTag
                        >
                    </PairRow>
                    <PairRow :label="$t('Schwelle')">{{
                        $tc(
                            ':count Minute → /up gestört|:count Minuten → /up gestört',
                            scheduler.threshold_minutes,
                        )
                    }}</PairRow>
                    <PairRow :label="$t('Aufgaben')">{{
                        tasks.length
                    }}</PairRow>
                </dl>
            </Card>
        </li>
        <li>
            <Card
                :title="$t('Warteschlange')"
                :class="queue.failed > 0 ? 'border-danger' : ''"
            >
                <dl>
                    <PairRow :label="$t('Treiber')">{{ queue.driver }}</PairRow>
                    <PairRow :label="$t('Wartend')">{{
                        queue.waiting
                    }}</PairRow>
                    <PairRow :label="$t('Fehlgeschlagen')">
                        <span :class="queue.failed > 0 ? 'text-danger' : ''">{{
                            queue.failed
                        }}</span>
                    </PairRow>
                    <PairRow :label="$t('Worker')">{{
                        queue.via_scheduler
                            ? $t('Cron, jede Minute (ohne Worker)')
                            : queue.driver === 'sync'
                              ? $t('sofort (sync)')
                              : $t('eigener Worker')
                    }}</PairRow>
                </dl>
                <ul
                    v-if="failed.length > 0"
                    class="text-danger mt-2 grid gap-1 text-[12px]"
                >
                    <li v-for="job in failed" :key="job.uuid">
                        <b>{{ job.name }}</b> — {{ job.message }}
                        <span
                            v-if="can_edit"
                            class="mt-1 flex flex-wrap gap-1.5 text-basalt"
                        >
                            <Button
                                variant="secondary"
                                size="sm"
                                @click="
                                    router.post(
                                        job.urls.retry,
                                        {},
                                        { preserveScroll: true },
                                    )
                                "
                                >{{ $t('Noch einmal versuchen') }}</Button
                            >
                            <Button
                                variant="secondary"
                                size="sm"
                                @click="
                                    router.delete(job.urls.forget, {
                                        preserveScroll: true,
                                    })
                                "
                                >{{ $t('Verwerfen') }}</Button
                            >
                        </span>
                    </li>
                </ul>
            </Card>
        </li>
        <li>
            <Card
                :title="$t('Live-Aktualisierung')"
                :class="
                    live.push && live.reachable === false ? 'border-danger' : ''
                "
            >
                <dl>
                    <PairRow :label="$t('Abfrage')">{{
                        $t('alle :seconds s, nur bei sichtbarem Tab', {
                            seconds: live.interval,
                        })
                    }}</PairRow>
                    <PairRow :label="$t('Grenze')">{{
                        $t(':count Abfragen je Minute und Adresse', {
                            count: live.rate,
                        })
                    }}</PairRow>
                    <PairRow :label="$t('Push')">
                        <span
                            :class="
                                live.push && live.reachable === false
                                    ? 'text-danger'
                                    : ''
                            "
                            >{{
                                !live.push
                                    ? $t(
                                          'nicht eingerichtet — die Abfrage trägt alles',
                                      )
                                    : live.reachable
                                      ? $t('erreichbar (:host)', {
                                            host: live.host ?? '',
                                        })
                                      : $t(
                                            'nicht erreichbar (:host) — die Abfrage übernimmt',
                                            { host: live.host ?? '' },
                                        )
                            }}</span
                        >
                    </PairRow>
                </dl>
            </Card>
        </li>
        <li>
            <Card
                :title="$t('Datenbank')"
                :class="database.ok ? '' : 'border-danger'"
            >
                <dl>
                    <PairRow :label="database.driver">{{
                        database.version ?? '—'
                    }}</PairRow>
                    <PairRow :label="$t('Antwort')">{{
                        database.ms === null
                            ? $t('nicht erreichbar')
                            : `${database.ms} ms`
                    }}</PairRow>
                    <PairRow :label="$t('Grösse')">{{
                        size(database.size)
                    }}</PairRow>
                    <PairRow :label="$t('Migrationen')">{{
                        database.pending === null
                            ? '—'
                            : database.pending === 0
                              ? $t('alle angewendet')
                              : pendingText(database.pending)
                    }}</PairRow>
                </dl>
            </Card>
        </li>
        <li>
            <Card :title="$t('Cache')">
                <dl>
                    <PairRow :label="$t('Treiber')">{{ cache.driver }}</PairRow>
                    <PairRow :label="$t('Antwort')">{{
                        cache.ms === null
                            ? $t('nicht erreichbar')
                            : `${cache.ms} ms`
                    }}</PairRow>
                </dl>
            </Card>
        </li>
        <li>
            <Card :title="$t('Speicher')">
                <dl>
                    <PairRow :label="$t('Platte')"
                        >{{ storage.disk
                        }}{{
                            storage.driver ? ` · ${storage.driver}` : ''
                        }}</PairRow
                    >
                    <PairRow :label="$t('Medien')">{{ storage.media }}</PairRow>
                    <PairRow :label="$t('Belegt')">{{
                        size(storage.bytes)
                    }}</PairRow>
                </dl>
            </Card>
        </li>
        <li>
            <Card :title="$t('Backups')">
                <dl>
                    <PairRow :label="$t('Letztes')">{{
                        backup.last
                            ? `${backup.last}${backup.bytes ? ` · ${size(backup.bytes)}` : ''}`
                            : $t('noch keines')
                    }}</PairRow>
                    <PairRow :label="$t('Wiederherstellung geprobt')">{{
                        backup.verified ?? $t('noch nie')
                    }}</PairRow>
                    <PairRow :label="$t('Befehl')"
                        ><code class="font-mono">basalto:backup</code></PairRow
                    >
                </dl>
            </Card>
        </li>
        <li v-if="cloud_domains">
            <Card
                :title="$t('Kundendomains in der Cloud')"
                :status="cloud_domains.warning ? 'warning' : null"
            >
                <dl>
                    <PairRow :label="$t('Belegt')"
                        >{{ cloud_domains.used }} / {{ cloud_domains.limit }} ·
                        {{ cloud_domains.percent }} %</PairRow
                    >
                </dl>
                <p
                    v-if="cloud_domains.warning"
                    class="text-warning mt-2 text-[12px]"
                >
                    {{
                        $t(
                            'Ab 80 % des Limits der Cloud-Organisation: Plan der Organisation erhöhen oder ein höheres Limit anfragen — darüber kostet jede Domain 0.25 USD im Monat.',
                        )
                    }}
                </p>
                <p v-else class="mt-2 text-[11.5px] text-basalt-light">
                    {{
                        $t(
                            'Limit der Cloud-Organisation (BASALTO_CLOUD_DOMAIN_LIMIT) — Warnung ab 80 %.',
                        )
                    }}
                </p>
            </Card>
        </li>
    </ul>

    <OperationsMonitoring
        v-if="monitoring && alarm"
        :monitoring="monitoring"
        :alarm="alarm"
    />

    <Card :title="$t('Aufgaben des Schedulers')" class="mt-4">
        <ul class="grid gap-1">
            <li
                v-for="task in tasks"
                :key="task.command"
                class="grid gap-1 border-b border-ash-light py-1.5 text-[12.5px] last:border-b-0 sm:grid-cols-[minmax(0,1fr)_9rem_12rem]"
            >
                <code class="font-mono break-all">{{ task.command }}</code>
                <span class="text-ash-dark font-mono">{{
                    task.expression
                }}</span>
                <span
                    :class="
                        task.status === 'fehler'
                            ? 'text-danger'
                            : 'text-ash-dark'
                    "
                    >{{
                        task.last_label
                            ? `${task.last_label}${task.status === 'fehler' ? ` · ${$t('Fehler')}` : ' ✓'}`
                            : $t('noch nicht gelaufen')
                    }}</span
                >
            </li>
        </ul>
    </Card>
</template>
