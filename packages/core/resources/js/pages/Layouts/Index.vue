<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AutosaveState from '../../components/AutosaveState.vue';
import { useAutosaveValue } from '../../components/autosave';
import Button from '../../components/Button.vue';
import Card from '../../components/Card.vue';
import CodeBlock from '../../components/CodeBlock.vue';
import EmptyState from '../../components/EmptyState.vue';
import FillEditor from '../../components/FillEditor.vue';
import Modal from '../../components/Modal.vue';
import Notice from '../../components/Notice.vue';
import PageHeader from '../../components/PageHeader.vue';
import StateTag from '../../components/StateTag.vue';
import Toggle from '../../components/Toggle.vue';
import {
    collapseMarkup,
    isValidName,
    isValidRegionName,
    moveItem,
    regionLabel,
    scheme,
    schemeText,
    settingInput,
    settingLabel,
} from '../../components/layoutEditor';
import type {
    FillItem,
    LayoutForm,
    PartForm,
    RegionForm,
    Sources,
    TemplateForm,
} from '../../components/layoutEditor';
import {
    COMMON_REGIONS,
    PRIMARY_SETTINGS,
    partNameFor,
    settingHelp,
} from '../../components/fillPalette';
import type { Finding } from '../../components/lavaEditor';
import { severityOf } from '../../components/lavaEditor';
import { postJson, requestError } from '../../http';
import { t, tc } from '../../i18n';
import type {
    BackendProps,
    LayoutInfo,
    PartInfo,
    PartKind,
    TemplateInfo,
} from '../../types';

/**
 * Theme-Verwaltung, Station C und G als Formular (M-28, Mockup 27 Bilder 2–3): Layouts mit Regionen, Vererbung und
 * Lava-Teilen in Reihenfolge (Ein/Aus, ↑ ↓), Teil-Instanzen mit ihren Einstellungen, Templates je Typ × Layout.
 * Daneben der Lava-Code, den das Formular schreibt (Probe über den Drucker), die schematische und die echte Vorschau
 * mit den Entwürfen. Gespeichert wird als Theme-Entwurf — live geht er mit «Veröffentlichen» im Theme-Editor.
 */
const props = defineProps<{
    tab: 'layouts' | 'parts' | 'templates';
    broken: string | null;
    database: boolean;
    can_edit: boolean;
    theme?: string;
    drafts: number;
    layouts: LayoutInfo[];
    parts: PartInfo[];
    part_kinds: PartKind[];
    templates: TemplateInfo[];
    sources: Sources | null;
    preview_path?: string;
    urls: Record<string, string>;
}>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);
const errors = computed(() => page.props.errors as Record<string, string>);
const editable = computed(() => props.can_edit && props.database);
const sources = computed<Sources>(
    () =>
        props.sources ?? {
            nav: [],
            global: [],
            form: [],
            type: [],
            template: [],
            layout: [],
            part: [],
            locales: [],
        },
);
const query = new URLSearchParams(
    typeof window === 'undefined' ? '' : window.location.search,
);

const station = computed(() =>
    props.tab === 'templates'
        ? { letter: 'G', keyword: 'template' }
        : { letter: 'C', keyword: 'layout' },
);
const title = computed(
    () =>
        ({
            layouts: t('Layout'),
            parts: t('Lava-Teile'),
            templates: t('Templates'),
        })[props.tab],
);
const lead = computed(() => {
    if (props.tab === 'parts') {
        return t(
            '«Teile» statt «Module», damit sich das Wort nicht mit den Basalto-Modulen beisst: registrierte Arten aus Kern und Modulen, platzierbar in Regionen und als Block in Seiten — jede Instanz mit eigenen Einstellungen.',
        );
    }
    if (props.tab === 'templates') {
        return t(
            'Template je Typ × Layout: welcher Typ in welchem Layout rendert und welche Regionen das Template selbst füllt.',
        );
    }

    return t(
        'Regionen mit Lava-Teilen in Reihenfolge, Vererbung mit extends. Formular links, Code rechts — eine Quelle.',
    );
});
const filters = computed(() => [
    { key: 'layouts', label: t('Layouts'), count: props.layouts.length },
    { key: 'parts', label: t('Teile'), count: props.parts.length },
    { key: 'templates', label: t('Templates'), count: props.templates.length },
]);

function tabHref(key: string, extra: Record<string, string> = {}): string {
    const base = props.urls.index ?? '';
    const params = new URLSearchParams({
        ...(key === 'layouts' ? {} : { tab: key }),
        ...extra,
    });
    const q = params.toString();

    return q ? `${base}?${q}` : base;
}

const clone = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T;
const firstError = (prefix: string): string =>
    Object.entries(errors.value).find(
        ([k]) => k === prefix || k.startsWith(`${prefix}.`),
    )?.[1] ?? '';

/* ── Probe: Code und Befunde zum Formular, ohne zu speichern ── */

const probeCode = ref<string | null>(null);
const probeFindings = ref<Finding[]>([]);
const probeError = ref('');
let probeTimer: ReturnType<typeof setTimeout> | undefined;

function probe(
    kind: 'layout' | 'part' | 'template',
    current: string | null,
    data: object,
): void {
    if (!editable.value) {
        return;
    }
    clearTimeout(probeTimer);
    probeTimer = setTimeout(async () => {
        try {
            const result = await postJson<{
                changed: boolean;
                code: string | null;
                findings: Finding[];
            }>(props.urls.probe ?? '', { probe: kind, current, ...data });
            probeCode.value = result.changed ? result.code : null;
            probeFindings.value = result.findings;
            probeError.value = '';
        } catch (error) {
            probeError.value = requestError(error);
        }
    }, 400);
}

const probeErrors = computed(() =>
    probeFindings.value.filter((f) => severityOf(f.severity) === 'error'),
);

/* ── Layouts ── */

const selectedLayout = ref<string>(
    query.get('layout') ?? props.layouts[0]?.name ?? '',
);
const layout = computed(
    () =>
        props.layouts.find((l) => l.name === selectedLayout.value) ??
        props.layouts[0] ??
        null,
);
const layoutForm = ref<LayoutForm | null>(
    layout.value ? clone(layout.value.form) : null,
);
const layoutDirty = computed(
    () =>
        layout.value !== null &&
        JSON.stringify(layoutForm.value) !== JSON.stringify(layout.value.form),
);

watch(layout, (l) => {
    layoutForm.value = l ? clone(l.form) : null;
    probeCode.value = null;
    probeFindings.value = [];
});
watch(
    () => props.layouts,
    () => {
        // Nicht über den Entwurf schreiben, solange noch gespeichert wird (sonst ginge Getipptes verloren)
        if (!layoutSave.busy()) {
            layoutForm.value = layout.value ? clone(layout.value.form) : null;
            probeCode.value = null;
        }
        previewKey.value++;
    },
);
watch(
    layoutForm,
    (form) => {
        if (form && layoutDirty.value && props.tab === 'layouts') {
            probe('layout', layout.value?.name ?? null, form);
        }
    },
    { deep: true },
);

/** Geerbte Regionen (nur lesen) — überschreibt das Layout eine, steht sie bei den eigenen. */
const inherited = computed(() =>
    (layout.value?.resolved ?? []).filter(
        (r) =>
            r.inherited &&
            !layoutForm.value?.regions.some((o) => o.name === r.name),
    ),
);
const allRegionNames = computed(() => [
    ...inherited.value.map((r) => r.name),
    ...(layoutForm.value?.regions ?? []).map((r) => r.name),
]);
const schemeRegions = computed(() =>
    scheme([...inherited.value, ...(layoutForm.value?.regions ?? [])]),
);
const partCount = computed(() =>
    (layoutForm.value?.regions ?? []).reduce(
        (n, r) => n + r.fill.filter((f) => f.word === 'part').length,
        0,
    ),
);

function setRegion(index: number, region: RegionForm | null): void {
    if (!layoutForm.value) {
        return;
    }
    const regions = [...layoutForm.value.regions];
    if (region === null) {
        regions.splice(index, 1);
    } else {
        regions[index] = region;
    }
    layoutForm.value = { ...layoutForm.value, regions };
}

function override(name: string): void {
    const source = inherited.value.find((r) => r.name === name);
    if (layoutForm.value && source) {
        layoutForm.value = {
            ...layoutForm.value,
            regions: [
                ...layoutForm.value.regions,
                {
                    name,
                    optional: source.optional,
                    where: null,
                    target: null,
                    fill: clone(source.fill),
                },
            ],
        };
    }
}

const regionOpen = ref(false);
const newRegion = ref<{
    name: string;
    where: 'before' | 'after' | null;
    target: string;
}>({ name: '', where: 'after', target: '' });
const needsPlace = computed(
    () => !(layout.value?.markup_regions ?? []).includes(newRegion.value.name),
);
const newRegionValid = computed(
    () =>
        isValidRegionName(newRegion.value.name) &&
        !allRegionNames.value.includes(newRegion.value.name) &&
        (!needsPlace.value ||
            (newRegion.value.where !== null && newRegion.value.target !== '')),
);

