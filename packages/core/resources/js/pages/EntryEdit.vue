<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useDebounceFn, useMediaQuery } from '@vueuse/core';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import BlockFields from '../components/BlockFields.vue';
import BlockList from '../components/BlockList.vue';
import BlockPreview from '../components/BlockPreview.vue';
import Button from '../components/Button.vue';
import Card from '../components/Card.vue';
import Field from '../components/Field.vue';
import Notice from '../components/Notice.vue';
import PageHeader from '../components/PageHeader.vue';
import PresenceBadge from '../components/PresenceBadge.vue';
import { type PresenceConfig, usePresence } from '../components/presence';
import PairRow from '../components/PairRow.vue';
import ModuleSlot from '../components/ModuleSlot.vue';
import RichTextField from '../components/RichTextField.vue';
import StatusHex from '../components/StatusHex.vue';
import TranslationMark from '../components/TranslationMark.vue';
import { announceError, useAutosave } from '../components/autosave';
import { keyOf } from '../components/blocks';
import { inputOf, markOf, usePreviewOpen } from '../components/guidedFields';
import { stageLabels } from '../components/workflow';
import { postHtml, postJson, requestError, withBase } from '../http';
import { t } from '../i18n';
import { versionStatus } from '../support';
import type {
    BackendProps,
    BlockValue,
    BlockedBlock,
    FieldValue,
    HistoryItem,
    MediaOption,
    Schema,
    SchemaField,
    SettingsExcerpt,
    TranslationState,
} from '../types';
import type { EntryReview } from '../types-workflow';
import { vDialog } from '../components/a11y';

/**
 * Der Editor eines Inhalts je Sprache (Bereich 11): Titel, die Felder des Typs (K-13) und die Blöcke aus dem Theme
 * mit Palette, verschachtelt beliebig tief; rechts Fassung, Seiteneinstellungen als Auszug (E-25) und Fassungen;
 * die Vorschau rendert ungespeicherte Änderungen im Split-Screen mit Gerätebreiten und Link für Dritte (M-05).
 * Gespeichert wird automatisch (Wunsch Hoang 16.10.2026) — in den offenen Entwurf der Sprache; eine neue Fassung
 * entsteht erst, wenn die neueste eingereicht, freigegeben oder veröffentlicht ist.
 */
const props = defineProps<{
    entry: { id: number; type: string; uuid: string };
    site: { handle: string; name: string; default_locale: string };
    locale: string;
    locales: {
        locale: string;
        is_default: boolean;
        status: string | null;
        url: string;
        translation?: TranslationState;
    }[];
    version: {
        id: number;
        number: number;
        status: string;
        status_label: string;
        title: string;
        slug: string;
        data: { intro?: FieldValue; content?: BlockValue[] } & Record<
            string,
            unknown
        >;
        can_edit: boolean;
        preview_url: string;
        author?: string | null;
    } | null;
    schema: Schema;
    fields: SchemaField[];
    type_label: string;
    has_content: boolean;
    settings_url: string | null;
    settings_excerpt?: SettingsExcerpt | null;
    allowed: string[];
    blocked?: BlockedBlock[];
    media: MediaOption[];
    history: HistoryItem[];
    urls: {
        index: string;
        save: string;
        submit: string;
        translate: string;
        translation?: string | null;
        preview_live?: string;
        share?: string;
        paste?: string;
        media_index?: string;
    };
    status: string | null;
    /** Die Freigabe dieser Sprachfassung (TB 05) — fehlt sie, bleibt die Zeile weg. */
    review?: EntryReview | null;
    /** TB 49: wer den Inhalt gerade ebenfalls bearbeitet. */
    presence?: PresenceConfig | null;
}>();

const page = usePage<BackendProps>();
const isDesktop = useMediaQuery('(min-width: 768px)');
const others = usePresence(props.presence);
const isWide = useMediaQuery('(min-width: 1024px)');

/** Zurück zum Seitenbaum (Station «Seiten» aus dem Menü) oder zu den Typ-Listen. */
const backUrl = computed(
    () =>
        (props.settings_url
            ? page.props.backend.menus.content.find(
                  (m) => m.route === 'basalto.pages.index',
              )?.href
            : null) ?? props.urls.index,
);
const crumb = computed(() =>
    props.settings_url
        ? `‹ ${t('Seiten')} › ${form.title || t('Seite')}`
        : `‹ ${t('Inhalte')} › ${props.type_label}`,
);
/** Der Seitenstatus nur, wenn der Rahmen ihn nicht schon als Flash zeigt. */
const ownStatus = computed(() =>
    props.status && props.status !== page.props.backend.flash?.status
        ? props.status
        : null,
);

/**
 * Die Typfelder im Editor: «intro» hat ein eigenes Feld (Einleitung), und bei Seiten liegen SEO-Felder in den
 * Seiteneinstellungen (E-25) — beides erscheint nicht ein zweites Mal.
 */
const typeFields = computed(() =>
    props.fields.filter(
        (f) => f.name !== 'intro' && !(props.settings_url && f.type === 'seo'),
    ),
);

