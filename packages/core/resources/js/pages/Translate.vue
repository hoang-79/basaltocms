<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import Button from '../components/Button.vue';
import Card from '../components/Card.vue';
import { controlClasses } from '../components/control';
import EmptyState from '../components/EmptyState.vue';
import ModuleSlot from '../components/ModuleSlot.vue';
import Notice from '../components/Notice.vue';
import PageHeader from '../components/PageHeader.vue';
import PairRow from '../components/PairRow.vue';
import RichTextField from '../components/RichTextField.vue';
import TranslationMark from '../components/TranslationMark.vue';
import { useAutosave } from '../components/autosave';
import {
    allFields,
    displayValue,
    fillableCount,
    fillEmpty,
    initialValues,
    isBlank,
    progressOf,
    progressText,
    takeOver,
    type TranslationValues,
} from '../components/translation';
import { t } from '../i18n';
import { pathOf } from '../support';
import type {
    BackendProps,
    FieldValue,
    TranslationField,
    TranslationLocale,
    TranslationProgress,
    TranslationSection,
    TranslationState,
} from '../types';

/**
 * Übersetzen nebeneinander (Bereich 12, M-06): links die Standardsprache nur lesend, rechts die Zielsprache Feld für
 * Feld. Ein Feld, das sich in der Quelle seit der letzten Übersetzung geändert hat, trägt «geändert» mit dem alten
 * Text; geteilte Felder (Bild, Link) stehen grau. «→ übernehmen» kopiert genau ein Feld, der Knopf oben alle leeren.
 * Ohne Fassung in der Zielsprache ein Leerzustand mit einem Knopf. Unter 1024 px Quelle und Ziel untereinander.
 * Gespeichert wird automatisch (Wunsch Hoang 16.10.2026) in den offenen Entwurf der Zielsprache — auf der
 * angezeigten Quellfassung, damit die Übersetzung danach «aktuell» ist.
 */
const props = defineProps<{
    entry: { id: number; type: string; type_label: string; title: string };
    site: { name: string; default_locale: string };
    source: {
        id: number;
        locale: string;
        number: number;
        status: string;
        status_label: string;
    } | null;
    target: {
        locale: string;
        number: number | null;
        status: string | null;
        status_label: string | null;
        is_required: boolean;
        can_edit: boolean;
    };
    state: TranslationState;
    outdated_since: { number: number; at: string | null } | null;
    fallback: string | null;
    locales: TranslationLocale[];
    sections: TranslationSection[];
    progress: TranslationProgress;
    urls: {
        save: string;
        create: string;
        editor: string;
        source_editor: string;
        back: string;
        paste?: string;
    };
    status: string | null;
}>();

const page = usePage<BackendProps>();

const sourceCode = computed(() =>
    (props.source?.locale ?? props.site.default_locale).toUpperCase(),
);
const targetCode = computed(() => props.target.locale.toUpperCase());
const title = computed(
    () =>
        `${props.entry.title || t('Ohne Titel')} · ${sourceCode.value} → ${targetCode.value}`,
);

/** Die Krume zurück: der Menüpunkt, auf den `back` zeigt («Seiten», «Übersetzung», …). */
const backLabel = computed(() => {
    const target = pathOf(props.urls.back ?? '');
    const item = page.props.backend.menus.content.find(
        (m) => pathOf(m.href) === target,
    );

    return (
        item?.label ??
        (props.entry.type === 'page' ? t('Seiten') : t('Inhalte'))
    );
});

const ownStatus = computed(() =>
    props.status && props.status !== page.props.backend.flash?.status
        ? props.status
        : null,
);

const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);
const errorCount = computed(() => Object.keys(errors.value).length);

/* ── Werte der Zielsprache ── */

