<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useAutosave } from '../../components/autosave';
import BlockFields from '../../components/BlockFields.vue';
import Button from '../../components/Button.vue';
import FieldPalette from '../../components/FieldPalette.vue';
import FieldSketch from '../../components/FieldSketch.vue';
import { uniqueName } from '../../components/forms';
import type { PaletteItem } from '../../components/formPalette';
import { handleFrom } from '../../components/layoutEditor';
import { labelClasses } from '../../components/control';
import Field from '../../components/Field.vue';
import Hex from '../../components/Hex.vue';
import Notice from '../../components/Notice.vue';
import PageHeader from '../../components/PageHeader.vue';
import Toggle from '../../components/Toggle.vue';
import { t, tc } from '../../i18n';
import type {
    BackendProps,
    ContentTypeFormData,
    ContentTypeFormField,
    ContentTypeInfo,
    ContentTypeOptions,
    FieldValue,
    SchemaField,
} from '../../types';

/**
 * Typ anlegen oder bearbeiten (Bereich 17/1, Station E): zwei Ansichten derselben Lava-Quelle — das Formular schreibt
 * über den Drucker nach site/types.lava, die Code-Ansicht zeigt das Ergebnis. Theme-Typen sind sichtbar, aber gesperrt.
 * «Neuer Typ» speichert mit Knopf; ein Site-Typ speichert sich beim Bearbeiten selbst (Autosave). Namen (Typ und Feld)
 * gehen erst beim Verlassen des Feldes raus — jeder Zwischenstand wäre eine Umbenennung, und die Inhalte hängen am Namen.
 */
const props = defineProps<{
    type: ContentTypeInfo | null;
    form: ContentTypeFormData;
    options: ContentTypeOptions;
    read_only: boolean;
    urls: { index: string; save: string; delete: string | null };
    method: 'post' | 'put';
}>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);

const isNew = computed(() => props.type === null);
const locales = computed(() => props.options.locales);
const defaultLocale = computed(() => props.options.default_locale);

function cloneForm(): ContentTypeFormData {
    return JSON.parse(JSON.stringify(props.form)) as ContentTypeFormData;
}

const form = useForm<ContentTypeFormData>(cloneForm());
const autosaving = props.method === 'put' && !props.read_only;
/**
 * Nach «Speichern» bleibt die Komponente stehen — die Felder folgen dem gespeicherten (vielleicht umbenannten) Typ.
 * Beim Autosave nicht: Das Formular ist dort selbst die Quelle, nach dem Umbenennen kommen nur Typ und Adressen neu.
 */
watch(
    () => props.type?.name ?? null,
    () => {
        if (autosaving) {
            return;
        }
        form.defaults(cloneForm());
        form.reset();
        form.clearErrors();
    },
);

const errors = computed(() => form.errors as unknown as Record<string, string>);
const errorCount = computed(() => Object.keys(errors.value).length);

function rowErrors(index: number): string[] {
    const prefix = `fields.${index}`;

    return Object.entries(errors.value)
        .filter(([k]) => k === prefix || k.startsWith(`${prefix}.`))
        .map(([, v]) => v);
}

/* ── Ansichten Formular · Code ── */

const view = ref<'form' | 'code'>('form');

const codeLines = computed(() =>
    props.type ? props.type.lava.replace(/\n$/, '').split('\n') : [],
);

/* ── Kopf ── */

const label = computed(
    () =>
        form.labels[defaultLocale.value] || props.type?.name || form.name || '',
);

const title = computed(() =>
    isNew.value ? t('Neuer Typ') : t('Typ «:type»', { type: label.value }),
);

const sourceText = computed(() => {
    const info = props.type;
    if (!info) {
        return t('neu — wird als Lava in site/types.lava geschrieben');
    }
    if (info.source === 'theme') {
        return t('aus dem Theme (:location)', {
            location: `${info.file}:${info.line}`,
        });
    }
    if (info.source === 'site') {
        return t('dieser Site (:file)', { file: info.file });
    }

    return t('nicht mehr definiert — die Inhalte bleiben');
});

/* ── Felder ── */

const hasTitleField = computed(() =>
    form.fields.some((f) => f.name === 'title'),
);

const fieldTypeOptions = computed(() => props.options.field_types);

const relationOptions = computed(() => [
    { value: '', label: t('— Zieltyp wählen —') },
    ...props.options.types.map((type) => ({ value: type, label: type })),
]);

const templateOptions = computed(() => [
    { value: '', label: '— default —' },
    ...props.options.templates.map((template) => ({
        value: template.name,
        label: t(':name — für :for', {
            name: template.name,
            for: template.for,
        }),
    })),
]);

function setField(index: number, patch: Partial<ContentTypeFormField>): void {
    // Wird ein Feld zu «Blöcke», sind alle Blöcke des Themes erlaubt — ohne Auswahl gäbe es keinen Inhaltsbereich
    if (
        patch.type === 'blocks' &&
        (form.fields[index]?.allow ?? []).length === 0
    ) {
        patch = { ...patch, allow: [...props.options.blocks] };
    }
    form.fields = form.fields.map((f, i) =>
        i === index ? { ...f, ...patch } : f,
    );
}