/** TB 33: häufige Regionen mit passendem Platz — ein Klick füllt Name und Platz. */
const regionChoices = computed(() =>
    COMMON_REGIONS.filter((r) => !allRegionNames.value.includes(r.name)).map(
        (r) => ({
            ...r,
            target: allRegionNames.value.includes(r.target)
                ? r.target
                : (allRegionNames.value[0] ?? ''),
        }),
    ),
);

function chooseRegion(choice: {
    name: string;
    where: 'before' | 'after';
    target: string;
}): void {
    newRegion.value = {
        name: choice.name,
        where: choice.where,
        target: choice.target,
    };
}

/** TB 33: Regionen des Layouts, von dem ein neues erbt — damit klar ist, was es übernimmt. */
const baseRegions = computed(() => {
    const base = props.layouts.find((l) => l.name === newLayout.value.extends);

    return base ? base.form.regions.map((r) => regionLabel(r.name)) : [];
});
const LAYOUT_NAMES = ['schmal', 'breit', 'landing', 'artikel'];
const layoutChoices = computed(() =>
    LAYOUT_NAMES.filter((n) => !props.layouts.some((l) => l.name === n)),
);

function addRegion(): void {
    if (!layoutForm.value || !newRegionValid.value) {
        return;
    }
    const r = newRegion.value;
    layoutForm.value = {
        ...layoutForm.value,
        regions: [
            ...layoutForm.value.regions,
            {
                name: r.name,
                optional: false,
                where: needsPlace.value ? r.where : null,
                target: needsPlace.value ? r.target : null,
                fill: [],
            },
        ],
    };
    regionOpen.value = false;
    newRegion.value = { name: '', where: 'after', target: '' };
}

// CR-013: Das Layout speichert selbst in den Entwurf — nach einer Pause und nur ohne Fehler beim Prüfen
const layoutSave = useAutosaveValue(() => layoutForm.value, {
    target: () =>
        layout.value && editable.value
            ? {
                  method: 'put',
                  url: `${props.urls.layouts}/${layout.value.name}`,
              }
            : null,
    only: ['layouts'],
    dirty: () => layoutDirty.value,
    enabled: () => probeErrors.value.length === 0,
    label: () => t('Layout «:name»', { name: layout.value?.name ?? '' }),
});

const newLayoutOpen = ref(false);
const newLayout = ref({ name: '', extends: props.layouts[0]?.name ?? '' });
function createLayout(): void {
    router.post(
        props.urls.layouts ?? '',
        {
            name: newLayout.value.name,
            extends: newLayout.value.extends,
            regions: [],
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                selectedLayout.value = newLayout.value.name;
                newLayoutOpen.value = false;
            },
        },
    );
}
const deleteOpen = ref(false);
function deleteLayout(): void {
    if (layout.value) {
        router.delete(`${props.urls.layouts}/${layout.value.name}`, {
            preserveScroll: true,
            onFinish: () => (deleteOpen.value = false),
        });
    }
}

const showMarkup = ref(false);

/* ── Vorschau ── */

const previewPath = ref(props.preview_path ?? '/');
const previewKey = ref(0);
const previewUrl = computed(
    () =>
        `${props.urls.preview_page}?pfad=${encodeURIComponent(previewPath.value || '/')}&v=${previewKey.value}`,
);

/* ── Teile ── */

const selectedPart = ref<string | null>(
    query.get('teil') ?? props.parts[0]?.name ?? null,
);
const creatingKind = ref<string | null>(null);
const part = computed(() =>
    creatingKind.value
        ? null
        : (props.parts.find((p) => p.name === selectedPart.value) ?? null),
);
const partForm = ref<PartForm | null>(
    part.value ? clone(part.value.form) : null,
);
const partKind = computed(
    () =>
        props.part_kinds.find((k) => k.name === (partForm.value?.kind ?? '')) ??
        null,
);
const partDirty = computed(() =>
    part.value === null
        ? partForm.value !== null
        : JSON.stringify(partForm.value) !== JSON.stringify(part.value.form),
);

// Andere Instanz gewählt: Entwurf neu aufsetzen; nach dem Speichern neu geladen: nur, wenn nichts mehr wartet
watch(
    () => part.value?.name,
    () => {
        if (!creatingKind.value) {
            partForm.value = part.value ? clone(part.value.form) : null;
            partNameDraft.value = partForm.value?.name ?? '';
        }
        probeCode.value = null;
        probeFindings.value = [];
    },
);
watch(
    () => props.parts,
    () => {
        if (!creatingKind.value && !partSave.busy()) {
            partForm.value = part.value ? clone(part.value.form) : null;
            partNameDraft.value = partForm.value?.name ?? '';
            probeCode.value = null;
        }
    },
);

/** Die Kennung einer bestehenden Instanz ändert sich erst mit Enter oder beim Verlassen (sie benennt um). */
const partNameDraft = ref(partForm.value?.name ?? '');
function commitPartName(): void {
    if (partForm.value && partForm.value.name !== partNameDraft.value) {
        partForm.value = { ...partForm.value, name: partNameDraft.value };
    }
}
watch(
    partForm,
    (form) => {
        if (
            form &&
            partDirty.value &&
            props.tab === 'parts' &&
            isValidName(form.name)
        ) {
            probe('part', part.value?.name ?? null, form);
        }
    },
    { deep: true },
);

function instancesOf(kind: string): PartInfo[] {
    return props.parts.filter((p) => p.kind === kind);
}

/* ── TB 33: neue Instanz — Kennung aus Art und Quelle, Einstellungen mit dem Wichtigsten zuerst ── */
const partNameTouched = ref(false);
const partMoreOpen = ref(false);
const orderedSettings = computed(() => {
    const all = partKind.value?.settings ?? [];

    return [
        ...all.filter((x) => PRIMARY_SETTINGS.includes(x)),
        ...all.filter((x) => !PRIMARY_SETTINGS.includes(x)),
    ];
});
const primaryCount = computed(
    () =>
        (partKind.value?.settings ?? []).filter((x) =>
            PRIMARY_SETTINGS.includes(x),
        ).length,
);
const showPartMore = computed(
    () =>
        partMoreOpen.value ||
        orderedSettings.value
            .slice(primaryCount.value)
            .some((x) => Boolean(errors.value[`settings.${x}`])),
);
watch(
    () => partForm.value?.settings.source,
    (source) => {
        const form = partForm.value;
        if (form && creatingKind.value && !partNameTouched.value) {
            form.name = partNameFor(
                form.kind,
                source ?? '',
                props.parts.map((p) => p.name),
            );
        }
    },
);

function startInstance(kind: string): void {
    creatingKind.value = kind;
    selectedPart.value = null;
    partNameTouched.value = false;
    partMoreOpen.value = false;
    // Die erste mögliche Quelle ist schon gewählt (z. B. das Menü «main») — die Kennung folgt ihr
    const kindSettings =
        props.part_kinds.find((k) => k.name === kind)?.settings ?? [];
    const firstSource = kindSettings.includes('source')
        ? (optionsOf(kind, 'source')[0] ?? '')
        : '';
    partForm.value = {
        name: partNameFor(
            kind,
            firstSource,
            props.parts.map((p) => p.name),
        ),
        kind,
        settings: firstSource === '' ? {} : { source: firstSource },
        markup: kind === 'html' ? '<p></p>' : null,
    };
}

function selectPart(name: string): void {
    creatingKind.value = null;
    selectedPart.value = name;
}

function optionsOf(kind: string, setting: string): string[] {
    const input = settingInput(kind, setting, sources.value);

    return 'options' in input ? input.options : [];
}

function setSetting(key: string, value: string): void {
    if (partForm.value) {
        partForm.value = {
            ...partForm.value,
            settings: { ...partForm.value.settings, [key]: value },
        };
    }
}

function toggleListValue(key: string, value: string): void {
    const current = (partForm.value?.settings[key] ?? '')
        .split(',')
        .map((v) => v.trim())
        .filter(Boolean);
    setSetting(
        key,
        (current.includes(value)
            ? current.filter((v) => v !== value)
            : [...current, value]
        ).join(', '),
    );
}

// CR-013: Eine bestehende Instanz speichert selbst in den Entwurf; neu angelegt wird weiter mit «Anlegen»
const partSave = useAutosaveValue(() => (part.value ? partForm.value : null), {
    target: () =>
        part.value && editable.value
            ? { method: 'put', url: `${props.urls.parts}/${part.value.name}` }
            : null,
    only: ['parts'],
    dirty: () => part.value !== null && partDirty.value,
    enabled: () =>
        probeErrors.value.length === 0 &&
        partForm.value !== null &&
        isValidName(partForm.value.name),
    label: () => t('Teil «:name»', { name: partForm.value?.name ?? '' }),
    onSaved: () => {
        selectedPart.value = partForm.value?.name ?? selectedPart.value;
    },
});

function savePart(): void {
    if (!partForm.value) {
        return;
    }
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            selectedPart.value = partForm.value?.name ?? null;
            creatingKind.value = null;
        },
    };
    if (part.value) {
        router.put(
            `${props.urls.parts}/${part.value.name}`,
            partForm.value as never,
            options,
        );
    } else {
        router.post(props.urls.parts ?? '', partForm.value as never, options);
    }
}