/** Startwerte der Typfelder: gespeicherter Wert, sonst die Vorgabe aus Lava. */
function initialFields(): Record<string, FieldValue> {
    const data: Record<string, unknown> = props.version?.data ?? {};
    const values: Record<string, FieldValue> = {};
    typeFields.value.forEach((f) => {
        const stored = data[f.name];
        values[f.name] =
            stored !== undefined && stored !== null
                ? (stored as FieldValue)
                : ['repeater', 'blocks', 'gallery', 'tags'].includes(f.type)
                  ? []
                  : ((f.default as FieldValue) ?? null);
    });

    return values;
}

/**
 * Alles, was gespeichert wird, in einem Formular — so sieht das Autosave jede Änderung, auch in Blöcken und
 * Typfeldern. Blöcke und Typfelder stehen darin nur als `object` — mit `FieldValue` rechnet sich der Typ von
 * `useForm` (Fehlerschlüssel je Pfad) unendlich tief; gelesen und geschrieben werden sie über `blocks` und
 * `typeValues` mit ihren richtigen Typen.
 */
const form = useForm<{
    title: string;
    slug: string;
    intro: object | string | null;
    content: object[];
    fields: object;
}>({
    title: props.version?.title ?? '',
    slug: props.version?.slug ?? '',
    intro: (props.version?.data.intro ?? null) as object | string | null,
    content: (props.version?.data.content ?? []).map((b) => ({
        type: b.type,
        data: { ...b.data },
    })),
    fields: initialFields(),
});
const blocks = computed<BlockValue[]>({
    get: () => form.content as BlockValue[],
    set: (value) => (form.content = value),
});
const typeValues = computed<Record<string, FieldValue>>({
    get: () => form.fields as Record<string, FieldValue>,
    set: (value) => (form.fields = value),
});

const errors = computed(() => form.errors as unknown as Record<string, string>);
const errorCount = computed(() => Object.keys(errors.value).length);
const fieldErrorCount = computed(
    () =>
        Object.keys(errors.value).filter((k) => k.startsWith('fields.')).length,
);
const pasteUrl = computed(() => props.urls.paste ?? '');
const liveVersion = computed(
    () => props.history.find((h) => h.status === 'published') ?? null,
);

function payload(): Record<string, unknown> {
    return {
        title: form.title,
        slug: form.slug,
        intro: form.intro,
        content: blocks.value,
        fields: typeValues.value,
    };
}

/* ── Autosave: 1200 ms nach der letzten Änderung, nur bei bearbeitbarer Fassung und mit Titel ── */

const canEdit = computed(() => props.version?.can_edit !== false);
const autosave = useAutosave(form, {
    url: () => props.urls.save,
    method: 'post',
    delay: 1200,
    // Fassungsnummer, Verlauf, Freigabe und Sprach-Reiter stimmen danach; das Formular bleibt, wie es getippt ist
    only: ['errors', 'version', 'history', 'review', 'locales'],
    enabled: () =>
        Boolean(props.version) && canEdit.value && form.title.trim() !== '',
    labels: {
        title: t('Titel'),
        slug: 'Slug',
        intro: t('Einleitung'),
        content: t('Blöcke'),
        fields: t('Felder'),
    },
    onSaved: markSavedBlocks,
});

/**
 * Welche Blöcke gerade gespeichert wurden: Das Autosave meldet bei Listen nur «content» als Ganzes — das Häkchen
 * im Blockkopf soll aber nur an den Blöcken stehen, die sich geändert haben. Verglichen wird mit dem Stand beim
 * letzten Speichern, je Block über seinen stabilen Schlüssel (überlebt Verschieben).
 */
const savedBlockKeys = ref<number[]>([]);
let blockSnapshot = new Map<number, string>(
    blocks.value.map((b) => [keyOf(b), JSON.stringify(b)]),
);
let blockClear: ReturnType<typeof setTimeout> | undefined;

function markSavedBlocks(keys: string[]): void {
    const next = new Map<number, string>(
        blocks.value.map((b) => [keyOf(b), JSON.stringify(b)]),
    );
    if (keys.some((k) => k === 'content' || k.startsWith('content.'))) {
        savedBlockKeys.value = [...next]
            .filter(([key, json]) => blockSnapshot.get(key) !== json)
            .map(([key]) => key);
        if (blockClear) {
            clearTimeout(blockClear);
        }
        blockClear = setTimeout(() => (savedBlockKeys.value = []), 2600);
    }
    blockSnapshot = next;
}

onBeforeUnmount(() => {
    if (blockClear) {
        clearTimeout(blockClear);
    }
});

/** Die Zeile in der Seitenleiste statt des Knopfs: was das Autosave gerade tut. */
const saveHint = computed(() => {
    switch (autosave.status.value) {
        case 'pending':
            return form.title.trim() === ''
                ? t('Ohne Titel wird nicht gespeichert.')
                : t('Änderungen werden gleich gespeichert …');
        case 'saving':
            return t('Speichert …');
        case 'error':
            return t('Nicht gespeichert — bitte die markierten Felder prüfen.');
        default:
            return t('Speichert automatisch in Fassung :number.', {
                number: props.version?.number ?? '',
            });
    }
});