/* ── TB 32: geführt wie Block- und Formular-Baukasten — Palette, Namen aus der Beschriftung, Wichtigstes zuerst ── */

/** Eine Kennung je Feld, die Umsortieren und Ändern überlebt (ein Symbol geht nicht mit zum Server) */
const UID = Symbol('feld');
type Keyed = ContentTypeFormField & { [UID]?: number };
let nextUid = 1;
/** Felder dieser Sitzung, deren Name der Beschriftung folgt — bis jemand ihn selbst ändert */
const following = new Set<number>();

function uidOf(index: number): number {
    const field = form.fields[index] as Keyed | undefined;
    if (!field) {
        return 0;
    }
    field[UID] ??= nextUid++;

    return field[UID];
}

const selected = ref<number | null>(null);
const moreOpen = ref<number | null>(null);
const labelsOpen = ref(false);
const routesOpen = ref(false);
const typeNameTouched = ref(!isNew.value);
const otherLocales = computed(() =>
    locales.value.filter((l) => l !== defaultLocale.value),
);

function typeLabel(type: string): string {
    return fieldTypeOptions.value.find((o) => o.value === type)?.label ?? type;
}

function fieldLabel(field: ContentTypeFormField): string {
    return field.labels?.[defaultLocale.value] || field.name || t('Neues Feld');
}

/** Der Name aus einer Beschriftung: «Kunde & Ort» → «kunde_ort», frei im Typ. */
function fieldNameFrom(index: number, label: string): string {
    const base = handleFrom(label)
        .replace(/-/g, '_')
        .replace(/^[^a-z]+/, '');
    const taken = form.fields.filter((_, i) => i !== index).map((f) => f.name);

    return base === ''
        ? (form.fields[index]?.name ?? '')
        : uniqueName(base, taken);
}

function setTypeLabel(value: string): void {
    form.labels = { ...form.labels, [defaultLocale.value]: value };
    if (!typeNameTouched.value) {
        form.name = handleFrom(value)
            .replace(/^[^a-z]+/, '')
            .slice(0, 40);
    }
}

function setFieldLabel(index: number, locale: string, value: string): void {
    const field = form.fields[index];
    if (!field) {
        return;
    }
    const patch: Partial<ContentTypeFormField> = {
        labels: { ...field.labels, [locale]: value },
    };
    if (locale === defaultLocale.value && following.has(uidOf(index))) {
        patch.name = fieldNameFrom(index, value);
    }
    setField(index, patch);
}

function setFieldName(index: number, value: string): void {
    following.delete(uidOf(index));
    setField(index, { name: value });
}

function isFollowing(index: number): boolean {
    return following.has(uidOf(index));
}

function addItem(item: PaletteItem): void {
    const taken = form.fields.map((f) => f.name);
    const labels: Record<string, string> = {};
    for (const l of locales.value) {
        const preset = item.preset?.labels[l as 'de' | 'en' | 'it' | 'fr'];
        labels[l] = preset ?? (l === defaultLocale.value ? item.label : '');
    }
    const field: Keyed = {
        name: item.preset
            ? uniqueName(item.preset.name, taken)
            : uniqueName(
                  handleFrom(item.label).replace(/-/g, '_') || item.type,
                  taken,
              ),
        type: item.type,
        required: item.preset?.required === true,
        translatable:
            item.preset?.translatable ??
            ['text', 'textarea', 'richtext', 'markdown'].includes(item.type),
        options: '',
        default: '',
        relation: '',
        allow: item.type === 'blocks' ? [...props.options.blocks] : [],
        labels,
        help: '',
        [UID]: nextUid++,
    };
    following.add(field[UID] ?? 0);
    form.fields = [...form.fields, field];
    selected.value = form.fields.length - 1;
    moreOpen.value = null;
}

function removeField(index: number): void {
    form.fields = form.fields.filter((_, i) => i !== index);
    if (selected.value === index) {
        selected.value = null;
    } else if (selected.value !== null && selected.value > index) {
        selected.value--;
    }
}

/** Weitere Möglichkeiten eines Feldes: offen auf Wunsch oder wenn dort ein Fehler steht. */
function moreFor(index: number): boolean {
    if (moreOpen.value === index) {
        return true;
    }
    const prefix = `fields.${index}.`;

    return Object.keys(errors.value).some(
        (k) =>
            k.startsWith(prefix) &&
            !['options', 'relation', 'allow'].some((p) =>
                k.startsWith(prefix + p),
            ) &&
            k !== `${prefix}labels.${defaultLocale.value}`,
    );
}

/** Route einer weiteren Sprache aus ihrer Beschriftung: «Projects» → «/projects/{slug}». */
function routeSuggestion(locale: string): string {
    const label = (form.labels[locale] ?? '').trim();
    const slug = handleFrom(label);

    return slug === '' ? '' : `/${slug}/{slug}`;
}

/* ── Vorschau des Editors: dieselben Felder, wie die Redaktion sie sieht ── */
const previewValues = ref<Record<string, FieldValue>>({});
const previewFields = computed<SchemaField[]>(() =>
    form.fields
        .filter((f) => /^[a-z][a-z0-9_]*$/.test(f.name))
        .map((f) => ({
            name: f.name,
            label: fieldLabel(f),
            help: f.help || null,
            type: f.type,
            required: f.required,
            translatable: f.translatable,
            options: f.options
                .split(',')
                .map((o) => o.trim())
                .filter(Boolean),
            default: f.default === '' ? null : f.default,
            allow: f.allow,
            relation: f.relation || null,
            fields: [],
        })),
);

