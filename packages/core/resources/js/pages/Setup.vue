<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '../components/Button.vue';
import Card from '../components/Card.vue';
import LicenseCard from '../components/LicenseCard.vue';
import OperationsCard from '../components/OperationsCard.vue';
import PageHeader from '../components/PageHeader.vue';
import PairRow from '../components/PairRow.vue';
import StatusHex from '../components/StatusHex.vue';
import { t } from '../i18n';
import { lineStatus } from '../support';
import type {
    BackendProps,
    LicenseInfo,
    LineRow,
    OperationsInfo,
    StatusKind,
} from '../types';

/** Die Einrichtung (Bereich 9 unten): der Prüfbericht A–Z wie `basalto:check`, rechts Theme, Lizenz, Betrieb. */
const props = defineProps<{
    line: LineRow[];
    theme: { name: string; source: string; checked_at: string | null } | null;
    /** TB 12 Scheibe 1: null auf der SaaS (Lizenz im Abo) */
    license: LicenseInfo | null;
    /** TB 12 Scheibe 1: null auf der SaaS für Kundenkonten (Betrieb der Instanz) */
    operations: OperationsInfo | null;
    urls: { check: string; publish: string };
}>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);

const rows = computed(() =>
    props.line.map((row) => ({ ...row, state: lineStatus(row.status) })),
);
const errors = computed(
    () => rows.value.filter((r) => r.state === 'danger').length,
);
const warnings = computed(
    () => rows.value.filter((r) => r.state === 'warning').length,
);
const okCount = computed(
    () => rows.value.filter((r) => r.state === 'success').length,
);
const zWaits = computed(() =>
    rows.value.some((r) => r.letter === 'Z' && r.state === 'waiting'),
);
const sourceLabel = computed(() =>
    props.theme?.source === 'database' ? t('Datenbank') : t('Dateien'),
);
const themeStatus = computed<'danger' | 'warning' | 'success'>(() =>
    errors.value > 0 ? 'danger' : warnings.value > 0 ? 'warning' : 'success',
);

const pointStatus: Record<ReturnType<typeof lineStatus>, StatusKind> = {
    success: 'success',
    danger: 'danger',
    warning: 'warning',
    waiting: 'missing',
};
const stateLabel = computed<Record<ReturnType<typeof lineStatus>, string>>(
    () => ({
        success: t('in Ordnung'),
        danger: t('Fehler'),
        warning: t('Warnung'),
        waiting: t('wartet'),
    }),
);
</script>

<template>
    <Head :title="$t('Einrichtung')" />
    <PageHeader
        :title="$t('Einrichtung')"
        :crumb="$t(':site · Modus Einrichtung', { site: siteName })"
    >
        <template #lead>
            <template v-if="theme">
                Theme <code class="font-mono">{{ theme.name }}</code>
                {{ $t('aus :source', { source: sourceLabel }) }} ·
                {{
                    $t('zuletzt geprüft :date', {
                        date: theme.checked_at ?? $t('noch nie'),
                    })
                }}
                ·
                <span :class="errors > 0 ? 'text-danger font-semibold' : ''">{{
                    $tc(':count Fehler|:count Fehler', errors)
                }}</span
                >,
                <span
                    :class="warnings > 0 ? 'text-warning font-semibold' : ''"
                    >{{
                        $tc(':count Warnung|:count Warnungen', warnings)
                    }}</span
                >
                {{ $t('— derselbe Stand wie') }}
                <code class="font-mono">basalto:check</code>.
                <span v-if="zWaits">
                    {{ $t('Z wartet, bis alle Stationen grün sind.') }}</span
                >
            </template>
            <template v-else>
                {{
                    $t(
                        'Kein Theme eingerichtet — die rote Linie wartet auf Station A.',
                    )
                }}
            </template>
        </template>
        <template #actions>
            <Button variant="secondary" :href="urls.check">{{
                $t('Jetzt prüfen')
            }}</Button>
            <Button :href="urls.publish">{{
                $t('Veröffentlichen (Z)')
            }}</Button>
        </template>
    </PageHeader>

    <div class="grid gap-4 md:grid-cols-[1.4fr_1fr]">
        <div>
            <p
                class="mb-1 text-[9.5px] font-semibold tracking-[.1em] text-basalt-light uppercase"
            >
                {{ $t('Prüfbericht · die zehn Stationen') }}
            </p>
            <ol
                class="divide-y divide-ash-light overflow-hidden rounded-lg border border-ash-light bg-white"
            >
                <li
                    v-for="row in rows"
                    :key="row.letter"
                    class="grid grid-cols-[28px_86px_24px_1fr] items-center gap-1.5 px-3 py-[7px] text-[12.5px]"
                    :class="row.state === 'danger' ? 'bg-danger/10' : ''"
                >
                    <span
                        class="font-mono text-[13px] font-semibold"
                        :class="row.state === 'waiting' ? 'text-ash-dark' : ''"
                        >{{ row.letter }}</span
                    >
                    <span
                        class="font-mono text-[11.5px]"
                        :class="
                            row.state === 'waiting'
                                ? 'text-ash-dark'
                                : 'text-basalt-light'
                        "
                        >{{ row.keyword }}</span
                    >
                    <StatusHex
                        :status="pointStatus[row.state]"
                        :title="stateLabel[row.state]"
                        size="md"
                    />
                    <span
                        :class="
                            row.state === 'waiting'
                                ? 'text-ash-dark'
                                : row.state === 'danger'
                                  ? 'text-danger font-semibold'
                                  : 'text-basalt'
                        "
                        >{{ row.summary }}</span
                    >
                </li>
                <li
                    v-if="rows.length === 0"
                    class="text-ash-dark px-3 py-4 text-center text-xs"
                >
                    {{
                        $t('Kein Prüfbericht — es ist kein Theme eingerichtet.')
                    }}
                </li>
            </ol>
        </div>
        <div class="space-y-2">
            <Card :title="$t('Theme')" :status="theme ? themeStatus : null">
                <template v-if="theme" #sub>
                    <code class="font-mono">{{ theme.name }}</code> ·
                    {{ sourceLabel }}
                </template>
                <dl v-if="theme">
                    <PairRow :label="$t('Stand')">
                        <span
                            :class="errors > 0 ? 'text-danger' : 'text-success'"
                            >{{
                                $tc(':count Fehler|:count Fehler', errors)
                            }}</span
                        >
                        ·
                        <span
                            :class="
                                warnings > 0
                                    ? 'text-warning'
                                    : 'text-basalt-light'
                            "
                            >{{
                                $tc(':count Warnung|:count Warnungen', warnings)
                            }}</span
                        >
                    </PairRow>
                    <PairRow :label="$t('Stationen')">{{
                        $t(':ok von :total in Ordnung', {
                            ok: okCount,
                            total: rows.length,
                        })
                    }}</PairRow>
                    <PairRow :label="$t('Geprüft')">{{
                        theme.checked_at ?? $t('noch nie')
                    }}</PairRow>
                </dl>
                <p v-else class="text-ash-dark text-xs">
                    {{ $t('Kein Theme eingerichtet.') }}
                </p>
            </Card>
            <LicenseCard v-if="license" :license="license" />
            <OperationsCard v-if="operations" :operations="operations" />
        </div>
    </div>
</template>
