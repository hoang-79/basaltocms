<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { computed, onMounted, ref, shallowRef, watch } from 'vue';
import { useAutosave } from '../../components/autosave';
import BlockPreview from '../../components/BlockPreview.vue';
import FieldPalette from '../../components/FieldPalette.vue';
import { presetField } from '../../components/formPalette';
import type { PaletteItem } from '../../components/formPalette';
import { usePreviewOpen } from '../../components/guidedFields';
import { handleFrom } from '../../components/layoutEditor';
import Button from '../../components/Button.vue';
import Card from '../../components/Card.vue';
import { labelClasses } from '../../components/control';
import Field from '../../components/Field.vue';
import {
    cleanOptions,
    duplicateField,
    errorsUnder,
    fieldNames,
    fieldSummary,
    fromBuilderData,
    handlePattern,
    lengthTypes,
    linesToOptions,
    moveItem,
    namesValid,
    newField,
    noInputTypes,
    normalizeForm,
    optionsToLines,
    optionTypes,
    placeholderTypes,
    rangeTypes,
    textKey,
    toBuilderData,
    uniqueName,
} from '../../components/forms';
import Hex from '../../components/Hex.vue';
import Notice from '../../components/Notice.vue';
import PageHeader from '../../components/PageHeader.vue';
import StateTag from '../../components/StateTag.vue';
import Tabs from '../../components/Tabs.vue';
import Toggle from '../../components/Toggle.vue';
import { t, tc } from '../../i18n';
import type { BackendProps, TabDef } from '../../types';
import type {
    FormBuilderData,
    FormFieldInput,
    FormPageProps,
    FormSubField,
} from '../../types-forms';

/**
 * Formular-Baukasten (Bereich 29, M-11): vier Reiter — Felder (mit Feld-Einstellungen und der Lava-Code-Ansicht
 * daneben), Aktionen, Spam und Aufbewahrung, Einbindung. Der Server schreibt die Eingabe über den Lava-Drucker nach
 * `forms.lava`; die Code-Ansicht zeigt das Ergebnis. «Neues Formular» legt mit Knopf an, danach speichert sich jede
 * Änderung selbst (Autosave) — Kennung und Feldnamen erst beim Verlassen des Feldes (jeder Zwischenstand wäre eine
 * Umbenennung). Theme-Formulare (Station H) sind nur zu lesen. Fehler kommen je Pfad vom Server und stehen am Feld.
 */
const props = defineProps<FormPageProps>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);

const defaultCode =
    props.locales.find((l) => l.default)?.code ?? props.locales[0]?.code ?? '';
const isNew = props.meta === null;
const readOnly = computed(() => props.meta?.source === 'theme');

const form = useForm<FormBuilderData>(
    toBuilderData(normalizeForm(props.form, defaultCode)),
).transform((data) => fromBuilderData(data as FormBuilderData));

const errors = computed(() => form.errors as unknown as Record<string, string>);

/** Der Titel in der Grundsprache, sonst die Kennung. */
const label = computed(() => form.labels['*'] || form.handle || '');
const title = computed(() =>
    isNew ? t('Neues Formular') : t('Formular «:form»', { form: label.value }),
);

const typeLabels = computed(
    () => new Map(props.field_types.map((t) => [t.value, t.label])),
);
function typeLabel(type: string): string {
    return typeLabels.value.get(type) ?? type;
}

/** Felder mit Eingabe (Gruppen aufgelöst, ohne Erklärungen) — so zählt auch die Liste der Formulare. */
const inputCount = computed(
    () =>
        form.fields
            .flatMap((f) => (f.type === 'group' ? f.fields : [f]))
            .filter((f) => !noInputTypes.includes(f.type)).length,
);

const lead = computed(() => {
    if (isNew) {
        return t(
            'Neu — der Baukasten schreibt es als Lava in forms.lava der Site.',
        );
    }
    const meta = props.meta;
    const source =
        meta?.source === 'theme'
            ? `${t('Theme')} · form ${form.handle} · ${meta.location}`
            : `Site · ${t('Baukasten')}`;

    return `${source} · ${tc(':count Feld|:count Felder', inputCount.value)} · ${tc(':count Eingang|:count Eingänge', meta?.submissions ?? 0)}`;
});

/* ── Reiter ── */

function countErrors(prefixes: string[]): number {
    return Object.keys(errors.value).filter((k) =>
        prefixes.some((p) => k === p || k.startsWith(`${p}.`)),
    ).length;
}

const tabs = computed<TabDef[]>(() => [
    {
        key: 'fields',
        label: t('Felder'),
        count: inputCount.value,
        errors: countErrors(['handle', 'labels', 'fields']),
    },
    {
        key: 'actions',
        label: t('Aktionen'),
        errors: countErrors([
            'to',
            'subject',
            'redirect',
            'webhook',
            'thanks',
            'submit',
            'submit_label',
            'store',
        ]),
    },
    {
        key: 'spam',
        label: t('Spam und Aufbewahrung'),
        errors: countErrors(['retain', 'consent']),
    },
    {
        key: 'embed',
        label: t('Einbindung'),
        count: props.meta?.embedded.length ?? 0,
    },
]);
const tab = ref('fields');
const errorCount = computed(() => Object.keys(errors.value).length);

/* ── Felder: Liste, Auswahl, Ziehen ── */

type Row = {
    field: FormFieldInput;
    group: number | null;
    index: number;
    path: string;
};

/** Die Felder flach: Unterfelder einer Gruppe direkt unter ihr, eingerückt. */
const rows = computed<Row[]>(() =>
    form.fields.flatMap((f, i) => [
        { field: f, group: null, index: i, path: `fields.${i}` },
        ...f.fields.map((c, j) => ({
            field: c,
            group: i,
            index: j,
            path: `fields.${i}.fields.${j}`,
        })),
    ]),
);

/** Das offene Feld — über das Objekt, so folgt die Auswahl beim Sortieren mit. */
const selected = shallowRef<FormFieldInput | null>(null);
const selectedRow = computed(
    () => rows.value.find((r) => r.field === selected.value) ?? null,
);

function select(field: FormFieldInput): void {
    selected.value = selected.value === field ? null : field;
}

function listOf(group: number | null): FormFieldInput[] {
    return group === null ? form.fields : (form.fields[group]?.fields ?? []);
}

function setList(group: number | null, list: FormFieldInput[]): void {
    if (group === null) {
        form.fields = list;
    } else {
        const owner = form.fields[group];
        if (owner) {
            // Unterfelder sind eine Ebene tief und tragen selbst keine Felder; dieselben Objekte, damit die Auswahl folgt
            owner.fields = list as FormSubField[];
        }
    }
}

function move(row: Row, to: number): void {
    setList(row.group, moveItem(listOf(row.group), row.index, to));
}

function duplicate(row: Row): void {
    const list = duplicateField(
        listOf(row.group),
        row.index,
        fieldNames(form.fields),
    );
    setList(row.group, list);
    selected.value = list[row.index + 1] ?? null;
}

function remove(row: Row): void {
    const open = selected.value;
    if (open === row.field || row.field.fields.some((c) => c === open)) {
        selected.value = null;
    }
    setList(
        row.group,
        listOf(row.group).filter((_, i) => i !== row.index),
    );
}