const fields = computed(() => allFields(props.sections ?? []));
/** Die Werte als `object` — mit `FieldValue` rechnet sich der Typ von `useForm` unendlich tief; gelesen über `values`. */
const form = useForm<{
    values: object;
    source_version_id: number | null;
}>({
    values: initialValues(props.sections ?? []),
    source_version_id: props.source?.id ?? null,
});
const values = computed<TranslationValues>({
    get: () => form.values as TranslationValues,
    set: (next) => (form.values = next),
});
const titleField = computed(() =>
    fields.value.find((f) => f.path === 'title' && f.translatable),
);
/** Ohne Titel nimmt der Server die Übersetzung nicht an — so lange wartet das Autosave, statt einen Fehler zu zeigen. */
const titleMissing = computed(
    () => Boolean(titleField.value) && isBlank(values.value.title),
);

/**
 * Autosave nach 1200 ms. Danach neu: Stand (aktuell/veraltet), Fassung und Sprach-Reiter — die Felder nicht, die
 * Eingabe läuft weiter. Kommen neue Abschnitte (Quelle gewechselt), beginnt das Formular von vorn.
 */
const autosave = useAutosave(form, {
    url: () => props.urls.save,
    method: 'post',
    delay: 1200,
    only: ['errors', 'state', 'target', 'locales', 'outdated_since'],
    enabled: () => props.target.can_edit && !titleMissing.value,
    labels: {
        values: t('Übersetzung'),
        ...Object.fromEntries(
            fields.value.map((f) => [`values.${f.path}`, f.label]),
        ),
    },
});

watch(
    () => props.sections,
    (sections) => {
        values.value = initialValues(sections ?? []);
        form.source_version_id = props.source?.id ?? null;
        autosave.rebase();
    },
);

const live = computed(() =>
    fields.value.length > 0
        ? progressOf(values.value, fields.value)
        : props.progress,
);
const percent = computed(() =>
    live.value.total > 0
        ? Math.round((live.value.translated / live.value.total) * 100)
        : 100,
);
const fillable = computed(() => fillableCount(values.value, fields.value));
const editable = computed(() => props.target.can_edit);

function setValue(path: string, value: unknown): void {
    values.value = { ...values.value, [path]: value };
}

/** «Aus Standardsprache übernehmen» und «→ übernehmen» ändern die Werte — das Autosave speichert sie wie getippt. */
function fillAll(): void {
    values.value = fillEmpty(values.value, fields.value).values;
}

function takeField(field: TranslationField): void {
    values.value = takeOver(values.value, field);
}

function savedField(field: TranslationField): boolean {
    return autosave.saved(`values.${field.path}`);
}

function create(): void {
    router.post(props.urls.create, {}, { preserveScroll: true });
}

function textOf(value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }

    return typeof value === 'string' ? value : String(value);
}

function errorFor(field: TranslationField): string {
    return errors.value[`values.${field.path}`] ?? '';
}

function blank(field: TranslationField): boolean {
    return isBlank(values.value[field.path]);
}

/** Die Übersetzung fehlt: das Ziel ist leer, die Quelle nicht (ein leeres Quellfeld braucht keine). */
function missing(field: TranslationField): boolean {
    return blank(field) && !isBlank(field.source);
}

function control(field: TranslationField): string {
    return [
        controlClasses({ error: Boolean(errorFor(field)) }),
        missing(field) ? 'border-dashed' : '',
    ].join(' ');
}

const sourceBox =
    'min-h-[38px] rounded-md border border-ash-light bg-paper px-2.5 py-2 text-[13px] break-words';
const fieldLabel =
    'mb-1 flex flex-wrap items-center gap-1.5 text-[9.5px] font-semibold tracking-[.1em] text-basalt-light uppercase';
const changedTag =
    'rounded border border-warning bg-warning/10 px-[5px] text-[10px] font-normal tracking-normal text-warning normal-case';
const sharedTag =
    'rounded border border-ash-light bg-white px-[5px] text-[10px] text-basalt-light';
const takeButton =
    'shrink-0 rounded-md border border-ash bg-white px-2 py-1.5 text-[11.5px] font-semibold whitespace-nowrap text-lava-dark hover:bg-paper disabled:text-ash';
</script>

