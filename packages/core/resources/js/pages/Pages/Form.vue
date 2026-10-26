<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { autosaveToast, useAutosave } from '../../components/autosave';
import Button from '../../components/Button.vue';
import { controlClasses, labelClasses } from '../../components/control';
import Field from '../../components/Field.vue';
import Hex from '../../components/Hex.vue';
import ModuleSlot from '../../components/ModuleSlot.vue';
import Notice from '../../components/Notice.vue';
import PageHeader from '../../components/PageHeader.vue';
import StatusHex from '../../components/StatusHex.vue';
import Tabs from '../../components/Tabs.vue';
import Toggle from '../../components/Toggle.vue';
import {
    fromDateTimeLocal,
    hostOf,
    pageTypeIcon,
    slugify,
    toDateTimeLocal,
    truncate,
    versionLabels,
    versionStatus,
} from '../../support';
import { t } from '../../i18n';
import type {
    BackendProps,
    PageFormData,
    PageInfo,
    PageMetaLocale,
    PageOptions,
    PageRedirectHere,
    TabDef,
} from '../../types';

/**
 * Seite anlegen oder bearbeiten (Bereich 28/2–5): Typwahl als Karten, danach die Reiter des Typs — eine Seite zwölf,
 * eine Weiterleitung vier (E-26). Fehler zählen je Reiter nach dem ersten Glied des Schlüssels («routing.slugs.en»);
 * Name, Slug und Metadaten je Sprache werden als neue Entwurfsfassung gespeichert. «Neue Seite» speichert mit Knopf;
 * beim Bearbeiten speichert sich jede Änderung selbst (Autosave) — Pflichtfelder und Slugs erst, wenn sie nicht leer sind.
 */
const props = defineProps<{
    page: PageInfo | null;
    type: string;
    type_label: string;
    tabs: string[];
    form: PageFormData;
    options: PageOptions;
    redirects_here: PageRedirectHere[];
    urls: { index: string; save: string; create: string };
    method: 'post' | 'put';
}>();

const shared = usePage<BackendProps>();
const siteName = computed(
    () => shared.props.backend.site?.name ?? shared.props.backend.name,
);

const isNew = computed(() => props.page === null);
const locales = computed(() => props.options.locales);
const defaultLocale = computed(
    () =>
        locales.value.find((l) => l.is_default)?.locale ??
        locales.value[0]?.locale ??
        'de',
);

/** Weitere Sprachen der Site — für eigene Ziele einer externen Weiterleitung. */
const otherLocales = computed(() =>
    locales.value.map((l) => l.locale).filter((l) => l !== defaultLocale.value),
);

function cloneForm(): PageFormData {
    return JSON.parse(JSON.stringify(props.form)) as PageFormData;
}

const form = useForm<PageFormData>(cloneForm());
watch(
    () => props.type,
    (type) => {
        form.type = type;
    },
);
/** Nach «Speichern» oder «Speichern und neu» bleibt die Komponente stehen — die Felder folgen dann der neuen Seite. */
watch(
    () => props.page?.id ?? null,
    () => {
        form.defaults(cloneForm());
        form.reset();
        form.clearErrors();
    },
);

const errors = computed(() => form.errors as unknown as Record<string, string>);

/* ── Reiter und Fehler je Reiter ── */

const tabLabels: Record<string, string> = {
    name: t('Name und Typ'),
    routing: t('Routing'),
    meta: t('Metadaten'),
    image: t('Bild'),
    canonical: t('Kanonische URL'),
    access: t('Zugriff'),
    layout: t('Layout'),
    cache: t('Cache'),
    rights: t('Rechte'),
    expert: t('Experten'),
    keyboard: t('Tastatur'),
    publication: t('Veröffentlichung'),
};

/** Der Reiter eines Fehlerschlüssels: `type`, `parent_id`, `feed.*` und `redirect.*` liegen auf «Name und Typ». */
function tabOfKey(key: string): string {
    const head = key.split('.')[0] ?? key;

    return ['type', 'parent_id', 'name', 'feed', 'redirect'].includes(head)
        ? 'name'
        : head;
}

const tabs = computed<TabDef[]>(() =>
    props.tabs.map((key) => ({
        key,
        label: tabLabels[key] ?? key,
        errors: Object.keys(errors.value).filter((k) => tabOfKey(k) === key)
            .length,
    })),
);
const tab = ref('name');
const errorTabs = computed(() => tabs.value.filter((t) => t.errors));
const errorCount = computed(() => Object.keys(errors.value).length);

/* ── Typ ── */

const isErrorType = computed(() => props.type.startsWith('error_'));
const errorCodes = ['401', '403', '404', '503'];

function isActiveType(value: string): boolean {
    return value.startsWith('error_')
        ? isErrorType.value
        : value === props.type;
}

/** Ein anderer Typ lädt das Formular mit dessen Reitern neu; die Eingaben bleiben (preserveState). */
function chooseType(value: string): void {
    if (!isNew.value || value === props.type) {
        return;
    }
    const query: Record<string, string | number> = { type: value };
    if (form.parent_id !== null) {
        query.parent = form.parent_id;
    }
    router.get(props.urls.create, query, {
        preserveState: true,
        preserveScroll: true,
    });
}

const parentOptions = computed(() => [
    { value: '', label: t('— oberste Ebene —') },
    ...props.options.parents.map((p) => ({
        value: String(p.id),
        label: `${'\u00a0'.repeat(p.depth * 3)}${p.title}${p.path ? ` (${p.path})` : ''}`,
    })),
]);

const targetOptions = computed(() => [
    { value: '', label: t('— Ziel wählen —') },
    ...props.options.targets.map((t) => ({
        value: String(t.id),
        label: `${t.title}${t.path ? ` (${t.path})` : ''}`,
    })),
]);

function toNullableId(value: string): number | null {
    return value === '' ? null : Number(value);
}

/* ── Routing: Pfad-Vorschau ── */

const host = computed(() => hostOf(props.options.site_url));

function prefixOf(locale: string): string {
    return locale === defaultLocale.value ? '' : `/${locale}`;
}

/**
 * Der Pfad, den die Seite mit dem Slug bekäme — aus Site, Sprachpräfix, dem Basispfad der Elternseite (ohne Präfix,
 * auch unter Ordnern korrekt) und Slug.
 */
function pathPreview(locale: string): { path: string; note: string } {
    const prefix = prefixOf(locale);
    if (form.routing.home[locale]) {
        return {
            path: `${host.value}${prefix || '/'}`,
            note: t('Startseite'),
        };
    }
    const slug = form.routing.slugs[locale] || slugify(form.name[locale] ?? '');
    if (!slug) {
        return {
            path: '',
            note:
                locale === defaultLocale.value
                    ? t('— entsteht aus dem Namen')
                    : t('— übernimmt :locale (Fallback)', {
                          locale: defaultLocale.value.toUpperCase(),
                      }),
        };
    }
    const parent = props.options.parents.find((p) => p.id === form.parent_id);
    const base = parent ? (parent.base_paths[locale] ?? null) : '';
    if (base === null) {
        return {
            path: '',
            note: t(
                'Der Pfad unter «:parent» ist in :locale noch nicht bekannt — die Elternseite hat dort keine Fassung',
                { parent: parent?.title ?? '', locale: locale.toUpperCase() },
            ),
        };
    }

    return { path: `${host.value}${prefix}${base}/${slug}`, note: '' };
}

/* ── Metadaten und Suchergebnis-Vorschau ── */

function metaOf(locale: string): PageMetaLocale {
    const entry = form.meta[locale];
    if (typeof entry === 'object') {
        return entry;
    }
    const fresh: PageMetaLocale = { title: '', description: '' };
    form.meta[locale] = fresh;

    return fresh;
}

