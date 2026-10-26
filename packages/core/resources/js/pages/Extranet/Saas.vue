<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import Card from '../../components/Card.vue';
import EmptyState from '../../components/EmptyState.vue';
import Notice from '../../components/Notice.vue';
import PageHeader from '../../components/PageHeader.vue';
import StateTag from '../../components/StateTag.vue';
import Table from '../../components/Table.vue';
import { t } from '../../i18n';
import type { InstanceSummary, SaasProps } from '../../types-extranet';

/**
 * SaaS-Betrieb im Hersteller-Extranet (CR-004 P-15): Reiter Instanzen · Domains · Umzüge · Löschungen. Der Server
 * wählt den Reiter über `?tab=` (Ziel der Übersicht ist `?tab=domains&filter=pending`), deshalb wechseln die Reiter
 * per Abruf statt nur im Browser. Instanzen als Karten mit Zustand, Prüfungen, Konten je Plan und dem Cloud-Limit
 * der Domains; die übrigen Reiter als Tabellen, die mobil zu Karten stapeln.
 */
const props = defineProps<SaasProps>();

type TabKey = SaasProps['tab'];

// Die Zahl der Instanzen steht nur im eigenen Reiter fest (entfernte werden nur dort abgefragt) — darum ohne Zähler.
const tabs = computed<{ key: TabKey; label: string; count: number | null }[]>(
    () => [
        { key: 'instances', label: t('Instanzen'), count: null },
        { key: 'domains', label: t('Domains'), count: props.domains.length },
        { key: 'moves', label: t('Umzüge'), count: props.moves.length },
        {
            key: 'deletions',
            label: t('Löschungen'),
            count: props.deletions.length,
        },
    ],
);

