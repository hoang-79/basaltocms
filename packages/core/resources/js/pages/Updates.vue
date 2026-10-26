<script setup lang="ts">
import { Deferred, Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Button from '../components/Button.vue';
import Card from '../components/Card.vue';
import CodeBlock from '../components/CodeBlock.vue';
import Modal from '../components/Modal.vue';
import Notice from '../components/Notice.vue';
import PageHeader from '../components/PageHeader.vue';
import PairRow from '../components/PairRow.vue';
import StateTag from '../components/StateTag.vue';
import { t } from '../i18n';

/**
 * Einstellungen › Updates (CR-007): Kern, mitgelieferte Module, Plugins und Themes gegen den Hersteller der Umgebung.
 * Kern und Plugins spielt ein eigener Prozess ein; solange er läuft, lädt die Seite alle zwei Sekunden den Stand nach.
 */
type Item = {
    name: string;
    title: string;
    installed: string | null;
    available: string | null;
    newer: boolean;
};
type ThemeItem = Item & { site_id: number; site: string; theme: string };
type Note = { version: string; date: string | null; notes: string };
type Check = {
    checked_at: string;
    error: string | null;
    marketplace_error: string | null;
    core: {
        installed: string;
        known: boolean;
        available: string | null;
        newer: boolean;
        size: number | null;
        notes: Note[];
    };
    plugins: Item[];
    themes: ThemeItem[];
    updates_until: string | null;
    updates_expired: boolean;
    count: number;
};
type Step = {
    label: string;
    state: string;
    detail: string | null;
    at: string;
};
type Status = {
    target: string;
    title: string;
    version: string;
    state: 'running' | 'done' | 'failed';
    started_at: string;
    finished_at: string | null;
    by: string | null;
    steps: Step[];
    error: string | null;
};

const props = defineProps<{
    check?: Check;
    installation: {
        mode: 'archiv' | 'git' | 'composer';
        version: string;
        known: boolean;
        saas: boolean;
        problems: string[];
        modules: { handle: string; label: string }[];
        commands: string;
    };
    source: { url: string; host: string; stage: string };
    usage: { on: boolean; url: string } | null;
    status: Status | null;
    can_apply: boolean;
    urls: {
        index: string;
        check: string;
        core: string;
        plugin: string;
        theme: string;
        themes: string;
        status: string;
    };
}>();

const page = usePage<{ errors: Record<string, string> }>();
const confirmCore = ref(false);
const busy = ref(false);
// Eigener Stand: während des Laufs per JSON nachgeladen — ein 503 im Wartungsmodus überspringt die Abfrage einfach
const status = ref<Status | null>(props.status);
watch(
    () => props.status,
    (value) => {
        status.value = value;
    },
);
const running = computed(() => status.value?.state === 'running');
const canRun = computed(
    () =>
        props.can_apply &&
        props.installation.mode === 'archiv' &&
        props.installation.problems.length === 0 &&
        !running.value,
);

let timer: ReturnType<typeof setInterval> | null = null;
async function poll(): Promise<void> {
    try {
        const response = await fetch(props.urls.status, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (response.ok) {
            status.value = (await response.json()) as Status | null;
        }
    } catch {
        // kurz nicht erreichbar (Tausch, Wartungsmodus) — beim nächsten Mal wieder
    }
}
watch(
    running,
    (now, before) => {
        if (now && timer === null) {
            timer = setInterval(poll, 2000);
        }
        if (!now && timer !== null) {
            clearInterval(timer);
            timer = null;
        }
        // Fertig: alles neu laden (Versionen, Zahl im Menü)
        if (before && !now) {
            router.visit(props.urls.index, { preserveScroll: true });
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => {
    if (timer !== null) {
        clearInterval(timer);
    }
});

function post(url: string, data: Record<string, string | number> = {}): void {
    busy.value = true;
    router.post(url, data, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            confirmCore.value = false;
        },
    });
}

function megabytes(bytes: number | null): string {
    return bytes === null ? '—' : `${(bytes / 1048576).toFixed(1)} MB`;
}

function time(iso: string | null): string {
    if (iso === null) {
        return '';
    }
    const date = new Date(iso);

    return Number.isNaN(date.getTime())
        ? iso
        : date.toLocaleString(undefined, {
              dateStyle: 'short',
              timeStyle: 'short',
          });
}

const stateLabel = computed(() =>
    status.value === null
        ? ''
        : status.value.state === 'running'
          ? t('läuft')
          : status.value.state === 'done'
            ? t('abgeschlossen')
            : t('gescheitert'),
);
</script>

<template>
    <Head :title="$t('Updates')" />
    <PageHeader :title="$t('Updates')" :crumb="$t('Verwaltung · Updates')">
        <template #lead>
            {{ $t('Quelle') }}
            <a :href="source.url" class="font-mono underline" rel="noopener">{{
                source.host
            }}</a>
            ({{ source.stage }})
            <template v-if="check">
                · {{ $t('geprüft :time', { time: time(check.checked_at) }) }}
            </template>
            <template v-if="usage">
                ·
                <Link
                    :href="usage.url"
                    class="underline"
                    :title="
                        $t(
                            'Mit der Prüfung gehen eine zufällige Kennung, die Version, PHP und die Art der Datenbank an den Hersteller — keine Adressen, keine Inhalte.',
                        )
                    "
                    >{{
                        usage.on
                            ? $t('Nutzungsangaben an den Hersteller: an')
                            : $t('Nutzungsangaben an den Hersteller: aus')
                    }}</Link
                >
            </template>
        </template>
        <template #actions>
            <Button
                variant="secondary"
                :disabled="busy || running"
                @click="post(urls.check)"
                >{{ $t('Jetzt prüfen') }}</Button
            >
        </template>
    </PageHeader>

    <Notice
        v-if="page.props.errors.update || page.props.errors.theme"
        tone="danger"
        class="mb-4"
        >{{ page.props.errors.update || page.props.errors.theme }}</Notice
    >

    <Card
        v-if="status"
        :title="`${status.title}${status.version ? ' ' + status.version : ''} · ${stateLabel}`"
        :status="
            status.state === 'done'
                ? 'success'
                : status.state === 'failed'
                  ? 'danger'
                  : 'lava'
        "
        class="mb-4"
        aria-live="polite"
    >
        <p class="text-ash-dark mb-2 text-[12.5px]">
            {{ $t('Gestartet :time', { time: time(status.started_at) })
            }}{{ status.by ? ` · ${status.by}` : '' }}
        </p>
        <ol class="grid gap-1 text-[13px]">
            <li
                v-for="(step, i) in status.steps"
                :key="i"
                class="flex flex-wrap items-baseline gap-x-2"
            >
                <span
                    aria-hidden="true"
                    :class="
                        step.state === 'ok'
                            ? 'text-success'
                            : step.state === 'rollback'
                              ? 'text-warning'
                              : 'text-danger'
                    "
                    >{{
                        step.state === 'ok'
                            ? '✓'
                            : step.state === 'rollback'
                              ? '↺'
                              : '✗'
                    }}</span
                >
                <span>{{ step.label }}</span>
                <span
                    v-if="step.detail && step.state !== 'ok'"
                    class="text-ash-dark w-full font-mono text-[11.5px] break-words whitespace-pre-wrap"
                    >{{ step.detail }}</span
                >
            </li>
            <li v-if="running" class="text-ash-dark motion-safe:animate-pulse">
                {{ $t('… läuft') }}
            </li>
        </ol>
        <Notice v-if="status.error" tone="danger" class="mt-3">{{
            status.error
        }}</Notice>
    </Card>

    <Notice
        v-if="!installation.saas && installation.mode !== 'archiv'"
        tone="grey"
        class="mb-4"
        :title="
            installation.mode === 'git'
                ? $t('Git-Arbeitsstand')
                : $t('Mit Composer gepflegt')
        "
    >
        <span class="mb-2 block">{{
            $t(
                'Diese Installation prüft hier, aktualisiert wird aber auf dem Server — so:',
            )
        }}</span>
        <CodeBlock :code="installation.commands" />
    </Notice>
    <Notice
        v-else-if="!installation.saas && installation.problems.length > 0"
        tone="warning"
        class="mb-4"
        :title="$t('Einspielen aus dem Backend geht hier nicht')"
    >
        <span v-for="(p, i) in installation.problems" :key="i" class="block">{{
            p
        }}</span>
    </Notice>

    <Deferred data="check">
        <template #fallback>
            <div
                class="grid gap-4 lg:grid-cols-2"
                aria-busy="true"
                :aria-label="$t('Prüfe beim Hersteller …')"
            >
                <div
                    v-for="i in 4"
                    :key="i"
                    class="h-36 animate-pulse rounded-lg border border-ash-light bg-paper motion-reduce:animate-none"
                ></div>
            </div>
        </template>

        <template v-if="check">
            <Notice
                v-if="check.updates_expired"
                tone="warning"
                class="mb-4"
                :title="$t('Update-Anspruch abgelaufen')"
                >{{
                    $t(
                        'Seit :date — die Software läuft weiter, und nichts wird gesperrt. Neue Versionen gehören zur Verlängerung der Lizenz.',
                        { date: check.updates_until ?? '' },
                    )
                }}</Notice
            >
            <div class="grid gap-4 lg:grid-cols-2">
                <Card
                    v-if="!installation.saas"
                    :title="$t('Kern')"
                    :sub="$t('Basalto mit Lava')"
                    :status="
                        check.error
                            ? 'danger'
                            : check.core.newer
                              ? 'lava'
                              : 'success'
                    "
                >
                    <dl>
                        <PairRow :label="$t('Installiert')"
                            ><span class="font-mono">{{
                                check.core.installed
                            }}</span>
                            <span
                                v-if="!check.core.known"
                                class="text-ash-dark"
                            >
                                ·
                                {{
                                    $t(
                                        'Stand vor CR-007, genaue Version unbekannt',
                                    )
                                }}</span
                            ></PairRow
                        >
                        <PairRow :label="$t('Verfügbar')">
                            <span class="font-mono">{{
                                check.core.available ?? '—'
                            }}</span>
                            <span v-if="check.core.size" class="text-ash-dark">
                                · {{ megabytes(check.core.size) }}</span
                            >
                        </PairRow>
                        <PairRow :label="$t('Stand')">
                            <StateTag
                                :tone="
                                    check.error
                                        ? 'danger'
                                        : check.core.newer
                                          ? 'lava'
                                          : 'success'
                                "
                                >{{
                                    check.error
                                        ? $t('nicht geprüft')
                                        : check.core.newer
                                          ? $t('Update verfügbar')
                                          : $t('aktuell')
                                }}</StateTag
                            >
                        </PairRow>
                    </dl>
                    <Notice v-if="check.error" tone="danger" class="mt-3">{{
                        check.error
                    }}</Notice>
                    <details
                        v-if="check.core.notes.length > 0"
                        class="mt-3"
                        open
                    >
                        <summary
                            class="cursor-pointer text-[13px] font-semibold"
                        >
                            {{ $t('Was ist neu?') }}
                        </summary>
                        <ul class="mt-2 grid gap-2 text-[13px]">
                            <li
                                v-for="note in check.core.notes"
                                :key="note.version"
                            >
                                <span class="font-mono font-semibold">{{
                                    note.version
                                }}</span>
                                <span v-if="note.date" class="text-ash-dark">
                                    · {{ note.date }}</span
                                >
                                <p class="mt-0.5 whitespace-pre-line">
                                    {{ note.notes }}
                                </p>
                            </li>
                        </ul>
                    </details>
                    <div
                        v-if="
                            check.core.newer && installation.mode === 'archiv'
                        "
                        class="mt-3"
                    >
                        <Button
                            :disabled="!canRun || busy"
                            @click="confirmCore = true"
                            >{{
                                $t('Auf :version aktualisieren', {
                                    version: check.core.available ?? '',
                                })
                            }}</Button
                        >
                    </div>
                </Card>

                <Card
                    v-if="!installation.saas"
                    :title="$t('Mitgelieferte Module')"
                    :sub="
                        $t('kommen mit dem Kern — Version :version', {
                            version: installation.version,
                        })
                    "
                >
                    <ul class="flex flex-wrap gap-1.5">
                        <li v-for="m in installation.modules" :key="m.handle">
                            <StateTag>{{ m.label }}</StateTag>
                        </li>
                    </ul>
                    <p class="text-ash-dark mt-3 text-[12.5px]">
                        {{
                            $t(
                                'Ein- und ausschalten je Site unter Verwaltung › Module.',
                            )
                        }}
                    </p>
                </Card>

                <Card
                    v-if="!installation.saas"
                    :title="$t('Plugins aus dem Marktplatz')"
                >
                    <Notice
                        v-if="check.marketplace_error"
                        tone="warning"
                        class="mb-2"
                        >{{ check.marketplace_error }}</Notice
                    >
                    <p
                        v-if="check.plugins.length === 0"
                        class="text-ash-dark text-[13px]"
                    >
                        {{
                            $t('Keine Plugins aus dem Marktplatz installiert.')
                        }}
                    </p>
                    <ul v-else class="grid gap-2">
                        <li
                            v-for="p in check.plugins"
                            :key="p.name"
                            class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-ash-light pb-2 last:border-0"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="block font-semibold">{{
                                    p.title
                                }}</span>
                                <span
                                    class="text-ash-dark font-mono text-[11.5px]"
                                    >{{ p.name }} · {{ p.installed ?? '—' }}
                                    <template v-if="p.newer"
                                        >→ {{ p.available }}</template
                                    ></span
                                >
                            </span>
                            <StateTag :tone="p.newer ? 'lava' : 'success'">{{
                                p.newer ? $t('Update verfügbar') : $t('aktuell')
                            }}</StateTag>
                            <Button
                                v-if="p.newer"
                                size="sm"
                                variant="secondary"
                                :disabled="!canRun || busy"
                                @click="post(urls.plugin, { name: p.name })"
                                >{{ $t('Aktualisieren') }}</Button
                            >
                        </li>
                    </ul>
                </Card>

                <Card :title="$t('Themes aus dem Marktplatz')">
                    <p
                        v-if="check.themes.length === 0"
                        class="text-ash-dark text-[13px]"
                    >
                        {{
                            $t(
                                'Keine Themes aus dem Marktplatz in Ihren Sites.',
                            )
                        }}
                    </p>
                    <ul v-else class="grid gap-2">
                        <li
                            v-for="th in check.themes"
                            :key="`${th.site_id}-${th.name}`"
                            class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-ash-light pb-2 last:border-0"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="block font-semibold">{{
                                    th.theme
                                }}</span>
                                <span class="text-ash-dark text-[11.5px]"
                                    >{{ th.site }} ·
                                    <span class="font-mono"
                                        >{{ th.installed ?? '—' }}
                                        <template v-if="th.newer"
                                            >→ {{ th.available }}</template
                                        ></span
                                    ></span
                                >
                            </span>
                            <StateTag :tone="th.newer ? 'lava' : 'success'">{{
                                th.newer
                                    ? $t('Update verfügbar')
                                    : $t('aktuell')
                            }}</StateTag>
                            <Button
                                v-if="th.newer && can_apply"
                                size="sm"
                                variant="secondary"
                                :disabled="busy"
                                @click="
                                    post(urls.theme, {
                                        site_id: th.site_id,
                                        name: th.name,
                                    })
                                "
                                >{{ $t('Als Entwurf laden') }}</Button
                            >
                        </li>
                    </ul>
                    <p class="text-ash-dark mt-3 text-[12.5px]">
                        {{
                            $t(
                                'Ein Theme-Update kommt als Entwurf — Sie prüfen und veröffentlichen es unter Einrichtung › Themes.',
                            )
                        }}
                    </p>
                </Card>
            </div>
        </template>
    </Deferred>

    <Modal
        :open="confirmCore"
        :title="$t('Kern aktualisieren?')"
        :wide="false"
        @close="confirmCore = false"
    >
        <p class="mb-2 text-[13px]">
            {{
                $t('Basalto spielt :version so ein:', {
                    version: check?.core.available ?? '',
                })
            }}
        </p>
        <ol class="mb-3 list-decimal pl-5 text-[13px]">
            <li>{{ $t('Archiv laden und Signatur prüfen') }}</li>
            <li>{{ $t('Sicherung von Datenbank und Medien') }}</li>
            <li>
                {{
                    $t(
                        'Wartungsmodus — die Website ist kurz nicht erreichbar, Sie arbeiten weiter',
                    )
                }}
            </li>
            <li>{{ $t('Dateien tauschen, migrieren, Assets und Caches') }}</li>
            <li>
                {{ $t('Geht etwas schief, kommen die alten Dateien zurück') }}
            </li>
        </ol>
        <p class="text-ash-dark mb-4 text-[12.5px]">
            {{
                $t(
                    '.env, der Speicher (Medien, Sicherungen) und eigene Themes bleiben unberührt.',
                )
            }}
        </p>
        <div class="flex flex-wrap gap-2">
            <Button :disabled="busy" @click="post(urls.core)">{{
                $t('Jetzt aktualisieren')
            }}</Button>
            <Button variant="secondary" @click="confirmCore = false">{{
                $t('Abbrechen')
            }}</Button>
        </div>
    </Modal>
</template>
