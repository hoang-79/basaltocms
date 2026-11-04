<script setup lang="ts">
import { Deferred, Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '../../components/Button.vue';
import CodeBlock from '../../components/CodeBlock.vue';
import { controlClasses } from '../../components/control';
import EmptyState from '../../components/EmptyState.vue';
import Hex from '../../components/Hex.vue';
import Modal from '../../components/Modal.vue';
import Notice from '../../components/Notice.vue';
import PageHeader from '../../components/PageHeader.vue';
import StateTag from '../../components/StateTag.vue';
import Tabs from '../../components/Tabs.vue';
import { t } from '../../i18n';
import type { TabDef } from '../../types';

/**
 * TB 11 Scheibe 2 (M-20, Mockup 20): Verwaltung › Module — Reiter «Module» (Stand je Site, geschaltet wird in der
 * Site) und «Marktplatz» (Katalog von basaltocms.com). Themes übernimmt Basalto signiert als Entwurf. Keine Bilder von
 * basaltocms.com im Backend (keine Fremdressource) — die Karten tragen das Sechseck mit dem Anfangsbuchstaben.
 *
 * CR-018 (TB 98): Plugins installiert Basalto selbst — signiertes Archiv, Sicherung, ohne Composer und ohne SSH —, wenn
 * die Installation aus dem Web-Installer stammt; sonst zeigt der Dialog die Composer-Befehle und den Grund. Dazu die
 * Liste der so installierten Plugins (ein/aus, entfernen) und das Kundenkonto, mit dem Basalto sie lädt.
 */
type Entry = {
    handle: string;
    name: string;
    kind: 'theme' | 'plugin';
    title: string;
    summary: string | null;
    author: string;
    version: string;
    license: string;
    free: boolean;
    price: number;
    currency: string | null;
    installs: number;
    requirements: Record<string, string>;
    url: string | null;
    require: string | null;
    sha256: string | null;
    /** TB 24: Kategorie und Prüfstand aus dem Katalog (Bilder bleiben draussen — keine Fremdressource) */
    category?: string | null;
    badges?: { label: string; ok: boolean | null }[];
    /** TB 25: Durchschnitt der Bewertungen und ihre Zahl */
    rating?: number | null;
    rating_count?: number;
};

type InstalledPlugin = {
    name: string;
    label: string;
    module: string;
    version: string;
    enabled: boolean;
    error: string | null;
};

const props = defineProps<{
    tab: 'modules' | 'marketplace';
    modules: {
        handle: string;
        label: string;
        switchable: boolean;
        enabled: boolean;
        api: number | null;
        requires: string[];
    }[];
    site: { name: string; edit_url: string };
    marketplace: { url: string; host: string; repository: string };
    catalog?: {
        ok: boolean;
        entries: Entry[];
        error: string | null;
        fetched_at: string | null;
    };
    plugins: InstalledPlugin[];
    plugin_install: {
        manageable: boolean;
        available: boolean;
        problems: string[];
        installed: string[];
        account: {
            source: 'config' | 'backend' | 'auth.json' | null;
            email: string | null;
        };
    };
    urls: {
        index: string;
        install_theme: string;
        theme_editor: string;
        try_on: string;
        install_plugin: string;
        toggle_plugin: string;
        remove_plugin: string;
        account: string;
        updates: string;
    };
}>();

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);

const active = ref<string>(props.tab);
const tabs = computed<TabDef[]>(() => [
    { key: 'modules', label: t('Module'), count: props.modules.length },
    { key: 'marketplace', label: t('Marktplatz') },
]);

/* ── Marktplatz: Filter und Suche im geladenen Katalog ── */

const kind = ref<'' | 'theme' | 'plugin'>('');
const q = ref('');
const entries = computed(() => {
    const needle = q.value.trim().toLowerCase();

    return (props.catalog?.entries ?? []).filter(
        (e) =>
            (kind.value === '' || e.kind === kind.value) &&
            (needle === '' ||
                [e.title, e.name, e.author, e.summary ?? '']
                    .join(' ')
                    .toLowerCase()
                    .includes(needle)),
    );
});
const counts = computed(() => {
    const all = props.catalog?.entries ?? [];

    return {
        all: all.length,
        theme: all.filter((e) => e.kind === 'theme').length,
        plugin: all.filter((e) => e.kind === 'plugin').length,
    };
});