/* ── TB 31: geführt wie die Block-Formulare — Palette, Name aus der Beschriftung, das Wichtigste zuerst ── */

/** Felder, die in dieser Sitzung dazukamen: Ihr Name folgt der Beschriftung, bis jemand ihn selbst ändert. */
const fresh = new WeakSet<FormFieldInput>();
const nameTouched = new WeakSet<FormFieldInput>();

function addItem(item: PaletteItem, group: number | null = null): void {
    const taken = fieldNames(form.fields);
    const field = item.preset
        ? presetField(item.preset, typeLabel(item.type), props.locales, taken)
        : newField(item.type, typeLabel(item.type), taken);
    setList(group, [...listOf(group), field]);
    const list = listOf(group);
    const added = list[list.length - 1] ?? null;
    if (added) {
        fresh.add(added);
    }
    selected.value = added;
}

/** Ein Name aus einer Beschriftung: «Ihre E-Mail» → «ihre_e_mail», frei im Formular. */
function nameFrom(field: FormFieldInput, label: string): string {
    const base = handleFrom(label).replace(/-/g, '_');
    const taken = fieldNames(form.fields).filter((n) => n !== field.name);

    return base === '' ? field.name : uniqueName(base, taken);
}

function setLabel(field: FormFieldInput, key: string, value: string): void {
    field.labels = { ...field.labels, [key]: value };
    if (key === '*' && fresh.has(field) && !nameTouched.has(field)) {
        field.name = nameFrom(field, value);
    }
}

function setName(field: FormFieldInput, value: string): void {
    nameTouched.add(field);
    field.name = value;
}

const defaultLocale = computed(
    () => props.locales.find((l) => l.default) ?? props.locales[0],
);
const otherLocales = computed(() => props.locales.filter((l) => !l.default));

/** Weitere Möglichkeiten eines Feldes: zu, bis jemand sie öffnet — offen, wenn dort ein Fehler steht. */
const moreOpen = ref(false);
watch(selected, () => (moreOpen.value = false));
const PRIMARY_KEYS = [
    'labels.*',
    'text.*',
    'options',
    'required',
    'default',
    'types',
    'size',
];
const moreError = computed(() => {
    const path = selectedRow.value?.path;
    if (!path) {
        return false;
    }

    return Object.keys(errors.value).some(
        (k) =>
            k.startsWith(`${path}.`) &&
            !k.startsWith(`${path}.fields.`) &&
            !PRIMARY_KEYS.includes(k.slice(path.length + 1)),
    );
});
const showMore = computed(() => moreOpen.value || moreError.value);

/** Titel: die Kennung entsteht beim Anlegen aus dem Titel der Grundsprache, bis jemand sie selbst ändert. */
const handleTouched = ref(!isNew);
const titlesOpen = ref(false);

function setTitle(key: string, value: string): void {
    form.labels = { ...form.labels, [key]: value };
    if (key === '*' && !handleTouched.value) {
        form.handle = handleFrom(value).replace(/-/g, '_').slice(0, 60);
    }
}

/* ── Live-Vorschau im Fenster (wie bei Blöcken): Feld ↔ Stelle ── */
const isDesktop = useMediaQuery('(min-width: 768px)');
const mounted = ref(false);
onMounted(() => (mounted.value = true));
const previewOpen = usePreviewOpen();
const previewPayload = computed(() => ({
    ...fromBuilderData(form.data() as FormBuilderData),
    locale: defaultCode,
}));

function onPreviewPick(name: string): void {
    const row = rows.value.find((r) => r.field.name === name);
    if (row) {
        tab.value = 'fields';
        selected.value = row.field;
    }
}

const dragging = ref<{ group: number | null; index: number } | null>(null);
const dropOn = ref<string | null>(null);

function dragStart(row: Row, event: DragEvent): void {
    dragging.value = { group: row.group, index: row.index };
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', row.path);
    }
}

function dragOver(row: Row, event: DragEvent): void {
    if (dragging.value && dragging.value.group === row.group) {
        event.preventDefault();
        dropOn.value = row.path;
    }
}

function drop(row: Row): void {
    const from = dragging.value;
    if (from && from.group === row.group) {
        setList(row.group, moveItem(listOf(row.group), from.index, row.index));
    }
    dragEnd();
}

function dragEnd(): void {
    dragging.value = null;
    dropOn.value = null;
}

/** Fehler einer Zeile — bei einer Gruppe ohne die ihrer Unterfelder (die stehen an deren Zeile). */
function rowErrors(row: Row): string[] {
    return Object.entries(errors.value)
        .filter(
            ([k]) =>
                (k === row.path || k.startsWith(`${row.path}.`)) &&
                !(row.group === null && k.startsWith(`${row.path}.fields.`)),
        )
        .map(([, v]) => v);
}

function fieldTitle(field: FormFieldInput): string {
    const text = field.labels['*'] || field.text['*'] || '';

    return text.length > 70 ? `${text.slice(0, 70)} …` : text || field.name;
}

function fieldIcon(type: string): string {
    return type === 'group'
        ? 'folder'
        : type === 'explanation'
          ? 'type'
          : type === 'file'
            ? 'media'
            : 'forms';
}

const childTypes = computed(() =>
    props.field_types.filter((t) => t.value !== 'group'),
);

/** Fehler am offenen Feld je Eigenschaft. */
function fieldError(key: string): string {
    const path = selectedRow.value?.path;

    return path ? (errors.value[`${path}.${key}`] ?? '') : '';
}

function setOptions(field: FormFieldInput, key: string, text: string): void {
    field.options = { ...field.options, [key]: linesToOptions(text) };
}

/* ── Code-Ansicht ── */

const codeLines = computed(() =>
    props.meta ? props.meta.lava.replace(/\n$/, '').split('\n') : [],
);
const codeStart = computed(() => {
    const match = /:(\d+)$/.exec(props.meta?.location ?? '');

    return match ? Number(match[1]) : 1;
});

/* ── Webhook-Geheimnis ── */

const showSecret = ref(false);
const copied = ref(false);
const confirmingSecret = ref(false);

async function copySecret(): Promise<void> {
    if (!props.meta?.secret) {
        return;
    }
    await navigator.clipboard.writeText(props.meta.secret);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}

function renewSecret(): void {
    if (!props.urls.secret) {
        return;
    }
    router.post(
        props.urls.secret,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['meta', 'flash'],
            onFinish: () => {
                confirmingSecret.value = false;
                showSecret.value = true;
            },
        },
    );
}

/** Platzhalter `{feldname}` für den Betreff — die ersten Felder als Beispiel. */
const subjectNames = computed(() =>
    form.fields
        .flatMap((f) => (f.type === 'group' ? f.fields : [f]))
        .filter((f) => !noInputTypes.includes(f.type))
        .map((f) => `{${f.name}}`)
        .slice(0, 4),
);

/* ── Speichern ── */

/** Solange ein Name (Kennung, Feldname) den Fokus hat, wartet das Speichern — erst beim Verlassen geht er raus. */
const editingName = ref(false);