function moveField(index: number, delta: number): void {
    const target = index + delta;
    if (target < 0 || target >= form.fields.length) {
        return;
    }
    if (selected.value === index) {
        selected.value = target;
    } else if (selected.value === target) {
        selected.value = index;
    }
    const next = [...form.fields];
    const a = next[index];
    const b = next[target];
    if (!a || !b) {
        return;
    }
    next[index] = b;
    next[target] = a;
    form.fields = next;
}

function toggleAllow(index: number, block: string, on: boolean): void {
    const current = form.fields[index]?.allow ?? [];
    setField(index, {
        allow: on
            ? [...new Set([...current, block])]
            : current.filter((b) => b !== block),
    });
}

/* ── Löschen (inline bestätigen) und Senden ── */

const confirmingDelete = ref(false);

function destroy(): void {
    if (props.urls.delete) {
        router.delete(props.urls.delete);
    }
}

const namePattern = /^[a-z][a-z0-9-]*$/;
/** Solange ein Name (Typ oder Feld) den Fokus hat, wartet das Speichern — erst beim Verlassen geht er raus. */
const editingName = ref(false);
const fieldNamePattern = /^[a-z][a-z0-9_]*$/;

/* Eine Zwischenfassung, die der Lava-Prüfer abweist, kommt als Fehler zurück — daher die längere Pause */
const autosave = autosaving
    ? useAutosave(form, {
          url: () => props.urls.save,
          delay: 1200,
          only: ['type', 'urls', 'errors'],
          enabled: () =>
              !editingName.value &&
              namePattern.test(form.name) &&
              (form.labels[defaultLocale.value] ?? '').trim() !== '' &&
              form.fields.length > 0 &&
              form.fields.every(
                  (f) =>
                      fieldNamePattern.test(f.name) &&
                      (f.type !== 'relation' || f.relation !== ''),
              ),
          labels: {
              name: t('Name'),
              labels: t('Beschriftung'),
              fields: t('Felder'),
              route: t('Route'),
              template: t('Template'),
          },
      })
    : null;

function saved(key: string): boolean {
    return autosave?.saved(key) ?? false;
}

function nameFocus(): void {
    editingName.value = true;
}

/** Namen gehen erst beim Verlassen des Feldes raus — sonst wäre jeder Zwischenstand eine Umbenennung. */
function nameBlur(): void {
    editingName.value = false;
    autosave?.flush();
}

function submit(): void {
    if (props.read_only) {
        return;
    }
    if (autosave) {
        editingName.value = false;
        autosave.flush();

        return;
    }
    save(false);
}

function save(close: boolean): void {
    form.transform((data) => (close ? { ...data, close: true } : data));
    const options = { preserveScroll: true };
    if (props.method === 'put') {
        form.put(props.urls.save, options);
    } else {
        form.post(props.urls.save, options);
    }
}

const checkbox = 'accent-lava-dark';
const opButton =
    'inline-flex min-h-6 min-w-6 items-center justify-center rounded px-1 text-basalt-light hover:bg-paper hover:text-basalt disabled:text-ash-light';
const viewButton =
    'px-2.5 py-[5px] text-xs font-semibold transition-colors first:rounded-l-md last:rounded-r-md';
</script>