function go(tab: TabKey, filter: 'pending' | null = null): void {
    router.get(props.urls.index, filter ? { tab, filter } : { tab }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

const checkLabels: Record<string, string> = {
    database: t('Datenbank'),
    scheduler: t('Zeitplaner'),
};

const instanceStatus = (
    instance: InstanceSummary,
): 'success' | 'danger' | 'warning' => {
    if (!instance.reachable || !instance.ready) {
        return 'danger';
    }

    return instance.domains.warning ? 'warning' : 'success';
};

const totalAccounts = (instance: InstanceSummary): number =>
    Object.values(instance.accounts).reduce((sum, n) => sum + n, 0);

const domainLabels: Record<string, string> = {
    waiting: t('wartet auf DNS'),
    certificate: t('Zertifikat wird ausgestellt'),
    active: t('aktiv'),
    error: t('Fehler'),
};
const domainTones: Record<string, 'warning' | 'success' | 'danger'> = {
    waiting: 'warning',
    certificate: 'warning',
    active: 'success',
    error: 'danger',
};

const moveLabels: Record<string, string> = {
    exporting: t('wird exportiert'),
    sent: t('gesendet'),
    queued: t('wartet auf Import'),
    imported: t('importiert'),
    done: t('abgeschlossen'),
    cleaned: t('abgeschlossen, aufgeräumt'),
    failed: t('fehlgeschlagen'),
};
const moveTone = (status: string): 'success' | 'danger' | 'warning' =>
    status === 'failed'
        ? 'danger'
        : status === 'done' || status === 'cleaned'
          ? 'success'
          : 'warning';
const directionLabel = (direction: string): string =>
    direction === 'in' ? t('eingehend') : t('ausgehend');

const chipClass = (active: boolean): string =>
    active
        ? 'border-lava-dark bg-lava/10 font-semibold text-lava-dark'
        : 'border-ash-light bg-white text-basalt-light hover:border-ash hover:text-basalt';
const linkClass = 'font-semibold text-lava-dark underline hover:text-basalt';
</script>

<template>
    <Head :title="$t('SaaS-Betrieb')" />
    <PageHeader
        :title="$t('SaaS-Betrieb')"
        :crumb="$t('Extranet › SaaS')"
        :lead="
            $t(
                'Zustand der Instanzen, eigene Domains mit dem Cloud-Limit, Umzüge zwischen Instanzen und anstehende Löschungen.',
            )
        "
    />

    <Notice v-if="!saas" tone="warning" class="mb-3.5">{{
        $t(
            'SaaS-Modus ist auf dieser Installation aus — Registrierung, Abos und Domains laufen hier nicht.',
        )
    }}</Notice>

    <nav
        class="mb-3 flex flex-wrap gap-x-[18px] border-b border-ash-light"
        :aria-label="$t('Bereiche')"
    >
        <button
            v-for="item in tabs"
            :key="item.key"
            type="button"
            class="-mb-px flex items-baseline gap-1.5 border-b-2 pb-[7px] text-[13px]"
            :class="
                item.key === tab
                    ? 'border-lava font-semibold text-basalt'
                    : 'border-transparent text-basalt-light hover:text-basalt'
            "
            :aria-current="item.key === tab ? 'page' : undefined"
            @click="go(item.key)"
        >
            {{ item.label }}
            <span
                v-if="item.count !== null"
                class="font-mono text-[11.5px] font-normal"
                >{{ item.count }}</span
            >
        </button>
    </nav>

    <!-- Instanzen -->
    <template v-if="tab === 'instances'">
        <div
            v-if="instances.length === 0"
            class="rounded-lg border border-ash-light bg-white"
        >
            <EmptyState
                :title="$t('Keine Instanz')"
                icon="operations"
                tone="ash"
            />
        </div>
        <div v-else class="grid gap-3 md:grid-cols-2">
            <Card
                v-for="instance in instances"
                :key="instance.instance"
                :status="instanceStatus(instance)"
            >
                <template #title>
                    <span class="font-mono">{{ instance.instance }}</span>
                </template>
                <template #right>
                    {{
                        instance.version
                            ? $t('Version :version', {
                                  version: instance.version,
                              })
                            : $t('Version unbekannt')
                    }}
                </template>
                <template #sub>
                    <StateTag
                        :tone="instance.reachable ? 'success' : 'danger'"
                        >{{
                            instance.reachable
                                ? $t('erreichbar')
                                : $t('nicht erreichbar')
                        }}</StateTag
                    >
                    <StateTag
                        v-if="instance.reachable"
                        :tone="instance.ready ? 'success' : 'danger'"
                        >{{
                            instance.ready ? $t('bereit') : $t('gestört')
                        }}</StateTag
                    >
                </template>

                <p
                    v-if="instance.error"
                    class="text-danger mb-2 text-[12px] font-semibold break-words"
                >
                    {{ instance.error }}
                </p>

                <ul
                    v-if="Object.keys(instance.checks).length > 0"
                    class="mb-2.5 flex flex-col gap-0.5 text-[12px]"
                    :aria-label="$t('Prüfungen')"
                >
                    <li
                        v-for="(check, key) in instance.checks"
                        :key="key"
                        class="flex flex-wrap items-baseline gap-x-1.5"
                    >
                        <span
                            :class="check.ok ? 'text-success' : 'text-danger'"
                            aria-hidden="true"
                            >{{ check.ok ? '✓' : '✕' }}</span
                        >
                        <span class="font-semibold">{{
                            checkLabels[key] ?? key
                        }}</span>
                        <span class="sr-only">{{
                            check.ok ? $t('in Ordnung') : $t('gestört')
                        }}</span>
                        <span class="break-words text-basalt-light">{{
                            check.detail
                        }}</span>
                    </li>
                </ul>

                <dl
                    class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 border-t border-ash-light pt-2 text-[12px]"
                >
                    <dt class="text-basalt-light">{{ $t('Konten') }}</dt>
                    <dd>
                        <span class="font-mono font-semibold">{{
                            totalAccounts(instance)
                        }}</span>
                        <span
                            v-if="Object.keys(instance.accounts).length > 0"
                            class="text-basalt-light"
                        >
                            ·
                            <template
                                v-for="(count, plan, i) in instance.accounts"
                                :key="plan"
                                ><template v-if="i > 0">, </template>{{ plan }}
                                {{ count }}</template
                            >
                        </span>
                    </dd>

                    <dt class="text-basalt-light">{{ $t('Domains') }}</dt>
                    <dd>
                        <span
                            class="font-mono"
                            :class="
                                instance.domains.warning
                                    ? 'text-warning font-semibold'
                                    : ''
                            "
                            >{{ instance.domains.used }} /
                            {{ instance.domains.limit }} ·
                            {{ instance.domains.percent }}&nbsp;%</span
                        >
                        <span
                            class="mt-1 block h-1.5 rounded-full bg-ash-light"
                            aria-hidden="true"
                            ><span
                                class="block h-1.5 rounded-full"
                                :class="
                                    instance.domains.warning
                                        ? 'bg-warning'
                                        : 'bg-success'
                                "
                                :style="{
                                    width: `${Math.min(100, instance.domains.percent)}%`,
                                }"
                        /></span>
                        <span
                            v-if="instance.domains.warning"
                            class="text-warning mt-1 block text-[11.5px]"
                            >{{
                                $t(
                                    'Das Domain-Limit der Cloud ist bald erreicht.',
                                )
                            }}</span
                        >
                    </dd>

                    <dt class="text-basalt-light">
                        {{ $t('Hängige Domains') }}
                    </dt>
                    <dd>
                        <button
                            v-if="instance.domains.pending > 0"
                            type="button"
                            :class="linkClass"
                            @click="go('domains', 'pending')"
                        >
                            {{ instance.domains.pending }}
                        </button>
                        <span v-else class="font-mono">0</span>
                    </dd>

                    <dt class="text-basalt-light">{{ $t('Offene Umzüge') }}</dt>
                    <dd>
                        <button
                            v-if="instance.moves > 0"
                            type="button"
                            :class="linkClass"
                            @click="go('moves')"
                        >
                            {{ instance.moves }}
                        </button>
                        <span v-else class="font-mono">0</span>
                    </dd>

                    <dt class="text-basalt-light">
                        {{ $t('Anstehende Löschungen') }}
                    </dt>
                    <dd>
                        <button
                            v-if="instance.deletions > 0"
                            type="button"
                            :class="linkClass"
                            @click="go('deletions')"
                        >
                            {{ instance.deletions }}
                        </button>
                        <span v-else class="font-mono">0</span>
                    </dd>
                </dl>
            </Card>
        </div>
    </template>

    <!-- Domains -->
    <template v-else-if="tab === 'domains'">
        <div
            class="mb-3 flex flex-wrap items-center gap-1.5"
            role="group"
            :aria-label="$t('Filter')"
        >
            <button
                type="button"
                class="rounded-full border px-2.5 py-[3px] text-[12px]"
                :class="chipClass(filter === 'pending')"
                :aria-pressed="filter === 'pending'"
                @click="go('domains', 'pending')"
            >
                {{ $t('hängig') }}
            </button>
            <button
                type="button"
                class="rounded-full border px-2.5 py-[3px] text-[12px]"
                :class="chipClass(filter === null)"
                :aria-pressed="filter === null"
                @click="go('domains')"
            >
                {{ $t('alle') }}
            </button>
        </div>
        <div
            v-if="domains.length === 0"
            class="rounded-lg border border-ash-light bg-white"
        >
            <EmptyState
                :title="
                    filter === 'pending'
                        ? $t('Keine hängige Domain')
                        : $t('Noch keine eigene Domain')
                "
                :text="
                    filter === 'pending'
                        ? $t('Alle eigenen Domains sind verbunden.')
                        : $t(
                              'Domains, die Kunden in ihrem Konto anlegen, erscheinen hier.',
                          )
                "
                :icon="filter === 'pending' ? 'check' : 'sites'"
                :tone="filter === 'pending' ? 'success' : 'ash'"
            />
        </div>
        <Table v-else>
            <template #head>
                <tr>
                    <th class="px-3 py-2">{{ $t('Host') }}</th>
                    <th class="px-3 py-2">{{ $t('Site') }}</th>
                    <th class="px-3 py-2">{{ $t('Konto') }}</th>
                    <th class="px-3 py-2">{{ $t('Zustand') }}</th>
                    <th class="px-3 py-2">{{ $t('angelegt') }}</th>
                </tr>
            </template>
            <tr v-for="domain in domains" :key="domain.id" class="align-top">
                <td
                    :data-label="$t('Host')"
                    class="px-3 py-2.5 font-mono text-[12.5px] font-semibold break-all"
                >
                    {{ domain.host }}
                </td>
                <td :data-label="$t('Site')" class="px-3 py-2.5 text-[12.5px]">
                    {{ domain.site }}
                </td>
                <td :data-label="$t('Konto')" class="px-3 py-2.5 text-[12.5px]">
                    <Link
                        v-if="domain.url"
                        :href="domain.url"
                        :class="linkClass"
                        >{{ domain.account }}</Link
                    >
                    <template v-else>{{ domain.account }}</template>
                </td>
                <td :data-label="$t('Zustand')" class="px-3 py-2.5">
                    <StateTag :tone="domainTones[domain.state] ?? 'grey'">{{
                        domainLabels[domain.state] ?? domain.state
                    }}</StateTag>
                    <p
                        v-if="domain.error"
                        class="text-danger mt-1 text-[11.5px] break-words"
                    >
                        {{ domain.error }}
                    </p>
                </td>
                <td
                    :data-label="$t('angelegt')"
                    class="px-3 py-2.5 text-xs md:whitespace-nowrap"
                >
                    {{ domain.created ?? '—' }}
                </td>
            </tr>
        </Table>
    </template>

    <!-- Umzüge -->
    <template v-else-if="tab === 'moves'">
        <div
            v-if="moves.length === 0"
            class="rounded-lg border border-ash-light bg-white"
        >
            <EmptyState
                :title="$t('Noch kein Umzug')"
                :text="
                    $t(
                        'Umzüge von Konten zwischen Instanzen erscheinen hier mit ihrem Stand.',
                    )
                "
                icon="redirect"
                tone="ash"
            />
        </div>
        <Table v-else>
            <template #head>
                <tr>
                    <th class="px-3 py-2">{{ $t('Konto') }}</th>
                    <th class="px-3 py-2">{{ $t('Von → nach') }}</th>
                    <th class="px-3 py-2">{{ $t('Status') }}</th>
                    <th class="px-3 py-2">{{ $t('Datum') }}</th>
                </tr>
            </template>
            <tr v-for="move in moves" :key="move.id" class="align-top">
                <td
                    :data-label="$t('Konto')"
                    class="px-3 py-2.5 text-[13px] font-semibold"
                >
                    {{ move.account }}
                    <p class="text-[11.5px] font-normal text-basalt-light">
                        {{ directionLabel(move.direction) }}
                    </p>
                </td>
                <td
                    :data-label="$t('Von → nach')"
                    class="px-3 py-2.5 font-mono text-[12.5px] break-all"
                >
                    {{ move.from }} → {{ move.to }}
                </td>
                <td :data-label="$t('Status')" class="px-3 py-2.5">
                    <StateTag :tone="moveTone(move.status)">{{
                        moveLabels[move.status] ?? move.status
                    }}</StateTag>
                    <p
                        v-if="move.error"
                        class="text-danger mt-1 text-[11.5px] break-words"
                    >
                        {{ move.error }}
                    </p>
                </td>
                <td
                    :data-label="$t('Datum')"
                    class="px-3 py-2.5 text-xs md:whitespace-nowrap"
                >
                    {{ move.created }}
                </td>
            </tr>
        </Table>
    </template>

    <!-- Löschungen -->
    <template v-else>
        <div
            v-if="deletions.length === 0"
            class="rounded-lg border border-ash-light bg-white"
        >
            <EmptyState
                :title="$t('Keine Löschung angesetzt')"
                :text="
                    $t(
                        'Gekündigte Konten erscheinen hier mit dem Tag, an dem ihre Daten gelöscht werden.',
                    )
                "
            />
        </div>
        <Table v-else>
            <template #head>
                <tr>
                    <th class="px-3 py-2">{{ $t('Konto') }}</th>
                    <th class="px-3 py-2">{{ $t('Laufzeit bis') }}</th>
                    <th class="px-3 py-2">{{ $t('Löschung am') }}</th>
                </tr>
            </template>
            <tr
                v-for="deletion in deletions"
                :key="deletion.id"
                class="align-top"
            >
                <td :data-label="$t('Konto')" class="px-3 py-2.5 text-[13px]">
                    <Link :href="deletion.url" :class="linkClass">{{
                        deletion.name
                    }}</Link>
                </td>
                <td
                    :data-label="$t('Laufzeit bis')"
                    class="px-3 py-2.5 text-xs md:whitespace-nowrap"
                >
                    {{ deletion.ends ?? '—' }}
                </td>
                <td
                    :data-label="$t('Löschung am')"
                    class="px-3 py-2.5 text-xs font-semibold md:whitespace-nowrap"
                >
                    {{ deletion.delete_after ?? '—' }}
                </td>
            </tr>
        </Table>
    </template>
</template>