const autosave =
    !isNew && !readOnly.value
        ? useAutosave(form, {
              url: () => props.urls.save,
              delay: 1200,
              only: ['meta', 'urls', 'errors'],
              enabled: () =>
                  !editingName.value &&
                  handlePattern.test(form.handle) &&
                  namesValid(form.fields),
              labels: {
                  handle: t('Kennung'),
                  labels: t('Titel'),
                  fields: t('Felder'),
                  to: t('Empfänger'),
                  subject: t('Betreff'),
                  thanks: t('Dankestext'),
                  submit_label: t('Knopf'),
                  consent: t('Zustimmung'),
                  store: t('Speichern'),
                  redirect: t('Weiterleitung'),
                  webhook: 'Webhook',
                  retain: t('Aufbewahrung'),
              },
          })
        : null;

function saved(key: string): boolean {
    return autosave?.saved(key) ?? false;
}

function nameFocus(): void {
    editingName.value = true;
}

function nameBlur(): void {
    editingName.value = false;
    void autosave?.flush();
}

function create(): void {
    if (readOnly.value) {
        return;
    }
    if (autosave) {
        editingName.value = false;
        void autosave.flush();

        return;
    }
    // Gelingt es, leitet der Server auf «Bearbeiten» um — die Seite baut sich neu auf (mit Autosave)
    form.post(props.urls.save, {
        preserveScroll: true,
        preserveState: 'errors',
    });
}

const confirmingDelete = ref(false);

function destroy(): void {
    if (props.urls.delete) {
        router.delete(props.urls.delete);
    }
}

const caption = labelClasses;
const textarea =
    'w-full rounded-md border border-ash-dark bg-white px-2.5 py-2 text-[13px] text-basalt placeholder:text-ash-dark focus:border-lava-dark focus:ring-1 focus:ring-lava-dark focus:outline-none disabled:bg-paper disabled:text-ash';
const opButton =
    'inline-flex min-h-6 min-w-6 items-center justify-center rounded px-1 text-basalt-light hover:bg-paper hover:text-basalt disabled:text-ash-light';
</script>