function reload(): void {
    router.get(
        props.urls.index,
        { reiter: 'marktplatz', neu: 1 },
        { preserveScroll: true, only: ['catalog'] },
    );
}

/* ── Installationsdialog ── */

const selected = ref<Entry | null>(null);
const copied = ref(false);
const installing = ref(false);

const commands = computed(() =>
    selected.value?.kind === 'plugin'
        ? [
              `composer config repositories.basalto composer ${props.marketplace.repository}`,
              `composer config http-basic.${props.marketplace.host} <${t('E-Mail')}> <${t('Token')}>`,
              selected.value.require ?? '',
          ].join('\n')
        : '',
);

function open(entry: Entry): void {
    selected.value = entry;
    copied.value = false;
}

async function copy(): Promise<void> {
    if (!navigator.clipboard) {
        return;
    }
    await navigator.clipboard.writeText(commands.value);
    copied.value = true;
}

function adopt(): void {
    if (!selected.value) {
        return;
    }
    installing.value = true;
    router.post(
        props.urls.install_theme,
        { name: selected.value.name, version: selected.value.version },
        {
            onFinish: () => {
                installing.value = false;
            },
            onError: () => {
                selected.value = null;
            },
        },
    );
}

/** CR-018: Plugin im Backend installieren — die Anfrage dauert (Laden, Sicherung, Migrationen). */
const installingPlugin = ref(false);
const isInstalledPlugin = (e: { kind: string; name: string }): boolean =>
    e.kind === 'plugin' && props.plugin_install.installed.includes(e.name);
const selectedInstalled = computed(
    () =>
        selected.value !== null &&
        props.plugin_install.installed.includes(selected.value.name),
);
function installPlugin(): void {
    if (!selected.value) {
        return;
    }
    installingPlugin.value = true;
    router.post(
        props.urls.install_plugin,
        { name: selected.value.name },
        {
            preserveScroll: true,
            onFinish: () => {
                installingPlugin.value = false;
            },
            onSuccess: () => {
                selected.value = null;
            },
        },
    );
}

const pluginBusy = ref(false);
function togglePlugin(plugin: InstalledPlugin): void {
    pluginBusy.value = true;
    router.post(
        props.urls.toggle_plugin,
        { name: plugin.name, enabled: !plugin.enabled },
        {
            preserveScroll: true,
            onFinish: () => {
                pluginBusy.value = false;
            },
        },
    );
}

const removing = ref<InstalledPlugin | null>(null);
const deleteData = ref(false);
function askRemove(plugin: InstalledPlugin): void {
    removing.value = plugin;
    deleteData.value = false;
}
function removePlugin(): void {
    if (!removing.value) {
        return;
    }
    pluginBusy.value = true;
    router.post(
        props.urls.remove_plugin,
        { name: removing.value.name, delete_data: deleteData.value },
        {
            preserveScroll: true,
            onFinish: () => {
                pluginBusy.value = false;
                removing.value = null;
            },
        },
    );
}

/** Kundenkonto von basaltocms.com: E-Mail und Token, im Backend geprüft und verschlüsselt abgelegt. */
const accountEmail = ref('');
const accountToken = ref('');
const connecting = ref(false);
function connectAccount(): void {
    connecting.value = true;
    router.put(
        props.urls.account,
        { email: accountEmail.value, token: accountToken.value },
        {
            preserveScroll: true,
            onFinish: () => {
                connecting.value = false;
            },
            onSuccess: () => {
                accountToken.value = '';
            },
        },
    );
}
function disconnectAccount(): void {
    router.delete(props.urls.account, { preserveScroll: true });
}

const search = controlClasses({ small: true });
const field = controlClasses({ small: true });

/** TB 13 Scheibe 1: Preis eines bezahlten Eintrags kurz — «CHF 59» bzw. «CHF 59.50». */
function priceLabel(entry: Entry): string {
    if (entry.free) {
        return t('kostenlos');
    }
    const amount = entry.price / 100;

    return `${entry.currency ?? 'CHF'} ${Number.isInteger(amount) ? amount : amount.toFixed(2)}`;
}
</script>

