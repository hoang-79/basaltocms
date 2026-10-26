<script setup lang="ts">
import { Head, router, useForm, usePage, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AccountFrame from '../../../components/AccountFrame.vue';
import Button from '../../../components/Button.vue';
import Card from '../../../components/Card.vue';
import Field from '../../../components/Field.vue';
import { formatBytes } from '../../../components/media';
import Notice from '../../../components/Notice.vue';
import PairRow from '../../../components/PairRow.vue';
import { t } from '../../../i18n';
import type { AccountHeader } from '../../../types-saas';

/**
 * TB 12 Scheibe 4 (M-21, Mockup 24): Export und Kündigung. Links der Vollexport — je Site oder das ganze Konto, als
 * Job mit Fortschritt (die Seite fragt alle zwei Sekunden nach, solange einer läuft), Mail mit Link, 7 Tage abrufbar.
 * Rechts die Kündigung mit Laufzeit, Sperre und Löschdatum; bestätigt mit dem Namen des Kontos und einem Haken. Nach
 * der Kündigung das Band mit den Daten und «Kündigung zurücknehmen» bis zum Ende der Laufzeit. Nach einem Umzug
 * zwischen den Instanzen «Umzug abschliessen». Zwei Spalten ab md, darunter gestapelt — kein waagrechtes Scrollen.
 */
type ExportRow = {
    id: string;
    label: string;
    status: 'queued' | 'running' | 'ready' | 'failed';
    progress: number;
    size: number | null;
    created: string;
    expires: string | null;
    download_url: string | null;
};

const props = defineProps<{
    account: AccountHeader;
    tab: string;
    is_owner: boolean;
    sites: { id: number; name: string; handle: string; can_export: boolean }[];
    exports: ExportRow[];
    export_days: number;
    exit: {
        state:
            | 'active'
            | 'cancelled'
            | 'locked'
            | 'moving'
            | 'moved'
            | 'erased';
        cancelled: string | null;
        ends: string | null;
        locked: string | null;
        deletes: string | null;
        lock_days: number;
        in_trial: boolean;
        plan: string | null;
        preview_ends: string | null;
        preview_deletes: string | null;
        immediate: boolean;
    };
    move: {
        id: string;
        status: string;
        source: string;
        target: string;
        completed: string | null;
        confirmed: string | null;
        can_confirm: boolean;
    } | null;
    urls: {
        export: string;
        cancel: string;
        revoke: string;
        confirm_move: string | null;
    };
}>();

const page = usePage<{ errors: Record<string, string> }>();

const scopes = computed(() => [
    ...(props.is_owner ? [{ value: '', label: t('Ganzes Konto') }] : []),
    ...props.sites
        .filter((s) => s.can_export)
        .map((s) => ({
            value: String(s.id),
            label: t('Site «:name»', { name: s.name }),
        })),
]);

const exportForm = useForm({ site: scopes.value[0]?.value ?? '' });
const running = computed(() =>
    props.exports.some((e) => e.status === 'queued' || e.status === 'running'),
);

// Fortschritt: nachfragen, solange ein Export läuft — ohne Polling bleibt Neuladen
const poll = usePoll(
    2000,
    { only: ['exports'] },
    { autoStart: running.value, keepAlive: false },
);
watch(running, (now) => (now ? poll.start() : poll.stop()));

function createExport(): void {
    exportForm
        .transform((data) => ({
            site: data.site === '' ? null : Number(data.site),
        }))
        .post(props.urls.export, { preserveScroll: true });
}

const cancelForm = useForm({ confirm: '', exported: false });

function cancel(): void {
    cancelForm.post(props.urls.cancel, {
        preserveScroll: true,
        onSuccess: () => cancelForm.reset(),
    });
}

function revoke(): void {
    router.post(props.urls.revoke, {}, { preserveScroll: true });
}

function confirmMove(): void {
    if (props.urls.confirm_move) {
        router.post(props.urls.confirm_move, {}, { preserveScroll: true });
    }
}

function statusText(row: ExportRow): string {
    switch (row.status) {
        case 'queued':
            return t('Wartet auf die Warteschlange …');
        case 'running':
            return t('Export wird erstellt … :percent %', {
                percent: row.progress,
            });
        case 'failed':
            return t('Fehlgeschlagen — bitte neu erstellen');
        default:
            return row.expires
                ? t(':size · verfügbar bis :date', {
                      size: formatBytes(row.size ?? 0),
                      date: row.expires,
                  })
                : t('Abgelaufen');
    }
}

const ended = computed(
    () => props.exit.state === 'cancelled' || props.exit.state === 'locked',
);
</script>

<template>
    <Head :title="$t('Konto · Export und Kündigung')" />
    <AccountFrame :account="account" :tab="tab">
        <p class="mb-3 max-w-[760px] text-[12.5px] text-basalt-light">
            {{
                $t(
                    '«Ihre Daten bleiben Ihre»: Der Vollexport ist jederzeit möglich, nicht erst beim Austritt.',
                )
            }}
        </p>

        <Notice
            v-if="exit.state === 'cancelled'"
            tone="warning"
            class="mb-3 font-semibold text-basalt!"
        >
            {{
                $t(
                    'Gekündigt · Laufzeit bis :ends · danach :days Tage gesperrt · Löschung am :deletes',
                    {
                        ends: exit.ends ?? '',
                        days: exit.lock_days,
                        deletes: exit.deletes ?? '',
                    },
                )
            }}
        </Notice>
        <Notice
            v-else-if="exit.state === 'locked'"
            tone="danger"
            class="mb-3 font-semibold text-basalt!"
        >
            {{
                $t(
                    'Gesperrt seit :ends — die Sites sind offline, das Backend ist nur lesbar · Löschung am :deletes',
                    { ends: exit.locked ?? '', deletes: exit.deletes ?? '' },
                )
            }}
        </Notice>

        <Card
            v-if="move"
            :title="
                $t('Umzug von «:source» nach «:target»', {
                    source: move.source,
                    target: move.target,
                })
            "
            :status="move.status === 'cleaned' ? 'success' : 'warning'"
            class="mb-4 max-w-[760px]"
        >
            <p class="text-[12.5px]">
                <template v-if="move.status === 'imported'">{{
                    $t(
                        'Angekommen am :date. Bitte prüfen Sie Ihre Sites — mit «Umzug abschliessen» löschen wir die alte Kopie.',
                        { date: move.completed ?? '' },
                    )
                }}</template>
                <template v-else-if="move.status === 'cleaned'">{{
                    $t(
                        'Abgeschlossen am :date — die alte Kopie ist gelöscht.',
                        {
                            date: move.confirmed ?? '',
                        },
                    )
                }}</template>
                <template v-else-if="move.status === 'failed'">{{
                    $t(
                        'Der Umzug ist gescheitert — wir kümmern uns darum, Ihre Sites laufen unverändert weiter.',
                    )
                }}</template>
                <template v-else>{{ $t('Der Umzug läuft …') }}</template>
            </p>
            <Notice v-if="page.props.errors.move" tone="danger" class="mt-2">{{
                page.props.errors.move
            }}</Notice>
            <div v-if="move.can_confirm" class="mt-2.5">
                <Button @click="confirmMove">{{
                    $t('Umzug abschliessen')
                }}</Button>
            </div>
        </Card>

        <div class="grid items-start gap-4 md:grid-cols-2">
            <Card
                :title="$t('Vollexport · :name', { name: account.name })"
                :sub="$t('JSON + Medien · dasselbe Format wie der Import')"
            >
                <dl>
                    <PairRow :label="$t('Inhalt')">{{
                        $t(
                            'Inhalte als JSON, Medien, Theme, Navigation, Formulare, Einstellungen',
                        )
                    }}</PairRow>
                    <PairRow :label="$t('Verfügbar')">{{
                        $t(':days Tage, dann gelöscht', { days: export_days })
                    }}</PairRow>
                </dl>
                <ul v-if="exports.length" class="mt-2.5 space-y-2">
                    <li
                        v-for="row in exports"
                        :key="row.id"
                        class="rounded-md border border-ash-light px-2.5 py-2 text-[12.5px]"
                    >
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-x-2"
                        >
                            <span class="font-semibold">{{ row.label }}</span>
                            <span class="text-[11.5px] text-basalt-light">{{
                                row.created
                            }}</span>
                        </div>
                        <div
                            class="mt-0.5 flex flex-wrap items-center justify-between gap-x-2 gap-y-1"
                        >
                            <span
                                :class="
                                    row.status === 'failed'
                                        ? 'text-danger'
                                        : 'text-basalt-light'
                                "
                                >{{ statusText(row) }}</span
                            >
                            <a
                                v-if="row.download_url"
                                :href="row.download_url"
                                class="text-lava-dark underline hover:text-basalt"
                                >{{ $t('Herunterladen') }}</a
                            >
                        </div>
                        <div
                            v-if="
                                row.status === 'queued' ||
                                row.status === 'running'
                            "
                            class="mt-1.5 h-1 overflow-hidden rounded bg-ash-light"
                            role="progressbar"
                            :aria-valuenow="row.progress"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            :aria-label="$t('Fortschritt des Exports')"
                        >
                            <div
                                class="h-full bg-lava transition-[width]"
                                :style="{ width: `${row.progress}%` }"
                            />
                        </div>
                    </li>
                </ul>
                <form
                    v-if="scopes.length"
                    class="mt-3"
                    novalidate
                    @submit.prevent="createExport"
                >
                    <Field
                        v-if="scopes.length > 1"
                        v-model="exportForm.site"
                        class="mt-0!"
                        :label="$t('Umfang')"
                        type="select"
                        :options="scopes"
                        :error="
                            page.props.errors.export ?? exportForm.errors.site
                        "
                    />
                    <Notice
                        v-else-if="page.props.errors.export"
                        tone="danger"
                        >{{ page.props.errors.export }}</Notice
                    >
                    <div class="mt-2.5">
                        <Button
                            type="submit"
                            variant="secondary"
                            :disabled="exportForm.processing || running"
                            >{{ $t('Neuen Export erstellen') }}</Button
                        >
                    </div>
                </form>
                <p class="mt-2 text-[11.5px] text-basalt-light">
                    {{
                        $t(
                            'Wir mailen, wenn er fertig ist. Export → leere Installation → Import ergibt dieselbe Site, auch auf Ihrem eigenen Server.',
                        )
                    }}
                </p>
            </Card>

            <Card
                v-if="ended"
                :title="$t('Was jetzt gilt')"
                :status="exit.state === 'locked' ? 'danger' : 'warning'"
            >
                <dl>
                    <PairRow
                        v-if="exit.state === 'cancelled'"
                        :label="$t('Bis :date', { date: exit.ends ?? '' })"
                        >{{ $t('alles wie bisher') }}</PairRow
                    >
                    <PairRow
                        :label="
                            $t(':from – :to', {
                                from: exit.locked ?? '',
                                to: exit.deletes ?? '',
                            })
                        "
                        >{{
                            $t(
                                'gesperrt: Backend nur lesbar mit Export, Frontend «nicht mehr verfügbar» (410)',
                            )
                        }}</PairRow
                    >
                    <PairRow :label="exit.deletes ?? ''">{{
                        $t(
                            'Löschung von Sites und Medien; Konto und Rechnungen bleiben; Alias wird frei',
                        )
                    }}</PairRow>
                </dl>
                <Notice
                    v-if="page.props.errors.revoke"
                    tone="danger"
                    class="mt-2"
                    >{{ page.props.errors.revoke }}</Notice
                >
                <div v-if="exit.state === 'cancelled' && is_owner" class="mt-3">
                    <Button @click="revoke">{{
                        $t('Kündigung zurücknehmen')
                    }}</Button>
                </div>
            </Card>

            <Card
                v-else-if="exit.state === 'active' && is_owner"
                :title="$t('Abo kündigen')"
                :sub="
                    exit.in_trial
                        ? $t('Testphase · die Laufzeit endet sofort')
                        : $t(':plan · Laufzeit bis :date', {
                              plan: exit.plan ?? '',
                              date: exit.preview_ends ?? '',
                          })
                "
                status="danger"
            >
                <form novalidate @submit.prevent="cancel">
                    <dl>
                        <PairRow :label="$t('Laufzeit bis')">{{
                            exit.immediate ? $t('sofort') : exit.preview_ends
                        }}</PairRow>
                        <PairRow :label="$t('Danach')">{{
                            $t(
                                ':days Tage gesperrt — lesbar für Sie, Export möglich',
                                { days: exit.lock_days },
                            )
                        }}</PairRow>
                        <PairRow :label="$t('Löschung am')">{{
                            exit.preview_deletes
                        }}</PairRow>
                    </dl>
                    <Field
                        v-model="cancelForm.confirm"
                        :label="$t('Name des Kontos zur Bestätigung')"
                        :placeholder="account.name"
                        :help="
                            $t('Geben Sie «:name» ein.', { name: account.name })
                        "
                        :error="cancelForm.errors.confirm"
                        autocomplete="off"
                    />
                    <div class="mt-2.5 flex items-start gap-2 text-[12.5px]">
                        <input
                            id="exit-exported"
                            v-model="cancelForm.exported"
                            type="checkbox"
                            class="mt-0.5 h-4 w-4 accent-lava-dark"
                            :aria-invalid="
                                cancelForm.errors.exported ? 'true' : undefined
                            "
                            :aria-describedby="
                                cancelForm.errors.exported
                                    ? 'exit-exported-error'
                                    : undefined
                            "
                        />
                        <label for="exit-exported">{{
                            $t(
                                'Ich habe den Export erstellt oder brauche ihn nicht.',
                            )
                        }}</label>
                    </div>
                    <p
                        v-if="cancelForm.errors.exported"
                        id="exit-exported-error"
                        class="text-danger mt-1 text-[12px]"
                    >
                        {{ cancelForm.errors.exported }}
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            variant="danger"
                            :disabled="cancelForm.processing"
                            >{{ $t('Kündigung bestätigen') }}</Button
                        >
                        <Button
                            variant="secondary"
                            @click="cancelForm.reset()"
                            >{{ $t('Abbrechen') }}</Button
                        >
                    </div>
                </form>
            </Card>

            <Card v-else-if="exit.state === 'active'" :title="$t('Kündigung')">
                <p class="text-[12.5px] text-basalt-light">
                    {{ $t('Kündigen kann nur der Besitzer des Kontos.') }}
                </p>
            </Card>
        </div>
    </AccountFrame>
</template>