<template>
    <Head :title="title" />
    <PageHeader
        :title="title"
        :crumb="$t(':site › Formulare', { site: siteName })"
        :lead="lead"
    >
        <template #actions>
            <Button
                v-if="urls.inbox"
                variant="secondary"
                size="sm"
                :href="urls.inbox"
                >{{ $t('Eingänge')
                }}<template v-if="meta">
                    · {{ meta.submissions }}</template
                ></Button
            >
            <Button
                v-if="!previewOpen && isDesktop && urls.preview"
                variant="secondary"
                size="sm"
                @click="previewOpen = true"
                >{{ $t('Live-Vorschau') }}</Button
            >
            <Button variant="link" :href="urls.index">{{
                $t('← Formulare')
            }}</Button>
        </template>
    </PageHeader>

    <Notice v-if="readOnly && meta" tone="grey" class="mb-3">
        {{ $t('Kommt aus dem Theme (Station H) — ändern in') }}
        <span class="font-mono">{{ meta.location }}</span
        >.
        {{
            $t(
                'Hier lässt es sich ansehen; Felder, Aktionen und Texte sind gesperrt, die Eingänge laufen wie bei jedem Formular.',
            )
        }}
    </Notice>

    <form
        class="rounded-lg border border-ash-light bg-white px-4 pt-3.5 pb-3.5"
        novalidate
        @submit.prevent="create"
    >
        <div class="flex flex-wrap items-center gap-2 text-sm font-semibold">
            <Hex icon="forms" :tone="readOnly ? 'ash' : 'basalt'" />
            <span class="break-words">{{
                isNew ? $t('Formular anlegen') : label
            }}</span>
            <span
                v-if="!isNew"
                class="font-mono text-[11.5px] font-normal text-basalt-light"
                >{{ form.handle }}</span
            >
        </div>
        <p class="mt-0.5 text-xs text-basalt-light">
            {{
                $t(
                    'Felder · Aktionen · Spam und Aufbewahrung · Einbindung — Basalto schreibt daraus Lava; der Code neben den Feldern ist dieselbe Quelle.',
                )
            }}
            <template v-if="autosave">
                {{
                    $t(
                        'Änderungen speichern sich selbst, Kennung und Feldnamen beim Verlassen des Feldes.',
                    )
                }}</template
            >
        </p>
        <Notice v-if="errorCount > 0" tone="danger" class="mt-2.5">
            <b>{{
                $tc(
                    ':count Angabe stimmt nicht.|:count Angaben stimmen nicht.',
                    errorCount,
                )
            }}</b>
            {{
                $t(
                    'Die übrigen Eingaben sind erhalten — die Fehler stehen am Feld und am Reiter.',
                )
            }}
        </Notice>

        <Tabs v-model="tab" :tabs="tabs" class="mt-3.5">
            <!-- ── Felder ── -->
            <template #fields>
                <div class="grid gap-x-5 gap-y-4 lg:grid-cols-2">
                    <div class="min-w-0">
                        <fieldset
                            :disabled="readOnly"
                            class="grid min-w-0 gap-x-4 gap-y-2.5 *:mt-0! sm:grid-cols-2"
                        >
                            <Field
                                v-if="defaultLocale"
                                :model-value="form.labels['*'] ?? ''"
                                :saved="saved('labels.*')"
                                :label="$t('Titel')"
                                required
                                :maxlength="120"
                                :error="errors['labels.*']"
                                :placeholder="$t('Offerte anfragen')"
                                :help="
                                    $t(
                                        'So heisst das Formular in der Liste und in der Vorschau.',
                                    )
                                "
                                @update:model-value="setTitle('*', $event)"
                            />
                            <Field
                                v-model="form.handle"
                                :saved="saved('handle')"
                                :label="$t('Kennung')"
                                mono
                                required
                                placeholder="offerte"
                                :maxlength="60"
                                :error="errors.handle"
                                :help="
                                    isNew
                                        ? $t(
                                              'Entsteht aus dem Titel; a–z, 0–9, «_» — so heisst das Formular in Lava und im Block «Formular»',
                                          )
                                        : $t(
                                              'a–z, 0–9, «_» — so heisst das Formular in Lava und im Block «Formular»',
                                          )
                                "
                                @update:model-value="handleTouched = true"
                                @focusin="nameFocus"
                                @focusout="nameBlur"
                            />
                        </fieldset>
                        <template v-if="otherLocales.length > 0">
                            <button
                                type="button"
                                class="mt-2 text-[12px] font-semibold text-lava-dark underline hover:text-basalt"
                                :aria-expanded="
                                    titlesOpen ||
                                    otherLocales.some(
                                        (l) => errors[`labels.${textKey(l)}`],
                                    )
                                "
                                @click="titlesOpen = !titlesOpen"
                            >
                                {{
                                    $t('Titel in weiteren Sprachen (:codes)', {
                                        codes: otherLocales
                                            .map((l) => l.code.toUpperCase())
                                            .join(' · '),
                                    })
                                }}
                            </button>
                            <fieldset
                                v-show="
                                    titlesOpen ||
                                    otherLocales.some(
                                        (l) => errors[`labels.${textKey(l)}`],
                                    )
                                "
                                :disabled="readOnly"
                                class="mt-2 grid min-w-0 gap-x-4 gap-y-2.5 *:mt-0! sm:grid-cols-2"
                            >
                                <Field
                                    v-for="l in otherLocales"
                                    :key="l.code"
                                    v-model="form.labels[textKey(l)]"
                                    :saved="saved(`labels.${textKey(l)}`)"
                                    :label="
                                        $t('Titel (:code)', {
                                            code: l.code.toUpperCase(),
                                        })
                                    "
                                    optional
                                    :maxlength="120"
                                    :error="errors[`labels.${textKey(l)}`]"
                                    :help="$t('leer = Titel der Grundsprache')"
                                />
                            </fieldset>
                        </template>

                        <span :class="[caption, 'mt-4']"
                            >{{ $t('Felder · :count', { count: inputCount })
                            }}<span
                                v-if="saved('fields')"
                                class="text-success ml-1.5 font-semibold tracking-normal normal-case"
                                >{{ $t('✓ gespeichert') }}</span
                            ></span
                        >
                        <ul
                            class="rounded-lg border border-ash-light"
                            :aria-label="$t('Felder')"
                        >
                            <li
                                v-for="row in rows"
                                :key="row.path"
                                class="flex flex-wrap items-start gap-x-2 gap-y-1 border-b border-ash-light px-2.5 py-2 text-[12.5px]"
                                :class="[
                                    row.group !== null ? 'pl-8' : '',
                                    selected === row.field
                                        ? 'bg-lava/5 shadow-[inset_3px_0_0_var(--color-lava)]'
                                        : '',
                                    dropOn === row.path
                                        ? 'outline-2 -outline-offset-2 outline-lava/60 outline-dashed'
                                        : '',
                                    rowErrors(row).length > 0
                                        ? 'bg-danger/5'
                                        : '',
                                ]"
                                :draggable="!readOnly"
                                @dragstart="dragStart(row, $event)"
                                @dragover="dragOver(row, $event)"
                                @dragleave="dropOn = null"
                                @drop.prevent="drop(row)"
                                @dragend="dragEnd"
                            >
                                <span
                                    v-if="!readOnly"
                                    class="text-ash-dark mt-0.5 cursor-grab tracking-[-2px] select-none"
                                    :title="$t('Ziehen sortiert')"
                                    aria-hidden="true"
                                    >⋮⋮</span
                                >
                                <Hex
                                    :icon="fieldIcon(row.field.type)"
                                    :tone="
                                        selected === row.field ? 'lava' : 'ash'
                                    "
                                />
                                <div class="min-w-40 flex-1">
                                    <button
                                        type="button"
                                        class="text-left font-semibold break-words hover:underline"
                                        @click="select(row.field)"
                                    >
                                        {{ fieldTitle(row.field) }}
                                    </button>
                                    <p
                                        class="text-[11.5px] break-words text-basalt-light"
                                    >
                                        <span class="font-mono">{{
                                            row.field.name
                                        }}</span>
                                        · {{ fieldSummary(row.field) }}
                                    </p>
                                    <p
                                        v-for="(message, k) in rowErrors(row)"
                                        :key="k"
                                        class="text-danger mt-0.5 text-[11.5px] font-semibold"
                                        role="alert"
                                    >
                                        {{ message }}
                                    </p>
                                </div>
                                <span
                                    class="ml-auto flex shrink-0 items-center gap-1 text-[11.5px]"
                                >
                                    <template v-if="!readOnly">
                                        <button
                                            type="button"
                                            :class="opButton"
                                            :disabled="row.index === 0"
                                            :title="$t('nach oben')"
                                            :aria-label="$t('nach oben')"
                                            @click="move(row, row.index - 1)"
                                        >
                                            ↑
                                        </button>
                                        <button
                                            type="button"
                                            :class="opButton"
                                            :disabled="
                                                row.index ===
                                                listOf(row.group).length - 1
                                            "
                                            :title="$t('nach unten')"
                                            :aria-label="$t('nach unten')"
                                            @click="move(row, row.index + 1)"
                                        >
                                            ↓
                                        </button>
                                    </template>
                                    <button
                                        type="button"
                                        class="px-1 text-lava-dark underline hover:text-basalt"
                                        :aria-expanded="selected === row.field"
                                        @click="select(row.field)"
                                    >
                                        {{
                                            readOnly
                                                ? $t('Ansehen')
                                                : $t('Bearbeiten')
                                        }}
                                    </button>
                                    <template v-if="!readOnly">
                                        <button
                                            type="button"
                                            :class="opButton"
                                            :title="$t('Duplizieren')"
                                            :aria-label="$t('Duplizieren')"
                                            @click="duplicate(row)"
                                        >
                                            ⧉
                                        </button>
                                        <button
                                            type="button"
                                            class="text-danger rounded px-1 hover:underline"
                                            :title="$t('Entfernen')"
                                            :aria-label="$t('Entfernen')"
                                            @click="remove(row)"
                                        >
                                            ✕
                                        </button>
                                    </template>
                                </span>
                            </li>
                            <li
                                class="flex flex-wrap items-start gap-x-2 gap-y-1 px-2.5 py-2 text-[12.5px]"
                            >
                                <span
                                    v-if="!readOnly"
                                    class="mt-0.5 w-[11px]"
                                    aria-hidden="true"
                                />
                                <Hex icon="check" tone="ash" />
                                <div class="min-w-40 flex-1">
                                    <span class="font-semibold">{{
                                        $t(
                                            'Zustimmung zur Datenschutzerklärung',
                                        )
                                    }}</span>
                                    <p class="text-[11.5px] text-basalt-light">
                                        <span class="font-mono">_consent</span>
                                        · checkbox ·
                                        {{
                                            $t(
                                                'Pflicht · Zeitpunkt kommt mit dem Eingang',
                                            )
                                        }}
                                    </p>
                                </div>
                                <span
                                    class="ml-auto flex shrink-0 items-center gap-1.5 text-[11.5px]"
                                >
                                    <StateTag>{{
                                        $t('Kern · immer')
                                    }}</StateTag>
                                    <button
                                        type="button"
                                        class="px-1 text-lava-dark underline hover:text-basalt"
                                        @click="tab = 'spam'"
                                    >
                                        {{
                                            readOnly
                                                ? $t('Text ansehen')
                                                : $t('Text ändern')
                                        }}
                                    </button>
                                </span>
                            </li>
                        </ul>
                        <p
                            v-if="errors.fields"
                            class="text-danger mt-1.5 text-[11.5px] font-semibold"
                            role="alert"
                        >
                            {{ errors.fields }}
                        </p>
                        <p class="mt-1 text-[11.5px] text-basalt-light">
                            {{ $tc(':count Feld|:count Felder', inputCount) }}
                            <template v-if="!readOnly"
                                >·
                                {{ $t('Ziehen oder ↑ ↓ sortiert') }}</template
                            >
                            ·
                            {{
                                $t(
                                    'das Zustimmungsfeld kommt aus dem Kern und bleibt immer.',
                                )
                            }}
                        </p>

                        <FieldPalette
                            v-if="!readOnly"
                            class="mt-3.5"
                            :types="field_types"
                            :locale="defaultCode"
                            @add="addItem($event)"
                        />
                    </div>

                    <div class="min-w-0">
                        <!-- Feld-Einstellungen -->
                        <section
                            v-if="selected && selectedRow"
                            class="rounded-lg border border-ash px-3.5 py-3"
                            :aria-label="
                                $t('Feld «:field»', {
                                    field: fieldTitle(selected),
                                })
                            "
                        >
                            <div
                                class="flex flex-wrap items-baseline justify-between gap-2"
                            >
                                <span
                                    class="text-sm font-semibold break-words"
                                    >{{
                                        $t('Feld «:field»', {
                                            field: fieldTitle(selected),
                                        })
                                    }}</span
                                >
                                <button
                                    type="button"
                                    class="text-[11.5px] text-basalt-light underline hover:text-basalt"
                                    @click="selected = null"
                                >
                                    {{ $t('Schliessen') }}
                                </button>
                            </div>
                            <p class="text-[11.5px] text-basalt-light">
                                {{ typeLabel(selected.type) }} ·
                                {{ fieldSummary(selected) }}
                            </p>

                            <fieldset :disabled="readOnly" class="min-w-0">
                                <!-- Das Wichtigste: Beschriftung, Pflicht und was der Typ unbedingt braucht -->
                                <Field
                                    v-if="selected.type === 'explanation'"
                                    :model-value="selected.text['*'] ?? ''"
                                    type="textarea"
                                    :rows="3"
                                    class="mt-2.5!"
                                    :label="$t('Text')"
                                    :help="
                                        $t(
                                            'Steht ohne Eingabe im Formular, z. B. ein Hinweis zur Bearbeitungszeit.',
                                        )
                                    "
                                    :error="fieldError('text.*')"
                                    @update:model-value="
                                        selected.text = {
                                            ...selected.text,
                                            '*': $event,
                                        }
                                    "
                                />
                                <Field
                                    v-else
                                    :model-value="selected.labels['*'] ?? ''"
                                    class="mt-2.5!"
                                    :label="
                                        selected.type === 'group'
                                            ? $t('Überschrift der Gruppe')
                                            : $t('Beschriftung')
                                    "
                                    required
                                    :maxlength="200"
                                    :help="
                                        selected.type === 'group'
                                            ? $t(
                                                  'Steht über den Feldern der Gruppe, z. B. «Adresse».',
                                              )
                                            : $t(
                                                  'So steht es über dem Feld; der Name im Eingang entsteht daraus.',
                                              )
                                    "
                                    :error="fieldError('labels.*')"
                                    @update:model-value="
                                        setLabel(selected, '*', $event)
                                    "
                                />
                                <label
                                    v-if="
                                        !noInputTypes.includes(selected.type) &&
                                        selected.type !== 'hidden'
                                    "
                                    class="mt-2.5 flex items-center gap-2 text-[12.5px]"
                                >
                                    <Toggle
                                        v-model="selected.required"
                                        :disabled="readOnly"
                                        :label="$t('Pflichtfeld')"
                                    />
                                    {{ $t('Pflichtfeld') }}
                                    <span
                                        class="text-[11.5px] text-basalt-light"
                                        >{{
                                            selected.required
                                                ? $t(
                                                      '— ohne Eingabe lässt sich nicht senden',
                                                  )
                                                : $t('— darf leer bleiben')
                                        }}</span
                                    >
                                </label>

                                <!-- Optionen in der Grundsprache, eine je Zeile -->
                                <Field
                                    v-if="optionTypes.includes(selected.type)"
                                    :model-value="
                                        optionsToLines(selected.options['*'])
                                    "
                                    type="textarea"
                                    :rows="4"
                                    class="mt-3!"
                                    :label="
                                        $t('Optionen · :count', {
                                            count: cleanOptions(
                                                selected.options['*'],
                                            ).length,
                                        })
                                    "
                                    :help="
                                        $t(
                                            'Eine Option je Zeile, in der Reihenfolge der Liste.',
                                        )
                                    "
                                    :error="fieldError('options')"
                                    @update:model-value="
                                        setOptions(selected, '*', $event)
                                    "
                                />
                                <Field
                                    v-if="selected.type === 'hidden'"
                                    v-model="selected.default"
                                    class="mt-3!"
                                    :label="$t('Wert')"
                                    mono
                                    optional
                                    :help="
                                        $t(
                                            'wird unsichtbar mitgeschickt (z. B. Kampagne)',
                                        )
                                    "
                                    :error="fieldError('default')"
                                />
                                <div
                                    v-if="selected.type === 'file'"
                                    class="mt-3 grid gap-x-3 gap-y-2.5 *:mt-0! sm:grid-cols-[1fr_8rem]"
                                >
                                    <Field
                                        v-model="selected.types"
                                        :label="$t('Dateitypen')"
                                        mono
                                        optional
                                        placeholder="pdf, docx"
                                        :help="
                                            $t(
                                                'Endungen mit Komma — leer: PDF und Bilder',
                                            )
                                        "
                                        :error="fieldError('types')"
                                    />
                                    <Field
                                        v-model="selected.size"
                                        :label="$t('Grösse in MB')"
                                        type="number"
                                        optional
                                        placeholder="10"
                                        :error="fieldError('size')"
                                    />
                                </div>

                                <!-- Gruppe: Unterfelder (eine Ebene) -->
                                <template
                                    v-if="
                                        selected.type === 'group' &&
                                        selectedRow.group === null &&
                                        !readOnly
                                    "
                                >
                                    <FieldPalette
                                        class="mt-3"
                                        :types="childTypes"
                                        :locale="defaultCode"
                                        :title="
                                            $t(
                                                'Unterfeld hinzufügen · :count in der Gruppe',
                                                {
                                                    count: selected.fields
                                                        .length,
                                                },
                                            )
                                        "
                                        @add="
                                            addItem($event, selectedRow.index)
                                        "
                                    />
                                    <p
                                        class="mt-1 text-[11.5px] text-basalt-light"
                                    >
                                        {{
                                            $t(
                                                'Die Unterfelder stehen eingerückt in der Liste — dort sortieren und bearbeiten.',
                                            )
                                        }}
                                    </p>
                                </template>
                            </fieldset>
                            <!-- Weitere Möglichkeiten -->
                            <button
                                type="button"
                                class="mt-3 flex w-full items-center gap-1.5 rounded-md border border-dashed border-ash-light px-3 py-2 text-left text-[12.5px] font-semibold text-basalt-light hover:border-ash hover:text-basalt"
                                :aria-expanded="showMore"
                                @click="moreOpen = !showMore"
                            >
                                <span aria-hidden="true">{{
                                    showMore ? '−' : '+'
                                }}</span>
                                {{
                                    showMore
                                        ? $t('Weniger zeigen')
                                        : $t(
                                              'Weitere Möglichkeiten — Name, Typ, Platzhalter, Hilfetext, Übersetzungen',
                                          )
                                }}
                            </button>
                            <fieldset
                                v-show="showMore"
                                :disabled="readOnly"
                                class="min-w-0"
                            >
                                <div
                                    class="mt-2.5 grid gap-x-3 gap-y-2.5 *:mt-0! sm:grid-cols-2"
                                >
                                    <Field
                                        :model-value="selected.name"
                                        :label="$t('Name')"
                                        mono
                                        required
                                        :maxlength="60"
                                        :error="fieldError('name')"
                                        :help="
                                            fresh.has(selected) &&
                                            !nameTouched.has(selected)
                                                ? $t(
                                                      'Schlüssel im Eingang und im CSV — folgt der Beschriftung, bis Sie ihn ändern',
                                                  )
                                                : $t(
                                                      'Schlüssel im Eingang und im CSV',
                                                  )
                                        "
                                        @update:model-value="
                                            setName(selected, $event)
                                        "
                                        @focusin="nameFocus"
                                        @focusout="nameBlur"
                                    />
                                    <Field
                                        v-model="selected.type"
                                        :label="$t('Typ')"
                                        type="select"
                                        :options="
                                            selectedRow.group === null
                                                ? field_types
                                                : childTypes
                                        "
                                        :error="fieldError('type')"
                                    />
                                </div>
                                <Field
                                    v-if="
                                        placeholderTypes.includes(selected.type)
                                    "
                                    v-model="selected.placeholder['*']"
                                    class="mt-2.5!"
                                    :label="$t('Platzhalter')"
                                    optional
                                    :maxlength="200"
                                    :help="
                                        $t(
                                            'Grauer Beispieltext im leeren Feld, z. B. «max@beispiel.ch».',
                                        )
                                    "
                                />
                                <Field
                                    v-if="
                                        !noInputTypes.includes(selected.type) &&
                                        selected.type !== 'hidden'
                                    "
                                    v-model="selected.help['*']"
                                    class="mt-2.5!"
                                    :label="$t('Hilfetext')"
                                    optional
                                    :maxlength="300"
                                    :help="$t('Steht klein unter dem Feld.')"
                                />
                                <div
                                    v-if="rangeTypes.includes(selected.type)"
                                    class="mt-2.5 grid grid-cols-3 gap-x-3 *:mt-0!"
                                >
                                    <Field
                                        v-model="selected.min"
                                        label="Min"
                                        type="number"
                                        optional
                                        :error="fieldError('min')"
                                    />
                                    <Field
                                        v-model="selected.max"
                                        label="Max"
                                        type="number"
                                        optional
                                        :error="fieldError('max')"
                                    />
                                    <Field
                                        v-model="selected.step"
                                        :label="$t('Schritt')"
                                        type="number"
                                        optional
                                        :error="fieldError('step')"
                                    />
                                </div>
                                <Field
                                    v-if="lengthTypes.includes(selected.type)"
                                    v-model="selected.max"
                                    class="mt-2.5!"
                                    :label="$t('Höchstens Zeichen')"
                                    type="number"
                                    optional
                                    :placeholder="$t('ohne Grenze')"
                                    :error="fieldError('max')"
                                />

                                <!-- Übersetzungen je weiterer Sprache -->
                                <section
                                    v-for="l in otherLocales"
                                    :key="`tr-${l.code}`"
                                    class="mt-3 rounded-md border border-ash-light px-3 py-2"
                                    :aria-label="
                                        $t('Übersetzung :code', {
                                            code: l.code.toUpperCase(),
                                        })
                                    "
                                >
                                    <span :class="caption">{{
                                        $t(
                                            'Übersetzung :code · leer = Grundsprache',
                                            {
                                                code: l.code.toUpperCase(),
                                            },
                                        )
                                    }}</span>
                                    <Field
                                        v-if="selected.type === 'explanation'"
                                        v-model="selected.text[textKey(l)]"
                                        type="textarea"
                                        :rows="2"
                                        :label="$t('Text')"
                                        optional
                                        :error="
                                            fieldError(`text.${textKey(l)}`)
                                        "
                                    />
                                    <Field
                                        v-else
                                        :model-value="
                                            selected.labels[textKey(l)] ?? ''
                                        "
                                        :label="
                                            selected.type === 'group'
                                                ? $t('Überschrift der Gruppe')
                                                : $t('Beschriftung')
                                        "
                                        optional
                                        :maxlength="200"
                                        :error="
                                            fieldError(`labels.${textKey(l)}`)
                                        "
                                        @update:model-value="
                                            setLabel(
                                                selected,
                                                textKey(l),
                                                $event,
                                            )
                                        "
                                    />
                                    <Field
                                        v-if="
                                            placeholderTypes.includes(
                                                selected.type,
                                            )
                                        "
                                        v-model="
                                            selected.placeholder[textKey(l)]
                                        "
                                        :label="$t('Platzhalter')"
                                        optional
                                        :maxlength="200"
                                    />
                                    <Field
                                        v-if="
                                            !noInputTypes.includes(
                                                selected.type,
                                            ) && selected.type !== 'hidden'
                                        "
                                        v-model="selected.help[textKey(l)]"
                                        :label="$t('Hilfetext')"
                                        optional
                                        :maxlength="300"
                                    />
                                    <Field
                                        v-if="
                                            optionTypes.includes(selected.type)
                                        "
                                        :model-value="
                                            optionsToLines(
                                                selected.options[textKey(l)],
                                            )
                                        "
                                        type="textarea"
                                        :rows="3"
                                        :label="
                                            $t('Optionen · :count', {
                                                count: cleanOptions(
                                                    selected.options[
                                                        textKey(l)
                                                    ],
                                                ).length,
                                            })
                                        "
                                        optional
                                        :help="
                                            $t(
                                                'leer = Optionen der Grundsprache',
                                            )
                                        "
                                        @update:model-value="
                                            setOptions(
                                                selected,
                                                textKey(l),
                                                $event,
                                            )
                                        "
                                    />
                                </section>
                            </fieldset>
                        </section>
                        <p
                            v-else
                            class="rounded-lg border border-dashed border-ash px-3.5 py-3 text-[12.5px] text-basalt-light"
                        >
                            {{
                                readOnly
                                    ? $t(
                                          '«Ansehen» zeigt die Einstellungen eines Feldes.',
                                      )
                                    : $t(
                                          '«Bearbeiten» öffnet die Einstellungen eines Feldes: Name, Typ, Pflicht, Beschriftung je Sprache und was der Typ sonst braucht.',
                                      )
                            }}
                        </p>

                        <!-- Code-Ansicht -->
                        <span :class="[caption, 'mt-4']"
                            >Lava ·
                            {{ meta ? meta.location : 'forms.lava' }}</span
                        >
                        <p
                            v-if="!meta"
                            class="rounded-md border border-dashed border-ash px-3 py-2.5 text-[12.5px] text-basalt-light"
                        >
                            {{
                                $t(
                                    'Der Code erscheint nach dem Anlegen — Basalto schreibt ihn in',
                                )
                            }}
                            <span class="font-mono">forms.lava</span>
                            {{ $t('der Site.') }}
                        </p>
                        <ol
                            v-else
                            class="rounded-md bg-basalt px-3 py-2.5 font-mono text-[11.5px] leading-[1.55] text-paper"
                            :aria-label="$t('Lava-Code')"
                        >
                            <li
                                v-for="(line, i) in codeLines"
                                :key="i"
                                class="grid grid-cols-[2rem_1fr] gap-2"
                            >
                                <span class="text-right text-ash select-none">{{
                                    codeStart + i
                                }}</span>
                                <span
                                    class="min-w-0 break-words whitespace-pre-wrap"
                                    >{{ line || ' ' }}</span
                                >
                            </li>
                        </ol>
                        <p class="mt-1 text-[11.5px] text-basalt-light">
                            {{
                                $t(
                                    'Baukasten und Code sind zwei Ansichten derselben Quelle',
                                )
                            }}<template v-if="readOnly">
                                —
                                {{
                                    $t(
                                        'ein Theme-Formular ändert man in der Lava-Zeile',
                                    )
                                }}</template
                            >.
                        </p>
                    </div>
                </div>
            </template>

            <!-- ── Aktionen ── -->
            <template #actions>
                <fieldset :disabled="readOnly" class="min-w-0 space-y-2.5">
                    <Card
                        :title="$t('Mail an Empfänger')"
                        :sub="$t('aus, solange kein Empfänger steht')"
                    >
                        <Field
                            v-model="form.to"
                            :saved="saved('to')"
                            :label="$t('Empfänger')"
                            optional
                            placeholder="info@firma.ch, verkauf@firma.ch"
                            :error="errors.to"
                            :help="
                                $t(
                                    'Mehrere mit Komma. Antwort-an ist der Absender (das erste E-Mail-Feld).',
                                )
                            "
                        />
                        <Field
                            v-model="form.subject"
                            :saved="saved('subject')"
                            :label="$t('Betreff')"
                            optional
                            :maxlength="200"
                            :placeholder="$t('Neue Anfrage · {name}')"
                            :error="errors.subject"
                        >
                            <template #help>
                                <span class="font-mono">{{
                                    $t('{feldname}')
                                }}</span>
                                {{ $t('setzt den Wert aus dem Eingang ein')
                                }}<template v-if="subjectNames.length > 0"
                                    >, {{ $t('z. B.') }}
                                    <span class="font-mono">{{
                                        subjectNames.join(' ')
                                    }}</span></template
                                >.
                            </template>
                        </Field>
                    </Card>
                    <div class="grid gap-2.5 md:grid-cols-2">
                        <Card :title="$t('Speichern')">
                            <label
                                class="flex items-center gap-2 text-[12.5px]"
                            >
                                <Toggle
                                    v-model="form.store"
                                    :disabled="readOnly"
                                    :label="$t('Eingänge im Backend speichern')"
                                />
                                {{ $t('Eingänge im Backend speichern')
                                }}<span
                                    v-if="saved('store')"
                                    class="text-success text-[11px] font-semibold"
                                    >{{ $t('✓ gespeichert') }}</span
                                >
                            </label>
                            <p class="mt-1 text-[11.5px] text-basalt-light">
                                {{
                                    $t(
                                        'Aufbewahrung :days Tage (Reiter «Spam und Aufbewahrung»). Aus: nur Mail und Webhook, kein Eingang im Backend.',
                                        {
                                            days:
                                                form.retain ||
                                                retention_default,
                                        },
                                    )
                                }}
                            </p>
                        </Card>
                        <Card :title="$t('Weiterleitung')">
                            <Field
                                v-model="form.redirect"
                                :saved="saved('redirect')"
                                :label="$t('Nach dem Senden zu')"
                                mono
                                optional
                                placeholder="/danke"
                                :maxlength="250"
                                :error="errors.redirect"
                                :help="
                                    $t(
                                        'Pfad einer Seite — leer: der Dankestext erscheint an Ort und Stelle.',
                                    )
                                "
                            />
                        </Card>
                    </div>
                    <Card
                        title="Webhook"
                        :sub="
                            $t(
                                'POST als JSON, HMAC-signiert, nur an öffentliche https-Adressen',
                            )
                        "
                    >
                        <Field
                            v-model="form.webhook"
                            :saved="saved('webhook')"
                            label="URL"
                            mono
                            optional
                            placeholder="https://hooks.example.ch/basalto"
                            :maxlength="500"
                            :error="errors.webhook"
                        />
                    </Card>
                </fieldset>
                <div
                    v-if="meta"
                    class="mt-2.5 rounded-lg border border-ash-light px-3.5 py-3"
                >
                    <span :class="caption">{{
                        $t('Geheimnis für die Signatur')
                    }}</span>
                    <div class="flex flex-wrap items-center gap-2">
                        <code
                            class="min-w-0 rounded-md border border-ash-light bg-paper px-2 py-1 font-mono text-[12px] break-all"
                            >{{
                                meta.secret
                                    ? showSecret
                                        ? meta.secret
                                        : '••••••••••••••••'
                                    : $t('noch keines')
                            }}</code
                        >
                        <template v-if="meta.secret">
                            <Button
                                variant="secondary"
                                size="sm"
                                @click="showSecret = !showSecret"
                                >{{
                                    showSecret
                                        ? $t('Verbergen')
                                        : $t('Anzeigen')
                                }}</Button
                            >
                            <Button
                                variant="secondary"
                                size="sm"
                                @click="copySecret"
                                >{{
                                    copied ? $t('✓ Kopiert') : $t('Kopieren')
                                }}</Button
                            >
                        </template>
                        <template v-if="urls.secret">
                            <Button
                                v-if="!confirmingSecret"
                                variant="link"
                                size="sm"
                                @click="confirmingSecret = true"
                                >{{ $t('Neu erzeugen') }}</Button
                            >
                            <span
                                v-else
                                class="inline-flex flex-wrap items-center gap-2 text-[12px]"
                                role="alertdialog"
                            >
                                {{ $t('Das alte gilt sofort nicht mehr.') }}
                                <Button
                                    variant="danger"
                                    size="sm"
                                    @click="renewSecret"
                                    >{{ $t('Ja, neu erzeugen') }}</Button
                                >
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    @click="confirmingSecret = false"
                                    >{{ $t('Nein') }}</Button
                                >
                            </span>
                        </template>
                    </div>
                    <p class="mt-1 text-[11.5px] text-basalt-light">
                        {{ $t('Der Empfänger prüft die Signatur im Header') }}
                        <span class="font-mono">X-Basalto-Signature</span>
                        {{ $t('(HMAC-SHA256 über den Inhalt).') }}
                    </p>
                </div>
                <fieldset :disabled="readOnly" class="mt-2.5 min-w-0">
                    <span :class="caption">{{ $t('Dankestext') }}</span>
                    <div class="grid gap-x-3 gap-y-2 *:mt-0! md:grid-cols-2">
                        <Field
                            v-for="l in locales"
                            :key="`thanks-${l.code}`"
                            v-model="form.thanks[textKey(l)]"
                            :saved="saved(`thanks.${textKey(l)}`)"
                            type="textarea"
                            :rows="2"
                            :label="l.code.toUpperCase()"
                            optional
                            :placeholder="
                                l.default
                                    ? $t(
                                          'Danke — wir melden uns innert zwei Arbeitstagen.',
                                      )
                                    : ''
                            "
                            :error="errors[`thanks.${textKey(l)}`]"
                        />
                    </div>
                    <span :class="[caption, 'mt-3']">{{
                        $t('Beschriftung des Knopfs')
                    }}</span>
                    <div class="grid gap-x-3 gap-y-2 *:mt-0! md:grid-cols-2">
                        <Field
                            v-for="l in locales"
                            :key="`submit-${l.code}`"
                            v-model="form.submit_label[textKey(l)]"
                            :saved="saved(`submit_label.${textKey(l)}`)"
                            :label="l.code.toUpperCase()"
                            optional
                            :maxlength="60"
                            :placeholder="l.default ? $t('Senden') : ''"
                            :error="errors[`submit.${textKey(l)}`]"
                        />
                    </div>
                </fieldset>
            </template>

            <!-- ── Spam und Aufbewahrung ── -->
            <template #spam>
                <div class="space-y-2.5">
                    <Card
                        :title="$t('Spam-Schutz ohne Rätsel')"
                        :sub="$t('an und nicht abschaltbar — kein CAPTCHA')"
                        status="success"
                    >
                        <ul class="space-y-1 text-[12.5px]">
                            <li class="flex items-center gap-2">
                                <Hex icon="check" tone="success" size="xs" />
                                {{
                                    $t('Honeypot-Feld, für Menschen unsichtbar')
                                }}
                            </li>
                            <li class="flex items-center gap-2">
                                <Hex icon="check" tone="success" size="xs" />
                                {{
                                    $t(
                                        'Zeitprüfung — unter 3 Sekunden gilt als Maschine',
                                    )
                                }}
                            </li>
                            <li class="flex items-center gap-2">
                                <Hex icon="check" tone="success" size="xs" />
                                {{ $t('Limit — 5 je Minute und IP') }}
                            </li>
                        </ul>
                        <p class="mt-1.5 text-[11.5px] text-basalt-light">
                            {{ $t('Spam wird nie gespeichert, nur gezählt')
                            }}<template v-if="meta"
                                >:
                                {{
                                    $t(':count bei diesem Formular', {
                                        count: meta.spam,
                                    })
                                }}</template
                            >.
                        </p>
                    </Card>
                    <fieldset :disabled="readOnly" class="min-w-0 space-y-2.5">
                        <Card :title="$t('Aufbewahrung')">
                            <Field
                                v-model="form.retain"
                                :saved="saved('retain')"
                                :label="$t('Eingänge löschen nach … Tagen')"
                                type="number"
                                optional
                                :placeholder="String(retention_default)"
                                :error="errors.retain"
                                :help="
                                    $t(
                                        'Leer gilt die Einstellung der Site: :days Tage. Danach löscht der Löschlauf die Eingänge samt Dateien und protokolliert die Zahl.',
                                        { days: retention_default },
                                    )
                                "
                                class="max-w-xs"
                            />
                        </Card>
                        <Card
                            :title="$t('Zustimmungsfeld')"
                            :sub="$t('immer · aus dem Kern')"
                        >
                            <div
                                class="grid gap-x-3 gap-y-2 *:mt-0! md:grid-cols-2"
                            >
                                <Field
                                    v-for="l in locales"
                                    :key="`consent-${l.code}`"
                                    v-model="form.consent[textKey(l)]"
                                    :saved="saved(`consent.${textKey(l)}`)"
                                    type="textarea"
                                    :rows="2"
                                    :label="
                                        $t('Text (:code)', {
                                            code: l.code.toUpperCase(),
                                        })
                                    "
                                    optional
                                    :placeholder="
                                        l.default
                                            ? $t(
                                                  'Ich bin einverstanden, dass meine Angaben zur Beantwortung gespeichert werden.',
                                              )
                                            : ''
                                    "
                                    :error="errors[`consent.${textKey(l)}`]"
                                />
                            </div>
                            <p class="mt-1 text-[11.5px] text-basalt-light">
                                {{
                                    $t(
                                        'Leer gilt der Text des Kerns. Der Zeitpunkt der Zustimmung wird mit dem Eingang gespeichert.',
                                    )
                                }}
                            </p>
                        </Card>
                    </fieldset>
                </div>
            </template>

            <!-- ── Einbindung ── -->
            <template #embed>
                <span :class="caption">{{
                    $t('Wo das Formular steht · :count', {
                        count: meta?.embedded.length ?? 0,
                    })
                }}</span>
                <ul
                    v-if="meta && meta.embedded.length > 0"
                    class="rounded-lg border border-ash-light"
                >
                    <li
                        v-for="(e, i) in meta.embedded"
                        :key="i"
                        class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-ash-light px-2.5 py-2 text-[12.5px] last:border-b-0"
                    >
                        <Hex :icon="e.url ? 'pages' : 'theme'" />
                        <span class="min-w-0 flex-1 break-words">{{
                            e.label
                        }}</span>
                        <Button
                            v-if="e.url"
                            variant="link"
                            size="sm"
                            :href="e.url"
                            >{{ $t('Im Editor öffnen') }}</Button
                        >
                        <span v-else class="text-[11.5px] text-basalt-light">{{
                            $t('im Theme (Station C)')
                        }}</span>
                    </li>
                </ul>
                <p
                    v-else
                    class="rounded-lg border border-dashed border-ash px-3 py-2.5 text-[12.5px] text-basalt-light"
                >
                    {{
                        meta
                            ? $t('Noch nirgends eingebunden.')
                            : $t(
                                  'Nach dem Anlegen lässt sich das Formular einbinden.',
                              )
                    }}
                </p>
                <Notice tone="grey" class="mt-2.5">
                    {{ $t('Zwei Wege, ein Formular: als') }}
                    <b>{{ $t('Block «Formular»') }}</b>
                    {{ $t('in jeder Seite (Editor, Palette) oder als') }}
                    <b>{{ $t('Lava-Teil') }}</b>
                    {{ $t('in einer Layout-Region (Station C, Teil vom Typ') }}
                    <span class="font-mono">form</span> {{ $t('mit') }}
                    <span class="font-mono"
                        >source: {{ form.handle || $t('kennung') }}</span
                    >). {{ $t('Der Eingangskorb ist derselbe.') }}
                </Notice>
            </template>
        </Tabs>

        <!-- Fuss -->
        <div
            class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-ash-light pt-3"
        >
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="link" :href="urls.index">{{
                    isNew ? $t('Abbrechen') : $t('← Zu den Formularen')
                }}</Button>
                <template v-if="urls.delete && !readOnly">
                    <Button
                        v-if="!confirmingDelete"
                        variant="danger"
                        size="sm"
                        @click="confirmingDelete = true"
                        >{{ $t('Entfernen') }}</Button
                    >
                    <span
                        v-else
                        class="inline-flex flex-wrap items-center gap-2 text-[12.5px]"
                        role="alertdialog"
                    >
                        <span
                            >{{ $t('Formular wirklich entfernen?') }}
                            {{
                                $tc(
                                    ':count Eingang bleibt bis zum Löschlauf.|:count Eingänge bleiben bis zum Löschlauf.',
                                    meta?.submissions ?? 0,
                                )
                            }}</span
                        >
                        <Button variant="danger" size="sm" @click="destroy">{{
                            $t('Ja, entfernen')
                        }}</Button>
                        <Button
                            variant="secondary"
                            size="sm"
                            @click="confirmingDelete = false"
                            >{{ $t('Nein') }}</Button
                        >
                    </span>
                </template>
            </div>
            <Button v-if="isNew" type="submit" :disabled="form.processing">{{
                $t('Anlegen')
            }}</Button>
        </div>
        <BlockPreview
            v-if="mounted && previewOpen && isDesktop && urls.preview"
            :url="urls.preview"
            :payload="previewPayload"
            :focus="selected?.name ?? null"
            @pick="onPreviewPick"
            @close="previewOpen = false"
        />
    </form>
</template>
