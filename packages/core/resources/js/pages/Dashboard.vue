<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '../components/Button.vue';
import Card from '../components/Card.vue';
import EmptyState from '../components/EmptyState.vue';
import LicenseCard from '../components/LicenseCard.vue';
import Notice from '../components/Notice.vue';
import OperationsCard from '../components/OperationsCard.vue';
import PageHeader from '../components/PageHeader.vue';
import StatusHex from '../components/StatusHex.vue';
import { taskMark } from '../components/workflow';
import type {
    BackendProps,
    DashboardSite,
    LicenseInfo,
    OperationsInfo,
} from '../types';
import type { HintAction, WorkflowHint, WorkflowTask } from '../types-workflow';

/**
 * Die Übersicht (Bereich 9, Modus Inhalte): Führungssatz in Prosa vom Server, Hinweise (ein fehlgeschlagener Job mit
 * «Noch einmal versuchen» und «Verwerfen» als Knöpfe), Aufgaben mit einem Klick am Ziel — die Art steht als Sechseck
 * am Zeilenanfang (Prüfen Lava, Übersetzung und 404-Treffer Bernstein, Geplant grün) —, dann Sites · Lizenz · Betrieb.
 */
const props = defineProps<{
    lead: string;
    date: string;
    hints: WorkflowHint[];
    tasks: WorkflowTask[];
    sites: DashboardSite[];
    /** TB 12 Scheibe 1: null auf der SaaS (Lizenz im Abo) */
    license: LicenseInfo | null;
    /** TB 12 Scheibe 1: null auf der SaaS für Kundenkonten (Betrieb der Instanz) */
    operations: OperationsInfo | null;
    /** null in der Demo: dort kein Hinweis auf Passkeys (die Konten entstehen jede Nacht neu) */
    passkeys: { count: number; url: string } | null;
}>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);

/** Der Passkey-Hinweis kommt aus `hints`; fehlt er dort, steht er aus `passkeys` — nie doppelt. */
const showPasskeyHint = computed(
    () =>
        props.passkeys !== null &&
        props.passkeys.count === 0 &&
        !props.hints.some((h) => h.kind === 'passkey'),
);

function hintTone(kind: WorkflowHint['kind']): 'lava' | 'danger' {
    return kind === 'passkey' ? 'lava' : 'danger';
}

const running = ref<string | null>(null);

/** Eine Aktion eines Hinweises (Job noch einmal, verwerfen) — danach lädt die Übersicht neu. */
function runHintAction(action: HintAction): void {
    const options = {
        preserveScroll: true,
        onStart: () => {
            running.value = action.url;
        },
        onFinish: () => {
            running.value = null;
        },
    };
    if (action.method === 'delete') {
        router.delete(action.url, options);
    } else {
        router.post(action.url, {}, options);
    }
}
</script>

<template>
    <Head :title="$t('Übersicht')" />
    <PageHeader
        :title="$t('Übersicht')"
        :crumb="$t(':site · Modus Inhalte', { site: siteName })"
    >
        <template #lead>
            <span v-if="date">{{ date }}. </span>{{ lead }}
        </template>
    </PageHeader>

    <div v-if="hints.length > 0 || showPasskeyHint" class="mb-4 space-y-2">
        <Notice
            v-for="(hint, i) in hints"
            :key="`${hint.kind}-${i}`"
            :tone="hintTone(hint.kind)"
        >
            {{ hint.text }}
            <Link
                v-if="hint.href && hint.action"
                :href="hint.href"
                class="ml-1 font-semibold underline hover:text-basalt"
                >{{ hint.action }}</Link
            >
            <span
                v-if="hint.actions && hint.actions.length > 0"
                class="mt-1.5 flex flex-wrap items-center gap-2"
            >
                <Button
                    v-for="(action, a) in hint.actions"
                    :key="action.url + action.method"
                    :variant="a === 0 ? 'secondary' : 'link'"
                    size="sm"
                    :disabled="running !== null"
                    @click="runHintAction(action)"
                    >{{ action.label }}</Button
                >
            </span>
        </Notice>
        <Notice v-if="passkeys && showPasskeyHint" tone="lava">
            <strong>{{ $t('Passkey einrichten.') }}</strong>
            {{
                $t(
                    'Dieses Konto hat noch keinen Passkey. Mit einem Passkey melden Sie sich ohne Code an, auch ohne Mail-Server.',
                )
            }}
            <Link
                :href="passkeys.url"
                class="ml-1 font-semibold text-lava-dark underline hover:text-basalt"
                >{{ $t('Jetzt einrichten') }}</Link
            >
        </Notice>
    </div>

    <p
        class="mb-1 text-[9.5px] font-semibold tracking-[.1em] text-basalt-light uppercase"
    >
        {{ $t('Aufgaben · :count', { count: tasks.length }) }}
    </p>
    <div class="rounded-lg border border-ash-light bg-white">
        <ul v-if="tasks.length > 0" class="divide-y divide-ash-light">
            <li
                v-for="task in tasks"
                :key="`${task.kind}-${task.href}-${task.title}`"
                class="flex items-center gap-3 px-3 py-2.5"
            >
                <span class="flex w-3 shrink-0 justify-center">
                    <StatusHex
                        v-if="taskMark(task.kind).status"
                        :status="taskMark(task.kind).status ?? 'none'"
                    />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[13.5px] font-semibold">{{ task.title }}</p>
                    <p
                        class="mt-0.5 text-[11.5px] [overflow-wrap:anywhere] text-basalt-light"
                    >
                        {{ task.meta }}
                    </p>
                </div>
                <Button
                    :href="task.href"
                    :variant="
                        taskMark(task.kind).primary ? 'primary' : 'secondary'
                    "
                    size="sm"
                    >{{ task.action }}</Button
                >
            </li>
        </ul>
        <EmptyState v-else :title="$t('Nichts wartet auf Sie')" />
    </div>

    <div class="mt-3.5 grid gap-2.5 md:grid-cols-3">
        <Card
            :title="$t('Sites')"
            :sub="$tc(':count Site|:count Sites', sites.length)"
        >
            <p v-if="sites.length === 0" class="text-ash-dark text-xs">
                {{ $t('Noch keine Site angelegt.') }}
            </p>
            <ul v-else class="space-y-2">
                <li
                    v-for="site in sites"
                    :key="site.id"
                    class="rounded-md border border-ash-light px-3 py-2"
                >
                    <Link
                        v-if="site.edit_url"
                        :href="site.edit_url"
                        class="text-[13.5px] font-semibold hover:underline"
                        >{{ site.name }}</Link
                    >
                    <span v-else class="text-[13.5px] font-semibold">{{
                        site.name
                    }}</span>
                    <p
                        class="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[11.5px] text-basalt-light"
                    >
                        <code
                            class="rounded border border-ash-light bg-paper px-1 font-mono text-[11px]"
                            >{{ site.handle }}</code
                        >
                        <span>{{
                            site.locales.map((l) => l.toUpperCase()).join(' ')
                        }}</span>
                        <span v-for="d in site.domains" :key="d.host">
                            · {{ d.host }}
                            <span
                                :class="
                                    d.verified ? 'text-success' : 'text-warning'
                                "
                                :title="
                                    d.verified
                                        ? $t('geprüft')
                                        : $t('nicht geprüft')
                                "
                                >{{
                                    d.verified ? '✓' : $t('nicht geprüft')
                                }}</span
                            >
                        </span>
                    </p>
                </li>
            </ul>
        </Card>
        <LicenseCard v-if="license" :license="license" />
        <OperationsCard v-if="operations" :operations="operations" />
    </div>
</template>
