<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '../components/Button.vue';
import Card from '../components/Card.vue';
import Field from '../components/Field.vue';
import Hex from '../components/Hex.vue';
import Modal from '../components/Modal.vue';
import Notice from '../components/Notice.vue';
import PageHeader from '../components/PageHeader.vue';
import PairRow from '../components/PairRow.vue';
import StateTag from '../components/StateTag.vue';
import Tabs from '../components/Tabs.vue';
import { freeThemeName, isThemeName, themeSlug } from '../components/themes';
import { t, tc } from '../i18n';
import type { TabDef } from '../types';

/**
 * Station «Themes» (M-15, Mockup 27): installierte Themes als Karten mit Umfang und Prüfstand, Vorschau in der eigenen
 * Sitzung, Wechsel je Site nach einem Bericht, was die Site mit dem neuen Theme nicht mehr zeigen würde. Inhalte
 * bleiben beim Wechsel unverändert.
 *
 * TB 22 (Theme-Bibliothek): eigene Themes der Site hinzufügen (Paket, Kopie, Marktplatz), bearbeiten und entfernen;
 * mitgelieferte Themes aus dem Ordner ausblenden.
 */
type ThemeCard = {
    name: string;
    label: string;
    author: string | null;
    version: string | null;
    license: string | null;
    description: string | null;
    preview_url: string | null;
    preview_link: string;
    impact_url: string;
    export_url: string;
    types: number;
    blocks: number;
    templates: number;
    errors: number;
    warnings: number;
    diagnostics: string[];
    updated: string | null;
    kind: 'own' | 'folder';
    // eigene Themes
    origin?: 'ordner' | 'kopie' | 'paket' | 'marktplatz';
    origin_name?: string | null;
    signed?: boolean;
    replaces_folder?: boolean;
    destroy_url?: string;
    edit_url?: string;
    // Ordner
    hidden?: boolean;
    hide_url?: string;
};
type Impact = {
    ok: boolean;
    diagnostics: string[];
    types: Record<string, number>;
    blocks: Record<string, number>;
    templates: Record<string, number>;
    navs: Record<string, number>;
    forms: string[];
    design: string[];
    empty: boolean;
};

const props = defineProps<{
    own: ThemeCard[];
    themes: ThemeCard[];
    active: string;
    source: 'files' | 'database';
    preview: string | null;
    can_edit: boolean;
    limits: { package_mb: number };
    urls: {
        switch: string;
        preview_end: string;
        check: string;
        upload: string;
        copy: string;
        marketplace: string;
        editor: string;
    };
}>();

const page = usePage();
const pageErrors = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);

const activeFirst = (list: ThemeCard[]): ThemeCard[] => [
    ...list.filter((theme) => theme.name === props.active),
    ...list.filter((theme) => theme.name !== props.active),
];
const ownSorted = computed(() => activeFirst(props.own));
const folders = computed(() =>
    activeFirst(props.themes.filter((theme) => !theme.hidden)),
);
const hiddenFolders = computed(() =>
    props.themes.filter((theme) => theme.hidden),
);
const allThemes = computed(() => [...props.own, ...props.themes]);

function originText(theme: ThemeCard): string {
    switch (theme.origin) {
        case 'kopie':
            return t('Kopie von «:name»', { name: theme.origin_name ?? '' });
        case 'paket':
            return theme.signed
                ? t('aus Paket (signiert)')
                : t('aus Paket (nicht signiert)');
        case 'marktplatz':
            return t('aus dem Marktplatz');
        default:
            return t('aus dem Ordner übernommen');
    }
}

/* Wechsel mit Bericht */
const target = ref<ThemeCard | null>(null);
const impact = ref<Impact | null>(null);
const loading = ref(false);
const loadError = ref('');
const form = useForm({ name: '', confirm: false });

async function askSwitch(theme: ThemeCard): Promise<void> {
    target.value = theme;
    impact.value = null;
    loadError.value = '';
    form.clearErrors();
    form.name = theme.name;
    form.confirm = false;
    loading.value = true;
    try {
        const response = await fetch(theme.impact_url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok) {
            throw new Error(String(response.status));
        }
        impact.value = (await response.json()) as Impact;
    } catch {
        loadError.value = t(
            'Der Bericht liess sich nicht laden. Bitte erneut versuchen.',
        );
    } finally {
        loading.value = false;
    }
}

function close(): void {
    target.value = null;
}