<template>
    <Head :title="$t('Module')" />
    <PageHeader
        :title="$t('Module')"
        :crumb="$t('Verwaltung › Module')"
        :lead="
            $t(
                'Mitgelieferte Module je Site schalten; Themes und Plugins aus dem Marktplatz von :host.',
                { host: marketplace.host },
            )
        "
    />

    <Notice v-if="errors.marketplace" tone="danger" class="mb-3">{{
        errors.marketplace
    }}</Notice>
    <Notice v-if="errors.plugin" tone="danger" class="mb-3" role="alert">{{
        errors.plugin
    }}</Notice>

    <Tabs v-model="active" :tabs="tabs" tools-for="marketplace">
        <template #tools>
            <div
                v-if="active === 'marketplace'"
                class="flex flex-wrap items-center gap-2"
            >
                <div
                    class="flex flex-wrap gap-1"
                    role="group"
                    :aria-label="$t('Nach Art filtern')"
                >
                    <Button
                        v-for="option in [
                            { value: '', label: $t('Alle'), count: counts.all },
                            {
                                value: 'theme',
                                label: $t('Themes'),
                                count: counts.theme,
                            },
                            {
                                value: 'plugin',
                                label: $t('Plugins'),
                                count: counts.plugin,
                            },
                        ]"
                        :key="option.value"
                        size="sm"
                        :variant="kind === option.value ? 'dark' : 'secondary'"
                        :aria-pressed="kind === option.value"
                        @click="kind = option.value as '' | 'theme' | 'plugin'"
                        >{{ option.label }} · {{ option.count }}</Button
                    >
                </div>
                <label class="sr-only" for="markt-q">{{ $t('Suchen') }}</label>
                <input
                    id="markt-q"
                    v-model="q"
                    type="search"
                    :placeholder="`⌕ ${$t('Suchen')}`"
                    :class="[search, 'w-full! sm:w-48!']"
                />
            </div>
        </template>

        <template #modules>
            <Notice tone="grey" class="mb-3">
                {{
                    $t(
                        'Geschaltet wird je Site unter Sites › «:site» › Module. Ausschalten versteckt Routen, Backend-Seiten und Lava-Tags des Moduls — löscht nichts.',
                        { site: site.name },
                    )
                }}
                <Link
                    :href="site.edit_url"
                    class="font-semibold text-lava-dark hover:underline"
                    >{{ $t('Zur Site') }} ›</Link
                >
            </Notice>
            <ul
                class="divide-y divide-ash-light rounded-lg border border-ash-light bg-white"
            >
                <li
                    v-for="m in modules"
                    :key="m.handle"
                    class="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5"
                >
                    <div class="min-w-0">
                        <b class="font-semibold">{{ m.label }}</b>
                        <p class="text-[11.5px] break-words text-basalt-light">
                            <span class="font-mono">{{ m.handle }}</span>
                            <template v-if="m.requires.length > 0">
                                ·
                                {{
                                    $t('braucht :modules', {
                                        modules: m.requires.join(', '),
                                    })
                                }}</template
                            ><template v-if="!m.switchable">
                                · {{ $t('immer an') }}</template
                            >
                        </p>
                    </div>
                    <StateTag :tone="m.enabled ? 'success' : 'grey'">{{
                        m.enabled
                            ? $t('an auf dieser Site')
                            : $t('aus auf dieser Site')
                    }}</StateTag>
                </li>
            </ul>

            <section
                v-if="plugins.length > 0 || plugin_install.available"
                class="mt-6"
                :aria-labelledby="'plugins-titel'"
            >
                <h2 id="plugins-titel" class="mb-2 text-[14px] font-semibold">
                    {{ $t('Plugins aus dem Marktplatz') }}
                </h2>
                <p
                    v-if="plugins.length === 0"
                    class="text-[12.5px] text-basalt-light"
                >
                    {{
                        $t(
                            'Noch keine — im Reiter «Marktplatz» installiert Basalto Plugins selbst, mit Sicherung.',
                        )
                    }}
                </p>
                <ul
                    v-else
                    class="divide-y divide-ash-light rounded-lg border border-ash-light bg-white"
                >
                    <li
                        v-for="p in plugins"
                        :key="p.name"
                        class="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5"
                    >
                        <div class="min-w-0">
                            <b class="font-semibold">{{ p.label }}</b>
                            <p
                                class="text-[11.5px] break-words text-basalt-light"
                            >
                                <span class="font-mono">{{ p.name }}</span>
                                · v{{ p.version }} · {{ p.module }}
                            </p>
                            <p
                                v-if="p.error"
                                class="text-danger text-[11.5px] break-words"
                            >
                                {{ p.error }}
                            </p>
                        </div>
                        <span class="flex flex-wrap items-center gap-1.5">
                            <StateTag
                                :tone="
                                    p.error
                                        ? 'danger'
                                        : p.enabled
                                          ? 'success'
                                          : 'grey'
                                "
                                >{{
                                    p.error
                                        ? $t('gestört')
                                        : p.enabled
                                          ? $t('an')
                                          : $t('aus')
                                }}</StateTag
                            >
                            <template v-if="plugin_install.manageable">
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    :disabled="pluginBusy"
                                    @click="togglePlugin(p)"
                                    >{{
                                        p.enabled
                                            ? $t('Ausschalten')
                                            : $t('Einschalten')
                                    }}</Button
                                >
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    :disabled="pluginBusy"
                                    @click="askRemove(p)"
                                    >{{ $t('Entfernen') }}</Button
                                >
                            </template>
                        </span>
                    </li>
                </ul>
                <p
                    v-if="plugins.length > 0"
                    class="mt-2 text-[11.5px] text-basalt-light"
                >
                    {{
                        $t(
                            'Ausschalten gilt für die ganze Installation; je Site schalten Sie unter Sites. Neue Versionen spielen Sie unter Updates ein.',
                        )
                    }}
                    <Link
                        :href="urls.updates"
                        class="font-semibold text-lava-dark hover:underline"
                        >{{ $t('Zu den Updates') }} ›</Link
                    >
                </p>
            </section>
        </template>

        <template #marketplace>
            <p class="mb-3 text-[12.5px] text-basalt-light">
                {{
                    $t(
                        'Themes sind Daten und laufen überall — «Theme übernehmen» legt sie signiert als Entwurf an. Plugins sind Code: Basalto installiert sie signiert aus dem Marktplatz und legt vorher eine Sicherung an — oder per Composer, wenn die Installation damit gepflegt wird. Nie als ZIP-Upload.',
                    )
                }}
            </p>
            <div
                v-if="plugin_install.manageable"
                class="mb-4 rounded-lg border border-ash-light bg-white p-3"
            >
                <h2 class="text-[13.5px] font-semibold">
                    {{
                        $t('Kundenkonto von :host', {
                            host: marketplace.host,
                        })
                    }}
                </h2>
                <p
                    v-if="plugin_install.account.source"
                    class="mt-1 flex flex-wrap items-center gap-2 text-[12.5px]"
                >
                    <StateTag tone="success">{{ $t('verbunden') }}</StateTag>
                    <span>{{
                        plugin_install.account.source === 'backend'
                            ? $t('als :email', {
                                  email: plugin_install.account.email ?? '',
                              })
                            : plugin_install.account.source === 'config'
                              ? $t('über die Server-Konfiguration (:email)', {
                                    email: plugin_install.account.email ?? '',
                                })
                              : $t('über auth.json (:email)', {
                                    email: plugin_install.account.email ?? '',
                                })
                    }}</span>
                    <Button
                        v-if="plugin_install.account.source === 'backend'"
                        size="sm"
                        variant="secondary"
                        @click="disconnectAccount"
                        >{{ $t('Trennen') }}</Button
                    >
                </p>
                <form
                    v-else
                    class="mt-2 grid gap-2 md:grid-cols-[1fr_1fr_auto] md:items-start"
                    @submit.prevent="connectAccount"
                >
                    <div>
                        <label class="sr-only" for="konto-email">{{
                            $t('E-Mail')
                        }}</label>
                        <input
                            id="konto-email"
                            v-model="accountEmail"
                            type="email"
                            autocomplete="email"
                            :placeholder="$t('E-Mail des Kundenkontos')"
                            :class="field"
                            :aria-invalid="errors.email ? 'true' : undefined"
                        />
                        <p
                            v-if="errors.email"
                            class="text-danger mt-1 text-[11.5px]"
                        >
                            {{ errors.email }}
                        </p>
                    </div>
                    <div>
                        <label class="sr-only" for="konto-token">{{
                            $t('Token')
                        }}</label>
                        <input
                            id="konto-token"
                            v-model="accountToken"
                            type="password"
                            autocomplete="off"
                            :placeholder="$t('Token')"
                            :class="[field, 'font-mono']"
                            :aria-invalid="errors.token ? 'true' : undefined"
                        />
                        <p
                            v-if="errors.token"
                            class="text-danger mt-1 text-[11.5px]"
                        >
                            {{ errors.token }}
                        </p>
                    </div>
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="
                            connecting ||
                            accountEmail === '' ||
                            accountToken === ''
                        "
                        >{{ $t('Verbinden') }}</Button
                    >
                    <p class="text-[11.5px] text-basalt-light md:col-span-3">
                        {{
                            $t(
                                'Damit lädt Basalto Plugins und gekaufte Themes. Das Token steht in Ihrem Autorenkonto auf :host (Anmeldung per Code); Basalto prüft es und speichert es verschlüsselt.',
                                { host: marketplace.host },
                            )
                        }}
                    </p>
                </form>
            </div>
            <Deferred data="catalog">
                <template #fallback>
                    <div
                        class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
                        :aria-label="$t('Katalog lädt …')"
                        aria-busy="true"
                    >
                        <div
                            v-for="i in 6"
                            :key="i"
                            class="h-[150px] animate-pulse rounded-lg border border-ash-light bg-white motion-reduce:animate-none"
                        ></div>
                    </div>
                </template>
                <Notice
                    v-if="catalog && !catalog.ok"
                    tone="warning"
                    :title="$t('Marktplatz nicht erreichbar')"
                >
                    {{ catalog.error }}
                    <Button size="sm" variant="secondary" @click="reload">{{
                        $t('Neu laden')
                    }}</Button>
                </Notice>
                <div
                    v-else-if="entries.length === 0"
                    class="rounded-lg border border-ash-light bg-white"
                >
                    <EmptyState
                        :title="
                            counts.all === 0
                                ? $t('Der Marktplatz ist noch leer')
                                : $t('Nichts gefunden')
                        "
                        icon="module"
                        tone="ash"
                    />
                </div>
                <ul v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <li
                        v-for="e in entries"
                        :key="e.handle"
                        class="flex flex-col rounded-lg border border-ash-light bg-white p-3"
                    >
                        <div class="flex items-start gap-2.5">
                            <Hex
                                :label="e.title.slice(0, 1).toUpperCase()"
                                :tone="e.kind === 'theme' ? 'lava' : 'basalt'"
                                size="md"
                            />
                            <div class="min-w-0 flex-1">
                                <h3
                                    class="text-[13.5px] font-semibold break-words"
                                >
                                    {{ e.title }}
                                </h3>
                                <p
                                    class="text-[11.5px] break-words text-basalt-light"
                                >
                                    {{ e.author }} · v{{ e.version
                                    }}{{ e.category ? ` · ${e.category}` : '' }}
                                </p>
                            </div>
                            <StateTag>{{
                                e.kind === 'theme' ? $t('Theme') : $t('Plugin')
                            }}</StateTag>
                        </div>
                        <p
                            v-if="e.summary"
                            class="mt-2 text-[12.5px] break-words"
                        >
                            {{ e.summary }}
                        </p>
                        <ul
                            v-if="e.badges && e.badges.length > 0"
                            class="mt-2 flex flex-wrap gap-x-2.5 gap-y-0.5 text-[11px]"
                            :aria-label="$t('Prüfstand')"
                        >
                            <li
                                v-for="b in e.badges"
                                :key="b.label"
                                :class="
                                    b.ok === true
                                        ? 'text-success'
                                        : b.ok === false
                                          ? 'text-danger'
                                          : 'text-basalt-light'
                                "
                            >
                                <span aria-hidden="true">{{
                                    b.ok === true
                                        ? '✓'
                                        : b.ok === false
                                          ? '✗'
                                          : '○'
                                }}</span>
                                {{ b.label }}
                            </li>
                        </ul>
                        <div
                            class="mt-auto flex flex-wrap items-center justify-between gap-2 pt-3"
                        >
                            <span class="flex flex-wrap items-center gap-1.5">
                                <StateTag :tone="e.free ? 'success' : 'lava'">{{
                                    priceLabel(e)
                                }}</StateTag>
                                <span
                                    v-if="e.rating"
                                    class="text-[11px] text-lava-dark"
                                    :aria-label="
                                        $tc(
                                            ':avg von 5 Sternen aus :count Bewertung|:avg von 5 Sternen aus :count Bewertungen',
                                            e.rating_count ?? 0,
                                            { avg: e.rating },
                                        )
                                    "
                                    >★ {{ e.rating }} ({{
                                        e.rating_count
                                    }})</span
                                >
                                <span class="text-[11px] text-basalt-light">{{
                                    $tc(
                                        ':count Installation|:count Installationen',
                                        e.installs,
                                    )
                                }}</span>
                            </span>
                            <span class="flex flex-wrap gap-1.5">
                                <Button
                                    v-if="e.kind === 'theme'"
                                    size="sm"
                                    variant="secondary"
                                    :href="`${urls.try_on}?theme=${encodeURIComponent(e.name)}`"
                                    >{{ $t('Anprobieren') }}</Button
                                >
                                <!-- Installiertes Plugin: kein zweites «Installieren», der Dialog sagt, wo es weitergeht -->
                                <template v-if="isInstalledPlugin(e)">
                                    <StateTag tone="success">{{
                                        $t('installiert')
                                    }}</StateTag>
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        @click="open(e)"
                                        >{{ $t('Details') }}</Button
                                    >
                                </template>
                                <Button v-else size="sm" @click="open(e)">{{
                                    $t('Installieren')
                                }}</Button>
                            </span>
                        </div>
                    </li>
                </ul>
                <p
                    v-if="catalog?.ok"
                    class="mt-2 text-[11.5px] text-basalt-light"
                >
                    {{
                        $t(
                            'Katalog von :url, zwischengespeichert für einige Minuten.',
                            {
                                url: marketplace.url,
                            },
                        )
                    }}
                    <button
                        type="button"
                        class="font-semibold text-lava-dark hover:underline"
                        @click="reload"
                    >
                        {{ $t('Neu laden') }}
                    </button>
                </p>
            </Deferred>
        </template>
    </Tabs>

    <Modal
        :open="selected !== null"
        :wide="false"
        :title="
            selected ? $t(':name installieren', { name: selected.title }) : ''
        "
        :subtitle="
            selected
                ? `${selected.author} · v${selected.version} · ${selected.license} · ${priceLabel(selected)}`
                : ''
        "
        @close="selected = null"
    >
        <div v-if="selected" class="flex flex-col gap-3 text-[13px]">
            <template v-if="selected.kind === 'plugin' && selectedInstalled">
                <p>
                    {{
                        $t(
                            ':name ist in dieser Installation schon installiert. Neue Versionen spielen Sie unter Updates ein.',
                            { name: selected.title },
                        )
                    }}
                </p>
                <div class="flex flex-wrap justify-end gap-2">
                    <Button variant="secondary" :href="urls.updates">{{
                        $t('Zu den Updates')
                    }}</Button>
                    <Button @click="selected = null">{{
                        $t('Schliessen')
                    }}</Button>
                </div>
            </template>
            <template
                v-else-if="
                    selected.kind === 'plugin' && plugin_install.available
                "
            >
                <p>
                    {{
                        $t(
                            'Basalto lädt das Plugin aus dem Marktplatz, prüft seine Signatur und ob es zu dieser Installation passt, legt eine Sicherung an und richtet es ein. Danach schalten Sie es unter Sites für die Sites ein, die es nutzen sollen.',
                        )
                    }}
                </p>
                <Notice v-if="!plugin_install.account.source" tone="warning">
                    {{
                        $t(
                            'Verbinden Sie zuerst Ihr Kundenkonto von :host — oben im Reiter «Marktplatz».',
                            { host: marketplace.host },
                        )
                    }}
                </Notice>
                <p v-if="!selected.free" class="text-[12px] text-basalt-light">
                    {{
                        $t(
                            'Bezahltes Plugin: erst auf :host kaufen — mit demselben Kundenkonto.',
                            { host: marketplace.host },
                        )
                    }}
                </p>
                <div class="flex flex-wrap justify-end gap-2">
                    <a
                        v-if="selected.url"
                        :href="selected.url"
                        target="_blank"
                        rel="noopener"
                        class="self-center text-[12.5px] font-semibold text-lava-dark hover:underline"
                        >{{
                            $t('Auf :host ansehen', { host: marketplace.host })
                        }}</a
                    >
                    <Button variant="secondary" @click="selected = null">{{
                        $t('Abbrechen')
                    }}</Button>
                    <Button
                        :disabled="
                            installingPlugin || !plugin_install.account.source
                        "
                        @click="installPlugin"
                        >{{
                            installingPlugin
                                ? $t('Installiert …')
                                : $t('Installieren')
                        }}</Button
                    >
                </div>
            </template>
            <template v-else-if="selected.kind === 'plugin'">
                <Notice v-if="plugin_install.problems.length > 0" tone="grey">
                    <span
                        v-for="(problem, i) in plugin_install.problems"
                        :key="i"
                        class="block"
                        >{{ problem }}</span
                    >
                </Notice>
                <p>
                    {{
                        $t(
                            'Plugins kommen hier per Composer — kein ZIP-Upload. Befehle in der Installation ausführen:',
                        )
                    }}
                </p>
                <CodeBlock :code="commands" />
                <p class="text-[12px] text-basalt-light">
                    {{
                        $t(
                            'Das Token steht im Autorenkonto auf :host (Anmeldung per Code). Danach php artisan migrate — das Plugin erscheint unter «Module».',
                            { host: marketplace.host },
                        )
                    }}
                    <template v-if="!selected.free">
                        {{
                            $t(
                                'Bezahltes Plugin: Composer liefert es erst nach dem Kauf auf :host.',
                                { host: marketplace.host },
                            )
                        }}
                    </template>
                </p>
                <div class="flex flex-wrap justify-end gap-2">
                    <a
                        v-if="selected.url"
                        :href="selected.url"
                        target="_blank"
                        rel="noopener"
                        class="self-center text-[12.5px] font-semibold text-lava-dark hover:underline"
                        >{{
                            $t('Auf :host ansehen', { host: marketplace.host })
                        }}</a
                    >
                    <Button variant="secondary" @click="copy">{{
                        copied ? $t('Kopiert') : $t('Befehle kopieren')
                    }}</Button>
                    <Button @click="selected = null">{{
                        $t('Schliessen')
                    }}</Button>
                </div>
            </template>
            <template v-else>
                <p>
                    {{
                        $t(
                            'Basalto lädt das Paket, prüft die Signatur des Marktplatzes und legt die Dateien als Entwürfe an. Veröffentlicht wird im Theme-Editor nach Vorschau und Freigabe.',
                        )
                    }}
                </p>
                <p v-if="!selected.free" class="text-[12px] text-basalt-light">
                    {{
                        $t(
                            'Bezahltes Theme: erst auf :host kaufen. Basalto lädt es mit Ihrem Kundenkonto — verbunden oben im Reiter «Marktplatz».',
                            { host: marketplace.host },
                        )
                    }}
                </p>
                <div class="flex flex-wrap justify-end gap-2">
                    <a
                        v-if="selected.url"
                        :href="selected.url"
                        target="_blank"
                        rel="noopener"
                        class="self-center text-[12.5px] font-semibold text-lava-dark hover:underline"
                        >{{
                            $t('Auf :host ansehen', { host: marketplace.host })
                        }}</a
                    >
                    <Button variant="secondary" @click="selected = null">{{
                        $t('Abbrechen')
                    }}</Button>
                    <Button :disabled="installing" @click="adopt">{{
                        $t('Theme übernehmen')
                    }}</Button>
                </div>
            </template>
        </div>
    </Modal>

    <Modal
        :open="removing !== null"
        :wide="false"
        :title="
            removing ? $t('«:name» entfernen', { name: removing.label }) : ''
        "
        :subtitle="removing ? `${removing.name} · v${removing.version}` : ''"
        @close="removing = null"
    >
        <div v-if="removing" class="flex flex-col gap-3 text-[13px]">
            <p>
                {{
                    $t(
                        'Basalto nimmt die Dateien des Plugins weg. Seine Daten bleiben in der Datenbank — installieren Sie es wieder, ist alles noch da.',
                    )
                }}
            </p>
            <label class="flex items-start gap-2">
                <input
                    v-model="deleteData"
                    type="checkbox"
                    class="mt-0.5 h-4 w-4 accent-lava-dark"
                />
                <span>{{
                    $t(
                        'Auch die Daten des Plugins löschen (seine Tabellen) — vorher legt Basalto eine Sicherung an.',
                    )
                }}</span>
            </label>
            <div class="flex flex-wrap justify-end gap-2">
                <Button variant="secondary" @click="removing = null">{{
                    $t('Abbrechen')
                }}</Button>
                <Button
                    variant="danger"
                    :disabled="pluginBusy"
                    @click="removePlugin"
                    >{{ $t('Entfernen') }}</Button
                >
            </div>
        </div>
    </Modal>
</template>