<template>
    <Head :title="title" />
    <PageHeader :title="title">
        <template #crumb>
            <Link :href="urls.back" class="font-semibold hover:underline"
                >‹ {{ backLabel }}</Link
            >
            › {{ entry.title }}
        </template>
        <template #lead>
            <template v-if="state === 'missing'">
                {{
                    $t('Noch keine Fassung auf :locale.', {
                        locale: targetCode,
                    })
                }}
                {{
                    target.is_required
                        ? $t(
                              ':locale ist eine Pflichtsprache — die Freigabe wartet auf diese Fassung.',
                              { locale: targetCode },
                          )
                        : fallback
                          ? $t(
                                ':locale ist keine Pflichtsprache; Besucher sehen den Fallback (:fallback).',
                                {
                                    locale: targetCode,
                                    fallback: fallback.toUpperCase(),
                                },
                            )
                          : $t(
                                ':locale ist keine Pflichtsprache; Besucher sehen den Fallback.',
                                { locale: targetCode },
                            )
                }}
            </template>
            <template v-else-if="state === 'source'">
                {{
                    $t(
                        ':locale ist die Standardsprache — wählen Sie oben eine Zielsprache.',
                        { locale: targetCode },
                    )
                }}
            </template>
            <template v-else>
                {{
                    target.number === null
                        ? $t(':locale-Fassung neu', { locale: targetCode })
                        : $t(':locale-Fassung :number', {
                              locale: targetCode,
                              number: target.number,
                          })
                }}
                ·
                <span
                    v-if="state === 'outdated'"
                    class="text-warning font-semibold"
                    >{{
                        !outdated_since
                            ? $t('veraltet')
                            : outdated_since.at
                              ? $t(
                                    'veraltet seit Fassung :number (:locale) vom :date',
                                    {
                                        number: outdated_since.number,
                                        locale: sourceCode,
                                        date: outdated_since.at,
                                    },
                                )
                              : $t('veraltet seit Fassung :number (:locale)', {
                                    number: outdated_since.number,
                                    locale: sourceCode,
                                })
                    }}</span
                >
                <span v-else class="text-success font-semibold">{{
                    $t('aktuell')
                }}</span>
                ·
                {{
                    $tc(
                        ':translated von :count Feld übersetzt|:translated von :count Feldern übersetzt',
                        live.total,
                        { translated: live.translated },
                    )
                }}
            </template>
        </template>
        <template #actions>
            <template v-if="state !== 'missing' && state !== 'source'">
                <Button
                    variant="secondary"
                    :disabled="!editable || fillable === 0"
                    @click="fillAll"
                    >{{ $t('Aus Standardsprache übernehmen') }}</Button
                >
            </template>
            <Button variant="link" :href="urls.editor">{{
                $t('Im Editor öffnen')
            }}</Button>
        </template>
    </PageHeader>

    <nav
        class="mb-3 flex flex-wrap border-b border-ash-light"
        :aria-label="$t('Sprachen')"
    >
        <Link
            v-for="l in locales"
            :key="l.locale"
            :href="l.url"
            class="-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2 text-[12.5px]"
            :class="
                l.locale === target.locale
                    ? 'border-lava font-semibold text-basalt'
                    : 'border-transparent text-basalt-light hover:text-basalt'
            "
            :aria-current="l.locale === target.locale ? 'page' : undefined"
        >
            {{ l.locale.toUpperCase() }}
            <span v-if="l.is_default" class="text-ash-dark text-[10px]">{{
                $t('Standard')
            }}</span>
            <span
                v-else-if="l.is_required"
                class="text-ash-dark text-[10px]"
                :title="$t('Pflichtsprache — Freigabe erst mit dieser Fassung')"
                >{{ $t('Pflicht') }}</span
            >
            <TranslationMark
                v-if="!l.is_default"
                :state="l.state"
                :locale="l.locale"
            />
        </Link>
    </nav>

    <Notice v-if="ownStatus" tone="success" class="mb-3">{{
        ownStatus
    }}</Notice>
    <Notice v-if="errorCount > 0" tone="danger" class="mb-3">
        <b>{{
            $tc(
                ':count Feld stimmt nicht.|:count Felder stimmen nicht.',
                errorCount,
            )
        }}</b>
        {{ $t('Die Eingaben sind erhalten — die Fehler stehen am Feld.') }}
    </Notice>

    <!-- Standardsprache: hier gibt es nichts zu übersetzen -->
    <Card v-if="state === 'source'">
        <EmptyState
            :title="$t('Das ist die Standardsprache')"
            :text="
                $t(
                    'Übersetzt wird aus dieser Sprache in die anderen — die Reiter oben führen zu ihnen.',
                )
            "
            icon="translation"
            tone="ash"
        >
            <Button variant="secondary" :href="urls.source_editor">{{
                $t('Im Editor öffnen')
            }}</Button>
        </EmptyState>
    </Card>

    <!-- Noch keine Fassung in der Zielsprache (Mockup 12/2) -->
    <div v-else-if="state === 'missing'" class="grid gap-3.5 md:grid-cols-2">
        <Card :title="$t('Standardsprache :locale', { locale: sourceCode })">
            <template v-if="source" #sub
                >{{ $t('Fassung :number', { number: source.number }) }} ·
                {{ source.status_label }}</template
            >
            <dl v-if="source">
                <PairRow :label="$t('Titel')">{{ entry.title }}</PairRow>
                <PairRow :label="$t('Typ')">{{ entry.type_label }}</PairRow>
                <PairRow :label="$t('Felder übersetzbar')">{{
                    progress.total
                }}</PairRow>
            </dl>
            <p v-else class="text-[12.5px] text-basalt-light">
                {{
                    $t(
                        'Noch keine Fassung in der Standardsprache — zuerst dort schreiben.',
                    )
                }}
            </p>
            <div class="mt-2.5">
                <Button variant="link" size="sm" :href="urls.source_editor">{{
                    $t(':locale im Editor öffnen', { locale: sourceCode })
                }}</Button>
            </div>
        </Card>
        <Card>
            <EmptyState
                :title="$t('Noch keine Fassung in dieser Sprache')"
                :text="
                    target.is_required
                        ? $t(
                              'Pflichtsprache — Freigabe erst mit dieser Fassung. Die Blöcke der Standardsprache (:locale) als Entwurf übernehmen und dann übersetzen.',
                              { locale: sourceCode },
                          )
                        : fallback
                          ? $t(
                                'Die Blöcke der Standardsprache (:locale) als Entwurf übernehmen und dann übersetzen. Bis dahin sehen Besucher den Fallback (:fallback).',
                                {
                                    locale: sourceCode,
                                    fallback: fallback.toUpperCase(),
                                },
                            )
                          : $t(
                                'Die Blöcke der Standardsprache (:locale) als Entwurf übernehmen und dann übersetzen. Bis dahin sehen Besucher den Fallback.',
                                { locale: sourceCode },
                            )
                "
                icon="translation"
                :tone="target.is_required ? 'lava' : 'ash'"
            >
                <Button
                    :disabled="!source || !target.can_edit"
                    @click="create"
                    >{{
                        $t('Übersetzung aus :locale anlegen', {
                            locale: sourceCode,
                        })
                    }}</Button
                >
            </EmptyState>
        </Card>
    </div>

    <!-- Nebeneinander -->
    <form v-else novalidate @submit.prevent="autosave.flush()">
        <Notice v-if="!editable" tone="grey" class="mb-3">
            {{ $t('Nur lesen — diese Fassung können Sie nicht bearbeiten.') }}
        </Notice>
        <Notice v-else-if="titleMissing" tone="warning" class="mb-3">
            {{
                $t(
                    'Gespeichert wird automatisch, sobald der Titel übersetzt ist.',
                )
            }}
        </Notice>

        <div
            class="hidden gap-3.5 text-[11px] text-basalt-light lg:grid lg:grid-cols-2"
        >
            <div>
                <b class="text-basalt">{{
                    $t('Standardsprache :locale', { locale: sourceCode })
                }}</b>
                <template v-if="source">
                    · {{ $t('Fassung :number', { number: source.number }) }} ·
                    {{ source.status_label }}</template
                >
                · {{ $t('nur lesen') }}
            </div>
            <div>
                <b class="text-basalt">{{
                    $t('Zielsprache :locale', { locale: targetCode })
                }}</b>
                ·
                {{
                    target.number === null
                        ? $t('Fassung neu')
                        : $t('Fassung :number', { number: target.number })
                }}
                <template v-if="target.status_label">
                    · {{ target.status_label }}</template
                >
                · {{ editable ? $t('bearbeiten') : $t('nur lesen') }}
            </div>
        </div>
        <p class="text-[11px] text-basalt-light lg:hidden">
            {{
                $t('Je Feld oben :source (nur lesen), darunter :target.', {
                    source: sourceCode,
                    target: targetCode,
                })
            }}
        </p>

        <section
            v-for="section in sections"
            :key="section.key"
            class="mt-3 rounded-lg border border-ash-light bg-white px-3.5 pt-3 pb-1"
        >
            <div
                class="flex flex-wrap items-baseline justify-between gap-x-2 text-sm font-semibold"
            >
                <h2>{{ section.title }}</h2>
                <span
                    v-if="section.subtitle"
                    class="font-mono text-[11px] font-normal text-basalt-light"
                    >{{ section.subtitle }}</span
                >
            </div>

            <div
                v-for="field in section.fields"
                :key="field.path"
                class="grid gap-x-3.5 gap-y-1.5 border-b border-ash-light py-2 last:border-b-0 lg:grid-cols-2"
            >
                <!-- Quelle -->
                <div class="min-w-0">
                    <span :class="fieldLabel">
                        {{ field.label }}
                        <span v-if="field.changed" :class="changedTag">{{
                            $t('geändert')
                        }}</span>
                    </span>
                    <div :class="sourceBox" class="flex items-start gap-2">
                        <div
                            v-if="
                                field.type === 'richtext' && field.source_html
                            "
                            class="rte-content min-w-0 flex-1"
                            v-html="field.source_html"
                        />
                        <span v-else class="min-w-0 flex-1">{{
                            displayValue(field.source)
                        }}</span>
                        <span
                            class="text-ash-dark shrink-0 font-mono text-[10px] lg:hidden"
                            >{{ sourceCode }}</span
                        >
                    </div>
                    <div
                        v-if="field.changed"
                        class="mt-1 text-[11px] text-basalt-light"
                    >
                        {{ $t('vorher:') }}
                        <del
                            v-if="field.previous_html"
                            class="rte-content inline-block align-top"
                            v-html="field.previous_html"
                        />
                        <del v-else-if="!isBlank(field.previous)">{{
                            displayValue(field.previous)
                        }}</del>
                        <span v-else>{{ $t('leer') }}</span>
                    </div>
                </div>

                <!-- Ziel -->
                <div class="min-w-0">
                    <span
                        :class="[
                            fieldLabel,
                            savedField(field) ? '' : 'hidden lg:flex',
                        ]"
                        ><span class="max-lg:hidden">{{ field.label }}</span
                        ><span
                            v-if="savedField(field)"
                            class="text-success font-semibold tracking-normal normal-case"
                            >✓ {{ $t('gespeichert') }}</span
                        ></span
                    >

                    <div
                        v-if="!field.translatable"
                        class="flex items-start gap-2 rounded-md border border-ash-light bg-paper px-2.5 py-2 text-[13px] break-words text-basalt-light"
                    >
                        <span class="min-w-0 flex-1">{{
                            displayValue(field.target ?? field.source)
                        }}</span>
                        <span
                            :class="sharedTag"
                            :title="
                                $t(
                                    'Gilt für alle Sprachen — wird in der Standardsprache gepflegt',
                                )
                            "
                            >{{ $t('geteilt') }}</span
                        >
                    </div>

                    <template v-else-if="field.type === 'richtext'">
                        <div
                            v-if="missing(field)"
                            class="text-ash-dark mb-1.5 flex items-center justify-between gap-2 rounded-md border border-dashed border-ash px-2.5 py-1.5 text-[12.5px]"
                        >
                            <span>{{ $t('Übersetzung fehlt …') }}</span>
                            <button
                                type="button"
                                :class="takeButton"
                                :disabled="!editable || isBlank(field.source)"
                                @click="takeField(field)"
                            >
                                → {{ $t('übernehmen') }}
                            </button>
                        </div>
                        <RichTextField
                            :model-value="
                                values[field.path] as FieldValue | undefined
                            "
                            :label="`${field.label} (${targetCode})`"
                            :subtitle="
                                $t(':section · Übersetzung :source → :target', {
                                    section: section.title,
                                    source: sourceCode,
                                    target: targetCode,
                                })
                            "
                            :placeholder="$t('Übersetzung fehlt …')"
                            :paste-url="urls.paste ?? ''"
                            :invalid="Boolean(errorFor(field))"
                            :disabled="!editable"
                            @update:model-value="setValue(field.path, $event)"
                        />
                    </template>

                    <div v-else class="flex items-start gap-2">
                        <textarea
                            v-if="field.type === 'textarea'"
                            :value="textOf(values[field.path])"
                            rows="3"
                            :class="control(field)"
                            :disabled="!editable"
                            :placeholder="
                                missing(field) ? $t('Übersetzung fehlt …') : ''
                            "
                            :aria-label="`${field.label} (${targetCode})`"
                            :aria-invalid="errorFor(field) ? 'true' : undefined"
                            @input="
                                setValue(
                                    field.path,
                                    ($event.target as HTMLTextAreaElement)
                                        .value,
                                )
                            "
                        />
                        <input
                            v-else
                            type="text"
                            :value="textOf(values[field.path])"
                            :class="control(field)"
                            :disabled="!editable"
                            :placeholder="
                                missing(field) ? $t('Übersetzung fehlt …') : ''
                            "
                            :aria-label="`${field.label} (${targetCode})`"
                            :aria-invalid="errorFor(field) ? 'true' : undefined"
                            @input="
                                setValue(
                                    field.path,
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                        />
                        <button
                            v-if="missing(field)"
                            type="button"
                            :class="takeButton"
                            :disabled="!editable || isBlank(field.source)"
                            :title="
                                $t('Den Text aus :locale übernehmen', {
                                    locale: sourceCode,
                                })
                            "
                            @click="takeField(field)"
                        >
                            → {{ $t('übernehmen') }}
                        </button>
                    </div>
                    <!-- TB 13 Scheibe 3: Teile der Module je Feld (KI «Vorschlag» aus der Quellfassung) -->
                    <ModuleSlot
                        v-if="
                            field.translatable &&
                            editable &&
                            !isBlank(field.source) &&
                            ['text', 'textarea', 'richtext'].includes(
                                field.type,
                            )
                        "
                        name="TranslationField"
                        :context="{
                            field: {
                                path: field.path,
                                label: field.label,
                                type: field.type,
                            },
                            value: values[field.path],
                        }"
                        @apply="setValue(field.path, $event as FieldValue)"
                    />
                    <p
                        v-if="errorFor(field)"
                        class="text-danger mt-1 text-[11.5px] font-semibold"
                        role="alert"
                    >
                        {{ errorFor(field) }}
                    </p>
                </div>
            </div>
        </section>

        <p
            v-if="sections.length === 0"
            class="mt-3 rounded-lg border border-ash-light bg-white px-3.5 py-3 text-[12.5px] text-basalt-light"
        >
            {{ $t('Dieser Inhalt hat keine übersetzbaren Felder.') }}
        </p>

        <div
            class="mt-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 text-[12.5px]"
        >
            <span>{{ progressText(live, target.locale) }}</span>
            <div
                class="h-1.5 w-full overflow-hidden rounded-full bg-ash-light sm:w-[200px]"
                role="progressbar"
                :aria-valuenow="live.translated"
                aria-valuemin="0"
                :aria-valuemax="live.total"
                :aria-label="
                    $t(':translated von :total übersetzt', {
                        translated: live.translated,
                        total: live.total,
                    })
                "
            >
                <i
                    class="block h-full rounded-full bg-lava"
                    :style="{ width: `${percent}%` }"
                />
            </div>
        </div>
        <div class="mt-3 flex flex-wrap justify-end gap-2 lg:hidden">
            <Button
                variant="secondary"
                :disabled="!editable || fillable === 0"
                @click="fillAll"
                >{{ $t('Übernehmen') }}</Button
            >
        </div>
    </form>
</template>