<template>
    <Head :title="title" />
    <PageHeader
        :title="title"
        :crumb="$t(':site › Einrichtung › Typen', { site: siteName })"
    >
        <template #lead>
            <span class="font-mono">{{ form.kind }}</span> · {{ sourceText }}
            <template v-if="type">
                · {{ $tc(':count Inhalt|:count Inhalte', type.entries) }}
            </template>
        </template>
        <template #actions>
            <div
                class="inline-flex rounded-md border border-ash bg-white"
                role="group"
                :aria-label="$t('Ansicht')"
            >
                <button
                    type="button"
                    :class="[
                        viewButton,
                        view === 'form'
                            ? 'bg-basalt text-paper'
                            : 'text-basalt-light hover:text-basalt',
                    ]"
                    :aria-pressed="view === 'form'"
                    @click="view = 'form'"
                >
                    {{ $t('Formular') }}
                </button>
                <button
                    type="button"
                    :class="[
                        viewButton,
                        view === 'code'
                            ? 'bg-basalt text-paper'
                            : 'text-basalt-light hover:text-basalt',
                    ]"
                    :aria-pressed="view === 'code'"
                    @click="view = 'code'"
                >
                    {{ $t('Code') }}
                </button>
            </div>
            <Button variant="link" :href="urls.index">{{
                $t('← Typen')
            }}</Button>
        </template>
    </PageHeader>

    <Notice v-if="read_only" tone="grey" class="mb-4">
        {{ $t('Dieser Typ kommt aus dem Theme. Bearbeiten in') }}
        <span class="font-mono">theme.lava</span>
        {{ $t('(Station E) oder im Theme-Editor.') }}
    </Notice>

    <!-- Code: dieselbe Quelle, nur lesend -->
    <section
        v-if="view === 'code'"
        class="rounded-lg border border-ash-light bg-white px-4 pt-3.5 pb-3.5"
    >
        <div class="flex items-center gap-2 text-sm font-semibold">
            <Hex icon="type" tone="basalt" />
            <span>{{
                type ? `${type.file}:${type.line}` : 'site/types.lava'
            }}</span>
        </div>
        <p v-if="!type" class="mt-2 text-[12.5px] text-basalt-light">
            {{
                $t(
                    'Der Code erscheint nach dem Speichern — Basalto schreibt ihn in',
                )
            }}
            <span class="font-mono">site/types.lava</span>.
        </p>
        <ol
            v-else
            class="mt-2.5 rounded-md bg-basalt px-3 py-2.5 font-mono text-[12px] leading-[1.55] text-paper"
        >
            <li
                v-for="(line, i) in codeLines"
                :key="i"
                class="grid grid-cols-[2.5rem_1fr] gap-2"
            >
                <span class="text-right text-ash select-none">{{
                    type.line + i
                }}</span>
                <span class="break-words whitespace-pre-wrap">{{
                    line || ' '
                }}</span>
            </li>
        </ol>
        <p class="mt-2 text-[11.5px] text-basalt-light">
            {{
                $t(
                    'Formular und Code sind zwei Ansichten derselben Quelle — beim Speichern bleibt der Inhalt, die Schreibweise des Codes (Einzüge, Kommentare) kann sich ändern.',
                )
            }}
        </p>
    </section>

    <!-- Formular -->
    <form
        v-else
        class="rounded-lg border border-ash-light bg-white px-4 pt-3.5 pb-3.5"
        novalidate
        @submit.prevent="submit"
    >
        <div class="flex items-center gap-2 text-sm font-semibold">
            <Hex icon="type" tone="basalt" />
            <span>{{ isNew ? $t('Typ anlegen') : label }}</span>
            <span
                v-if="!isNew"
                class="font-mono text-[11.5px] font-normal text-basalt-light"
                >{{ form.name }}</span
            >
        </div>
        <p class="mt-0.5 text-xs text-basalt-light">
            {{
                $t(
                    'Name, Beschriftung, Felder, Route und Template — Basalto schreibt daraus Lava; der Code daneben ist dieselbe Quelle.',
                )
            }}
            <template v-if="autosave">
                {{
                    $t(
                        'Änderungen speichern sich selbst, Namen beim Verlassen des Feldes.',
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
                    'Die übrigen Eingaben sind erhalten — die Fehler stehen am Feld.',
                )
            }}
        </Notice>

        <div class="grid gap-x-6 gap-y-4 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <div class="min-w-0">
                <!-- Art -->
                <template v-if="isNew">
                    <span :class="[labelClasses, 'mt-4']">{{ $t('Art') }}</span>
                    <div class="flex flex-wrap gap-x-5 gap-y-1 text-[12.5px]">
                        <label class="flex items-start gap-1.5">
                            <input
                                v-model="form.kind"
                                type="radio"
                                name="kind"
                                value="type"
                                :class="[checkbox, 'mt-0.5']"
                            />
                            <span
                                >{{ $t('Inhaltstyp') }}
                                <span
                                    class="block text-[11.5px] text-basalt-light"
                                    >{{
                                        $t(
                                            'viele Inhalte mit Adresse — Liste und Editor entstehen aus den Feldern',
                                        )
                                    }}</span
                                ></span
                            >
                        </label>
                        <label class="flex items-start gap-1.5">
                            <input
                                v-model="form.kind"
                                type="radio"
                                name="kind"
                                value="global"
                                :class="[checkbox, 'mt-0.5']"
                            />
                            <span
                                >{{ $t('Einzelinhalt') }}
                                <span
                                    class="block text-[11.5px] text-basalt-light"
                                    ><span class="font-mono">global</span> —
                                    {{
                                        $t(
                                            'genau einer je Site, ohne Adresse (Fusszeile, Kontakt)',
                                        )
                                    }}</span
                                ></span
                            >
                        </label>
                    </div>
                    <p
                        v-if="errors.kind"
                        class="text-danger mt-1 text-[11.5px] font-semibold"
                    >
                        {{ errors.kind }}
                    </p>
                </template>

                <!-- Beschriftung und Name (TB 32: der Name entsteht aus der Beschriftung) -->
                <div class="mt-3 grid gap-x-4 gap-y-2.5 *:mt-0! md:grid-cols-2">
                    <Field
                        :disabled="read_only"
                        :model-value="form.labels[defaultLocale] ?? ''"
                        :saved="saved(`labels.${defaultLocale}`)"
                        :label="$t('Beschriftung')"
                        required
                        :maxlength="80"
                        :placeholder="
                            form.kind === 'type'
                                ? $t('Projekt')
                                : $t('Fusszeile')
                        "
                        :error="errors[`labels.${defaultLocale}`]"
                        :help="
                            $t(
                                'So heisst der Typ in Listen, Reitern und im Editor.',
                            )
                        "
                        @update:model-value="setTypeLabel($event)"
                    />
                    <Field
                        :disabled="read_only"
                        v-model="form.name"
                        :saved="saved('name')"
                        :label="$t('Name')"
                        mono
                        required
                        placeholder="projekt"
                        pattern="[a-z][a-z0-9\-]*"
                        :maxlength="40"
                        :error="errors.name"
                        :help="
                            isNew && !typeNameTouched
                                ? $t(
                                      'Entsteht aus der Beschriftung · Bezeichner in Lava und in der Route · a–z, 0–9, Bindestrich',
                                  )
                                : $t(
                                      'Bezeichner in Lava und in der Route · a–z, 0–9, Bindestrich',
                                  )
                        "
                        @update:model-value="typeNameTouched = true"
                        @focusin="nameFocus"
                        @focusout="nameBlur"
                    />
                </div>
                <template v-if="otherLocales.length > 0">
                    <button
                        type="button"
                        class="mt-2 text-[12px] font-semibold text-lava-dark underline hover:text-basalt"
                        :aria-expanded="labelsOpen"
                        @click="labelsOpen = !labelsOpen"
                    >
                        {{
                            $t('Beschriftung in weiteren Sprachen (:codes)', {
                                codes: otherLocales
                                    .map((l) => l.toUpperCase())
                                    .join(' · '),
                            })
                        }}
                    </button>
                    <div
                        v-show="labelsOpen"
                        class="mt-2 grid gap-x-4 gap-y-2.5 *:mt-0! md:grid-cols-2"
                    >
                        <Field
                            :disabled="read_only"
                            v-for="l in otherLocales"
                            :key="l"
                            v-model="form.labels[l]"
                            :saved="saved(`labels.${l}`)"
                            :label="
                                $t('Beschriftung (:code)', {
                                    code: l.toUpperCase(),
                                })
                            "
                            optional
                            :maxlength="80"
                            :error="errors[`labels.${l}`]"
                            :help="$t('leer = Beschriftung der Grundsprache')"
                        />
                    </div>
                </template>

                <!-- Felder -->
                <span :class="[labelClasses, 'mt-4']"
                    >{{ $t('Felder · :count', { count: form.fields.length })
                    }}<span
                        v-if="saved('fields')"
                        class="text-success ml-1.5 font-semibold tracking-normal normal-case"
                        >{{ $t('✓ gespeichert') }}</span
                    ></span
                >
                <p
                    v-if="form.kind === 'type' && !hasTitleField"
                    class="text-warning mb-1 text-[11.5px]"
                >
                    {{ $t('Ein Inhaltstyp braucht ein Feld') }}
                    <span class="font-mono">title</span>
                    {{
                        $t(
                            '(Text) — es trägt den Titel in Listen und im Editor.',
                        )
                    }}
                </p>
                <ul
                    class="rounded-lg border border-ash-light"
                    :aria-label="$t('Felder')"
                >
                    <li
                        v-for="(f, i) in form.fields"
                        :key="i"
                        class="border-b border-ash-light text-[12.5px] last:border-b-0"
                        :class="[
                            selected === i
                                ? 'bg-lava/5 shadow-[inset_3px_0_0_var(--color-lava)]'
                                : '',
                            rowErrors(i).length > 0 ? 'bg-danger/5' : '',
                        ]"
                    >
                        <div
                            class="flex flex-wrap items-start gap-x-2 gap-y-1 px-2.5 py-2"
                        >
                            <span
                                class="mt-0.5 w-10 shrink-0 rounded bg-paper p-0.5"
                            >
                                <FieldSketch :type="f.type" />
                            </span>
                            <div class="min-w-40 flex-1">
                                <button
                                    type="button"
                                    class="text-left font-semibold break-words hover:underline"
                                    @click="
                                        selected = selected === i ? null : i
                                    "
                                >
                                    {{ fieldLabel(f) }}
                                </button>
                                <p
                                    class="text-[11.5px] break-words text-basalt-light"
                                >
                                    <span class="font-mono">{{
                                        f.name || '—'
                                    }}</span>
                                    · {{ typeLabel(f.type) }}
                                    <template v-if="f.required">
                                        · {{ $t('Pflicht') }}</template
                                    >
                                    <template v-if="f.translatable">
                                        · {{ $t('übersetzbar') }}</template
                                    >
                                </p>
                                <p
                                    v-for="(message, k) in rowErrors(i)"
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
                                <template v-if="!read_only">
                                    <button
                                        type="button"
                                        :class="opButton"
                                        :disabled="i === 0"
                                        :title="$t('nach oben')"
                                        :aria-label="$t('nach oben')"
                                        @click="moveField(i, -1)"
                                    >
                                        ↑
                                    </button>
                                    <button
                                        type="button"
                                        :class="opButton"
                                        :disabled="i === form.fields.length - 1"
                                        :title="$t('nach unten')"
                                        :aria-label="$t('nach unten')"
                                        @click="moveField(i, 1)"
                                    >
                                        ↓
                                    </button>
                                </template>
                                <button
                                    type="button"
                                    class="px-1 text-lava-dark underline hover:text-basalt"
                                    :aria-expanded="selected === i"
                                    @click="
                                        selected = selected === i ? null : i
                                    "
                                >
                                    {{
                                        read_only
                                            ? $t('Ansehen')
                                            : $t('Bearbeiten')
                                    }}
                                </button>
                                <button
                                    v-if="!read_only"
                                    type="button"
                                    class="text-danger rounded px-1 hover:underline"
                                    :title="$t('Entfernen')"
                                    :aria-label="$t('Entfernen')"
                                    @click="removeField(i)"
                                >
                                    ✕
                                </button>
                            </span>
                        </div>

                        <!-- Einstellungen des Feldes: das Wichtigste zuerst -->
                        <div
                            v-if="selected === i"
                            class="border-t border-ash-light bg-white px-3 pt-1 pb-3"
                        >
                            <Field
                                :disabled="read_only"
                                :model-value="f.labels[defaultLocale] ?? ''"
                                class="mt-2!"
                                :label="$t('Beschriftung')"
                                required
                                :maxlength="120"
                                :help="
                                    $t(
                                        'Steht im Editor über dem Feld; der Name entsteht daraus.',
                                    )
                                "
                                :error="
                                    errors[
                                        `fields.${i}.labels.${defaultLocale}`
                                    ]
                                "
                                @update:model-value="
                                    setFieldLabel(i, defaultLocale, $event)
                                "
                            />
                            <div
                                class="mt-2.5 flex flex-wrap gap-x-5 gap-y-1.5"
                            >
                                <label class="flex items-center gap-1.5">
                                    <Toggle
                                        :model-value="f.required"
                                        :disabled="read_only"
                                        :label="$t('Pflicht')"
                                        @update:model-value="
                                            setField(i, { required: $event })
                                        "
                                    />
                                    {{ $t('Pflicht') }}
                                    <span
                                        class="text-[11.5px] text-basalt-light"
                                        >{{
                                            $t('— Einreichen erst mit Inhalt')
                                        }}</span
                                    >
                                </label>
                                <label class="flex items-center gap-1.5">
                                    <Toggle
                                        :model-value="f.translatable"
                                        :disabled="read_only"
                                        :label="$t('übersetzbar')"
                                        @update:model-value="
                                            setField(i, {
                                                translatable: $event,
                                            })
                                        "
                                    />
                                    {{ $t('übersetzbar') }}
                                    <span
                                        class="text-[11.5px] text-basalt-light"
                                        >{{
                                            f.translatable
                                                ? $t(
                                                      '— je Sprache eigener Text',
                                                  )
                                                : $t('— gilt für alle Sprachen')
                                        }}</span
                                    >
                                </label>
                            </div>

                            <!-- Was der Typ unbedingt braucht -->
                            <Field
                                :disabled="read_only"
                                v-if="f.type === 'select'"
                                :model-value="f.options"
                                class="mt-2.5!"
                                :label="$t('Optionen')"
                                mono
                                placeholder="produkt, projekt"
                                :help="
                                    $t(
                                        'Mit Komma getrennt, in der Reihenfolge der Auswahl.',
                                    )
                                "
                                :error="errors[`fields.${i}.options`]"
                                @update:model-value="
                                    setField(i, { options: $event })
                                "
                            />
                            <div v-else-if="f.type === 'blocks'" class="mt-2.5">
                                <span :class="labelClasses">{{
                                    $t('Erlaubte Blöcke')
                                }}</span>
                                <p
                                    v-if="options.blocks.length === 0"
                                    class="text-ash-dark text-xs"
                                >
                                    {{
                                        $t(
                                            'Das Theme hat keine Blöcke (Station F).',
                                        )
                                    }}
                                </p>
                                <div
                                    v-else
                                    class="flex flex-wrap gap-x-3 gap-y-1"
                                >
                                    <label
                                        v-for="b in options.blocks"
                                        :key="b"
                                        class="flex items-center gap-1 font-mono text-xs"
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="f.allow.includes(b)"
                                            :disabled="read_only"
                                            :class="checkbox"
                                            @change="
                                                toggleAllow(
                                                    i,
                                                    b,
                                                    (
                                                        $event.target as HTMLInputElement
                                                    ).checked,
                                                )
                                            "
                                        />
                                        {{ b }}
                                    </label>
                                </div>
                                <p class="mt-1 text-[11.5px] text-basalt-light">
                                    {{
                                        $t(
                                            'Welche Blöcke die Redaktion hier einsetzen darf — ohne Auswahl hat der Editor keinen Inhaltsbereich.',
                                        )
                                    }}
                                </p>
                            </div>
                            <Field
                                :disabled="read_only"
                                v-else-if="f.type === 'relation'"
                                :model-value="f.relation"
                                class="mt-2.5!"
                                :label="$t('Zieltyp')"
                                type="select"
                                :options="relationOptions"
                                :help="
                                    $t(
                                        'Auf welche Inhalte das Feld verweist, z. B. «person».',
                                    )
                                "
                                :error="errors[`fields.${i}.relation`]"
                                @update:model-value="
                                    setField(i, { relation: $event })
                                "
                            />

                            <!-- Weitere Möglichkeiten -->
                            <button
                                type="button"
                                class="mt-3 flex w-full items-center gap-1.5 rounded-md border border-dashed border-ash-light px-3 py-2 text-left text-[12.5px] font-semibold text-basalt-light hover:border-ash hover:text-basalt"
                                :aria-expanded="moreFor(i)"
                                @click="moreOpen = moreFor(i) ? null : i"
                            >
                                <span aria-hidden="true">{{
                                    moreFor(i) ? '−' : '+'
                                }}</span>
                                {{
                                    moreFor(i)
                                        ? $t('Weniger zeigen')
                                        : $t(
                                              'Weitere Möglichkeiten — Name, Typ, Hilfetext, Vorgabe, Übersetzungen',
                                          )
                                }}
                            </button>
                            <div v-show="moreFor(i)">
                                <div
                                    class="mt-2.5 grid gap-x-3 gap-y-2.5 *:mt-0! sm:grid-cols-2"
                                >
                                    <Field
                                        :disabled="read_only"
                                        :model-value="f.name"
                                        :label="$t('Name')"
                                        mono
                                        required
                                        placeholder="kunde"
                                        :maxlength="40"
                                        :error="errors[`fields.${i}.name`]"
                                        :help="
                                            isFollowing(i)
                                                ? $t(
                                                      'Schlüssel der Inhalte und in Lava — folgt der Beschriftung, bis Sie ihn ändern',
                                                  )
                                                : $t(
                                                      'Schlüssel der Inhalte und in Lava — bei bestehenden Inhalten nur mit Bedacht ändern',
                                                  )
                                        "
                                        @update:model-value="
                                            setFieldName(i, $event)
                                        "
                                        @focusin="nameFocus"
                                        @focusout="nameBlur"
                                    />
                                    <Field
                                        :disabled="read_only"
                                        :model-value="f.type"
                                        :label="$t('Typ')"
                                        type="select"
                                        :options="fieldTypeOptions"
                                        :error="errors[`fields.${i}.type`]"
                                        @update:model-value="
                                            setField(i, { type: $event })
                                        "
                                    />
                                </div>
                                <Field
                                    :disabled="read_only"
                                    :model-value="f.help"
                                    class="mt-2.5!"
                                    :label="$t('Hilfetext')"
                                    optional
                                    :maxlength="300"
                                    :help="
                                        $t(
                                            'Steht im Editor klein unter dem Feld.',
                                        )
                                    "
                                    @update:model-value="
                                        setField(i, { help: $event })
                                    "
                                />
                                <Field
                                    :disabled="read_only"
                                    v-if="
                                        ![
                                            'select',
                                            'blocks',
                                            'relation',
                                        ].includes(f.type)
                                    "
                                    :model-value="f.default"
                                    class="mt-2.5!"
                                    :label="$t('Vorgabe')"
                                    mono
                                    optional
                                    :placeholder="$t('leer')"
                                    :help="
                                        $t(
                                            'Steht in neuen Inhalten schon im Feld.',
                                        )
                                    "
                                    :error="errors[`fields.${i}.default`]"
                                    @update:model-value="
                                        setField(i, { default: $event })
                                    "
                                />
                                <div
                                    v-if="otherLocales.length > 0"
                                    class="mt-2.5 grid gap-x-3 gap-y-2.5 *:mt-0! sm:grid-cols-2"
                                >
                                    <Field
                                        :disabled="read_only"
                                        v-for="l in otherLocales"
                                        :key="l"
                                        :model-value="f.labels[l] ?? ''"
                                        :label="
                                            $t('Beschriftung (:code)', {
                                                code: l.toUpperCase(),
                                            })
                                        "
                                        optional
                                        :maxlength="120"
                                        :help="$t('leer = Grundsprache')"
                                        @update:model-value="
                                            setFieldLabel(i, l, $event)
                                        "
                                    />
                                </div>
                            </div>
                        </div>
                    </li>
                </ul>
                <p
                    v-if="form.fields.length === 0"
                    class="text-ash-dark py-2 text-xs"
                >
                    {{ $t('Noch kein Feld — unten eines hinzufügen.') }}
                </p>
                <p
                    v-if="errors.fields"
                    class="text-danger mt-2 text-[11.5px] font-semibold"
                    role="alert"
                >
                    {{ errors.fields }}
                </p>
                <FieldPalette
                    v-if="!read_only"
                    class="mt-3"
                    kind="type"
                    :types="fieldTypeOptions"
                    :locale="defaultLocale"
                    @add="addItem($event)"
                />

                <!-- Route und Template -->
                <template v-if="form.kind === 'type'">
                    <span :class="[labelClasses, 'mt-4']">{{
                        $t('Adresse · Template')
                    }}</span>
                    <div class="grid gap-x-4 gap-y-2.5 *:mt-0! md:grid-cols-2">
                        <Field
                            :disabled="read_only"
                            v-model="form.route[defaultLocale]"
                            :saved="saved(`route.${defaultLocale}`)"
                            :label="$t('Route')"
                            mono
                            optional
                            :placeholder="`/${form.name || 'projekt'}/{slug}`"
                            :maxlength="120"
                            :error="errors[`route.${defaultLocale}`]"
                            :help="
                                $t(
                                    'leer = :route · {slug} ist der Slug des Inhalts',
                                    {
                                        route: `/${form.name || '‹name›'}/{slug}`,
                                    },
                                )
                            "
                        />
                        <Field
                            :disabled="read_only"
                            v-model="form.template"
                            :saved="saved('template')"
                            :label="$t('Template')"
                            type="select"
                            :options="templateOptions"
                            :error="errors.template"
                            :help="
                                $t(
                                    'Wie ein Inhalt des Typs aussieht (Station G) — leer = default',
                                )
                            "
                        />
                    </div>
                    <template v-if="otherLocales.length > 0">
                        <button
                            type="button"
                            class="mt-2 text-[12px] font-semibold text-lava-dark underline hover:text-basalt"
                            :aria-expanded="
                                routesOpen ||
                                otherLocales.some((l) => errors[`route.${l}`])
                            "
                            @click="routesOpen = !routesOpen"
                        >
                            {{
                                $t('Routen in weiteren Sprachen (:codes)', {
                                    codes: otherLocales
                                        .map((l) => l.toUpperCase())
                                        .join(' · '),
                                })
                            }}
                        </button>
                        <div
                            v-show="
                                routesOpen ||
                                otherLocales.some((l) => errors[`route.${l}`])
                            "
                            class="mt-2 grid gap-x-4 gap-y-2.5 *:mt-0! md:grid-cols-2"
                        >
                            <div
                                v-for="l in otherLocales"
                                :key="l"
                                class="min-w-0"
                            >
                                <Field
                                    :disabled="read_only"
                                    v-model="form.route[l]"
                                    :saved="saved(`route.${l}`)"
                                    :label="
                                        $t('Route (:code)', {
                                            code: l.toUpperCase(),
                                        })
                                    "
                                    mono
                                    optional
                                    :placeholder="`/${form.name || 'projekt'}/{slug}`"
                                    :maxlength="120"
                                    :error="errors[`route.${l}`]"
                                />
                                <button
                                    v-if="
                                        !read_only &&
                                        routeSuggestion(l) &&
                                        form.route[l] !== routeSuggestion(l)
                                    "
                                    type="button"
                                    class="mt-1 text-[11.5px] text-lava-dark underline hover:text-basalt"
                                    @click="form.route[l] = routeSuggestion(l)"
                                >
                                    {{
                                        $t(
                                            'Aus der Beschriftung: :route übernehmen',
                                            {
                                                route: routeSuggestion(l),
                                            },
                                        )
                                    }}
                                </button>
                            </div>
                        </div>
                    </template>
                    <p
                        v-if="options.templates.length === 0"
                        class="text-warning mt-1 text-[11.5px]"
                    >
                        {{
                            $t(
                                'Das Theme liefert kein Template — Station G prüfen.',
                            )
                        }}
                    </p>
                </template>
                <Notice v-else tone="grey" class="mt-4">
                    {{
                        $t(
                            'Ein Einzelinhalt hat keine Adresse und kein Template — das Theme liest ihn über',
                        )
                    }}
                    <span class="font-mono"
                        >{{ form.name || '‹name›' }}.feld</span
                    >.
                </Notice>
            </div>

            <!-- TB 32: So sieht die Redaktion den Editor — aus denselben Feldern, bei jeder Änderung neu -->
            <aside class="min-w-0" :aria-label="$t('Vorschau des Editors')">
                <div
                    class="sticky top-4 rounded-lg border border-ash-light bg-paper px-3 py-2.5"
                >
                    <p class="text-[13px] font-semibold">
                        {{ $t('Vorschau des Editors') }}
                    </p>
                    <p class="mb-2 text-[11.5px] text-basalt-light">
                        {{
                            $t(
                                'So sieht die Redaktion einen neuen Inhalt — ausprobieren erlaubt, gespeichert wird nichts.',
                            )
                        }}
                    </p>
                    <div
                        class="rounded-md border border-ash-light bg-white p-3"
                    >
                        <BlockFields
                            v-model="previewValues"
                            :fields="previewFields"
                            :media="[]"
                            :errors="{}"
                            path="vorschau"
                        />
                    </div>
                </div>
            </aside>
        </div>

        <!-- Fuss -->
        <div
            class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-ash-light pt-3"
        >
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="link" :href="urls.index">{{
                    read_only || autosave
                        ? $t('← Zu den Typen')
                        : $t('Abbrechen')
                }}</Button>
                <template v-if="urls.delete && !read_only">
                    <Button
                        v-if="!confirmingDelete"
                        variant="danger"
                        size="sm"
                        @click="confirmingDelete = true"
                        >{{ $t('Löschen') }}</Button
                    >
                    <span
                        v-else
                        class="inline-flex flex-wrap items-center gap-2 text-[12.5px]"
                        role="alertdialog"
                    >
                        <span
                            >{{ $t('Wirklich löschen?') }}
                            {{
                                $tc(
                                    ':count Inhalt bleibt erhalten.|:count Inhalte bleiben erhalten.',
                                    type?.entries ?? 0,
                                )
                            }}</span
                        >
                        <Button variant="danger" size="sm" @click="destroy">{{
                            $t('Ja, löschen')
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
            <div v-if="!read_only && !autosave" class="flex flex-wrap gap-2">
                <Button
                    variant="secondary"
                    :disabled="form.processing"
                    @click="save(true)"
                    >{{ $t('Speichern und schliessen') }}</Button
                >
                <Button type="submit" :disabled="form.processing">{{
                    $t('Speichern')
                }}</Button>
            </div>
        </div>
    </form>
</template>
