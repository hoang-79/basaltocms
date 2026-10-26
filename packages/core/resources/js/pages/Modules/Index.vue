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
 * Site) und «Marktplatz» (Katalog von basaltocms.com). Der Installationsdialog zeigt für Plugins die Composer-Befehle
 * mit dem Token-Hinweis — kein ZIP-Upload (CR-001 § 3.4); Themes übernimmt Basalto signiert als Entwurf. Keine
 * Bilder von basaltocms.com im Backend (keine Fremdressource) — die Karten tragen das Sechseck mit dem Anfangsbuchstaben.
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
    urls: {
        index: string;
        install_theme: string;
        theme_editor: string;
        try_on: string;
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

const search = controlClasses({ small: true });

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
        </template>

        <template #marketplace>
            <p class="mb-3 text-[12.5px] text-basalt-light">
                {{
                    $t(
                        'Themes sind Daten und laufen überall — «Theme übernehmen» legt sie signiert als Entwurf an. Plugins sind Code und kommen per Composer, nie als ZIP-Upload.',
                    )
                }}
            </p>
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
                                <Button size="sm" @click="open(e)">{{
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
            <template v-if="selected.kind === 'plugin'">
                <p>
                    {{
                        $t(
                            'Plugins kommen per Composer — kein ZIP-Upload. Befehle in der Installation ausführen:',
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
                            'Bezahltes Theme: erst auf :host kaufen. Basalto lädt es mit E-Mail und Token Ihres Kundenkontos (BASALTO_MARKETPLACE_EMAIL und BASALTO_MARKETPLACE_TOKEN in .env oder auth.json wie für Composer).',
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
</template>