/** Nicht bearbeitbare Fassung (eingereicht, freigegeben, veröffentlicht): kein Autosave — ausdrücklich als neuer Entwurf speichern. */
function saveAsNewDraft(): void {
    form.post(props.urls.save, {
        preserveScroll: true,
        preserveState: true,
        only: ['errors', 'version', 'history', 'review', 'locales'],
        // Ab jetzt ein Entwurf: dieser Stand ist die Ausgangslage, das Autosave übernimmt (Status zurück auf «ruhig»)
        onSuccess: () => {
            autosave.rebase();
            autosave.flush();
        },
    });
}

/** Einreichen: ausstehendes Autosave zuerst abschliessen, dann einreichen — nie mit ungespeicherten Änderungen. */
const submitting = ref(false);

function submitNow(): void {
    if (autosave.status.value === 'error' || form.hasErrors) {
        submitting.value = false;

        return;
    }
    router.post(
        props.urls.submit,
        {},
        { onFinish: () => (submitting.value = false) },
    );
}

function submitForReview(): void {
    autosave.flush();
    if (autosave.status.value === 'pending') {
        // Nicht speicherbar (Titel leer) — dann auch nicht einreichen
        announceError(t('erst den Titel eintragen, dann einreichen'));

        return;
    }
    submitting.value = true;
    if (!form.processing && autosave.status.value !== 'saving') {
        submitNow();

        return;
    }
    const stop = watch(
        () => [form.processing, autosave.status.value] as const,
        ([processing, status]) => {
            if (!processing && status !== 'saving' && status !== 'pending') {
                stop();
                submitNow();
            }
        },
    );
}

function restore(item: HistoryItem): void {
    if (item.restore_url) {
        router.post(item.restore_url, {}, { preserveScroll: true });
    }
}

/* ── Fassungen: Änderungen aufklappen ── */

const openChanges = ref<number[]>([]);

function toggleChanges(id: number): void {
    openChanges.value = openChanges.value.includes(id)
        ? openChanges.value.filter((x) => x !== id)
        : [...openChanges.value, id];
}

function describe(change: {
    path: string;
    kind: string;
    old: unknown;
    new: unknown;
}): string {
    const text = (v: unknown): string =>
        v === null || v === undefined
            ? '∅'
            : typeof v === 'string'
              ? v
              : JSON.stringify(v);
    if (change.kind === 'added') {
        return t(':path: neu «:value»', {
            path: change.path,
            value: text(change.new),
        });
    }
    if (change.kind === 'removed') {
        return t(':path: entfernt «:value»', {
            path: change.path,
            value: text(change.old),
        });
    }

    return `${change.path}: «${text(change.old)}» → «${text(change.new)}»`;
}

/* ── Vorschau (M-05): Split-Screen, 800 ms entprellt, Gerätebreiten, Link für Dritte ── */

const previewOn = ref(false);
const previewTab = ref<'editor' | 'preview'>('editor');
const previewHtml = ref('');
const previewState = ref<'idle' | 'loading' | 'error'>('idle');
const previewError = ref('');
const previewDevice = ref<'desktop' | 'tablet' | 'mobile'>('desktop');
const deviceWidths = { desktop: '100%', tablet: '768px', mobile: '375px' };
const shareState = ref<'idle' | 'loading' | 'done' | 'error'>('idle');
const shareUrl = ref('');
const shareExpires = ref('');
const shareError = ref('');
const copied = ref(false);
let previewSerial = 0;

/** `srcdoc` hat keine Adresse — ein `<base>` lässt relative Theme-Assets (CSS, Bilder) von der Site laden. */
async function loadPreview(): Promise<void> {
    if (!props.urls.preview_live) {
        return;
    }
    const serial = ++previewSerial;
    previewState.value = 'loading';
    try {
        const html = await postHtml(props.urls.preview_live, {
            title: form.title,
            intro: form.intro,
            fields: typeValues.value,
            content: blocks.value,
        });
        if (serial === previewSerial) {
            previewHtml.value = withBase(html);
            previewState.value = 'idle';
        }
    } catch (e) {
        if (serial === previewSerial) {
            previewState.value = 'error';
            previewError.value = requestError(e);
        }
    }
}

const schedulePreview = useDebounceFn(loadPreview, 800);

/* TB 30: ohne Split-Screen ein kleines Vorschau-Fenster, das dem Feld mit dem Fokus folgt */
const windowOpen = usePreviewOpen();
const windowFocus = ref<string | null>(null);
const windowPayload = computed(() => ({
    title: form.title,
    intro: form.intro,
    fields: typeValues.value,
    content: blocks.value,
}));

function onBlocksFocus(event: FocusEvent): void {
    const mark = markOf(
        (event.target as HTMLElement | null)?.id ?? '',
        'content',
    );
    if (mark !== null) {
        windowFocus.value = mark;
    }
}

function onWindowPick(mark: string): void {
    const id = inputOf(mark, 'content');
    const input = id ? document.getElementById(id) : null;
    if (input) {
        input.scrollIntoView({ block: 'center', behavior: 'smooth' });
        input.focus({ preventScroll: true });
    }
}

watch(
    () => [previewOn.value, JSON.stringify(payload())],
    ([on, next], old) => {
        if (!on) {
            return;
        }
        if (!old || !old[0]) {
            void loadPreview();
        } else if (next !== old[1]) {
            previewState.value = 'loading';
            void schedulePreview();
        }
    },
);

function togglePreview(): void {
    previewOn.value = !previewOn.value;
    previewTab.value = previewOn.value ? 'preview' : 'editor';
}