function confirmSwitch(): void {
    form.confirm = !(impact.value?.empty ?? true);
    form.put(props.urls.switch, {
        preserveScroll: true,
        onSuccess: () => close(),
    });
}

type ReportRow = { label: string; items: string[] };
const reportRows = computed<ReportRow[]>(() => {
    const i = impact.value;
    if (!i) {
        return [];
    }
    const counted = (
        record: Record<string, number>,
        amount: (n: number) => string,
    ) => Object.entries(record).map(([name, n]) => `${name} — ${amount(n)}`);

    return [
        {
            label: t('Inhaltstypen, die das Theme nicht kennt'),
            items: counted(i.types, (n) =>
                tc(':count Inhalt|:count Inhalte', n),
            ),
        },
        {
            label: t('Blöcke, die nicht mehr angezeigt werden'),
            items: counted(i.blocks, (n) =>
                tc(':count Inhalt|:count Inhalte', n),
            ),
        },
        {
            label: t('Templates, auf die Seiten verweisen'),
            items: counted(i.templates, (n) =>
                tc(':count Seite|:count Seiten', n),
            ),
        },
        {
            label: t('Menüs mit eigenen Einträgen'),
            items: counted(i.navs, (n) =>
                tc(':count Eintrag|:count Einträge', n),
            ),
        },
        { label: t('Formulare mit Eingängen'), items: i.forms },
        { label: t('Überschreibungen in Station B'), items: i.design },
    ].filter((row) => row.items.length > 0);
});

/* Hinzufügen: Paket, Kopie, Marktplatz */
const addOpen = ref(false);
const addTab = ref<string>('paket');
const uploadForm = useForm<{ package: File | null }>({ package: null });
const copyForm = useForm({ from: '', name: '', label: '' });
const nameTouched = ref(false);

function openAdd(
    tab: 'paket' | 'kopie' | 'marktplatz',
    from?: ThemeCard,
): void {
    addTab.value = tab;
    uploadForm.reset();
    uploadForm.clearErrors();
    copyForm.clearErrors();
    nameTouched.value = false;
    const source =
        from ??
        allThemes.value.find((theme) => theme.name === props.active) ??
        allThemes.value[0];
    copyForm.from = source?.name ?? '';
    copyForm.label = source ? t('Kopie von :name', { name: source.label }) : '';
    copyForm.name = suggestName(copyForm.label);
    addOpen.value = true;
}

function suggestName(label: string): string {
    return freeThemeName(
        label,
        allThemes.value.map((theme) => theme.name),
    );
}

function onLabel(value: string | number | null): void {
    copyForm.label = String(value ?? '');
    if (!nameTouched.value) {
        copyForm.name = suggestName(copyForm.label);
    }
}

function onName(value: string | number | null): void {
    nameTouched.value = true;
    copyForm.name = themeSlug(String(value ?? '')) || String(value ?? '');
}

const copyNameError = computed(() => {
    if (copyForm.errors.name) {
        return copyForm.errors.name;
    }
    if (copyForm.name !== '' && !isThemeName(copyForm.name)) {
        return t(
            'Der Name darf nur Kleinbuchstaben, Ziffern und Bindestriche enthalten (z. B. «mein-theme»).',
        );
    }
    if (allThemes.value.some((theme) => theme.name === copyForm.name)) {
        return t(
            'Ein Theme «:name» gibt es schon — bitte einen anderen Namen wählen.',
            { name: copyForm.name },
        );
    }
    return '';
});

function submitUpload(): void {
    uploadForm.post(props.urls.upload, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => (addOpen.value = false),
    });
}

function submitCopy(): void {
    copyForm.post(props.urls.copy, {
        preserveScroll: true,
        onSuccess: () => (addOpen.value = false),
    });
}

const addTabs = computed<TabDef[]>(() => [
    { key: 'paket', label: t('Paket hochladen') },
    { key: 'kopie', label: t('Kopie anlegen') },
    { key: 'marktplatz', label: t('Aus dem Marktplatz') },
]);

/* Entfernen und Ausblenden */
const removing = ref<ThemeCard | null>(null);
const removeProcessing = ref(false);

function confirmRemove(): void {
    if (!removing.value?.destroy_url) {
        return;
    }
    removeProcessing.value = true;
    router.delete(removing.value.destroy_url, {
        preserveScroll: true,
        onFinish: () => {
            removeProcessing.value = false;
            removing.value = null;
        },
    });
}