/** TB 13 Scheibe 3: ein übernommener Vorschlag eines Moduls (KI «SEO-Vorschläge») — das Autosave speichert ihn als Entwurf. */
function applyMeta(locale: string, value: unknown): void {
    const next = value as Partial<PageMetaLocale>;
    const meta = metaOf(locale);
    meta.title = typeof next.title === 'string' ? next.title : meta.title;
    meta.description =
        typeof next.description === 'string'
            ? next.description
            : meta.description;
}

function serpTitle(locale: string): string {
    const meta = metaOf(locale);
    if (meta.title.trim() !== '') {
        return meta.title;
    }

    return `${form.name[locale] ?? ''}${props.options.title_suffix}`;
}

function serpPath(locale: string): string {
    const path = props.page
        ? (props.page.paths[locale] ?? '')
        : pathPreview(locale).path.slice(host.value.length);
    const segments = path.split('/').filter(Boolean);

    return [host.value, ...segments].join(' › ');
}

/* ── Bild und Fokuspunkt ── */

const selectedMedia = computed(
    () => props.options.media.find((m) => m.id === form.image.media_id) ?? null,
);

const focusDragging = ref(false);

function setFocus(event: PointerEvent): void {
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    form.image.focus_x =
        Math.round(
            Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width)) *
                100,
        ) / 100;
    form.image.focus_y =
        Math.round(
            Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height)) *
                100,
        ) / 100;
}

function startFocus(event: PointerEvent): void {
    focusDragging.value = true;
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
    setFocus(event);
}

function moveFocus(event: PointerEvent): void {
    if (focusDragging.value) {
        setFocus(event);
    }
}

function endFocus(): void {
    if (!focusDragging.value) {
        return;
    }
    focusDragging.value = false;
    // Autosave wartet beim Ziehen — erst nach dem Loslassen speichern
    autosave?.flush();
}

/* ── Rechte, Veröffentlichung ── */

function toggleRole(role: string, on: boolean): void {
    form.rights.roles = on
        ? [...new Set([...form.rights.roles, role])]
        : form.rights.roles.filter((r) => r !== role);
}

const ownerOptions = computed(() => [
    { value: '', label: t('— Rechte der Site —') },
    ...props.options.users.map((u) => ({
        value: String(u.id),
        label: u.name,
    })),
]);

const templateOptions = computed(() => [
    { value: '', label: t('— Template wählen —') },
    ...props.options.templates.map((t) => ({ value: t, label: t })),
]);

const childrenOptions = computed(() => [
    { value: 'inherit', label: t('vererben') },
    ...props.options.templates.map((t) => ({ value: t, label: t })),
]);

const contentTypeOptions = computed(() =>
    props.options.content_types.map((t) => ({ value: t, label: t })),
);

function statusLabel(locale: string): string {
    const status = props.page?.status[locale] ?? null;

    return status ? (versionLabels[status] ?? status) : t('fehlt');
}

const sheetSub = computed(() => {
    if (!props.page) {
        return t('Typ wählen — danach erscheinen die Reiter, die der Typ hat.');
    }
    const parts = [props.type_label];
    if (props.page.type === 'page') {
        parts.push(
            form.layout.mode === 'own' && form.layout.template
                ? t('Template :template', { template: form.layout.template })
                : t('Template aus dem Typ'),
        );
    }
    const number = props.page.versions[defaultLocale.value];
    if (number) {
        parts.push(
            t('Fassung :number (:locale) :status', {
                number,
                locale: defaultLocale.value.toUpperCase(),
                status: statusLabel(defaultLocale.value),
            }),
        );
    }

    return parts.join(' · ');
});

/* ── Senden ── */

/** Eine URL ist erst vollständig genug zum Speichern, wenn der Host einen Punkt hat — sonst blitzte beim Tippen ein Fehler auf. */
function urlReady(url: string): boolean {
    return /^https?:\/\/[^\s/.]+\.[^\s/]{2,}/.test(url.trim());
}

const ready = computed(() => {
    if ((form.name[defaultLocale.value] ?? '').trim() === '') {
        return false;
    }
    // Ein Slug, der schon eine Fassung hat, wird nicht leer gespeichert — erst, wenn wieder etwas drinsteht
    const slugCleared = locales.value.some(
        (l) =>
            (props.page?.versions[l.locale] ?? null) !== null &&
            (form.routing.slugs[l.locale] ?? '').trim() === '',
    );
    if (slugCleared || focusDragging.value) {
        return false;
    }
    if (props.type === 'redirect') {
        if (
            form.redirect.kind === 'internal'
                ? form.redirect.entry_id === null
                : !urlReady(form.redirect.url)
        ) {
            return false;
        }
    }
    if (
        props.tabs.includes('canonical') &&
        form.canonical.mode === 'custom' &&
        !urlReady(form.canonical.url)
    ) {
        return false;
    }

    return !(
        props.tabs.includes('layout') &&
        form.layout.mode === 'own' &&
        form.layout.template === ''
    );
});

/*
 * Ausgangswerte der Namen je Sprache: Der Server überschreibt einen Namen nur, wenn er hier geändert wurde — ein
 * inzwischen im Inhaltseditor geänderter Titel bleibt so stehen (Drei-Wege-Abgleich).
 */
const nameBase = ref<Record<string, string>>({
    ...(form.name as Record<string, string>),
});
if (props.method === 'put') {
    form.transform((data) => ({
        ...data,
        base: Object.fromEntries(
            Object.entries(nameBase.value).map(([locale, name]) => [
                locale,
                { name },
            ]),
        ),
    }));
}

/* Autosave nur beim Bearbeiten; danach die Kopfdaten (Fassung, Status) und die Umleitungen frisch — das Formular bleibt */
const autosave =
    props.method === 'put'
        ? useAutosave(form, {
              url: () => props.urls.save,
              delay: 1200,
              onSaved: () => {
                  nameBase.value = { ...(form.name as Record<string, string>) };
              },
              only: ['page', 'redirects_here', 'errors'],
              enabled: () => ready.value,
              labels: {
                  name: t('Name'),
                  parent_id: t('Elternseite'),
                  routing: t('Routing'),
                  meta: t('Metadaten'),
                  image: t('Bild'),
                  canonical: t('Kanonische URL'),
                  layout: t('Layout'),
                  cache: t('Cache'),
                  rights: t('Rechte'),
                  expert: t('Experten'),
                  keyboard: t('Tastaturkürzel'),
                  publication: t('Zeitfenster'),
                  feed: t('Feed'),
                  redirect: t('Weiterleitung'),
              },
          })
        : null;

function saved(key: string): boolean {
    return autosave?.saved(key) ?? false;
}

/* Der Server prüft das ganze Formular — die Meldung unten rechts nennt den Reiter, in dem der Fehler steht */
watch(
    () => autosave?.status.value,
    (status) => {
        const key = Object.keys(errors.value)[0];
        if (status !== 'error' || key === undefined) {
            return;
        }
        const label = tabLabels[tabOfKey(key)] ?? '';
        autosaveToast.text = t('Nicht gespeichert — :message', {
            message: `${label ? `${label}: ` : ''}${errors.value[key] ?? ''}`,
        });
    },
);

function submit(): void {
    if (autosave) {
        autosave.flush();
    } else {
        save('stay');
    }
}

function save(mode: 'stay' | 'close' | 'new'): void {
    form.transform((data) =>
        mode === 'close'
            ? { ...data, close: true }
            : mode === 'new'
              ? { ...data, new: true }
              : data,
    );
    const options = { preserveScroll: true };
    if (props.method === 'put') {
        form.put(props.urls.save, options);
    } else {
        form.post(props.urls.save, options);
    }
}

const small = controlClasses({ small: true });
const checkbox = 'accent-lava-dark';
const checkRow = 'flex items-start gap-2 py-1 text-[12.5px]';
const sourceLabels: Record<string, string> = {
    slug_change: t('Slug-Wechsel'),
    manual: t('von Hand'),
};
</script>