async function share(): Promise<void> {
    if (!props.urls.share) {
        return;
    }
    shareState.value = 'loading';
    copied.value = false;
    try {
        const result = await postJson<{ url: string; expires_at: string }>(
            props.urls.share,
            {},
        );
        shareUrl.value = result.url;
        shareExpires.value = result.expires_at;
        shareState.value = 'done';
    } catch (e) {
        shareError.value = requestError(e);
        shareState.value = 'error';
    }
}

async function copyShare(): Promise<void> {
    try {
        await navigator.clipboard.writeText(shareUrl.value);
        copied.value = true;
    } catch {
        copied.value = false;
    }
}

/* ── Mobil: Seitenleiste als Blatt ── */

const sheetOpen = ref(false);

const deviceButton =
    'rounded px-2 py-0.5 text-[11.5px] font-semibold transition-colors';
</script>

<template>
    <Head :title="form.title || $t('Inhalt')" />
    <PageHeader :title="form.title || $t('Inhalt')" :crumb="crumb">
        <template #actions>
            <PresenceBadge :people="others" />
            <span
                v-if="errorCount > 0"
                class="text-danger text-[12.5px] font-semibold"
                role="alert"
            >
                {{
                    $tc(
                        ':count Feld zu prüfen|:count Felder zu prüfen',
                        errorCount,
                    )
                }}
            </span>
            <Button
                v-if="urls.translation && locale !== site.default_locale"
                variant="secondary"
                :href="urls.translation"
                >{{ $t('Übersetzen nebeneinander') }}</Button
            >
            <template v-if="version">
                <Button
                    variant="secondary"
                    :aria-pressed="previewOn"
                    @click="togglePreview"
                    >{{
                        previewOn ? $t('Vorschau aus') : $t('Vorschau')
                    }}</Button
                >
                <Button
                    v-if="
                        !previewOn &&
                        !windowOpen &&
                        isDesktop &&
                        urls.preview_live
                    "
                    variant="secondary"
                    @click="windowOpen = true"
                    >{{ $t('Live-Vorschau') }}</Button
                >
                <Button
                    v-if="!canEdit"
                    :disabled="form.processing || !form.isDirty"
                    @click="saveAsNewDraft"
                    >{{ $t('Als neuen Entwurf speichern') }}</Button
                >
                <Button
                    v-if="version.status === 'draft'"
                    variant="secondary"
                    :disabled="submitting"
                    @click="submitForReview"
                    >{{ $t('Einreichen') }}</Button
                >
            </template>
            <Button variant="link" :href="backUrl">{{
                backUrl === urls.index
                    ? `← ${$t('Inhalte')}`
                    : `← ${$t('Seiten')}`
            }}</Button>
        </template>
    </PageHeader>

    <nav
        class="mb-4 flex flex-wrap border-b border-ash-light"
        :aria-label="$t('Sprachen')"
    >
        <Link
            v-for="l in locales"
            :key="l.locale"
            :href="l.url"
            class="-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2 text-[12.5px]"
            :class="
                l.locale === locale
                    ? 'border-lava font-semibold text-basalt'
                    : 'border-transparent text-basalt-light hover:text-basalt'
            "
            :aria-current="l.locale === locale ? 'page' : undefined"
        >
            {{ l.locale.toUpperCase() }}
            <span v-if="l.is_default" class="text-ash-dark text-[10px]">{{
                $t('Standard')
            }}</span>
            <StatusHex :status="versionStatus(l.status)" />
            <TranslationMark
                v-if="!l.is_default"
                :state="l.translation"
                :locale="l.locale"
            />
        </Link>
        <button
            v-if="version && !isDesktop"
            type="button"
            class="ml-auto px-2 py-2 text-[12px] text-lava-dark underline"
            @click="sheetOpen = true"
        >
            {{ $t('Fassung :number', { number: version.number }) }} ·
            {{ version.status_label }}
        </button>
    </nav>
    <Notice v-if="ownStatus" tone="success" class="mb-4">{{
        ownStatus
    }}</Notice>
    <Notice
        v-if="review?.rejection"
        tone="warning"
        :title="$t('Zurückgewiesen')"
        class="mb-4"
    >
        {{
            review.rejection.by
                ? $t('Zurückgewiesen von :name', { name: review.rejection.by })
                : $t('Zurückgewiesen')
        }}<template v-if="review.rejection.at">
            ({{ review.rejection.at }})</template
        >
        — «{{ review.rejection.comment }}»
        <Link
            :href="review.url"
            class="ml-1 font-semibold text-lava-dark underline hover:text-basalt"
            >{{ $t('Zur Freigabe') }}</Link
        >
    </Notice>
    <p
        v-if="review && version"
        class="-mt-2 mb-4 flex flex-wrap items-center gap-x-2 gap-y-1 text-[12.5px] text-basalt-light"
    >
        <StatusHex
            :status="versionStatus(review.stage)"
            :label="stageLabels[review.stage] ?? review.stage"
        />
        <span>{{
            $t('Fassung :number (:locale)', {
                number: version.number,
                locale: locale.toUpperCase(),
            })
        }}</span>
        <span aria-hidden="true">·</span>
        <Link
            :href="review.url"
            class="font-semibold text-lava-dark underline hover:text-basalt"
            >{{ $t('Freigabe') }}</Link
        >
    </p>

    <Card v-if="!version" :title="$t('Noch keine Fassung in dieser Sprache')">
        <p class="mb-3 text-[12.5px] text-basalt-light">
            {{
                $t(
                    'Die Blöcke der Standardsprache (:locale) als Entwurf übernehmen und dann übersetzen.',
                    { locale: site.default_locale.toUpperCase() },
                )
            }}
        </p>
        <Button @click="router.post(urls.translate)">
            {{
                $t('Übersetzung aus :locale anlegen', {
                    locale: site.default_locale.toUpperCase(),
                })
            }}
        </Button>
    </Card>

    <form v-else novalidate @submit.prevent="autosave.flush()">
        <Notice v-if="!canEdit" tone="grey" class="mb-3">
            {{
                $t(
                    'Fassung :number (:status) wird nicht automatisch gespeichert — «Als neuen Entwurf speichern» legt mit Ihren Änderungen eine neue Fassung an, ab dann speichert sie sich selbst.',
                    { number: version.number, status: version.status_label },
                )
            }}
        </Notice>
        <!-- Unter 1024 px mit Vorschau: echte Reiter Editor · Vorschau -->
        <div
            v-if="previewOn && !isWide"
            class="mb-3 flex border-b border-ash-light"
            role="tablist"
        >
            <button
                v-for="t in ['editor', 'preview'] as const"
                :key="t"
                type="button"
                role="tab"
                :aria-selected="previewTab === t"
                class="-mb-px border-b-2 px-3 py-2 text-[12.5px]"
                :class="
                    previewTab === t
                        ? 'border-lava font-semibold text-basalt'
                        : 'border-transparent text-basalt-light'
                "
                @click="previewTab = t"
            >
                {{ t === 'editor' ? $t('Editor') : $t('Vorschau') }}
            </button>
        </div>

        <div
            class="grid gap-4"
            :class="
                previewOn
                    ? isWide
                        ? 'lg:grid-cols-2'
                        : ''
                    : 'md:grid-cols-[minmax(0,1fr)_300px]'
            "
        >
            <!-- Editor -->
            <div
                v-show="!previewOn || isWide || previewTab === 'editor'"
                class="min-w-0 space-y-4"
            >
                <Card :title="settings_url ? $t('Seite') : type_label">
                    <template #right>
                        <span class="font-mono">{{ entry.type }}</span>
                    </template>
                    <Field
                        v-model="form.title"
                        :label="$t('Titel')"
                        required
                        :saved="autosave.saved('title')"
                        :error="form.errors.title"
                    />
                    <Field
                        v-if="!settings_url"
                        v-model="form.slug"
                        :label="`Slug (${locale})`"
                        mono
                        :saved="autosave.saved('slug')"
                        :error="form.errors.slug"
                        :help="$t('leer = aus dem Titel')"
                    />
                    <Field
                        :label="$t('Einleitung')"
                        optional
                        :saved="autosave.saved('intro')"
                        :error="form.errors.intro"
                    >
                        <template #default="{ id, invalid }">
                            <RichTextField
                                :id="id"
                                :model-value="form.intro as FieldValue"
                                :label="$t('Einleitung')"
                                :subtitle="$t('Seite · Einleitung')"
                                :invalid="invalid"
                                :media="media"
                                :paste-url="pasteUrl"
                                @update:model-value="
                                    form.intro = $event as object | null
                                "
                            />
                            <!-- TB 13 Scheibe 3: Teile der Module an der Einleitung (KI «Text verbessern») -->
                            <ModuleSlot
                                name="EntryField"
                                :context="{
                                    field: {
                                        name: 'intro',
                                        label: $t('Einleitung'),
                                        type: 'richtext',
                                    },
                                    value: form.intro,
                                    path: 'intro',
                                }"
                                @apply="form.intro = $event as object | null"
                            />
                        </template>
                    </Field>
                </Card>

                <Card
                    v-if="typeFields.length > 0"
                    :title="$t('Felder')"
                    :status="fieldErrorCount > 0 ? 'danger' : null"
                >
                    <template #right>
                        <span
                            v-if="autosave.saved('fields')"
                            class="text-success mr-1.5 font-semibold"
                            >✓ {{ $t('gespeichert') }}</span
                        >
                        <template
                            v-for="(part, i) in $tc(
                                ':count Feld des Typs :type|:count Felder des Typs :type',
                                typeFields.length,
                            ).split(':type')"
                            :key="i"
                            ><span v-if="i > 0" class="font-mono">{{
                                entry.type
                            }}</span
                            >{{ part }}</template
                        >
                    </template>
                    <BlockFields
                        :fields="typeFields"
                        :model-value="typeValues"
                        :media="media"
                        :errors="errors"
                        path="fields"
                        :paste-url="pasteUrl"
                        :blocks="schema.blocks"
                        :context="
                            $t('Feld des Typs :type', { type: type_label })
                        "
                        @update:model-value="typeValues = $event"
                    />
                </Card>

                <Card
                    v-if="has_content"
                    :title="$t('Blöcke')"
                    :sub="
                        $tc(
                            ':count Block in Seitenreihenfolge|:count Blöcke in Seitenreihenfolge',
                            blocks.length,
                        )
                    "
                >
                    <BlockList
                        v-model="blocks"
                        :saved-keys="savedBlockKeys"
                        @focusin="onBlocksFocus"
                        :schema="schema.blocks"
                        :allowed="allowed"
                        :blocked="blocked"
                        :media="media"
                        :errors="errors"
                        path="content"
                        :paste-url="pasteUrl"
                        :scope="$t('den Typ :type', { type: type_label })"
                        :scope-code="`type ${entry.type} · allow`"
                    />
                </Card>
            </div>

            <!-- Vorschau -->
            <div
                v-if="previewOn"
                v-show="isWide || previewTab === 'preview'"
                class="min-w-0"
            >
                <div
                    class="sticky top-2 overflow-hidden rounded-lg border border-ash-light bg-white"
                >
                    <div
                        class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-ash-light px-2.5 py-1.5 text-[11.5px]"
                    >
                        <span
                            class="inline-flex rounded-md border border-ash-light bg-paper p-0.5"
                            role="group"
                            :aria-label="$t('Gerätebreite')"
                        >
                            <button
                                v-for="d in [
                                    'desktop',
                                    'tablet',
                                    'mobile',
                                ] as const"
                                :key="d"
                                type="button"
                                :class="[
                                    deviceButton,
                                    previewDevice === d
                                        ? 'bg-basalt text-paper'
                                        : 'text-basalt-light hover:text-basalt',
                                ]"
                                :aria-pressed="previewDevice === d"
                                @click="previewDevice = d"
                            >
                                {{
                                    d === 'desktop'
                                        ? $t('Desktop')
                                        : d === 'tablet'
                                          ? $t('Tablet')
                                          : $t('Mobil')
                                }}
                            </button>
                        </span>
                        <span
                            class="ml-auto text-basalt-light"
                            aria-live="polite"
                        >
                            <template v-if="!urls.preview_live">{{
                                $t(
                                    'gespeicherte Fassung :number — Live-Vorschau folgt mit dem Endpunkt',
                                    { number: version.number },
                                )
                            }}</template>
                            <template v-else-if="previewState === 'loading'">{{
                                $t('Vorschau wird aktualisiert …')
                            }}</template>
                            <template v-else-if="previewState === 'error'"
                                ><span class="text-danger font-semibold">{{
                                    $t('Vorschau fehlgeschlagen: :error', {
                                        error: previewError,
                                    })
                                }}</span></template
                            >
                            <template v-else>{{
                                $t('Vorschau aktuell')
                            }}</template>
                        </span>
                        <button
                            type="button"
                            class="text-lava-dark underline hover:text-basalt disabled:text-ash disabled:no-underline"
                            :disabled="!urls.share || shareState === 'loading'"
                            :title="
                                urls.share
                                    ? $t(
                                          'Signierter Link ohne Anmeldung, 72 Stunden gültig',
                                      )
                                    : $t('Endpunkt folgt')
                            "
                            @click="share"
                        >
                            {{ $t('Link für Dritte (72 h)') }}
                        </button>
                    </div>
                    <div
                        v-if="shareState === 'done' || shareState === 'error'"
                        class="flex flex-wrap items-center gap-2 border-b border-ash-light bg-paper px-2.5 py-1.5 text-[11.5px]"
                    >
                        <template v-if="shareState === 'done'">
                            <span class="min-w-0 flex-1 truncate font-mono">{{
                                shareUrl
                            }}</span>
                            <button
                                type="button"
                                class="rounded-md border border-ash bg-white px-2 py-0.5 text-xs font-semibold"
                                @click="copyShare"
                            >
                                {{ copied ? $t('Kopiert ✓') : $t('Kopieren') }}
                            </button>
                            <span class="text-basalt-light">{{
                                $t('läuft ab :date', { date: shareExpires })
                            }}</span>
                        </template>
                        <span v-else class="text-danger font-semibold">{{
                            shareError
                        }}</span>
                    </div>
                    <div
                        class="scroll-y bg-ash-light/40 p-2"
                        style="--scroll-bg: #f6f4f0"
                    >
                        <iframe
                            v-if="urls.preview_live"
                            :srcdoc="previewHtml"
                            :title="$t('Vorschau')"
                            sandbox="allow-same-origin"
                            class="mx-auto block h-[70vh] max-w-full rounded border border-ash-light bg-white transition-[width]"
                            :style="{ width: deviceWidths[previewDevice] }"
                        />
                        <iframe
                            v-else
                            :src="version.preview_url"
                            :title="$t('Vorschau der gespeicherten Fassung')"
                            class="mx-auto block h-[70vh] max-w-full rounded border border-ash-light bg-white transition-[width]"
                            :style="{ width: deviceWidths[previewDevice] }"
                        />
                    </div>
                </div>
            </div>

            <!-- Seitenleiste (Desktop) -->
            <aside
                v-if="!previewOn && isDesktop"
                class="space-y-4"
                :aria-label="$t('Fassung und Einstellungen')"
            >
                <Card :title="$t('Fassung')">
                    <dl>
                        <PairRow :label="$t('Nr.')"
                            >{{ version.number }} ·
                            <StatusHex
                                :status="versionStatus(version.status)"
                                :label="version.status_label"
                        /></PairRow>
                        <PairRow v-if="version.author" :label="$t('Autor')">{{
                            version.author
                        }}</PairRow>
                        <PairRow :label="$t('Live')">
                            <template v-if="liveVersion">{{
                                $t('Fassung :number', {
                                    number: liveVersion.number,
                                })
                            }}</template>
                            <span v-else class="text-ash-dark font-normal">{{
                                $t('noch nicht veröffentlicht')
                            }}</span>
                        </PairRow>
                    </dl>
                    <div class="mt-2.5 flex flex-col gap-1.5">
                        <Button
                            variant="secondary"
                            wide
                            @click="togglePreview"
                            >{{ $t('Vorschau') }}</Button
                        >
                        <p
                            v-if="canEdit"
                            class="text-[11.5px] text-basalt-light"
                            aria-live="polite"
                        >
                            {{ saveHint }}
                        </p>
                        <Button
                            v-else
                            wide
                            :disabled="form.processing || !form.isDirty"
                            @click="saveAsNewDraft"
                            >{{ $t('Als neuen Entwurf speichern') }}</Button
                        >
                        <Button
                            v-if="version.status === 'draft'"
                            variant="secondary"
                            wide
                            :disabled="submitting"
                            @click="submitForReview"
                            >{{ $t('Einreichen') }}</Button
                        >
                        <Button
                            v-if="review"
                            variant="secondary"
                            wide
                            :href="review.url"
                            >{{ $t('Freigabe') }}</Button
                        >
                    </div>
                </Card>

                <Card
                    v-if="settings_excerpt"
                    :title="$t('Seiteneinstellungen')"
                    :sub="$t('eine Quelle · Bereich 28')"
                >
                    <dl>
                        <PairRow :label="$t('Typ')"
                            >{{ settings_excerpt.type_label }} ·
                            {{ $t('Template') }}
                            <span class="font-mono">{{
                                settings_excerpt.template
                            }}</span></PairRow
                        >
                        <PairRow :label="`Slug ${locale.toUpperCase()}`"
                            ><span class="font-mono">{{
                                settings_excerpt.slug
                            }}</span></PairRow
                        >
                        <PairRow :label="$t('Titel (SEO)')">{{
                            settings_excerpt.meta_title || '—'
                        }}</PairRow>
                        <PairRow label="Robots">{{
                            settings_excerpt.robots
                        }}</PairRow>
                        <PairRow :label="$t('Sichtbar')">{{
                            settings_excerpt.visible
                        }}</PairRow>
                        <PairRow :label="$t('Zeitfenster')">{{
                            settings_excerpt.window || '—'
                        }}</PairRow>
                    </dl>
                    <div v-if="settings_url" class="mt-2.5">
                        <Button variant="secondary" wide :href="settings_url">{{
                            $t('Alle Einstellungen (12 Reiter)')
                        }}</Button>
                    </div>
                </Card>
                <Card
                    v-else-if="settings_url"
                    :title="$t('Seiteneinstellungen')"
                    :sub="$t('eine Quelle · Bereich 28')"
                >
                    <p class="text-[12.5px] text-basalt-light">
                        {{
                            $t(
                                'Slug, Metadaten und Sichtbarkeit stehen in den Seiteneinstellungen — eine Quelle, kein zweites Formular.',
                            )
                        }}
                    </p>
                    <div class="mt-2.5">
                        <Button variant="secondary" wide :href="settings_url">{{
                            $t('Alle Einstellungen (12 Reiter)')
                        }}</Button>
                    </div>
                </Card>

                <Card
                    :title="$t('Fassungen')"
                    :sub="
                        $tc(':count Fassung|:count Fassungen', history.length)
                    "
                >
                    <ol class="divide-y divide-ash-light text-[12.5px]">
                        <li
                            v-for="h in history"
                            :key="h.id"
                            class="py-2 first:pt-0 last:pb-0"
                        >
                            <div class="flex flex-wrap items-center gap-x-2">
                                <span class="inline-flex items-center gap-1.5"
                                    >{{
                                        $t('Nr. :number', { number: h.number })
                                    }}
                                    <StatusHex
                                        :status="versionStatus(h.status)"
                                        :label="h.status_label"
                                /></span>
                                <span
                                    v-if="h.author || h.created_at"
                                    class="text-[11px] text-basalt-light"
                                    >{{
                                        [h.author, h.created_at]
                                            .filter(Boolean)
                                            .join(' · ')
                                    }}</span
                                >
                                <span
                                    class="ml-auto flex gap-2 text-[11px] text-lava-dark"
                                >
                                    <button
                                        type="button"
                                        class="underline hover:text-basalt"
                                        :aria-expanded="
                                            openChanges.includes(h.id)
                                        "
                                        @click="toggleChanges(h.id)"
                                    >
                                        {{ $t('Vergleichen') }}
                                    </button>
                                    <a
                                        :href="h.preview_url"
                                        target="_blank"
                                        rel="noopener"
                                        class="underline hover:text-basalt"
                                        >{{ $t('Vorschau') }}</a
                                    >
                                    <button
                                        v-if="
                                            h.restore_url && h.id !== version.id
                                        "
                                        type="button"
                                        class="underline hover:text-basalt"
                                        :title="
                                            $t(
                                                'Legt aus dieser Fassung einen neuen Entwurf an',
                                            )
                                        "
                                        @click="restore(h)"
                                    >
                                        {{ $t('Wiederherstellen') }}
                                    </button>
                                </span>
                            </div>
                            <template v-if="openChanges.includes(h.id)">
                                <ul
                                    v-if="h.changes.length"
                                    class="mt-1 space-y-0.5 text-[11px] text-basalt-light"
                                >
                                    <li
                                        v-for="(c, i) in h.changes"
                                        :key="i"
                                        class="font-mono break-all"
                                    >
                                        {{ describe(c) }}
                                    </li>
                                </ul>
                                <p
                                    v-else
                                    class="text-ash-dark mt-1 text-[11px]"
                                >
                                    {{
                                        $t(
                                            'keine Änderungen gegenüber der Vorgängerin',
                                        )
                                    }}
                                </p>
                            </template>
                        </li>
                    </ol>
                </Card>
            </aside>
        </div>

        <!-- Mobil: feste Leiste unten, Seitenleiste als Blatt -->
        <div
            v-if="!isDesktop"
            class="sticky bottom-0 z-30 -mx-4 mt-4 flex gap-2 border-t border-ash-light bg-paper px-4 py-2.5"
        >
            <Button variant="secondary" @click="togglePreview">{{
                previewOn ? $t('Vorschau aus') : $t('Vorschau')
            }}</Button>
            <Button
                v-if="version.status === 'draft'"
                wide
                :disabled="submitting"
                @click="submitForReview"
                >{{ $t('Einreichen') }}</Button
            >
            <Button
                v-else-if="!canEdit"
                wide
                :disabled="form.processing || !form.isDirty"
                @click="saveAsNewDraft"
                >{{ $t('Als neuen Entwurf speichern') }}</Button
            >
        </div>
        <div
            v-if="sheetOpen && !isDesktop"
            class="fixed inset-0 z-40 flex items-end bg-basalt/40"
            @click.self="sheetOpen = false"
        >
            <div
                v-dialog="() => (sheetOpen = false)"
                class="scroll-y max-h-[85vh] w-full rounded-t-2xl border-t border-ash bg-white px-4 pt-2 pb-5"
                role="dialog"
                aria-modal="true"
                :aria-label="$t('Fassung und Einstellungen')"
            >
                <div
                    class="mx-auto mb-3 h-1 w-9 rounded bg-ash"
                    aria-hidden="true"
                />
                <p class="mb-1 text-[13px] font-semibold">
                    {{ $t('Fassung :number', { number: version.number }) }} ·
                    {{ version.status_label }}
                </p>
                <dl>
                    <PairRow v-if="version.author" :label="$t('Autor')">{{
                        version.author
                    }}</PairRow>
                    <PairRow
                        v-if="settings_excerpt"
                        :label="`Slug ${locale.toUpperCase()}`"
                        ><span class="font-mono">{{
                            settings_excerpt.slug
                        }}</span></PairRow
                    >
                    <PairRow v-if="settings_excerpt" :label="$t('Typ')"
                        >{{ settings_excerpt.type_label }} ·
                        {{ settings_excerpt.template }}</PairRow
                    >
                    <PairRow :label="$t('Live')">{{
                        liveVersion
                            ? $t('Fassung :number', {
                                  number: liveVersion.number,
                              })
                            : '—'
                    }}</PairRow>
                </dl>
                <div class="mt-3 flex flex-col gap-1.5">
                    <Button
                        v-if="version.status === 'draft'"
                        wide
                        :disabled="submitting"
                        @click="submitForReview"
                        >{{ $t('Einreichen') }}</Button
                    >
                    <Button
                        v-if="review"
                        variant="secondary"
                        wide
                        :href="review.url"
                        >{{ $t('Freigabe') }}</Button
                    >
                    <Button
                        v-if="settings_url"
                        variant="secondary"
                        wide
                        :href="settings_url"
                        >{{ $t('Seiteneinstellungen') }}</Button
                    >
                </div>
                <p
                    class="mt-3 mb-1 text-[11px] font-semibold tracking-[.1em] text-basalt-light uppercase"
                >
                    {{ $t('Fassungen') }}
                </p>
                <ol class="divide-y divide-ash-light text-[12.5px]">
                    <li
                        v-for="h in history"
                        :key="h.id"
                        class="flex items-center gap-2 py-1.5"
                    >
                        <span class="inline-flex items-center gap-1.5"
                            >{{ $t('Nr. :number', { number: h.number }) }}
                            <StatusHex
                                :status="versionStatus(h.status)"
                                :label="h.status_label"
                        /></span>
                        <button
                            v-if="h.restore_url && h.id !== version.id"
                            type="button"
                            class="ml-auto text-[11px] text-lava-dark underline"
                            @click="restore(h)"
                        >
                            {{ $t('Wiederherstellen') }}
                        </button>
                    </li>
                </ol>
            </div>
        </div>
        <BlockPreview
            v-if="
                version &&
                urls.preview_live &&
                windowOpen &&
                !previewOn &&
                isDesktop &&
                blocks.length > 0
            "
            :url="urls.preview_live"
            :payload="windowPayload"
            :focus="windowFocus"
            @pick="onWindowPick"
            @close="windowOpen = false"
        />
    </form>
</template>
