<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AccountFrame from '../../../components/AccountFrame.vue';
import Button from '../../../components/Button.vue';
import Card from '../../../components/Card.vue';
import { formatBytes } from '../../../components/media';
import Notice from '../../../components/Notice.vue';
import PairRow from '../../../components/PairRow.vue';
import { t } from '../../../i18n';
import type { AccountHeader, SaasPlan, UsageRow } from '../../../types-saas';

/**
 * TB 12 Scheibe 1 (Mockup 23 · Plan): Testphase mit Resttagen, Kontingente mit Belegung — bei Überschreitung ein Hinweis,
 * nie eine Sperre —, darunter die Stufen. «Plan wählen» führt zu Abo und Rechnungen (Scheibe 2).
 */
defineProps<{
    account: AccountHeader;
    tab: string;
    usage: UsageRow[];
    hints: string[];
    plans: SaasPlan[];
    extra: { site: number; domain: number };
    urls: { choose: string };
}>();

function amount(row: UsageRow): string {
    return row.unit === 'bytes'
        ? `${formatBytes(row.used)} / ${formatBytes(row.limit)}`
        : `${row.used} / ${row.limit}`;
}

function share(row: UsageRow): number {
    return row.limit <= 0
        ? row.used > 0
            ? 100
            : 0
        : Math.min(100, Math.round((row.used / row.limit) * 100));
}

function storage(mb: number): string {
    return mb >= 1024 ? `${Math.round(mb / 1024)} GB` : `${mb} MB`;
}

function domains(count: number): string {
    return count === 0
        ? t('keine eigene Domain')
        : t(':count eigene Domain(s) · Apex + www', { count });
}
</script>

<template>
    <Head :title="$t('Konto · Plan')" />
    <AccountFrame :account="account" :tab="tab">
        <div class="grid gap-4 md:grid-cols-2">
            <Card
                :title="$t('Testphase')"
                :status="
                    account.trial
                        ? account.trial.expired
                            ? 'warning'
                            : 'lava'
                        : 'success'
                "
            >
                <template v-if="account.trial">
                    <p class="text-[13.5px]">
                        <template v-if="account.trial.expired">{{
                            $t('Abgelaufen am :date.', {
                                date: account.trial.ends ?? '',
                            })
                        }}</template>
                        <template v-else>{{
                            $tc(
                                'Noch :count Tag · bis :date|Noch :count Tage · bis :date',
                                account.trial.days_left,
                                { date: account.trial.ends ?? '' },
                            )
                        }}</template>
                    </p>
                    <p class="mt-1.5 text-[12px] text-basalt-light">
                        {{
                            $t(
                                'Nichts läuft automatisch an. Ohne Plan bleiben Ihre Sites erreichbar; ab :date zeigen sie unten einen Hinweis auf die abgelaufene Testphase.',
                                { date: account.trial.banner_from ?? '' },
                            )
                        }}
                    </p>
                </template>
                <p v-else class="text-[13.5px]">
                    {{
                        $t('Plan :plan', {
                            plan: account.plan?.label ?? '—',
                        })
                    }}
                </p>
            </Card>
            <Card :title="$t('Kontingente')">
                <dl>
                    <PairRow
                        v-for="row in usage"
                        :key="row.key"
                        :label="row.label"
                    >
                        <span :class="row.over ? 'text-warning' : ''"
                            >{{ amount(row)
                            }}<span v-if="row.over">
                                · {{ $t('überschritten') }}</span
                            ></span
                        >
                        <span
                            class="mt-1 ml-auto block h-1 w-28 overflow-hidden rounded bg-ash-light"
                            aria-hidden="true"
                            ><span
                                class="block h-full"
                                :class="row.over ? 'bg-warning' : 'bg-lava'"
                                :style="{ width: `${share(row)}%` }"
                            ></span
                        ></span>
                    </PairRow>
                </dl>
                <Notice
                    v-for="hint in hints"
                    :key="hint"
                    tone="warning"
                    class="mt-2"
                    >{{ hint }}</Notice
                >
            </Card>
        </div>

        <h2 class="mt-5 mb-2 text-[13px] font-semibold">{{ $t('Pläne') }}</h2>
        <div class="grid gap-3 md:grid-cols-3">
            <section
                v-for="plan in plans"
                :key="plan.key"
                class="flex flex-col rounded-lg border bg-white px-3.5 py-3"
                :class="
                    plan.current
                        ? 'border-lava-dark ring-2 ring-lava/20'
                        : 'border-ash-light'
                "
                :aria-label="plan.label"
            >
                <p
                    class="text-[10px] font-semibold tracking-[.1em] text-basalt-light uppercase"
                >
                    {{ plan.label
                    }}<span v-if="plan.current"> · {{ $t('Ihr Plan') }}</span>
                </p>
                <p class="mt-1 text-[22px] font-bold">
                    CHF {{ plan.price
                    }}<small class="text-[12px] font-normal text-basalt-light">
                        / {{ $t('Monat') }}</small
                    >
                </p>
                <ul class="mt-2 space-y-0.5 text-[12.5px] text-basalt-light">
                    <li>{{ $t('Alias inklusive') }}</li>
                    <li>{{ domains(plan.domains) }}</li>
                    <li>{{ $tc(':count Site|:count Sites', plan.sites) }}</li>
                    <li>
                        {{
                            $t(':size Speicher', {
                                size: storage(plan.storage_mb),
                            })
                        }}
                    </li>
                    <li>
                        {{ $tc(':count Benutzer|:count Benutzer', plan.users) }}
                    </li>
                    <li>
                        {{ $t('Instanz «:name»', { name: plan.instance }) }}
                    </li>
                </ul>
                <div class="mt-auto pt-3">
                    <Button
                        v-if="!plan.current"
                        size="sm"
                        variant="secondary"
                        :href="`${urls.choose}?plan=${plan.key}`"
                        >{{ $t('Plan wählen') }}</Button
                    >
                    <span
                        v-else
                        class="text-success text-[12px] font-semibold"
                        >{{ $t('Aktuell') }}</span
                    >
                </div>
            </section>
        </div>
        <p class="mt-2 text-[11.5px] text-basalt-light">
            {{
                $t(
                    'Zusatz-Site CHF :site/Monat · Zusatzdomain CHF :domain/Monat · Kontingente sind Hinweise, Ihre Inhalte bleiben immer erreichbar.',
                    { site: extra.site, domain: extra.domain },
                )
            }}
        </p>
    </AccountFrame>
</template>