<template>
    <Head
        :title="
            isNew
                ? $t('Neue Seite')
                : $t(':title · Einstellungen', { title: page?.title ?? '' })
        "
    />
    <PageHeader
        :title="
            isNew
                ? $t('Neue Seite')
                : $t(':title · Einstellungen', { title: page?.title ?? '' })
        "
        :crumb="
            $t(':site › Seiten › :page', {
                site: siteName,
                page: isNew ? $t('Neu') : (page?.title ?? ''),
            })
        "
    >
        <template
            v-if="page && (page.content_url || page.preview_url)"
            #actions
        >
            <Button
                v-if="page.content_url"
                variant="secondary"
                :href="page.content_url"
                >{{ $t('Inhalt bearbeiten') }}</Button
            >
            <a
                v-if="page.preview_url"
                :href="page.preview_url"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center rounded-md border border-ash bg-white px-[13px] py-2 text-[13px] font-semibold text-basalt hover:bg-paper"
                >{{ $t('Vorschau') }}</a
            >
        </template>
    </PageHeader>

    <form
        class="rounded-lg border border-ash-light bg-white px-4 pt-3.5 pb-3.5"
        novalidate
        @submit.prevent="submit"
    >
        <div class="flex items-center gap-2 text-sm font-semibold">
            <Hex :icon="pageTypeIcon(type)" tone="basalt" />
            <span>{{
                isNew
                    ? $t('Seite anlegen')
                    : $t(':type «:title»', {
                          type: type_label,
                          title: page?.title ?? '',
                      })
            }}</span>
        </div>
        <p class="mt-0.5 text-xs text-basalt-light">
            {{ sheetSub
            }}<template v-if="autosave">
                · {{ $t('Änderungen speichern sich selbst.') }}</template
            >
        </p>
        <Notice v-if="errorTabs.length > 0" tone="danger" class="mt-2.5">
            <b>{{
                $tc(
                    ':count Angabe stimmt nicht.|:count Angaben stimmen nicht.',
                    errorCount,
                )
            }}</b>
            {{ $t('Die übrigen Eingaben sind erhalten. Bitte prüfen:') }}
            {{ errorTabs.map((t) => `${t.label} (${t.errors})`).join(', ') }}
        </Notice>

        <Tabs v-model="tab" :tabs="tabs" class="mt-3.5">
            <!-- Name und Typ -->
            <template #name>
                <div class="grid gap-x-4 gap-y-2.5 *:mt-0! md:grid-cols-2">
                    <Field
                        v-for="(l, i) in locales"
                        :key="l.locale"
                        v-model="form.name[l.locale]"
                        :saved="saved(`name.${l.locale}`)"
                        :label="
                            $t('Name (:locale)', {
                                locale: l.locale.toUpperCase(),
                            })
                        "
                        :required="l.is_default"
                        :optional="!l.is_default && !l.is_required"
                        :error="errors[`name.${l.locale}`]"
                        :help="
                            i === 0
                                ? $t(
                                      'Der Name im Baum und in Menüs; der Seitentitel steht in «Metadaten».',
                                  )
                                : ''
                        "
                    />
                </div>

                <span :class="[labelClasses, 'mt-4']">{{ $t('Typ') }}</span>
                <div
                    v-if="isNew"
                    class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <button
                        v-for="t in options.types"
                        :key="t.value"
                        type="button"
                        class="rounded-lg border px-3 py-2 text-left transition-colors"
                        :class="
                            isActiveType(t.value)
                                ? 'border-lava-dark shadow-[0_0_0_2px_var(--color-lava-light)]'
                                : 'border-ash-light hover:border-ash'
                        "
                        :aria-pressed="isActiveType(t.value)"
                        @click="chooseType(t.value)"
                    >
                        <span
                            class="flex items-center gap-2 text-[13px] font-semibold"
                        >
                            <Hex
                                :icon="pageTypeIcon(t.value)"
                                :tone="isActiveType(t.value) ? 'basalt' : 'ash'"
                            />
                            {{ t.label }}
                        </span>
                        <span
                            class="mt-0.5 block text-[11.5px] text-basalt-light"
                            >{{ t.description }}</span
                        >
                    </button>
                </div>
                <div v-else class="flex items-center gap-2 text-[13px]">
                    <Hex :icon="pageTypeIcon(type)" tone="basalt" />
                    <span class="font-semibold">{{ type_label }}</span>
                    <span class="text-[11.5px] text-basalt-light"
                        >· {{ $t('der Typ bleibt beim Bearbeiten') }}</span
                    >
                </div>
                <div
                    v-if="isNew && isErrorType"
                    class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px]"
                >
                    <span class="text-basalt-light">{{
                        $t('Fehlercode')
                    }}</span>
                    <label
                        v-for="code in errorCodes"
                        :key="code"
                        class="flex items-center gap-1.5 font-mono"
                    >
                        <input
                            type="radio"
                            name="error_code"
                            :value="code"
                            :checked="type === `error_${code}`"
                            :class="checkbox"
                            @change="chooseType(`error_${code}`)"
                        />
                        {{ code }}
                    </label>
                </div>
                <p class="mt-1 text-[11.5px] text-basalt-light">
                    {{
                        $t(
                            'Der Typ bestimmt, welche Reiter es gibt: Eine Weiterleitung hat kein Layout und keine Metadaten, ein Ordner nur Name und Rechte.',
                        )
                    }}
                </p>
                <p
                    v-if="errors.type"
                    class="text-danger mt-1 text-[11.5px] font-semibold"
                >
                    {{ errors.type }}
                </p>

                <div class="mt-3 grid gap-x-4 gap-y-2.5 *:mt-0! md:grid-cols-2">
                    <Field
                        :model-value="
                            form.parent_id === null
                                ? ''
                                : String(form.parent_id)
                        "
                        :saved="saved('parent_id')"
                        :label="$t('Elternseite')"
                        type="select"
                        :options="parentOptions"
                        :error="errors.parent_id"
                        :help="
                            $t(
                                'Ort im Baum — nur Seiten und Ordner können Unterseiten haben.',
                            )
                        "
                        @update:model-value="
                            form.parent_id = toNullableId($event)
                        "
                    />
                </div>

                <template v-if="type === 'feed'">
                    <span :class="[labelClasses, 'mt-4']">{{
                        $t('Feed')
                    }}</span>
                    <div class="grid gap-x-4 gap-y-2.5 *:mt-0! md:grid-cols-2">
                        <Field
                            v-model="form.feed.type"
                            :saved="saved('feed.type')"
                            :label="$t('Quelle')"
                            type="select"
                            :options="contentTypeOptions"
                            :error="errors['feed.type']"
                            :help="
                                $t(
                                    'Der Inhaltstyp, dessen Einträge der Feed listet.',
                                )
                            "
                        />
                        <Field
                            :model-value="form.feed.limit"
                            :saved="saved('feed.limit')"
                            :label="$t('Anzahl')"
                            type="number"
                            inputmode="numeric"
                            :error="errors['feed.limit']"
                            :help="
                                $t('1 bis 100 Einträge, die neuesten zuerst.')
                            "
                            @update:model-value="
                                form.feed.limit = Number($event)
                            "
                        />
                        <Field
                            :model-value="form.feed.format ?? 'rss'"
                            :saved="saved('feed.format')"
                            :label="$t('Format')"
                            type="select"
                            :options="[
                                { value: 'rss', label: 'RSS 2.0' },
                                { value: 'atom', label: 'Atom 1.0' },
                            ]"
                            :error="errors['feed.format']"
                            :help="
                                $t(
                                    'Beide verstehen alle Feed-Leser; Atom trägt genauere Zeitangaben.',
                                )
                            "
                            @update:model-value="
                                form.feed.format =
                                    $event === 'atom' ? 'atom' : 'rss'
                            "
                        />
                    </div>
                </template>

                <template v-if="type === 'redirect'">
                    <span :class="[labelClasses, 'mt-4']"
                        >{{ $t('Weiterleitung')
                        }}<span
                            v-if="
                                saved('redirect.kind') ||
                                saved('redirect.status')
                            "
                            class="text-success ml-1.5 font-semibold tracking-normal normal-case"
                            >✓ {{ $t('gespeichert') }}</span
                        ></span
                    >
                    <div class="flex flex-wrap gap-x-5 gap-y-1 text-[12.5px]">
                        <label class="flex items-center gap-1.5">
                            <input
                                v-model="form.redirect.kind"
                                type="radio"
                                name="redirect_kind"
                                value="internal"
                                :class="checkbox"
                            />
                            {{ $t('intern — auf eine Seite dieser Site') }}
                        </label>
                        <label class="flex items-center gap-1.5">
                            <input
                                v-model="form.redirect.kind"
                                type="radio"
                                name="redirect_kind"
                                value="external"
                                :class="checkbox"
                            />
                            {{ $t('extern — auf eine URL ausserhalb') }}
                        </label>
                    </div>
                    <p
                        v-if="errors['redirect.kind']"
                        class="text-danger mt-1 text-[11.5px] font-semibold"
                    >
                        {{ errors['redirect.kind'] }}
                    </p>
                    <div
                        class="mt-2 grid gap-x-4 gap-y-2.5 *:mt-0! md:grid-cols-2"
                    >
                        <Field
                            v-if="form.redirect.kind === 'internal'"
                            :model-value="
                                form.redirect.entry_id === null
                                    ? ''
                                    : String(form.redirect.entry_id)
                            "
                            :saved="saved('redirect.entry_id')"
                            :label="$t('Ziel')"
                            type="select"
                            :options="targetOptions"
                            :error="errors['redirect.entry_id']"
                            required
                            @update:model-value="
                                form.redirect.entry_id = toNullableId($event)
                            "
                        />
                        <Field
                            v-else
                            v-model="form.redirect.url"
                            :saved="saved('redirect.url')"
                            label="URL"
                            mono
                            inputmode="url"
                            placeholder="https://"
                            :error="errors['redirect.url']"
                            required
                        />
                        <div>
                            <span :class="labelClasses">{{
                                $t('Statuscode')
                            }}</span>
                            <div
                                class="flex flex-wrap gap-x-5 gap-y-1 pt-1.5 text-[12.5px]"
                            >
                                <label class="flex items-center gap-1.5">
                                    <input
                                        type="radio"
                                        name="redirect_status"
                                        :checked="form.redirect.status === 302"
                                        :class="checkbox"
                                        @change="form.redirect.status = 302"
                                    />
                                    {{ $t('302 vorübergehend') }}
                                </label>
                                <label class="flex items-center gap-1.5">
                                    <input
                                        type="radio"
                                        name="redirect_status"
                                        :checked="form.redirect.status === 301"
                                        :class="checkbox"
                                        @change="form.redirect.status = 301"
                                    />
                                    {{ $t('301 dauerhaft') }}
                                </label>
                            </div>
                            <p
                                v-if="errors['redirect.status']"
                                class="text-danger mt-1 text-[11.5px] font-semibold"
                            >
                                {{ errors['redirect.status'] }}
                            </p>
                        </div>
                        <template v-if="form.redirect.kind === 'external'">
                            <Field
                                v-for="locale in otherLocales"
                                :key="locale"
                                v-model="form.redirect.urls[locale]"
                                :saved="saved(`redirect.urls.${locale}`)"
                                :label="`URL (${locale.toUpperCase()})`"
                                mono
                                inputmode="url"
                                :placeholder="$t('leer = Grundsprache')"
                                :error="errors[`redirect.urls.${locale}`]"
                            />
                        </template>
                    </div>
                </template>
            </template>

            <!-- Routing -->
            <template #routing>
                <span :class="labelClasses">{{
                    $t('Slug je Sprache · Pfad-Vorschau')
                }}</span>
                <div
                    v-for="l in locales"
                    :key="l.locale"
                    class="grid items-start gap-x-3 gap-y-1 border-b border-ash-light py-2 md:grid-cols-[2.75rem_1fr_1.2fr]"
                >
                    <span class="pt-2 font-mono text-xs font-bold"
                        >{{ l.locale.toUpperCase()
                        }}<span
                            v-if="saved(`routing.slugs.${l.locale}`)"
                            class="text-success block font-sans text-[11px] font-semibold"
                            >✓</span
                        ></span
                    >
                    <Field
                        v-model="form.routing.slugs[l.locale]"
                        :saved="saved(`routing.slugs.${l.locale}`)"
                        :label="`Slug (${l.locale.toUpperCase()})`"
                        mono
                        :placeholder="
                            slugify(form.name[l.locale] ?? '') || 'slug'
                        "
                        :error="errors[`routing.slugs.${l.locale}`]"
                        class="mt-0! [&>label]:sr-only"
                    />
                    <div class="text-xs text-basalt-light md:pt-2">
                        <span v-if="pathPreview(l.locale).path"
                            >{{ $t('Pfad-Vorschau') }}
                            <span class="font-mono break-all text-basalt">{{
                                pathPreview(l.locale).path
                            }}</span></span
                        >
                        <span v-if="pathPreview(l.locale).note" class="block">{{
                            pathPreview(l.locale).note
                        }}</span>
                        <label
                            class="mt-1 flex items-center gap-1.5 text-[12px] text-basalt"
                        >
                            <input
                                v-model="form.routing.home[l.locale]"
                                type="checkbox"
                                :class="checkbox"
                            />
                            {{
                                $t('Startseite in :locale', {
                                    locale: l.locale.toUpperCase(),
                                })
                            }}
                            <span
                                v-if="saved(`routing.home.${l.locale}`)"
                                class="text-success font-semibold"
                                >✓ {{ $t('gespeichert') }}</span
                            >
                        </label>
                    </div>
                </div>

                <div class="mt-3 grid gap-x-4 gap-y-2.5 *:mt-0! md:grid-cols-2">
                    <Field
                        :model-value="form.routing.priority"
                        :saved="saved('routing.priority')"
                        :label="$t('Routenpriorität')"
                        type="number"
                        inputmode="numeric"
                        :error="errors['routing.priority']"
                        :help="
                            $t(
                                'Höher gewinnt bei gleichen Pfaden (selten nötig).',
                            )
                        "
                        @update:model-value="
                            form.routing.priority = Number($event)
                        "
                    />
                </div>
                <label :class="[checkRow, 'mt-2']">
                    <input
                        v-model="form.routing.redirect_on_slug_change"
                        type="checkbox"
                        :class="[checkbox, 'mt-0.5']"
                    />
                    <span
                        >{{
                            $t(
                                'Umleitung bei Slug-Wechsel anlegen (301, alte Adresse antwortet weiter)',
                            )
                        }}<span
                            v-if="saved('routing.redirect_on_slug_change')"
                            class="text-success ml-1.5 font-semibold"
                            >✓ {{ $t('gespeichert') }}</span
                        ></span
                    >
                </label>

                <span :class="[labelClasses, 'mt-4']">{{
                    $t('Umleitungen auf diese Seite · :count', {
                        count: redirects_here.length,
                    })
                }}</span>
                <p
                    v-if="redirects_here.length === 0"
                    class="text-ash-dark text-xs"
                >
                    {{ $t('keine') }}
                </p>
                <ul v-else class="divide-y divide-ash-light text-[12.5px]">
                    <li
                        v-for="r in redirects_here"
                        :key="r.from"
                        class="flex flex-wrap items-center gap-x-2 py-1.5"
                    >
                        <span class="font-mono text-xs break-all">{{
                            r.from
                        }}</span>
                        <span class="text-basalt-light"
                            >→
                            <span class="font-mono text-xs break-all">{{
                                r.to
                            }}</span>
                            · {{ r.status }} ·
                            {{ sourceLabels[r.source] ?? r.source }} ·
                            {{ $t(':count Treffer', { count: r.hits }) }}</span
                        >
                    </li>
                </ul>
            </template>

            <!-- Metadaten -->
            <template #meta>
                <div
                    v-for="l in locales"
                    :key="l.locale"
                    class="border-b border-ash-light pb-3 not-first:pt-3"
                >
                    <div class="grid gap-x-4 gap-y-2.5 *:mt-0! md:grid-cols-2">
                        <Field
                            v-model="metaOf(l.locale).title"
                            :saved="saved(`meta.${l.locale}.title`)"
                            :label="
                                $t('Seitentitel (:locale)', {
                                    locale: l.locale.toUpperCase(),
                                })
                            "
                            :maxlength="70"
                            :error="errors[`meta.${l.locale}.title`]"
                        >
                            <template #help>
                                {{ $t('Leer = Name + Suffix aus publish.seo') }}
                                ·
                                <span class="font-mono"
                                    >{{
                                        metaOf(l.locale).title.length
                                    }}/70</span
                                >
                            </template>
                        </Field>
                        <Field
                            v-model="metaOf(l.locale).description"
                            :saved="saved(`meta.${l.locale}.description`)"
                            :label="
                                $t('Beschreibung (:locale)', {
                                    locale: l.locale.toUpperCase(),
                                })
                            "
                            type="textarea"
                            :rows="2"
                            :maxlength="160"
                            :error="errors[`meta.${l.locale}.description`]"
                        >
                            <template #help>
                                <span class="font-mono"
                                    >{{
                                        metaOf(l.locale).description.length
                                    }}/160</span
                                >
                            </template>
                        </Field>
                    </div>
                    <!-- TB 13 Scheibe 3: Teile der Module an den Metadaten (KI «SEO-Vorschläge») -->
                    <ModuleSlot
                        v-if="page && type === 'page'"
                        name="SeoFields"
                        :context="{
                            entry: page.id,
                            locale: l.locale,
                            title: metaOf(l.locale).title,
                            description: metaOf(l.locale).description,
                        }"
                        @apply="applyMeta(l.locale, $event)"
                    />
                    <span :class="[labelClasses, 'mt-2.5']">{{
                        $t('Suchergebnis-Vorschau · :locale', {
                            locale: l.locale.toUpperCase(),
                        })
                    }}</span>
                    <div
                        class="rounded-md border border-ash-light bg-paper px-3 py-2 text-[12.5px]"
                    >
                        <div class="text-success text-[11.5px] break-all">
                            {{ serpPath(l.locale) }}
                        </div>
                        <div class="font-semibold text-[#1a0dab]">
                            {{ truncate(serpTitle(l.locale), 60) || '—' }}
                        </div>
                        <div class="text-basalt-light">
                            {{
                                truncate(metaOf(l.locale).description, 160) ||
                                $t('— keine Beschreibung')
                            }}
                        </div>
                    </div>
                </div>
                <div class="mt-3 grid gap-x-4 gap-y-2.5 *:mt-0! md:grid-cols-2">
                    <Field
                        v-model="form.meta.robots"
                        :saved="saved('meta.robots')"
                        label="Robots"
                        type="select"
                        :options="options.robots"
                        :error="errors['meta.robots']"
                        :help="
                            $t('Was Suchmaschinen mit der Seite tun dürfen.')
                        "
                    />
                </div>
                <p class="mt-2 text-[11.5px] text-basalt-light">
                    {{
                        $t(
                            'Die Vorschau zeigt Titel und Beschreibung so, wie Suchmaschinen sie etwa abschneiden (60 / 160 Zeichen).',
                        )
                    }}
                </p>
            </template>

            <!-- Bild -->
            <template #image>
                <div class="grid gap-4 md:grid-cols-[260px_1fr]">
                    <div>
                        <div
                            v-if="selectedMedia?.url"
                            class="relative cursor-crosshair touch-none overflow-hidden rounded-md bg-paper select-none"
                            :title="
                                $t('Fokus :x × :y', {
                                    x: form.image.focus_x,
                                    y: form.image.focus_y,
                                })
                            "
                            @pointerdown.prevent="startFocus"
                            @pointermove="moveFocus"
                            @pointerup="endFocus"
                            @pointercancel="endFocus"
                        >
                            <img
                                :src="selectedMedia.url"
                                :alt="selectedMedia.name"
                                class="block h-36 w-full object-cover"
                                draggable="false"
                            />
                            <span
                                class="pointer-events-none absolute h-[18px] w-[18px] -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-lava bg-white/60 shadow-[0_0_0_2px_rgba(35,38,43,.3)]"
                                :style="{
                                    left: `${form.image.focus_x * 100}%`,
                                    top: `${form.image.focus_y * 100}%`,
                                }"
                                aria-hidden="true"
                            />
                        </div>
                        <div
                            v-else
                            class="text-ash-dark flex h-36 items-center justify-center rounded-md border border-dashed border-ash text-xs"
                        >
                            {{ $t('kein Seitenbild') }}
                        </div>
                        <p class="mt-1 text-[11.5px] text-basalt-light">
                            {{
                                $t(
                                    'Fokuspunkt: ziehen — die Variante «og» wird neu geschnitten.',
                                )
                            }}<span
                                v-if="
                                    saved('image.focus_x') ||
                                    saved('image.focus_y')
                                "
                                class="text-success ml-1 font-semibold"
                                >✓ {{ $t('gespeichert') }}</span
                            >
                            <span class="font-mono"
                                >{{ form.image.focus_x }} ×
                                {{ form.image.focus_y }}</span
                            >
                        </p>
                    </div>
                    <div>
                        <span :class="labelClasses"
                            >{{ $t('Seitenbild')
                            }}<span
                                v-if="saved('image.media_id')"
                                class="text-success ml-1.5 font-semibold tracking-normal normal-case"
                                >✓ {{ $t('gespeichert') }}</span
                            ></span
                        >
                        <p
                            v-if="options.media.length === 0"
                            class="text-ash-dark text-xs"
                        >
                            {{
                                $t(
                                    'Noch keine Bilder in der Mediathek — Station «Medien».',
                                )
                            }}
                        </p>
                        <div
                            v-else
                            class="grid grid-cols-[repeat(auto-fill,minmax(4.5rem,1fr))] gap-1.5"
                        >
                            <button
                                type="button"
                                class="flex h-[4.5rem] items-center justify-center rounded-md border text-[11px]"
                                :class="
                                    form.image.media_id === null
                                        ? 'border-lava-dark text-basalt shadow-[0_0_0_2px_var(--color-lava-light)]'
                                        : 'border-ash-light text-basalt-light hover:border-ash'
                                "
                                @click="form.image.media_id = null"
                            >
                                {{ $t('keins') }}
                            </button>
                            <button
                                v-for="m in options.media"
                                :key="m.id"
                                type="button"
                                class="h-[4.5rem] overflow-hidden rounded-md border bg-paper"
                                :class="
                                    form.image.media_id === m.id
                                        ? 'border-lava-dark shadow-[0_0_0_2px_var(--color-lava-light)]'
                                        : 'border-ash-light hover:border-ash'
                                "
                                :title="`${m.name}${m.width ? ` · ${m.width} × ${m.height}` : ''}`"
                                @click="form.image.media_id = m.id"
                            >
                                <img
                                    v-if="m.url"
                                    :src="m.url"
                                    :alt="m.name"
                                    class="h-full w-full object-cover"
                                />
                                <span
                                    v-else
                                    class="block px-1 text-[10px] break-all text-basalt-light"
                                    >{{ m.name }}</span
                                >
                            </button>
                        </div>
                        <p
                            v-if="errors['image.media_id']"
                            class="text-danger mt-1 text-[11.5px] font-semibold"
                        >
                            {{ errors['image.media_id'] }}
                        </p>
                        <p class="mt-1 text-[11.5px] text-basalt-light">
                            <span v-if="selectedMedia"
                                >{{ selectedMedia.name
                                }}<span v-if="selectedMedia.width">
                                    · {{ selectedMedia.width }} ×
                                    {{ selectedMedia.height }}</span
                                >
                                ·
                            </span>
                            {{
                                $t(
                                    'Für Teilen (Open Graph, Variante og) und für Listen (Variante thumb). Leer = Bild aus publish.seo.image.',
                                )
                            }}
                        </p>
                        <Field
                            v-model="form.image.alt"
                            :saved="saved('image.alt')"
                            :label="$t('Alt-Text')"
                            :maxlength="200"
                            :error="errors['image.alt']"
                            :help="
                                $t(
                                    'Beschreibt das Bild für Screenreader und Suchmaschinen.',
                                )
                            "
                            class="mt-3"
                        />
                    </div>
                </div>
            </template>

            <!-- Kanonische URL -->
            <template #canonical>
                <span :class="labelClasses"
                    >{{ $t('Kanonische URL')
                    }}<span
                        v-if="saved('canonical.mode')"
                        class="text-success ml-1.5 font-semibold tracking-normal normal-case"
                        >✓ {{ $t('gespeichert') }}</span
                    ></span
                >
                <div class="flex flex-wrap gap-x-5 gap-y-1 text-[12.5px]">
                    <label class="flex items-center gap-1.5">
                        <input
                            v-model="form.canonical.mode"
                            type="radio"
                            name="canonical_mode"
                            value="auto"
                            :class="checkbox"
                        />
                        {{ $t('automatisch (diese Seite)') }}
                    </label>
                    <label class="flex items-center gap-1.5">
                        <input
                            v-model="form.canonical.mode"
                            type="radio"
                            name="canonical_mode"
                            value="custom"
                            :class="checkbox"
                        />
                        {{ $t('individuell') }}
                    </label>
                </div>
                <p
                    v-if="errors['canonical.mode']"
                    class="text-danger mt-1 text-[11.5px] font-semibold"
                >
                    {{ errors['canonical.mode'] }}
                </p>
                <div class="mt-2 grid gap-x-4 gap-y-2.5 *:mt-0! md:grid-cols-2">
                    <Field
                        v-if="form.canonical.mode === 'custom'"
                        v-model="form.canonical.url"
                        :saved="saved('canonical.url')"
                        label="URL"
                        mono
                        inputmode="url"
                        placeholder="https://"
                        required
                        :error="errors['canonical.url']"
                        :help="
                            $t(
                                'Zeigt auf das Original, wenn derselbe Inhalt unter mehreren Adressen steht.',
                            )
                        "
                    />
                    <Field
                        v-model="form.canonical.keep_query"
                        :saved="saved('canonical.keep_query')"
                        :label="$t('Erhaltene Query-Parameter')"
                        mono
                        optional
                        placeholder="page, tag"
                        :error="errors['canonical.keep_query']"
                        :help="
                            $t(
                                'Kommagetrennt. Alle anderen Parameter werden in der kanonischen URL entfernt.',
                            )
                        "
                    />
                </div>
                <Notice tone="grey" class="mt-3">
                    <template
                        v-for="(part, i) in $t(
                            'Die Sprachfassungen verweisen über :attribute aufeinander und brauchen keine Einstellung.',
                        ).split(':attribute')"
                        :key="i"
                        ><span v-if="i > 0" class="font-mono">hreflang</span
                        >{{ part }}</template
                    >
                </Notice>
            </template>

            <!-- Zugriff -->
            <template #access>
                <!-- TB 13 Scheibe 2 (M-23): «Nur für Mitglieder», vererbt auf Unterseiten -->
                <Notice
                    v-if="options.access.inherited"
                    tone="lava"
                    class="mb-3"
                >
                    {{ $t('Geschützt durch') }}
                    <a
                        :href="options.access.inherited.url"
                        class="font-semibold underline"
                        >«{{ options.access.inherited.title }}»</a
                    >
                    —
                    {{
                        $t(
                            'der Schutz gilt für alle Unterseiten. Diese Seite ist nur für Mitglieder, was auch immer hier steht.',
                        )
                    }}
                </Notice>
                <div class="flex items-start gap-2.5 text-[13px]">
                    <Toggle
                        v-model="form.access.protected"
                        class="mt-px"
                        :label="$t('Seite schützen')"
                    />
                    <span
                        >{{ $t('Seite schützen')
                        }}<span
                            v-if="saved('access.protected')"
                            class="text-success ml-1.5 text-[11.5px] font-semibold"
                            >✓ {{ $t('gespeichert') }}</span
                        >
                        <span class="block text-[11.5px] text-basalt-light">{{
                            $t(
                                'nur angemeldete Mitglieder sehen die Seite — Gäste sehen die Anmeldung, Menü, Sitemap, Suche und Feeds lassen sie weg',
                            )
                        }}</span>
                        <span
                            v-if="errors['access.protected']"
                            class="text-danger block text-[11.5px] font-semibold"
                            >{{ errors['access.protected'] }}</span
                        ></span
                    >
                </div>
                <label :class="[checkRow, 'mt-2.5']">
                    <input
                        v-model="form.access.children"
                        type="checkbox"
                        :disabled="!form.access.protected"
                        :class="[checkbox, 'mt-0.5']"
                    />
                    <span :class="form.access.protected ? '' : 'opacity-55'"
                        >{{ $t('Schutz gilt auch für alle Unterseiten')
                        }}<span
                            v-if="saved('access.children')"
                            class="text-success ml-1.5 font-semibold"
                            >✓ {{ $t('gespeichert') }}</span
                        >
                        <span class="block text-[11.5px] text-basalt-light">{{
                            $t(
                                'ab Werk an — aus, wenn nur diese Seite geschützt sein soll und ihre Unterseiten öffentlich bleiben',
                            )
                        }}</span></span
                    >
                </label>
                <span :class="[labelClasses, 'mt-4']">{{
                    $t('Mitgliedergruppen')
                }}</span>
                <p class="text-ash-dark text-xs">
                    {{
                        $t(
                            '— noch keine Gruppen: alle aktiven Mitglieder sehen alle geschützten Seiten',
                        )
                    }}
                </p>
                <Notice
                    v-if="!options.access.installed || !options.access.enabled"
                    tone="grey"
                    class="mt-3"
                >
                    <b>{{ $t('Modul «Mitglieder» aus.') }}</b>
                    {{
                        $t(
                            'Geschützte Seiten sieht dann niemand ausser der Redaktion — auch keine Anmeldung. Einschalten unter Verwaltung › Module.',
                        )
                    }}
                    <a
                        :href="options.access.modules_url"
                        class="ml-1 font-semibold underline"
                        >{{ $t('Zu den Modulen') }}</a
                    >
                </Notice>
                <p
                    v-else-if="options.access.admin_url"
                    class="mt-3 text-[11.5px] text-basalt-light"
                >
                    {{
                        $t(
                            'Wer Mitglied ist, wie man es wird (offen, mit Freigabe, nur auf Einladung) und wer gesperrt ist:',
                        )
                    }}
                    <a
                        :href="options.access.admin_url"
                        class="font-semibold text-lava-dark underline"
                        >{{ $t('Mitglieder verwalten') }}</a
                    >
                </p>
            </template>

            <!-- Layout -->
            <template #layout>
                <span :class="labelClasses"
                    >{{ $t('Layout')
                    }}<span
                        v-if="saved('layout.mode')"
                        class="text-success ml-1.5 font-semibold tracking-normal normal-case"
                        >✓ {{ $t('gespeichert') }}</span
                    ></span
                >
                <div class="flex flex-wrap gap-x-5 gap-y-1 text-[12.5px]">
                    <label class="flex items-center gap-1.5">
                        <input
                            v-model="form.layout.mode"
                            type="radio"
                            name="layout_mode"
                            value="inherit"
                            :class="checkbox"
                        />
                        {{ $t('vererbt vom Elternelement (Standard)') }}
                    </label>
                    <label class="flex items-center gap-1.5">
                        <input
                            v-model="form.layout.mode"
                            type="radio"
                            name="layout_mode"
                            value="own"
                            :class="checkbox"
                        />
                        {{ $t('eigenes') }}
                    </label>
                </div>
                <p
                    v-if="errors['layout.mode']"
                    class="text-danger mt-1 text-[11.5px] font-semibold"
                >
                    {{ errors['layout.mode'] }}
                </p>
                <div class="mt-2 grid gap-x-4 gap-y-2.5 *:mt-0! md:grid-cols-2">
                    <Field
                        v-model="form.layout.template"
                        :saved="saved('layout.template')"
                        :label="$t('Eigenes Template')"
                        type="select"
                        :options="templateOptions"
                        :disabled="form.layout.mode !== 'own'"
                        :required="form.layout.mode === 'own'"
                        :error="errors['layout.template']"
                        :help="
                            $t(
                                'aus Station G — das Template bestimmt das Layout',
                            )
                        "
                    />
                    <Field
                        v-model="form.layout.children"
                        :saved="saved('layout.children')"
                        :label="$t('Layout für Unterseiten')"
                        type="select"
                        :options="childrenOptions"
                        :error="errors['layout.children']"
                        :help="
                            $t(
                                'Unterseiten übernehmen es, solange sie nichts Eigenes wählen.',
                            )
                        "
                    />
                </div>
                <p
                    v-if="options.templates.length === 0"
                    class="text-warning mt-1 text-[11.5px]"
                >
                    {{
                        $t(
                            'Das Theme liefert kein Template für Seiten — Station G prüfen.',
                        )
                    }}
                </p>
                <Notice tone="grey" class="mt-3">
                    {{
                        $t(
                            'Regionen und Lava-Teile des Layouts bearbeiten Sie in der Einrichtung, Station C — hier wählen Sie nur, welches Layout gilt.',
                        )
                    }}
                </Notice>
            </template>

            <!-- Cache -->
            <template #cache>
                <div class="mb-3 flex items-start gap-2.5 text-[13px]">
                    <Toggle
                        v-model="form.cache.page_cache"
                        class="mt-px"
                        :label="$t('Seiten-Cache für diese Seite')"
                    />
                    <span
                        >{{ $t('Seiten-Cache für diese Seite')
                        }}<span
                            v-if="saved('cache.page_cache')"
                            class="text-success ml-1.5 text-[11.5px] font-semibold"
                            >✓ {{ $t('gespeichert') }}</span
                        >
                        <span class="block text-[11.5px] text-basalt-light">{{
                            $t(
                                'Die Seite kommt fertig aus dem Cache; jede Veröffentlichung macht ihn sofort ungültig. Aus für Seiten, die sich bei jedem Besuch ändern.',
                            )
                        }}</span>
                        <span
                            v-if="errors['cache.page_cache']"
                            class="text-danger block text-[11.5px] font-semibold"
                            >{{ errors['cache.page_cache'] }}</span
                        ></span
                    >
                </div>
                <div class="grid gap-x-4 gap-y-2.5 *:mt-0! md:grid-cols-2">
                    <Field
                        :model-value="form.cache.shared_max_age"
                        :saved="saved('cache.shared_max_age')"
                        :label="$t('Geteilter Cache (Sekunden)')"
                        type="number"
                        inputmode="numeric"
                        :error="errors['cache.shared_max_age']"
                        :help="$t('s-maxage · CDN und Proxy')"
                        @update:model-value="
                            form.cache.shared_max_age = Number($event)
                        "
                    />
                    <Field
                        :model-value="form.cache.private_max_age"
                        :saved="saved('cache.private_max_age')"
                        :label="$t('Privater Cache (Sekunden)')"
                        type="number"
                        inputmode="numeric"
                        :error="errors['cache.private_max_age']"
                        :help="$t('max-age · Browser')"
                        @update:model-value="
                            form.cache.private_max_age = Number($event)
                        "
                    />
                </div>
                <label :class="[checkRow, 'mt-2']">
                    <input
                        v-model="form.cache.always_shared"
                        type="checkbox"
                        :class="[checkbox, 'mt-0.5']"
                    />
                    <span
                        >{{
                            $t(
                                'Immer aus dem geteilten Cache (auch für angemeldete Besucher)',
                            )
                        }}<span
                            v-if="saved('cache.always_shared')"
                            class="text-success ml-1.5 font-semibold"
                            >✓ {{ $t('gespeichert') }}</span
                        ></span
                    >
                </label>
                <p class="mt-2 text-[11.5px] text-basalt-light">
                    {{
                        $t(
                            'Startwerte aus den Einstellungen der Site (mit Verlauf, K-07).',
                        )
                    }}
                </p>
            </template>

            <!-- Rechte -->
            <template #rights>
                <div class="grid gap-x-4 gap-y-2.5 *:mt-0! md:grid-cols-2">
                    <Field
                        :model-value="
                            form.rights.owner_id === null
                                ? ''
                                : String(form.rights.owner_id)
                        "
                        :saved="saved('rights.owner_id')"
                        :label="$t('Besitzer der Seite')"
                        type="select"
                        :options="ownerOptions"
                        :error="errors['rights.owner_id']"
                        @update:model-value="
                            form.rights.owner_id = toNullableId($event)
                        "
                    />
                </div>
                <span :class="[labelClasses, 'mt-4']"
                    >{{ $t('Rollen, die bearbeiten dürfen')
                    }}<span
                        v-if="saved('rights.roles')"
                        class="text-success ml-1.5 font-semibold tracking-normal normal-case"
                        >✓ {{ $t('gespeichert') }}</span
                    ></span
                >
                <label
                    v-for="r in options.roles"
                    :key="r.value"
                    :class="checkRow"
                >
                    <input
                        type="checkbox"
                        :checked="form.rights.roles.includes(r.value)"
                        :class="[checkbox, 'mt-0.5']"
                        @change="
                            toggleRole(
                                r.value,
                                ($event.target as HTMLInputElement).checked,
                            )
                        "
                    />
                    <span>{{ r.label }}</span>
                </label>
                <p
                    v-if="errors['rights.roles']"
                    class="text-danger mt-1 text-[11.5px] font-semibold"
                >
                    {{ errors['rights.roles'] }}
                </p>
                <p class="mt-2 text-[11.5px] text-basalt-light">
                    {{
                        $t(
                            'Optional je Seite; massgebend bleiben die Rechte je Site und Rolle (K-06).',
                        )
                    }}
                </p>
            </template>

            <!-- Experten -->
            <template #expert>
                <div class="grid gap-x-4 gap-y-2.5 *:mt-0! md:grid-cols-2">
                    <Field
                        v-model="form.expert.css_class"
                        :saved="saved('expert.css_class')"
                        :label="$t('CSS-Klasse')"
                        mono
                        optional
                        :error="errors['expert.css_class']"
                        :help="$t('am body und im Menü-Eintrag')"
                    />
                    <label v-if="options.mounts?.length" class="block">
                        <span :class="labelClasses">{{
                            $t('Modul einhängen')
                        }}</span>
                        <select
                            v-model="form.expert.mount"
                            :class="
                                controlClasses({
                                    error: !!errors['expert.mount'],
                                })
                            "
                        >
                            <option :value="null">{{ $t('keines') }}</option>
                            <option
                                v-for="mount in options.mounts"
                                :key="mount.value"
                                :value="mount.value"
                            >
                                {{ mount.label }}
                            </option>
                        </select>
                        <span
                            v-if="saved('expert.mount')"
                            class="text-success ml-1.5 text-[11.5px] font-semibold"
                            >✓ {{ $t('gespeichert') }}</span
                        >
                        <span
                            v-if="errors['expert.mount']"
                            class="text-danger mt-1 block text-[11.5px] font-semibold"
                            >{{ errors['expert.mount'] }}</span
                        >
                        <span
                            class="mt-1 block text-[11.5px] text-basalt-light"
                            >{{
                                $t(
                                    'Das Modul beantwortet diese Seite und alle Adressen darunter, z. B. das Forum unter /community.',
                                )
                            }}</span
                        >
                    </label>
                </div>
                <div class="mt-2">
                    <label :class="checkRow">
                        <input
                            v-model="form.expert.in_sitemap"
                            type="checkbox"
                            :class="[checkbox, 'mt-0.5']"
                        />
                        <span
                            >{{ $t('In der HTML-Sitemap zeigen')
                            }}<span
                                v-if="saved('expert.in_sitemap')"
                                class="text-success ml-1.5 font-semibold"
                                >✓ {{ $t('gespeichert') }}</span
                            ></span
                        >
                    </label>
                    <label :class="checkRow">
                        <input
                            v-model="form.expert.in_search"
                            type="checkbox"
                            :class="[checkbox, 'mt-0.5']"
                        />
                        <span
                            >{{ $t('Im Suchindex führen')
                            }}<span
                                v-if="saved('expert.in_search')"
                                class="text-success ml-1.5 font-semibold"
                                >✓ {{ $t('gespeichert') }}</span
                            ></span
                        >
                    </label>
                    <label :class="checkRow">
                        <input
                            v-model="form.expert.hide_in_menu"
                            type="checkbox"
                            :class="[checkbox, 'mt-0.5']"
                        />
                        <span
                            >{{ $t('Im Menü verstecken (bleibt erreichbar)')
                            }}<span
                                v-if="saved('expert.hide_in_menu')"
                                class="text-success ml-1.5 font-semibold"
                                >✓ {{ $t('gespeichert') }}</span
                            ></span
                        >
                    </label>
                </div>
                <p class="mt-2 text-[11.5px] text-basalt-light">
                    {{
                        $t(
                            'Sichtbarkeit in Menü, Sitemap und Suche — drei Haken, keine Magie: Was hier aus ist, fehlt dort.',
                        )
                    }}
                </p>
            </template>

            <!-- Tastatur -->
            <template #keyboard>
                <div class="grid gap-x-4 gap-y-2.5 *:mt-0! md:grid-cols-2">
                    <Field
                        v-model="form.keyboard.shortcut"
                        :saved="saved('keyboard.shortcut')"
                        :label="$t('Tastaturkürzel')"
                        mono
                        optional
                        :maxlength="1"
                        pattern="[a-z0-9]"
                        :error="errors['keyboard.shortcut']"
                        :help="
                            $t(
                                'accesskey · ein Zeichen a–z oder 0–9 · Besucher springen mit Alt (Windows) oder Ctrl+Alt (Mac) + Zeichen zur Seite. Vergebene Kürzel der Site zeigt der Fehler.',
                            )
                        "
                    />
                </div>
            </template>

            <!-- Veröffentlichung -->
            <template #publication>
                <span :class="labelClasses">{{
                    $t('Statusmodell der Fassung')
                }}</span>
                <p v-if="!page" class="text-ash-dark text-xs">
                    {{
                        $t(
                            'Die erste Fassung entsteht mit dem Speichern — als Entwurf in jeder Sprache mit Namen.',
                        )
                    }}
                </p>
                <div
                    v-else
                    class="flex flex-wrap gap-x-5 gap-y-1.5 text-[12.5px]"
                >
                    <span
                        v-for="l in locales"
                        :key="l.locale"
                        class="inline-flex items-center gap-1.5"
                    >
                        <StatusHex
                            :status="versionStatus(page.status[l.locale])"
                            :label="l.locale.toUpperCase()"
                            size="md"
                        />
                        {{ statusLabel(l.locale) }}
                        <span
                            v-if="page.versions[l.locale]"
                            class="text-[11px] text-basalt-light"
                            >·
                            {{
                                $t('Fassung :number', {
                                    number: page.versions[l.locale] ?? '',
                                })
                            }}</span
                        >
                    </span>
                </div>
                <p class="mt-1 text-[11.5px] text-basalt-light">
                    {{
                        $t(
                            'Das Statusmodell der Fassung ändert die Station «Freigabe» (Bereich 13), nicht dieses Formular.',
                        )
                    }}
                </p>

                <span :class="[labelClasses, 'mt-4']">{{
                    $t('Zeitfenster · Zeitzone der Site :timezone', {
                        timezone: options.timezone,
                    })
                }}</span>
                <div class="grid gap-x-4 gap-y-2.5 *:mt-0! md:grid-cols-2">
                    <Field
                        :saved="saved('publication.visible_from')"
                        :label="$t('Anzeigen ab')"
                        optional
                        :error="errors['publication.visible_from']"
                        :help="$t('leer = sofort')"
                    >
                        <template #default="{ id, invalid, describedby }">
                            <input
                                :id="id"
                                :aria-describedby="describedby"
                                type="datetime-local"
                                :value="
                                    toDateTimeLocal(
                                        form.publication.visible_from,
                                    )
                                "
                                :class="controlClasses({ error: invalid })"
                                :aria-invalid="invalid ? 'true' : undefined"
                                @input="
                                    form.publication.visible_from =
                                        fromDateTimeLocal(
                                            ($event.target as HTMLInputElement)
                                                .value,
                                        )
                                "
                            />
                        </template>
                    </Field>
                    <Field
                        :saved="saved('publication.visible_until')"
                        :label="$t('Anzeigen bis')"
                        optional
                        :error="errors['publication.visible_until']"
                        :help="$t('leer = unbegrenzt')"
                    >
                        <template #default="{ id, invalid, describedby }">
                            <input
                                :id="id"
                                :aria-describedby="describedby"
                                type="datetime-local"
                                :value="
                                    toDateTimeLocal(
                                        form.publication.visible_until,
                                    )
                                "
                                :class="controlClasses({ error: invalid })"
                                :aria-invalid="invalid ? 'true' : undefined"
                                @input="
                                    form.publication.visible_until =
                                        fromDateTimeLocal(
                                            ($event.target as HTMLInputElement)
                                                .value,
                                        )
                                "
                            />
                        </template>
                    </Field>
                </div>
                <Notice tone="grey" class="mt-3">
                    {{
                        $t(
                            'Ausserhalb des Zeitfensters antwortet die Seite 404 und fehlt in Menüs, Sitemap und Suche — die Fassung bleibt veröffentlicht. Die Zeiten gelten in der Zeitzone :timezone.',
                            { timezone: options.timezone },
                        )
                    }}
                </Notice>
            </template>
        </Tabs>

        <div
            class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-ash-light pt-3"
        >
            <Button v-if="autosave" variant="link" :href="urls.index"
                >← {{ $t('Zum Seitenbaum') }}</Button
            >
            <Button v-else variant="link" :href="urls.index">{{
                $t('Abbrechen')
            }}</Button>
            <div v-if="!autosave" class="flex flex-wrap gap-2">
                <Button
                    variant="secondary"
                    :disabled="form.processing"
                    @click="save('new')"
                    >{{ $t('Speichern und neu') }}</Button
                >
                <Button
                    variant="secondary"
                    :disabled="form.processing"
                    @click="save('close')"
                    >{{ $t('Speichern und schliessen') }}</Button
                >
                <Button type="submit" :disabled="form.processing">{{
                    $t('Speichern')
                }}</Button>
            </div>
        </div>
    </form>
</template>