const removePartOpen = ref(false);
function removePart(): void {
    if (part.value) {
        router.delete(`${props.urls.parts}/${part.value.name}`, {
            preserveScroll: true,
            onFinish: () => (removePartOpen.value = false),
        });
    }
}

/* ── Templates ── */

const openTemplate = ref<string | null>(null);
const templateForm = ref<TemplateForm | null>(null);
const template = computed(
    () => props.templates.find((t) => t.name === openTemplate.value) ?? null,
);
const templateLayoutRegions = computed(() => {
    const l = props.layouts.find((x) => x.name === templateForm.value?.layout);
    // TB 35: auch Regionen, die nur das Markup einsetzt — und die, die das Template schon selbst füllt
    const names = l
        ? [...l.resolved.map((r) => r.name), ...(l.markup_regions ?? [])]
        : (template.value?.layout_regions ?? []);

    return [
        ...new Set([
            ...names,
            ...(templateForm.value?.regions ?? []).map((r) => r.name),
        ]),
    ];
});

function editTemplate(t: TemplateInfo): void {
    openTemplate.value = openTemplate.value === t.name ? null : t.name;
    templateForm.value = openTemplate.value ? clone(t.form) : null;
}
watch(
    () => props.templates,
    () => {
        if (!templateSave.busy()) {
            templateForm.value = template.value
                ? clone(template.value.form)
                : null;
        }
    },
);

function templateRegion(
    name: string,
): { name: string; fill: FillItem[] } | null {
    return templateForm.value?.regions.find((r) => r.name === name) ?? null;
}

function setTemplateRegion(name: string, fill: FillItem[] | null): void {
    if (!templateForm.value) {
        return;
    }
    const regions = templateForm.value.regions.filter((r) => r.name !== name);
    if (fill !== null) {
        const index = templateForm.value.regions.findIndex(
            (r) => r.name === name,
        );
        regions.splice(index < 0 ? regions.length : index, 0, { name, fill });
    }
    templateForm.value = { ...templateForm.value, regions };
}

function layoutFillOf(layoutName: string, region: string): FillItem[] {
    return (
        props.layouts
            .find((l) => l.name === layoutName)
            ?.resolved.find((r) => r.name === region)?.fill ?? []
    );
}

// CR-013: Das offene Template speichert selbst in den Entwurf; danach lädt die Vorschau neu
const templateSave = useAutosaveValue(() => templateForm.value, {
    target: () =>
        template.value && editable.value
            ? {
                  method: 'put',
                  url: (props.urls.templates ?? '').replace(
                      '__name__',
                      template.value.name,
                  ),
              }
            : null,
    only: ['templates'],
    dirty: () =>
        template.value !== null &&
        templateForm.value !== null &&
        JSON.stringify(templateForm.value) !==
            JSON.stringify(template.value.form),
    label: () => t('Template «:name»', { name: template.value?.name ?? '' }),
    onSaved: () => templatePreviewKey.value++,
});

/* ── TB 35: Templates geführt — Vorschau einer Seite mit dem Template, Erklärungen, Vorschlag noindex ── */
const templatePreviewKey = ref(0);

function templatePreviewUrl(t: TemplateInfo): string {
    return `${props.urls.preview_page}?pfad=${encodeURIComponent(t.example ?? '/')}&v=${templatePreviewKey.value}`;
}

/** Rechts-, Danke- und Fehlerseiten gehören selten in Suchmaschinen. */
function noindexSuggested(t: TemplateInfo): boolean {
    return (
        templateForm.value?.noindex === false &&
        /legal|recht|impressum|datenschutz|privacy|danke|thanks|404|fehler|error/.test(
            t.name,
        )
    );
}

function regionMode(name: string, own: boolean): void {
    if (!templateForm.value || (templateRegion(name) !== null) === own) {
        return;
    }
    setTemplateRegion(
        name,
        own
            ? (JSON.parse(
                  JSON.stringify(layoutFillOf(templateForm.value.layout, name)),
              ) as FillItem[])
            : null,
    );
}

function adopt(): void {
    router.post(props.urls.adopt ?? '', {}, { preserveScroll: true });
}

const sel =
    'rounded-md border border-ash bg-white px-2 py-1 text-[13px] disabled:bg-paper disabled:text-basalt-light';
</script>