function setHidden(theme: ThemeCard, hidden: boolean): void {
    if (theme.hide_url) {
        router.put(theme.hide_url, { hidden }, { preserveScroll: true });
    }
}

function canRemove(theme: ThemeCard): boolean {
    return (
        theme.kind === 'own' &&
        (theme.name !== props.active || theme.replaces_folder === true)
    );
}
</script>

<template>
    <Head title="Themes" />
    <PageHeader title="Themes" :crumb="$t('Einrichtung · Themes')">
        <template #lead>
            {{
                $t(
                    'Mitgelieferte Themes und die eigenen Themes dieser Site. Ein Wechsel gilt nur für diese Site; Inhalte, Menüeinträge und Formulareingänge bleiben erhalten und erscheinen wieder, sobald ein Theme sie kennt.',
                )
            }}
        </template>
        <template v-if="can_edit" #actions>
            <Button size="sm" @click="openAdd('paket')">{{
                $t('Theme hinzufügen')
            }}</Button>
        </template>
    </PageHeader>

    <Notice v-if="preview" class="mb-4" role="status">
        {{
            $t(
                'Vorschau mit Theme «:theme» läuft in Ihrer Sitzung — die Site selbst ist unverändert.',
                { theme: preview },
            )
        }}
        <a :href="urls.preview_end" class="ml-2 font-semibold underline">{{
            $t('Vorschau beenden')
        }}</a>
    </Notice>
    <Notice
        v-if="pageErrors.theme || pageErrors.name"
        tone="danger"
        class="mb-4"
        role="alert"
        >{{ pageErrors.theme ?? pageErrors.name }}</Notice
    >
    <Notice v-if="!can_edit" class="mb-4">
        {{
            $t(
                'Ihre Rolle erlaubt nicht, Themes zu wechseln, hinzuzufügen oder zu entfernen; die Vorschau steht allen offen.',
            )
        }}
    </Notice>

    <template
        v-for="group in [
            {
                key: 'own',
                title: $t('Eigene Themes'),
                sub: $t(
                    'Liegen in der Datenbank dieser Site — mit Bildern, Schriften, Entwürfen und Fassungen.',
                ),
                list: ownSorted,
            },
            {
                key: 'folder',
                title: $t('Mitgelieferte Themes'),
                sub: $t(
                    'Aus dem Ordner themes/ der Installation — gelten für alle Sites, hier nur lesbar.',
                ),
                list: folders,
            },
        ]"
        :key="group.key"
    >
        <section v-if="group.list.length > 0" class="mb-8">
            <h2 class="mb-1 text-[15px] font-semibold">{{ group.title }}</h2>
            <p class="mb-3 text-[12.5px] text-basalt-light">{{ group.sub }}</p>
            <ul
                class="grid grid-cols-[repeat(auto-fill,minmax(17rem,1fr))] gap-4"
            >
                <li v-for="theme in group.list" :key="theme.name">
                    <Card
                        :class="
                            theme.name === active
                                ? 'border-lava-dark ring-2 ring-lava/30'
                                : ''
                        "
                    >
                        <div
                            class="mb-3 flex h-28 items-center justify-center overflow-hidden rounded-sm border border-ash-light bg-paper"
                        >
                            <img
                                v-if="theme.preview_url"
                                :src="theme.preview_url"
                                :alt="
                                    $t('Vorschaubild :theme', {
                                        theme: theme.label,
                                    })
                                "
                                class="h-full w-full object-cover"
                            />
                            <Hex
                                v-else
                                icon="theme"
                                size="lg"
                                aria-hidden="true"
                            />
                        </div>
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="text-[15px] font-semibold">
                                {{ theme.label }}
                            </h3>
                            <StateTag
                                v-if="theme.name === active"
                                tone="lava"
                                >{{ $t('aktiv') }}</StateTag
                            >
                            <StateTag
                                v-else-if="theme.errors > 0"
                                tone="danger"
                                >{{ $t('Fehler') }}</StateTag
                            >
                        </div>
                        <p
                            class="mb-2 font-mono text-[11.5px] text-basalt-light"
                        >
                            <template v-if="theme.kind === 'own'"
                                >{{ theme.name }} ·
                                {{ originText(theme) }}</template
                            >
                            <template v-else>themes/{{ theme.name }}</template>
                        </p>
                        <p
                            v-if="theme.kind === 'own' && theme.replaces_folder"
                            class="mb-2 text-[12px] text-basalt-light"
                        >
                            {{
                                $t(
                                    'Ersetzt für diese Site den Ordner themes/:name.',
                                    { name: theme.name },
                                )
                            }}
                        </p>
                        <p
                            v-if="theme.description"
                            class="mb-2 text-[12.5px] text-basalt-light"
                        >
                            {{ theme.description }}
                        </p>
                        <dl>
                            <PairRow :label="$t('Autor')">{{
                                theme.author ?? '—'
                            }}</PairRow>
                            <PairRow :label="$t('Version')"
                                >{{ theme.version ? `v${theme.version}` : '—'
                                }}{{
                                    theme.license ? ` · ${theme.license}` : ''
                                }}</PairRow
                            >
                            <PairRow :label="$t('Umfang')">{{
                                $t(
                                    ':types Typen · :blocks Blöcke · :templates Templates',
                                    {
                                        types: theme.types,
                                        blocks: theme.blocks,
                                        templates: theme.templates,
                                    },
                                )
                            }}</PairRow>
                            <PairRow :label="$t('Prüfung')">
                                <span
                                    :class="
                                        theme.errors > 0
                                            ? 'text-danger'
                                            : theme.warnings > 0
                                              ? 'text-warning'
                                              : 'text-success'
                                    "
                                    >{{
                                        $tc(
                                            ':count Fehler|:count Fehler',
                                            theme.errors,
                                        )
                                    }}
                                    ·
                                    {{
                                        $tc(
                                            ':count Warnung|:count Warnungen',
                                            theme.warnings,
                                        )
                                    }}</span
                                >
                            </PairRow>
                            <PairRow :label="$t('Stand')">{{
                                theme.updated ?? '—'
                            }}</PairRow>
                        </dl>
                        <details
                            v-if="theme.diagnostics.length > 0"
                            class="mt-2 text-[12px]"
                        >
                            <summary class="cursor-pointer text-basalt-light">
                                {{ $t('Befunde ansehen') }}
                            </summary>
                            <ul class="mt-1 grid gap-1 font-mono text-[11px]">
                                <li
                                    v-for="(d, i) in theme.diagnostics"
                                    :key="i"
                                >
                                    {{ d }}
                                </li>
                            </ul>
                        </details>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <template v-if="theme.name === active">
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    :href="urls.check"
                                    >{{ $t('Jetzt prüfen') }}</Button
                                >
                            </template>
                            <template v-else>
                                <Button
                                    v-if="can_edit"
                                    size="sm"
                                    :disabled="theme.errors > 0"
                                    @click="askSwitch(theme)"
                                    >{{
                                        $t('Zu diesem Theme wechseln')
                                    }}</Button
                                >
                                <a
                                    :href="theme.preview_link"
                                    class="inline-flex items-center rounded-md border border-ash-light px-3 py-1.5 text-[12.5px] font-semibold hover:border-basalt focus-visible:outline-2 focus-visible:outline-basalt"
                                    >{{ $t('Vorschau') }}</a
                                >
                            </template>
                            <Button
                                v-if="can_edit && theme.edit_url"
                                size="sm"
                                variant="secondary"
                                :href="theme.edit_url"
                                >{{ $t('Bearbeiten') }}</Button
                            >
                        </div>
                        <div
                            v-if="can_edit"
                            class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[12.5px]"
                        >
                            <button
                                type="button"
                                class="underline"
                                @click="openAdd('kopie', theme)"
                            >
                                {{ $t('Kopie anlegen') }}
                            </button>
                            <a
                                :href="theme.export_url"
                                class="underline"
                                download
                                >{{ $t('Als Paket laden') }}</a
                            >
                            <button
                                v-if="canRemove(theme)"
                                type="button"
                                class="text-danger underline"
                                @click="removing = theme"
                            >
                                {{ $t('Entfernen …') }}
                            </button>
                            <button
                                v-if="
                                    theme.kind === 'folder' &&
                                    theme.name !== active
                                "
                                type="button"
                                class="underline"
                                @click="setHidden(theme, true)"
                            >
                                {{ $t('Ausblenden') }}
                            </button>
                        </div>
                    </Card>
                </li>
            </ul>
        </section>
    </template>

    <section v-if="hiddenFolders.length > 0" class="mb-8">
        <details>
            <summary class="cursor-pointer text-[13px] font-semibold">
                {{
                    $tc(
                        'Ausgeblendet: :count Theme|Ausgeblendet: :count Themes',
                        hiddenFolders.length,
                    )
                }}
            </summary>
            <ul class="mt-2 grid gap-1 text-sm">
                <li
                    v-for="theme in hiddenFolders"
                    :key="theme.name"
                    class="flex flex-wrap items-center gap-3"
                >
                    <span>{{ theme.label }}</span>
                    <span class="font-mono text-[11.5px] text-basalt-light"
                        >themes/{{ theme.name }}</span
                    >
                    <button
                        v-if="can_edit"
                        type="button"
                        class="text-[12.5px] underline"
                        @click="setHidden(theme, false)"
                    >
                        {{ $t('Wieder einblenden') }}
                    </button>
                </li>
            </ul>
        </details>
    </section>

    <Modal
        :open="target !== null"
        :title="$t('Zu «:theme» wechseln', { theme: target?.label ?? '' })"
        :subtitle="$t('Gilt nur für diese Site — gelöscht wird nichts.')"
        :wide="false"
        @close="close"
    >
        <p v-if="loading" class="text-sm" role="status">
            {{ $t('Bericht wird erstellt …') }}
        </p>
        <Notice v-else-if="loadError" tone="danger">{{ loadError }}</Notice>
        <template v-else-if="impact">
            <Notice v-if="!impact.ok" tone="danger">
                {{ $t('Das Theme hat Fehler und kann nicht gewählt werden.') }}
                <ul class="mt-1 font-mono text-[11px]">
                    <li v-for="(d, i) in impact.diagnostics" :key="i">
                        {{ d }}
                    </li>
                </ul>
            </Notice>
            <p v-else-if="impact.empty" class="text-sm">
                {{
                    $t(
                        'Alles, was die Site heute zeigt, kennt auch dieses Theme.',
                    )
                }}
            </p>
            <template v-else>
                <p class="mb-2 text-sm">
                    {{
                        $t(
                            'Mit diesem Theme würde die Site Folgendes nicht mehr anzeigen. Es bleibt in der Datenbank und erscheint wieder, sobald ein Theme es kennt.',
                        )
                    }}
                </p>
                <dl class="grid gap-2">
                    <div v-for="row in reportRows" :key="row.label">
                        <dt class="text-[12px] font-semibold">
                            {{ row.label }}
                        </dt>
                        <dd>
                            <ul class="font-mono text-[11.5px]">
                                <li v-for="item in row.items" :key="item">
                                    {{ item }}
                                </li>
                            </ul>
                        </dd>
                    </div>
                </dl>
            </template>
            <p
                v-if="form.errors.name || form.errors.confirm"
                class="text-danger mt-2 text-[12px]"
                role="alert"
            >
                {{ form.errors.name ?? form.errors.confirm }}
            </p>
            <div class="mt-4 flex flex-wrap gap-2">
                <Button
                    :disabled="!impact.ok || form.processing"
                    @click="confirmSwitch"
                    >{{
                        impact.empty ? $t('Wechseln') : $t('Trotzdem wechseln')
                    }}</Button
                >
                <Button variant="secondary" @click="close">{{
                    $t('Abbrechen')
                }}</Button>
            </div>
        </template>
    </Modal>

    <Modal
        :open="addOpen"
        :title="$t('Theme hinzufügen')"
        :subtitle="
            $t(
                'Neue Themes kommen in die Bibliothek dieser Site — aktiv erst nach dem Wechsel.',
            )
        "
        :wide="false"
        @close="addOpen = false"
    >
        <Tabs v-model="addTab" :tabs="addTabs">
            <template #paket>
                <form class="space-y-3" @submit.prevent="submitUpload">
                    <p class="text-sm">
                        {{
                            $t(
                                'Ein Theme-Paket (ZIP mit manifest.json), z. B. aus «Als Paket laden» einer anderen Installation. Geprüft werden Prüfsummen und — falls vorhanden — die Signatur. Höchstens :mb MB.',
                                { mb: limits.package_mb },
                            )
                        }}
                    </p>
                    <label class="block text-sm">
                        <span
                            class="mb-1 block text-[11.5px] font-semibold tracking-wide uppercase"
                            >{{ $t('Theme-Paket (ZIP)') }}</span
                        >
                        <input
                            type="file"
                            accept=".zip,application/zip"
                            class="text-sm"
                            @change="
                                uploadForm.package =
                                    ($event.target as HTMLInputElement)
                                        .files?.[0] ?? null
                            "
                        />
                    </label>
                    <p
                        v-if="uploadForm.errors.package"
                        class="text-danger text-sm"
                        role="alert"
                    >
                        {{ uploadForm.errors.package }}
                    </p>
                    <div class="flex gap-3">
                        <Button
                            type="submit"
                            :disabled="
                                !uploadForm.package || uploadForm.processing
                            "
                            >{{
                                uploadForm.processing
                                    ? $t('Wird geprüft …')
                                    : $t('Hochladen')
                            }}</Button
                        >
                        <Button variant="secondary" @click="addOpen = false">{{
                            $t('Abbrechen')
                        }}</Button>
                    </div>
                </form>
            </template>

            <template #kopie>
                <form class="space-y-3" @submit.prevent="submitCopy">
                    <p class="text-sm">
                        {{
                            $t(
                                'Eine Kopie mit allen Dateien, Bildern und Schriften — der beste Start für ein eigenes Theme. Das Original bleibt unverändert.',
                            )
                        }}
                    </p>
                    <Field
                        v-model="copyForm.from"
                        type="select"
                        :label="$t('Vorlage')"
                        :options="
                            allThemes.map((theme) => ({
                                value: theme.name,
                                label: theme.label,
                            }))
                        "
                        :error="copyForm.errors.from"
                    />
                    <Field
                        :model-value="copyForm.label"
                        :label="$t('Bezeichnung')"
                        :maxlength="120"
                        :error="copyForm.errors.label"
                        @update:model-value="onLabel"
                    />
                    <Field
                        :model-value="copyForm.name"
                        :label="$t('Name')"
                        mono
                        :maxlength="60"
                        :help="
                            $t(
                                'Kleinbuchstaben, Ziffern und Bindestriche — so heisst das Theme in Lava und im Paket.',
                            )
                        "
                        :error="copyNameError"
                        @update:model-value="onName"
                    />
                    <div class="flex gap-3">
                        <Button
                            type="submit"
                            :disabled="
                                copyForm.processing ||
                                copyNameError !== '' ||
                                copyForm.name === ''
                            "
                            >{{ $t('Kopie anlegen') }}</Button
                        >
                        <Button variant="secondary" @click="addOpen = false">{{
                            $t('Abbrechen')
                        }}</Button>
                    </div>
                </form>
            </template>

            <template #marktplatz>
                <div class="space-y-3">
                    <p class="text-sm">
                        {{
                            $t(
                                'Themes aus dem Marktplatz sind vom Hersteller signiert. Installiert kommen sie hier als eigenes Theme in die Bibliothek; ein späteres Update erscheint im Theme-Editor als Entwurf.',
                            )
                        }}
                    </p>
                    <Button :href="urls.marketplace">{{
                        $t('Zum Marktplatz')
                    }}</Button>
                </div>
            </template>
        </Tabs>
    </Modal>

    <Modal
        :open="removing !== null"
        :title="
            $t('Theme «:theme» entfernen?', { theme: removing?.label ?? '' })
        "
        :wide="false"
        @close="removing = null"
    >
        <p class="text-sm">
            <template v-if="removing?.name === active">
                {{
                    $t(
                        'Das Theme ist aktiv. Nach dem Entfernen zeigt die Site sofort wieder das mitgelieferte Theme gleichen Namens aus dem Ordner.',
                    )
                }}
            </template>
            {{
                $t(
                    'Dateien, Bilder, Schriften, Entwürfe und alle Fassungen dieses Themes werden gelöscht. Inhalte der Site bleiben unverändert. Wer es später noch braucht, lädt es vorher als Paket.',
                )
            }}
        </p>
        <div class="mt-4 flex flex-wrap gap-3">
            <Button
                variant="danger"
                :disabled="removeProcessing"
                @click="confirmRemove"
                >{{ $t('Endgültig entfernen') }}</Button
            >
            <a
                v-if="removing"
                :href="removing.export_url"
                download
                class="inline-flex items-center rounded-md border border-ash bg-white px-3 py-1.5 text-sm text-basalt hover:bg-paper"
                >{{ $t('Erst als Paket laden') }}</a
            >
            <Button variant="secondary" @click="removing = null">{{
                $t('Abbrechen')
            }}</Button>
        </div>
    </Modal>
</template>