<template>
    <Head :title="title" />
    <PageHeader
        :title="title"
        :crumb="$t(':site › Einrichtung', { site: siteName })"
        :station="station"
        :lead="lead"
    >
        <template #actions>
            <Button
                v-if="drafts > 0"
                variant="secondary"
                size="sm"
                :href="urls.theme_editor"
                >{{
                    $tc(
                        ':count Entwurf · Freigabe|:count Entwürfe · Freigabe',
                        drafts,
                    )
                }}</Button
            >
        </template>
    </PageHeader>

    <Notice v-if="errors.lava" tone="danger" class="mb-3" role="alert">
        {{ errors.lava }}
        <span class="mt-1 block text-[12px]">{{
            $t(
                'Nichts gespeichert — ein Theme mit Fehler geht nie live. Der Code rechts zeigt die Stelle.',
            )
        }}</span>
    </Notice>
    <Notice v-if="broken" tone="danger" class="mb-3" role="alert">{{
        broken
    }}</Notice>
    <Card
        v-else-if="!database"
        :title="$t('Dieses Theme liegt im Ordner')"
        class="mb-4"
    >
        <p class="text-sm">
            {{ $t('Das Formular zeigt den Stand von') }}
            <span class="font-mono">themes/{{ theme }}</span
            >.
            {{
                $t(
                    'Ändern lässt es sich hier erst, wenn die Site das Theme in die Datenbank übernimmt — auf Laravel Cloud gibt es keinen Schreibzugriff aufs Repo. Danach gelten Prüfung, Vorschau, Freigabe und Fassungen.',
                )
            }}
        </p>
        <div v-if="can_edit" class="mt-3 flex flex-wrap gap-2">
            <Button size="sm" @click="adopt">{{
                $t('In die Datenbank übernehmen')
            }}</Button>
            <Button variant="secondary" size="sm" :href="urls.theme_editor">{{
                $t('Zum Theme-Editor')
            }}</Button>
        </div>
    </Card>
    <Notice v-if="database && !can_edit" tone="grey" class="mb-3">{{
        $t('Ihre Rolle erlaubt, den Stand anzusehen, nicht zu ändern.')
    }}</Notice>

    <nav
        class="mb-3 flex flex-wrap items-end gap-x-4 border-b border-ash-light"
        :aria-label="$t('Ansicht')"
    >
        <Link
            v-for="f in filters"
            :key="f.key"
            :href="tabHref(f.key)"
            class="-mb-px flex items-baseline gap-1.5 border-b-2 py-2 text-[13px] whitespace-nowrap"
            :class="
                f.key === tab
                    ? 'border-lava font-semibold text-basalt'
                    : 'border-transparent text-basalt-light hover:text-basalt'
            "
            :aria-current="f.key === tab ? 'page' : undefined"
            preserve-scroll
        >
            {{ f.label }}
            <span class="font-mono text-[11.5px] font-normal">{{
                f.count
            }}</span>
        </Link>
        <Link
            :href="urls.variants ?? ''"
            class="-mb-px border-b-2 border-transparent py-2 text-[13px] whitespace-nowrap text-basalt-light hover:text-basalt"
        >
            {{ $t('Bildvarianten (B) ›') }}
        </Link>
    </nav>

    <!-- ── Layouts (Station C) ── -->
    <template v-if="tab === 'layouts'">
        <div
            v-if="!layout || !layoutForm"
            class="rounded-lg border border-ash-light bg-white"
        >
            <EmptyState
                :title="$t('Das Theme definiert kein Layout')"
                :text="
                    $t(
                        'Station C ist leer — ein Layout mit Markup legt man im Lava-Editor an.',
                    )
                "
                icon="theme"
                tone="ash"
            />
        </div>
        <template v-else>
            <div
                class="mb-3 flex flex-wrap items-center gap-2"
                role="group"
                :aria-label="$t('Layout wählen')"
            >
                <button
                    v-for="l in layouts"
                    :key="l.name"
                    type="button"
                    class="rounded-md border px-2.5 py-1 font-mono text-[12px]"
                    :class="
                        l.name === layout.name
                            ? 'border-lava-dark bg-lava/10 font-semibold text-lava-dark'
                            : 'border-ash bg-white text-basalt'
                    "
                    :aria-pressed="l.name === layout.name"
                    @click="selectedLayout = l.name"
                >
                    {{ l.name
                    }}<span
                        v-if="l.extends"
                        class="font-normal text-basalt-light"
                    >
                        · extends {{ l.extends }}</span
                    >
                </button>
                <Button
                    v-if="editable"
                    variant="link"
                    size="sm"
                    @click="newLayoutOpen = true"
                    >{{ $t('+ Layout') }}</Button
                >
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <section class="min-w-0" :aria-label="$t('Formular')">
                    <Card
                        :title="$t('Layout «:name»', { name: layout.name })"
                        :sub="`${layout.file}:${layout.line} · ${$tc(':count eigene Region|:count eigene Regionen', layoutForm.regions.length)} · ${$tc(':count Teil|:count Teile', partCount)}`"
                    >
                        <label
                            class="mb-3 block text-[12px] font-semibold tracking-wide text-basalt-light uppercase"
                        >
                            {{ $t('Vererbung (extends)') }}
                            <select
                                v-model="layoutForm.extends"
                                :class="[
                                    sel,
                                    'mt-1 block w-full font-mono normal-case',
                                ]"
                                :disabled="!editable"
                            >
                                <option v-if="layout.has_markup" :value="null">
                                    {{ $t('— keine: eigenes Markup') }}
                                </option>
                                <!-- TB 29: Das geerbte Layout kann aus einer anderen Schicht kommen (nicht in der Liste) — trotzdem anzeigen -->
                                <option
                                    v-if="
                                        layout.extends &&
                                        !layouts.some(
                                            (x) => x.name === layout?.extends,
                                        )
                                    "
                                    :value="layout.extends"
                                >
                                    {{ layout.extends }}
                                </option>
                                <option
                                    v-for="l in layouts.filter(
                                        (x) => x.name !== layout?.name,
                                    )"
                                    :key="l.name"
                                    :value="l.name"
                                >
                                    {{ l.name }}
                                </option>
                            </select>
                            <span
                                class="mt-0.5 block text-[11.5px] font-normal tracking-normal text-basalt-light normal-case"
                                >{{
                                    $t(
                                        'Übernimmt Markup und Regionen des gewählten Layouts — «keine» heisst: dieses Layout hat eigenes Markup.',
                                    )
                                }}</span
                            >
                            <span
                                v-if="errors.extends"
                                class="text-danger mt-1 block text-[12px] font-semibold normal-case"
                                >{{ errors.extends }}</span
                            >
                        </label>

                        <div v-if="inherited.length" class="mb-3">
                            <p class="mb-1 text-[12px] text-basalt-light">
                                {{
                                    $t(
                                        'Geerbt aus :layout — gilt, solange dieses Layout die Region nicht selbst füllt:',
                                        { layout: layoutForm.extends ?? '' },
                                    )
                                }}
                            </p>
                            <ul class="space-y-1">
                                <li
                                    v-for="r in inherited"
                                    :key="r.name"
                                    class="flex flex-wrap items-center gap-2 rounded-md bg-paper px-2 py-1.5 text-[12.5px] text-basalt-light"
                                >
                                    <span class="font-semibold">{{
                                        regionLabel(r.name)
                                    }}</span>
                                    <span class="font-mono text-[11px]">{{
                                        r.name
                                    }}</span>
                                    <span
                                        class="min-w-0 flex-1 font-mono text-[11px] break-words"
                                        >{{
                                            r.fill
                                                .map((f) => f.name ?? f.word)
                                                .join(' · ') || $t('leer')
                                        }}</span
                                    >
                                    <Button
                                        v-if="editable"
                                        variant="link"
                                        size="sm"
                                        @click="override(r.name)"
                                        >{{
                                            $t('Füllung überschreiben')
                                        }}</Button
                                    >
                                </li>
                            </ul>
                        </div>

                        <div class="space-y-2.5">
                            <section
                                v-for="(r, i) in layoutForm.regions"
                                :key="r.name"
                                class="rounded-lg border border-ash-light bg-white px-2.5 py-2"
                                :aria-label="
                                    $t('Region :name', {
                                        name: regionLabel(r.name),
                                    })
                                "
                            >
                                <div
                                    class="mb-1.5 flex flex-wrap items-center gap-x-2 gap-y-1"
                                >
                                    <span
                                        class="hex inline-block h-3 w-[13px] bg-basalt"
                                        aria-hidden="true"
                                    />
                                    <span class="text-[13px] font-semibold">{{
                                        regionLabel(r.name)
                                    }}</span>
                                    <span
                                        class="font-mono text-[11px] text-basalt-light"
                                        >{{ r.name }}</span
                                    >
                                    <StateTag v-if="r.where" tone="grey">{{
                                        r.where === 'before'
                                            ? $t('vor :target', {
                                                  target: r.target ?? '',
                                              })
                                            : $t('nach :target', {
                                                  target: r.target ?? '',
                                              })
                                    }}</StateTag>
                                    <label
                                        class="flex items-center gap-1 text-[11.5px] text-basalt-light"
                                        :title="
                                            $t(
                                                'Bleibt die Region leer, fällt sie ganz weg — samt Rahmen im Markup.',
                                            )
                                        "
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="r.optional"
                                            :disabled="!editable"
                                            @change="
                                                setRegion(i, {
                                                    ...r,
                                                    optional: (
                                                        $event.target as HTMLInputElement
                                                    ).checked,
                                                })
                                            "
                                        />
                                        {{ $t('freiwillig (optional)') }}
                                    </label>
                                    <span
                                        v-if="editable"
                                        class="ml-auto flex gap-0.5"
                                    >
                                        <button
                                            type="button"
                                            class="rounded px-1 text-[12px] text-basalt-light hover:bg-ash-light disabled:opacity-35"
                                            :disabled="i === 0"
                                            :aria-label="
                                                $t('Region :name nach oben', {
                                                    name: r.name,
                                                })
                                            "
                                            @click="
                                                layoutForm = {
                                                    ...layoutForm,
                                                    regions: moveItem(
                                                        layoutForm.regions,
                                                        i,
                                                        -1,
                                                    ),
                                                }
                                            "
                                        >
                                            ↑
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded px-1 text-[12px] text-basalt-light hover:bg-ash-light disabled:opacity-35"
                                            :disabled="
                                                i ===
                                                layoutForm.regions.length - 1
                                            "
                                            :aria-label="
                                                $t('Region :name nach unten', {
                                                    name: r.name,
                                                })
                                            "
                                            @click="
                                                layoutForm = {
                                                    ...layoutForm,
                                                    regions: moveItem(
                                                        layoutForm.regions,
                                                        i,
                                                        1,
                                                    ),
                                                }
                                            "
                                        >
                                            ↓
                                        </button>
                                        <button
                                            type="button"
                                            class="hover:text-danger rounded px-1 text-[12px] text-basalt-light hover:bg-ash-light"
                                            :aria-label="
                                                $t('Region :name entfernen', {
                                                    name: r.name,
                                                })
                                            "
                                            @click="setRegion(i, null)"
                                        >
                                            ✕
                                        </button>
                                    </span>
                                </div>
                                <p
                                    v-if="
                                        firstError(`regions.${i}.where`) ||
                                        firstError(`regions.${i}.name`)
                                    "
                                    class="text-danger mb-1 text-[12px] font-semibold"
                                    role="alert"
                                >
                                    {{
                                        firstError(`regions.${i}.where`) ||
                                        firstError(`regions.${i}.name`)
                                    }}
                                </p>
                                <FillEditor
                                    :model-value="r.fill"
                                    :region="r.name"
                                    :parts="parts"
                                    :sources="sources"
                                    :editable="editable"
                                    :label="
                                        $t('Region :name', {
                                            name: regionLabel(r.name),
                                        })
                                    "
                                    :empty-text="
                                        $t(
                                            'Leer im Layout — füllt ein Template die Region, steht es dort (Reiter Templates); sonst bleibt sie leer.',
                                        )
                                    "
                                    :error="firstError(`regions.${i}.fill`)"
                                    @update:model-value="
                                        setRegion(i, { ...r, fill: $event })
                                    "
                                    @part="
                                        router.visit(
                                            tabHref('parts', { teil: $event }),
                                        )
                                    "
                                />
                            </section>
                        </div>
                        <div
                            v-if="editable"
                            class="mt-2.5 flex flex-wrap items-center gap-2"
                        >
                            <Button
                                variant="secondary"
                                size="sm"
                                @click="regionOpen = true"
                                >{{ $t('+ Region') }}</Button
                            >
                            <span class="text-[11.5px] text-basalt-light">{{
                                $t(
                                    'Eigene Regionen bekommen einen Namen und einen Platz im Markup des Layouts.',
                                )
                            }}</span>
                        </div>
                        <div
                            v-if="editable"
                            class="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-ash-light pt-3"
                        >
                            <Button
                                variant="danger"
                                size="sm"
                                class="mr-auto"
                                @click="deleteOpen = true"
                                >{{ $t('Layout entfernen …') }}</Button
                            >
                            <AutosaveState
                                :status="layoutSave.status.value"
                                draft
                                :blocked="
                                    probeErrors.length > 0
                                        ? $t(
                                              'Nicht gespeichert — zuerst die Fehler beim Prüfen beheben.',
                                          )
                                        : ''
                                "
                            />
                        </div>
                    </Card>
                </section>

                <section
                    class="min-w-0 space-y-3"
                    :aria-label="$t('Code und Vorschau')"
                >
                    <div>
                        <p
                            class="mb-1 flex flex-wrap items-center gap-2 text-[12px] text-basalt-light"
                        >
                            <span class="font-mono">{{ layout.file }}</span>
                            <StateTag v-if="probeCode" tone="warning">{{
                                $t('ungespeichert')
                            }}</StateTag>
                            <span v-else>{{ $t('gespeicherter Stand') }}</span>
                        </p>
                        <CodeBlock
                            :code="
                                showMarkup
                                    ? (probeCode ?? layout.code)
                                    : collapseMarkup(probeCode ?? layout.code)
                                          .code
                            "
                            :start="
                                probeCode || !showMarkup ? null : layout.line
                            "
                        />
                        <button
                            v-if="collapseMarkup(layout.code).collapsed"
                            type="button"
                            class="mt-1 text-[12px] text-lava-dark underline"
                            @click="showMarkup = !showMarkup"
                        >
                            {{
                                showMarkup
                                    ? $t('Markup einklappen')
                                    : $t('Markup zeigen')
                            }}
                        </button>
                        <ul
                            v-if="probeFindings.length"
                            class="mt-2 space-y-1 text-[12.5px]"
                            :aria-label="$t('Befunde')"
                        >
                            <li
                                v-for="(f, i) in probeFindings"
                                :key="i"
                                class="flex gap-2"
                            >
                                <StateTag
                                    :tone="
                                        severityOf(f.severity) === 'error'
                                            ? 'danger'
                                            : 'warning'
                                    "
                                    >{{
                                        severityOf(f.severity) === 'error'
                                            ? $t('E')
                                            : $t('W')
                                    }}</StateTag
                                >
                                <span
                                    ><span class="font-mono"
                                        >{{ f.file }}:{{ f.line }}</span
                                    >
                                    {{ f.message }}</span
                                >
                            </li>
                        </ul>
                        <p
                            v-if="probeError"
                            class="text-danger mt-1 text-[12px]"
                            role="alert"
                        >
                            {{ probeError }}
                        </p>
                    </div>

                    <div
                        class="flex flex-col gap-1.5 rounded-lg border border-ash-light bg-paper p-2"
                        :aria-label="$t('Schematische Vorschau')"
                    >
                        <p
                            class="text-[10px] tracking-[0.1em] text-basalt-light uppercase"
                        >
                            {{ $t('Vorschau · :name', { name: layout.name }) }}
                        </p>
                        <div
                            v-for="r in schemeRegions.top"
                            :key="`t-${r.name}`"
                            class="rounded bg-basalt px-2 py-1.5 text-center font-mono text-[10.5px] break-words text-paper"
                        >
                            {{ schemeText(r) }}
                        </div>
                        <div
                            class="grid gap-1.5"
                            :class="
                                schemeRegions.aside
                                    ? 'grid-cols-[1fr_minmax(90px,30%)]'
                                    : 'grid-cols-1'
                            "
                        >
                            <div
                                v-if="schemeRegions.main"
                                class="flex min-h-16 items-center justify-center rounded border border-dashed border-ash bg-white px-2 text-center font-mono text-[10.5px] break-words"
                            >
                                {{ schemeText(schemeRegions.main) }}
                            </div>
                            <div
                                v-if="schemeRegions.aside"
                                class="flex min-h-16 items-center justify-center rounded border border-dashed border-ash bg-white px-2 text-center font-mono text-[10.5px] break-words"
                            >
                                {{ schemeText(schemeRegions.aside) }}
                            </div>
                        </div>
                        <div
                            v-for="r in schemeRegions.bottom"
                            :key="`b-${r.name}`"
                            class="rounded bg-basalt px-2 py-1.5 text-center font-mono text-[10.5px] break-words text-paper"
                        >
                            {{ schemeText(r) }}
                        </div>
                    </div>

                    <div
                        v-if="can_edit"
                        class="overflow-hidden rounded-lg border border-ash-light bg-white"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2 border-b border-ash-light px-3 py-1.5 text-[12px]"
                        >
                            <label class="flex items-center gap-1">
                                {{ $t('Vorschau mit Entwürfen') }}
                                <input
                                    v-model.lazy="previewPath"
                                    class="w-32 rounded border border-ash px-1 font-mono text-[12px]"
                                    :aria-label="
                                        $t('Pfad der Seite in der Vorschau')
                                    "
                                />
                            </label>
                            <a
                                :href="previewUrl"
                                target="_blank"
                                rel="noopener"
                                class="underline"
                                >{{ $t('im neuen Tab') }}</a
                            >
                        </div>
                        <iframe
                            :key="previewKey"
                            :src="previewUrl"
                            :title="
                                $t('Vorschau der Seite mit den Theme-Entwürfen')
                            "
                            class="h-[420px] w-full"
                            loading="lazy"
                        />
                        <p
                            class="border-t border-ash-light px-3 py-1.5 text-[11.5px] text-basalt-light"
                        >
                            {{
                                $t(
                                    'Zeigt den gespeicherten Entwurf — nach «Als Entwurf speichern» neu geladen.',
                                )
                            }}
                        </p>
                    </div>

                    <div>
                        <p
                            class="mb-1 text-[10px] tracking-[0.1em] text-basalt-light uppercase"
                        >
                            {{ $t('Template je Typ × Layout (G)') }}
                        </p>
                        <ul
                            class="divide-y divide-ash-light rounded-lg border border-ash-light bg-white text-[12.5px]"
                        >
                            <li
                                v-for="t in templates"
                                :key="t.name"
                                class="flex flex-wrap gap-x-3 gap-y-0.5 px-3 py-1.5"
                            >
                                <span class="w-24 font-mono text-[12px]">{{
                                    t.name
                                }}</span>
                                <span class="w-28">{{
                                    $t('Typ :type', { type: t.for ?? '' })
                                }}</span>
                                <span
                                    class="font-mono text-[12px]"
                                    :class="
                                        t.layout === layout.name
                                            ? 'font-semibold text-lava-dark'
                                            : ''
                                    "
                                    >{{ t.layout }}</span
                                >
                                <span class="ml-auto text-basalt-light">{{
                                    $tc(
                                        ':count Inhalt|:count Inhalte',
                                        t.used_by,
                                    )
                                }}</span>
                            </li>
                        </ul>
                    </div>
                </section>
            </div>
        </template>
    </template>

    <!-- ── Lava-Teile (Station C) ── -->
    <template v-else-if="tab === 'parts'">
        <div class="grid gap-4 lg:grid-cols-2">
            <section class="min-w-0" :aria-label="$t('Registrierte Teile')">
                <ul
                    class="divide-y divide-ash-light rounded-lg border border-ash-light bg-white"
                >
                    <li
                        v-for="k in part_kinds"
                        :key="k.name"
                        class="px-3 py-2"
                        :class="k.enabled ? '' : 'bg-paper'"
                    >
                        <div
                            class="flex flex-wrap items-center gap-x-2 gap-y-1"
                        >
                            <span
                                class="hex inline-block h-3 w-[13px]"
                                :class="k.enabled ? 'bg-basalt' : 'bg-ash'"
                                aria-hidden="true"
                            />
                            <span
                                class="text-[13px] font-semibold"
                                :class="k.enabled ? '' : 'text-basalt-light'"
                                >{{ k.label }}</span
                            >
                            <span
                                class="font-mono text-[11px] text-basalt-light"
                                >{{ k.name }} · {{ k.origin }}</span
                            >
                            <span
                                class="ml-auto font-mono text-[11px] text-basalt-light"
                                >{{ k.instances }}</span
                            >
                            <Button
                                v-if="editable && k.enabled"
                                variant="link"
                                size="sm"
                                @click="startInstance(k.name)"
                                >{{ $t('+ Instanz') }}</Button
                            >
                        </div>
                        <p
                            v-if="!k.enabled"
                            class="mt-0.5 text-[11.5px] text-basalt-light"
                        >
                            {{
                                $t(
                                    ':module ist für diese Site aus — Instanzen bleiben, ausgegeben wird nichts.',
                                    { module: k.origin },
                                )
                            }}
                        </p>
                        <ul
                            v-if="instancesOf(k.name).length"
                            class="mt-1 flex flex-wrap gap-1.5"
                        >
                            <li v-for="p in instancesOf(k.name)" :key="p.name">
                                <button
                                    type="button"
                                    class="rounded border px-2 py-0.5 font-mono text-[11.5px]"
                                    :class="
                                        p.name === selectedPart && !creatingKind
                                            ? 'border-lava-dark bg-lava/10 text-lava-dark'
                                            : 'border-ash-light bg-white'
                                    "
                                    :aria-pressed="
                                        p.name === selectedPart && !creatingKind
                                    "
                                    @click="selectPart(p.name)"
                                >
                                    {{ p.name }}
                                </button>
                            </li>
                        </ul>
                    </li>
                </ul>
                <p class="mt-2 text-[11.5px] text-basalt-light">
                    {{
                        $t(
                            ':count registrierte Teile aus Kern und Modulen · Instanzen mit eigenen Einstellungen in Regionen — und als Block in Seiten («+ Lava-Teil» im Editor).',
                            { count: part_kinds.length },
                        )
                    }}
                </p>
            </section>

            <section
                class="min-w-0 space-y-3"
                :aria-label="$t('Einstellungen der Instanz')"
            >
                <Card
                    v-if="partForm"
                    :title="
                        part
                            ? $t('Lava-Teil «:name»', { name: part.name })
                            : $t('Neue Instanz · :kind', {
                                  kind: partKind?.label ?? partForm.kind,
                              })
                    "
                    :sub="
                        part
                            ? `${part.file}:${part.line} · ${part.used_in.join(' · ') || $t('in keiner Region')}`
                            : $t(
                                  'erscheint nach dem Speichern in der Auswahl der Regionen',
                              )
                    "
                >
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label
                            class="block text-[12px] font-semibold tracking-wide text-basalt-light uppercase"
                        >
                            {{ $t('Teil') }}
                            <input
                                :value="`${partKind?.label ?? partForm.kind} (${partForm.kind}) · ${partKind?.origin ?? ''}`"
                                disabled
                                :class="[sel, 'mt-1 block w-full normal-case']"
                            />
                        </label>
                        <label
                            class="block text-[12px] font-semibold tracking-wide text-basalt-light uppercase"
                        >
                            {{ $t('Kennung') }}
                            <input
                                v-if="part"
                                v-model="partNameDraft"
                                :disabled="!editable"
                                :class="[
                                    sel,
                                    'mt-1 block w-full font-mono normal-case',
                                ]"
                                @blur="commitPartName"
                                @keydown.enter.prevent="commitPartName"
                                @keydown.esc="partNameDraft = partForm.name"
                            />
                            <input
                                v-else
                                :value="partForm.name"
                                :disabled="!editable"
                                :class="[
                                    sel,
                                    'mt-1 block w-full font-mono normal-case',
                                ]"
                                @input="
                                    partNameTouched = true;
                                    partForm = {
                                        ...partForm,
                                        name: (
                                            $event.target as HTMLInputElement
                                        ).value,
                                    };
                                "
                            />
                            <span
                                class="mt-0.5 block text-[11px] font-normal normal-case"
                                >{{
                                    creatingKind && !partNameTouched
                                        ? $t(
                                              'Entsteht aus Art und Quelle · a–z, 0–9, Unterstrich · eindeutig je Theme',
                                          )
                                        : $t(
                                              'a–z, 0–9, Unterstrich · eindeutig je Theme',
                                          )
                                }}</span
                            >
                            <span
                                v-if="errors.name"
                                class="text-danger block text-[12px] normal-case"
                                >{{ errors.name }}</span
                            >
                        </label>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <template
                            v-for="(setting, si) in orderedSettings"
                            :key="setting"
                        >
                            <button
                                v-if="
                                    si === primaryCount &&
                                    si < orderedSettings.length
                                "
                                type="button"
                                class="flex w-full items-center gap-1.5 rounded-md border border-dashed border-ash-light px-3 py-2 text-left text-[12.5px] font-semibold text-basalt-light hover:border-ash hover:text-basalt sm:col-span-2"
                                :aria-expanded="showPartMore"
                                @click="partMoreOpen = !showPartMore"
                            >
                                <span aria-hidden="true">{{
                                    showPartMore ? '−' : '+'
                                }}</span>
                                {{
                                    showPartMore
                                        ? $t('Weniger zeigen')
                                        : $t('Weitere Möglichkeiten (:list)', {
                                              list: orderedSettings
                                                  .slice(primaryCount)
                                                  .map((x) => settingLabel(x))
                                                  .join(', '),
                                          })
                                }}
                            </button>
                            <label
                                v-if="
                                    settingInput(
                                        partForm.kind,
                                        setting,
                                        sources,
                                    ).type !== 'list'
                                "
                                v-show="si < primaryCount || showPartMore"
                                class="block text-[12px] font-semibold tracking-wide text-basalt-light uppercase"
                            >
                                {{ settingLabel(setting) }}
                                <select
                                    v-if="
                                        settingInput(
                                            partForm.kind,
                                            setting,
                                            sources,
                                        ).type === 'select'
                                    "
                                    :value="partForm.settings[setting] ?? ''"
                                    :disabled="!editable"
                                    :class="[
                                        sel,
                                        'mt-1 block w-full font-mono normal-case',
                                    ]"
                                    @change="
                                        setSetting(
                                            setting,
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        )
                                    "
                                >
                                    <option value="">—</option>
                                    <option
                                        v-for="o in optionsOf(
                                            partForm.kind,
                                            setting,
                                        )"
                                        :key="o"
                                        :value="o"
                                    >
                                        {{ o }}
                                    </option>
                                </select>
                                <input
                                    v-else
                                    :value="partForm.settings[setting] ?? ''"
                                    :type="
                                        settingInput(
                                            partForm.kind,
                                            setting,
                                            sources,
                                        ).type === 'number'
                                            ? 'number'
                                            : 'text'
                                    "
                                    :min="
                                        settingInput(
                                            partForm.kind,
                                            setting,
                                            sources,
                                        ).type === 'number'
                                            ? 1
                                            : undefined
                                    "
                                    :disabled="!editable"
                                    :class="[
                                        sel,
                                        'mt-1 block w-full normal-case',
                                    ]"
                                    @input="
                                        setSetting(
                                            setting,
                                            ($event.target as HTMLInputElement)
                                                .value,
                                        )
                                    "
                                />
                                <span
                                    v-if="settingHelp(setting)"
                                    class="mt-0.5 block text-[11px] font-normal tracking-normal text-basalt-light normal-case"
                                    >{{ settingHelp(setting) }}</span
                                >
                                <span
                                    v-if="errors[`settings.${setting}`]"
                                    class="text-danger block text-[12px] normal-case"
                                    >{{ errors[`settings.${setting}`] }}</span
                                >
                            </label>
                            <fieldset
                                v-else
                                v-show="si < primaryCount || showPartMore"
                                class="sm:col-span-2"
                            >
                                <legend
                                    class="text-[12px] font-semibold tracking-wide text-basalt-light uppercase"
                                >
                                    {{ settingLabel(setting) }}
                                </legend>
                                <p
                                    v-if="settingHelp(setting)"
                                    class="text-[11px] text-basalt-light"
                                >
                                    {{ settingHelp(setting) }}
                                </p>
                                <div
                                    class="mt-1 flex flex-wrap gap-x-3 gap-y-1"
                                >
                                    <label
                                        v-for="o in optionsOf(
                                            partForm.kind,
                                            setting,
                                        )"
                                        :key="o"
                                        class="flex items-center gap-1 font-mono text-[12px]"
                                    >
                                        <input
                                            type="checkbox"
                                            :disabled="!editable"
                                            :checked="
                                                (
                                                    partForm.settings[
                                                        setting
                                                    ] ?? ''
                                                )
                                                    .split(',')
                                                    .map((v) => v.trim())
                                                    .includes(o)
                                            "
                                            @change="
                                                toggleListValue(setting, o)
                                            "
                                        />
                                        {{ o }}
                                    </label>
                                </div>
                            </fieldset>
                            <label
                                v-for="l in setting === 'label'
                                    ? sources.locales
                                    : []"
                                v-show="showPartMore"
                                :key="`${setting}.${l}`"
                                class="block text-[12px] font-semibold tracking-wide text-basalt-light uppercase"
                            >
                                {{ settingLabel(setting) }} ·
                                {{ l.toUpperCase() }}
                                <input
                                    :value="
                                        partForm.settings[`${setting}.${l}`] ??
                                        ''
                                    "
                                    :disabled="!editable"
                                    :class="[
                                        sel,
                                        'mt-1 block w-full normal-case',
                                    ]"
                                    @input="
                                        setSetting(
                                            `${setting}.${l}`,
                                            ($event.target as HTMLInputElement)
                                                .value,
                                        )
                                    "
                                />
                            </label>
                        </template>
                    </div>
                    <p
                        v-if="
                            (partKind?.settings ?? []).length === 0 &&
                            partForm.kind !== 'html'
                        "
                        class="mt-3 text-[12.5px] text-basalt-light"
                    >
                        {{ $t('Dieser Teil hat keine Einstellungen.') }}
                    </p>
                    <label
                        v-if="partForm.kind === 'html'"
                        class="mt-3 block text-[12px] font-semibold tracking-wide text-basalt-light uppercase"
                    >
                        {{ $t('Markup (Lava)') }}
                        <textarea
                            :value="partForm.markup ?? ''"
                            rows="6"
                            :disabled="!editable"
                            class="mt-1 block w-full rounded-md border border-ash bg-white px-2 py-1.5 font-mono text-[12px] normal-case"
                            @input="
                                partForm = {
                                    ...partForm,
                                    markup: (
                                        $event.target as HTMLTextAreaElement
                                    ).value,
                                }
                            "
                        />
                        <span
                            class="mt-0.5 block text-[11px] font-normal normal-case"
                            >{{
                                $t(
                                    'Wie jedes Markup des Themes: HTML mit Lava (z. B.',
                                )
                            }}
                            <span
                                class="font-mono"
                                v-text="'{{ site.name }}'"
                            />).</span
                        >
                    </label>
                    <div
                        v-if="editable"
                        class="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-ash-light pt-3"
                    >
                        <Button
                            v-if="part"
                            variant="danger"
                            size="sm"
                            class="mr-auto"
                            @click="removePartOpen = true"
                            >{{ $t('Entfernen …') }}</Button
                        >
                        <Button
                            v-if="!part"
                            variant="secondary"
                            size="sm"
                            class="mr-auto"
                            @click="
                                creatingKind = null;
                                selectedPart = parts[0]?.name ?? null;
                            "
                            >{{ $t('Abbrechen') }}</Button
                        >
                        <AutosaveState
                            v-if="part"
                            :status="partSave.status.value"
                            draft
                            :blocked="
                                probeErrors.length > 0
                                    ? $t(
                                          'Nicht gespeichert — zuerst die Fehler beim Prüfen beheben.',
                                      )
                                    : !isValidName(partForm.name)
                                      ? $t(
                                            'Nicht gespeichert — die Kennung ist ungültig.',
                                        )
                                      : ''
                            "
                        />
                        <Button
                            v-else
                            size="sm"
                            :disabled="
                                !partDirty ||
                                !isValidName(partForm.name) ||
                                probeErrors.length > 0
                            "
                            @click="savePart"
                            >{{ $t('Anlegen (Entwurf)') }}</Button
                        >
                    </div>
                </Card>
                <div v-else class="rounded-lg border border-ash-light bg-white">
                    <EmptyState
                        :title="$t('Noch keine Instanz')"
                        :text="$t('Links bei einer Art «+ Instanz» wählen.')"
                        icon="theme"
                        tone="ash"
                    />
                </div>
                <div v-if="partForm">
                    <p
                        class="mb-1 flex items-center gap-2 text-[12px] text-basalt-light"
                    >
                        {{
                            $t(
                                'Code der Instanz — dieselbe Quelle wie das Formular',
                            )
                        }}
                        <StateTag v-if="probeCode" tone="warning">{{
                            $t('ungespeichert')
                        }}</StateTag>
                    </p>
                    <CodeBlock
                        :code="probeCode ?? part?.code ?? '…'"
                        :start="probeCode ? null : (part?.line ?? null)"
                    />
                    <ul
                        v-if="probeFindings.length"
                        class="mt-2 space-y-1 text-[12.5px]"
                        :aria-label="$t('Befunde')"
                    >
                        <li
                            v-for="(f, i) in probeFindings"
                            :key="i"
                            class="flex gap-2"
                        >
                            <StateTag
                                :tone="
                                    severityOf(f.severity) === 'error'
                                        ? 'danger'
                                        : 'warning'
                                "
                                >{{
                                    severityOf(f.severity) === 'error'
                                        ? $t('E')
                                        : $t('W')
                                }}</StateTag
                            >
                            <span
                                ><span class="font-mono"
                                    >{{ f.file }}:{{ f.line }}</span
                                >
                                {{ f.message }}</span
                            >
                        </li>
                    </ul>
                    <p class="mt-1 text-[11.5px] text-basalt-light">
                        {{
                            $t(
                                'Ein Teil eines ausgeschalteten Moduls steht grau und sagt, welches Modul fehlt.',
                            )
                        }}
                    </p>
                </div>
            </section>
        </div>
    </template>

    <!-- ── Templates (Station G) ── -->
    <template v-else>
        <ul
            class="divide-y divide-ash-light rounded-lg border border-ash-light bg-white"
        >
            <li v-for="t in templates" :key="t.name" class="px-3 py-2">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="w-28 font-mono text-[12.5px] font-semibold">{{
                        t.name
                    }}</span>
                    <span class="w-36 text-[12.5px]">{{
                        $t('für «:type»', { type: t.for_label ?? t.for ?? '' })
                    }}</span>
                    <span class="font-mono text-[12px]">{{ t.layout }}</span>
                    <StateTag v-if="t.noindex" tone="grey">noindex</StateTag>
                    <span class="text-[12px] text-basalt-light">{{
                        $tc(':count Inhalt|:count Inhalte', t.used_by)
                    }}</span>
                    <Button
                        variant="link"
                        size="sm"
                        class="ml-auto"
                        :aria-expanded="openTemplate === t.name"
                        @click="editTemplate(t)"
                        >{{
                            openTemplate === t.name
                                ? $t('Schliessen')
                                : editable
                                  ? $t('Bearbeiten')
                                  : $t('Ansehen')
                        }}</Button
                    >
                </div>
                <div
                    v-if="openTemplate === t.name && templateForm"
                    class="mt-2 grid gap-4 lg:grid-cols-2"
                >
                    <div class="min-w-0 space-y-2">
                        <div class="flex flex-wrap items-end gap-3">
                            <p class="w-full text-[12.5px] text-basalt-light">
                                {{
                                    $t(
                                        'Inhalte vom Typ «:type» mit diesem Template stehen im gewählten Layout; jede Region zeigt, was das Layout dort hat, oder eine eigene Füllung.',
                                        { type: t.for_label ?? t.for ?? '' },
                                    )
                                }}
                            </p>
                            <label
                                class="block text-[12px] font-semibold tracking-wide text-basalt-light uppercase"
                            >
                                {{ $t('Layout') }}
                                <select
                                    v-model="templateForm.layout"
                                    :disabled="!editable"
                                    :class="[
                                        sel,
                                        'mt-1 block font-mono normal-case',
                                    ]"
                                >
                                    <option
                                        v-for="l in sources.layout"
                                        :key="l"
                                        :value="l"
                                    >
                                        {{ l }}
                                    </option>
                                </select>
                            </label>
                            <span
                                class="flex items-center gap-2 pb-1 text-[12.5px]"
                            >
                                <Toggle
                                    v-model="templateForm.noindex"
                                    :disabled="!editable"
                                    :label="
                                        $t('nicht in Suchmaschinen (noindex)')
                                    "
                                />
                                {{ $t('nicht in Suchmaschinen (noindex)') }}
                            </span>
                        </div>
                        <p class="text-[11.5px] text-basalt-light">
                            {{
                                templateForm.noindex
                                    ? $t(
                                          'Seiten mit diesem Template erscheinen nicht bei Google & Co. und fehlen in der Sitemap.',
                                      )
                                    : $t(
                                          'Seiten mit diesem Template dürfen in Suchmaschinen erscheinen.',
                                      )
                            }}
                            <button
                                v-if="noindexSuggested(t) && editable"
                                type="button"
                                class="font-semibold text-lava-dark underline"
                                @click="templateForm.noindex = true"
                            >
                                {{
                                    $t(
                                        'Für Rechts-, Danke- und Fehlerseiten üblich: noindex einschalten',
                                    )
                                }}
                            </button>
                        </p>
                        <section
                            v-for="name in templateLayoutRegions"
                            :key="name"
                            class="rounded-lg border border-ash-light px-2.5 py-2"
                            :aria-label="
                                $t('Region :name', { name: regionLabel(name) })
                            "
                        >
                            <div class="mb-1 flex flex-wrap items-center gap-2">
                                <span class="text-[13px] font-semibold">{{
                                    regionLabel(name)
                                }}</span>
                                <span
                                    class="font-mono text-[11px] text-basalt-light"
                                    >{{ name }}</span
                                >
                                <span
                                    class="ml-auto inline-flex rounded-md border border-ash text-[11.5px]"
                                    role="group"
                                    :aria-label="
                                        $t('Füllung von :region', {
                                            region: regionLabel(name),
                                        })
                                    "
                                >
                                    <button
                                        type="button"
                                        class="rounded-l-md px-2 py-0.5"
                                        :class="
                                            templateRegion(name) === null
                                                ? 'bg-basalt text-paper'
                                                : 'text-basalt-light hover:text-basalt'
                                        "
                                        :aria-pressed="
                                            templateRegion(name) === null
                                        "
                                        :disabled="!editable"
                                        @click="regionMode(name, false)"
                                    >
                                        {{ $t('wie im Layout') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-r-md px-2 py-0.5"
                                        :class="
                                            templateRegion(name) !== null
                                                ? 'bg-lava-dark text-white'
                                                : 'text-basalt-light hover:text-basalt'
                                        "
                                        :aria-pressed="
                                            templateRegion(name) !== null
                                        "
                                        :disabled="!editable"
                                        @click="regionMode(name, true)"
                                    >
                                        {{ $t('eigene Füllung') }}
                                    </button>
                                </span>
                            </div>
                            <FillEditor
                                v-if="templateRegion(name)"
                                :model-value="templateRegion(name)?.fill ?? []"
                                :region="name"
                                :parts="parts"
                                :sources="sources"
                                :editable="editable"
                                :label="
                                    $t('Template :template Region :region', {
                                        template: t.name,
                                        region: name,
                                    })
                                "
                                @update:model-value="
                                    setTemplateRegion(name, $event)
                                "
                                @part="
                                    router.visit(
                                        tabHref('parts', { teil: $event }),
                                    )
                                "
                            />
                            <p v-else class="text-[12px] text-basalt-light">
                                {{ $t('wie im Layout:') }}
                                <span class="font-mono">{{
                                    layoutFillOf(templateForm.layout, name)
                                        .map((f) => f.name ?? f.word)
                                        .join(' · ') || $t('leer')
                                }}</span>
                            </p>
                        </section>
                        <p
                            v-if="firstError('regions') || errors.layout"
                            class="text-danger text-[12px] font-semibold"
                            role="alert"
                        >
                            {{ firstError('regions') || errors.layout }}
                        </p>
                        <div
                            v-if="editable"
                            class="flex justify-end gap-2 border-t border-ash-light pt-2"
                        >
                            <AutosaveState
                                :status="templateSave.status.value"
                                draft
                            />
                        </div>
                    </div>
                    <div class="min-w-0 space-y-2">
                        <div
                            v-if="can_edit && t.example"
                            class="overflow-hidden rounded-lg border border-ash-light bg-white"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2 border-b border-ash-light px-3 py-1.5 text-[12px]"
                            >
                                <span
                                    >{{ $t('Vorschau mit Entwürfen') }} ·
                                    <span class="font-mono">{{
                                        t.example
                                    }}</span></span
                                >
                                <a
                                    :href="templatePreviewUrl(t)"
                                    target="_blank"
                                    rel="noopener"
                                    class="underline"
                                    >{{ $t('im neuen Tab') }}</a
                                >
                            </div>
                            <iframe
                                :key="templatePreviewKey"
                                :src="templatePreviewUrl(t)"
                                :title="
                                    $t('Eine Seite mit dem Template «:name»', {
                                        name: t.name,
                                    })
                                "
                                class="h-[360px] w-full"
                                loading="lazy"
                            />
                            <p
                                class="border-t border-ash-light px-3 py-1.5 text-[11.5px] text-basalt-light"
                            >
                                {{
                                    $t(
                                        'Eine Seite mit diesem Template — nach «Als Entwurf speichern» neu geladen.',
                                    )
                                }}
                            </p>
                        </div>
                        <CodeBlock :code="collapseMarkup(t.code).code" />
                    </div>
                </div>
            </li>
        </ul>
        <p class="mt-2 text-[11.5px] text-basalt-light">
            {{
                $t(
                    'Ein Typ kann mehrere Templates haben; die Seite wählt eines (Seiteneinstellungen › Layout). Das Markup eines Templates bleibt Code (Lava-Editor).',
                )
            }}
        </p>
    </template>

    <Modal
        :open="regionOpen"
        :title="$t('Neue Region')"
        :subtitle="$t('Name und Platz im Markup des Layouts')"
        :wide="false"
        @close="regionOpen = false"
    >
        <form class="space-y-3" @submit.prevent="addRegion">
            <label class="block text-sm">
                {{ $t('Name') }}
                <input
                    v-model="newRegion.name"
                    class="mt-1 w-full rounded border border-ash px-2 py-1 font-mono"
                    placeholder="banner"
                />
                <span class="mt-0.5 block text-[11.5px] text-basalt-light">{{
                    $t(
                        'Kleinbuchstaben, Ziffern, Binde- und Unterstrich — so heisst die Region in Lava und in Templates.',
                    )
                }}</span>
            </label>
            <div
                v-if="regionChoices.length > 0"
                class="flex flex-wrap items-center gap-1.5 text-[12px]"
            >
                <span class="text-basalt-light">{{ $t('Häufig:') }}</span>
                <button
                    v-for="c in regionChoices"
                    :key="c.name"
                    type="button"
                    class="rounded-full border px-2 py-0.5"
                    :class="
                        newRegion.name === c.name
                            ? 'border-lava-dark bg-lava/10 text-lava-dark'
                            : 'border-ash-light hover:border-lava'
                    "
                    @click="chooseRegion(c)"
                >
                    {{ regionLabel(c.name) }}
                    <span class="font-mono text-[11px] text-basalt-light">{{
                        c.name
                    }}</span>
                </button>
            </div>
            <p
                v-if="newRegion.name && !needsPlace"
                class="text-[12.5px] text-basalt-light"
            >
                {{
                    $t(
                        'Das Markup setzt «:name» schon ein — kein Platz nötig.',
                        {
                            name: newRegion.name,
                        },
                    )
                }}
            </p>
            <div v-else class="flex flex-wrap gap-2">
                <label class="text-sm">
                    {{ $t('Platz') }}
                    <select
                        v-model="newRegion.where"
                        class="mt-1 block rounded border border-ash px-2 py-1"
                    >
                        <option value="before">{{ $t('davor') }}</option>
                        <option value="after">{{ $t('danach') }}</option>
                    </select>
                </label>
                <label class="text-sm">
                    {{ $t('Region') }}
                    <select
                        v-model="newRegion.target"
                        class="mt-1 block rounded border border-ash px-2 py-1 font-mono"
                    >
                        <option value="">—</option>
                        <option v-for="n in allRegionNames" :key="n" :value="n">
                            {{ n }}
                        </option>
                    </select>
                </label>
            </div>
            <p
                v-if="newRegion.name && !newRegionValid"
                class="text-danger text-[12.5px]"
            >
                {{
                    $t(
                        'Kleinbuchstaben, Ziffern, Binde- und Unterstrich; noch nicht vorhanden; mit Platz.',
                    )
                }}
            </p>
            <div class="flex gap-2">
                <Button type="submit" :disabled="!newRegionValid">{{
                    $t('Region hinzufügen')
                }}</Button>
                <Button variant="secondary" @click="regionOpen = false">{{
                    $t('Abbrechen')
                }}</Button>
            </div>
        </form>
    </Modal>

    <Modal
        :open="newLayoutOpen"
        :title="$t('Neues Layout')"
        :subtitle="$t('Erweitert ein bestehendes — das Markup bleibt Code')"
        :wide="false"
        @close="newLayoutOpen = false"
    >
        <form class="space-y-3" @submit.prevent="createLayout">
            <label class="block text-sm">
                {{ $t('Name') }}
                <input
                    v-model="newLayout.name"
                    class="mt-1 w-full rounded border border-ash px-2 py-1 font-mono"
                    placeholder="schmal"
                />
                <span class="mt-0.5 block text-[11.5px] text-basalt-light">{{
                    $t(
                        'a–z, 0–9, «_» — unter diesem Namen wählen es Templates und die Seiteneinstellungen.',
                    )
                }}</span>
            </label>
            <div
                v-if="layoutChoices.length > 0"
                class="flex flex-wrap items-center gap-1.5 text-[12px]"
            >
                <span class="text-basalt-light">{{ $t('Zum Beispiel:') }}</span>
                <button
                    v-for="n in layoutChoices"
                    :key="n"
                    type="button"
                    class="rounded-full border px-2 py-0.5 font-mono"
                    :class="
                        newLayout.name === n
                            ? 'border-lava-dark bg-lava/10 text-lava-dark'
                            : 'border-ash-light hover:border-lava'
                    "
                    @click="newLayout.name = n"
                >
                    {{ n }}
                </button>
            </div>
            <label class="block text-sm">
                {{ $t('Erbt von') }}
                <select
                    v-model="newLayout.extends"
                    class="mt-1 block w-full rounded border border-ash px-2 py-1 font-mono"
                >
                    <option v-for="l in layouts" :key="l.name" :value="l.name">
                        {{ l.name }}
                    </option>
                </select>
                <span class="mt-0.5 block text-[11.5px] text-basalt-light">{{
                    baseRegions.length > 0
                        ? $t(
                              'Übernimmt Markup und Regionen (:regions) — danach füllen Sie Regionen anders oder fügen eigene hinzu.',
                              { regions: baseRegions.join(' · ') },
                          )
                        : $t(
                              'Übernimmt Markup und Regionen — danach füllen Sie Regionen anders oder fügen eigene hinzu.',
                          )
                }}</span>
            </label>
            <p v-if="errors.name" class="text-danger text-[12.5px]">
                {{ errors.name }}
            </p>
            <div class="flex gap-2">
                <Button
                    type="submit"
                    :disabled="!isValidName(newLayout.name)"
                    >{{ $t('Anlegen (Entwurf)') }}</Button
                >
                <Button variant="secondary" @click="newLayoutOpen = false">{{
                    $t('Abbrechen')
                }}</Button>
            </div>
        </form>
    </Modal>

    <Modal
        :open="deleteOpen"
        :title="$t('Layout «:name» entfernen?', { name: layout?.name ?? '' })"
        :wide="false"
        @close="deleteOpen = false"
    >
        <p class="text-sm">
            {{
                $t(
                    'Als Entwurf — ein Template, das es noch nennt, sperrt das Speichern (der Compiler meldet es).',
                )
            }}
        </p>
        <div class="mt-4 flex gap-2">
            <Button variant="danger" @click="deleteLayout">{{
                $t('Entfernen')
            }}</Button>
            <Button variant="secondary" @click="deleteOpen = false">{{
                $t('Abbrechen')
            }}</Button>
        </div>
    </Modal>

    <Modal
        :open="removePartOpen"
        :title="$t('Lava-Teil «:name» entfernen?', { name: part?.name ?? '' })"
        :wide="false"
        @close="removePartOpen = false"
    >
        <p class="text-sm">
            {{
                part?.used_in.length
                    ? $t(
                          'Er steht in: :regions — zuerst dort herausnehmen, sonst meldet der Compiler einen Fehler.',
                          { regions: part.used_in.join(', ') },
                      )
                    : $t('Er steht in keiner Region.')
            }}
        </p>
        <div class="mt-4 flex gap-2">
            <Button variant="danger" @click="removePart">{{
                $t('Entfernen')
            }}</Button>
            <Button variant="secondary" @click="removePartOpen = false">{{
                $t('Abbrechen')
            }}</Button>
        </div>
    </Modal>
</template>
