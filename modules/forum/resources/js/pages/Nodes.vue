<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { t } from '@core/i18n';
import Button from '@core/components/Button.vue';
import { controlClasses, labelClasses } from '@core/components/control';
import EmptyState from '@core/components/EmptyState.vue';
import Modal from '@core/components/Modal.vue';
import Notice from '@core/components/Notice.vue';
import PageHeader from '@core/components/PageHeader.vue';
import StateTag from '@core/components/StateTag.vue';
import type {
    ForumNodesProps,
    NodeRow,
    PrefixRow,
    ThreadField,
} from '../types';

/**
 * Extranet › Community › Foren (CR-006 FO-04, Vorbild ACP «Foren»): der Baum aus Kategorien, Foren und Links.
 * Anlegen und Ändern im selben geführten Formular: zuerst die Art (mit Erklärung), dann Name je Sprache, Ort im
 * Baum, Adresse (aus dem Namen vorgeschlagen, mit Vorschau). Verschieben mit Hoch/Runter — per Tastatur bedienbar.
 */
const props = defineProps<ForumNodesProps>();
const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
/** Fehler eines Felds mit Punkt im Namen (`title.de`) — die Typen von useForm kennen nur die obersten Schlüssel. */
const fieldError = (key: string): string =>
    (form.errors as Record<string, string | undefined>)[key] ?? '';

const defaultLocale = computed(
    () =>
        props.locales.find((l) => l.is_default)?.locale ??
        props.locales[0]?.locale ??
        'de',
);
const otherLocales = computed(() => props.locales.filter((l) => !l.is_default));

type NodeForm = {
    type: string;
    parent_id: number | null;
    slug: string;
    title: Record<string, string>;
    description: Record<string, string>;
    link_url: string;
    closed: boolean;
    icon: string;
    prefixes: number[];
    prefix_required: boolean;
    fields: ThreadField[];
};

function blank(): NodeForm {
    const title: Record<string, string> = {};
    const description: Record<string, string> = {};
    for (const l of props.locales) {
        title[l.locale] = '';
        description[l.locale] = '';
    }

    return {
        type: 'board',
        parent_id: null,
        slug: '',
        title,
        description,
        link_url: '',
        closed: false,
        icon: 'chat',
        prefixes: [],
        prefix_required: false,
        fields: [],
    };
}

const editing = ref<NodeRow | null>(null);
const open = ref(false);
const form = useForm<NodeForm>(blank());

function create(parent: number | null = null): void {
    editing.value = null;
    form.defaults(blank());
    form.reset();
    form.clearErrors();
    form.parent_id = parent;
    open.value = true;
}

function edit(row: NodeRow): void {
    editing.value = row;
    const value = blank();
    value.type = row.type;
    value.parent_id = row.parent_id;
    value.slug = row.slug;
    value.title = { ...value.title, ...row.title };
    value.description = { ...value.description, ...row.description };
    value.link_url = row.link_url ?? '';
    value.closed = row.closed;
    value.icon = row.icon;
    value.prefixes = [...row.prefixes];
    value.prefix_required = row.prefix_required;
    value.fields = row.fields.map((f) => ({
        ...f,
        label: { ...f.label },
        options: [...f.options],
    }));
    form.defaults(value);
    form.reset();
    form.clearErrors();
    open.value = true;
}

function save(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };
    if (editing.value) {
        form.put(editing.value.update_url, options);
    } else {
        form.post(props.urls.store, options);
    }
}

function move(row: NodeRow, direction: 'up' | 'down'): void {
    router.post(row.move_url, { direction }, { preserveScroll: true });
}

function remove(row: NodeRow): void {
    if (
        window.confirm(
            t('«:title» löschen? Unterelemente rücken eine Ebene hoch.', {
                title: titleOf(row),
            }),
        )
    ) {
        router.delete(row.destroy_url, { preserveScroll: true });
    }
}

function titleOf(row: NodeRow): string {
    return (
        row.title[defaultLocale.value] ??
        Object.values(row.title)[0] ??
        row.slug
    );
}

/** Die Adresse, wie sie im Frontend aussieht — der Slug kommt aus dem Namen, solange keiner eingetragen ist. */
function slugify(value: string): string {
    return value
        .toLowerCase()
        .replace(/ä/g, 'ae')
        .replace(/ö/g, 'oe')
        .replace(/ü/g, 'ue')
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}
const slugPreview = computed(() => {
    const slug =
        form.slug.trim() !== ''
            ? form.slug.trim()
            : slugify(form.title[defaultLocale.value] ?? '');

    return `${props.base ?? '/community'}/${slug || '…'}`;
});

/** Mögliche Eltern: alles ausser dem Element selbst und seinen Nachkommen, keine Links. */
const parentOptions = computed(() => {
    const blocked = new Set<number>();
    if (editing.value) {
        blocked.add(editing.value.id);
        let grew = true;
        while (grew) {
            grew = false;
            for (const n of props.nodes) {
                if (
                    n.parent_id !== null &&
                    blocked.has(n.parent_id) &&
                    !blocked.has(n.id)
                ) {
                    blocked.add(n.id);
                    grew = true;
                }
            }
        }
    }

    return props.nodes.filter((n) => !blocked.has(n.id) && n.type !== 'link');
});

const typeHelp: Record<string, string> = {
    category: t(
        'Gliedert die Übersicht in Abschnitte, z. B. «Hilfe» oder «Allgemein». Enthält Foren, keine Themen.',
    ),
    board: t(
        'Hier eröffnen Mitglieder Themen und antworten. Kann Unterforen haben.',
    ),
    link: t(
        'Ein Eintrag, der woandershin führt — z. B. zur Doku oder zu GitHub.',
    ),
};

/* Themenfelder im Dialog */
function addField(): void {
    form.fields.push({
        label: { [defaultLocale.value]: '' },
        type: 'text',
        options: [],
        required: false,
    });
}
function removeField(index: number): void {
    form.fields.splice(index, 1);
}
function optionsText(field: ThreadField): string {
    return field.options.join('\n');
}
function setOptions(field: ThreadField, value: string): void {
    field.options = value.split('\n').map((o) => o.trim());
}
const fieldTypeLabels: Record<string, string> = {
    text: t('Text'),
    url: t('Adresse (URL)'),
    select: t('Auswahl'),
};

/* Präfixe */
const prefixForm = useForm<{ label: Record<string, string>; color: string }>({
    label: {},
    color: 'grey',
});
const editingPrefix = ref<PrefixRow | null>(null);
function prefixLabel(prefix: PrefixRow): string {
    return (
        prefix.label[defaultLocale.value] ??
        Object.values(prefix.label)[0] ??
        ''
    );
}
function editPrefix(prefix: PrefixRow): void {
    editingPrefix.value = prefix;
    prefixForm.label = { ...prefix.label };
    prefixForm.color = prefix.color;
    prefixForm.clearErrors();
}
function newPrefix(): void {
    editingPrefix.value = null;
    prefixForm.label = { [defaultLocale.value]: '' };
    prefixForm.color = 'grey';
    prefixForm.clearErrors();
}
function savePrefix(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => newPrefix(),
    };
    if (editingPrefix.value) {
        prefixForm.put(editingPrefix.value.update_url, options);
    } else {
        prefixForm.post(props.urls.prefix_store, options);
    }
}
function removePrefix(prefix: PrefixRow): void {
    if (
        window.confirm(
            t('Präfix «:label» löschen? Themen verlieren es.', {
                label: prefixLabel(prefix),
            }),
        )
    ) {
        router.delete(prefix.destroy_url, { preserveScroll: true });
    }
}
const colorLabels: Record<string, string> = {
    grey: t('Grau'),
    lava: t('Lava'),
    green: t('Grün'),
    blue: t('Blau'),
    yellow: t('Gelb'),
    red: t('Rot'),
};
/** Dieselben Farben wie im Frontend (Styles.php des Forums). */
const colorHex: Record<string, string> = {
    grey: '#9aa0a6',
    lava: '#b53f1d',
    green: '#1f7a46',
    blue: '#1d5fa8',
    yellow: '#f2c94c',
    red: '#b8232f',
};
const prefixError = (key: string): string =>
    (prefixForm.errors as Record<string, string | undefined>)[key] ?? '';

function siblingsOf(row: NodeRow): NodeRow[] {
    return props.nodes.filter((n) => n.parent_id === row.parent_id);
}
</script>

<template>
    <Head :title="$t('Foren')" />
    <PageHeader
        :title="$t('Foren')"
        :crumb="$t('Community')"
        :lead="
            $t(
                'Kategorien gliedern, Foren enthalten Themen, Links führen weg. Reihenfolge und Ebenen wirken sofort im Frontend.',
            )
        "
    >
        <template #actions>
            <Button :href="urls.overview" variant="secondary">{{
                $t('Übersicht')
            }}</Button>
            <Button @click="create()">{{ $t('Neues Element') }}</Button>
        </template>
    </PageHeader>

    <Notice v-if="errors.forum" tone="danger" class="mb-3">{{
        errors.forum
    }}</Notice>

    <section class="mb-4 rounded-lg border border-ash-light bg-white p-3">
        <h2 class="text-sm font-semibold text-basalt">{{ $t('Präfixe') }}</h2>
        <p class="text-[11.5px] text-basalt-light">
            {{
                $t(
                    'Schilder vor dem Titel eines Themas, z. B. «Frage», «Gelöst», «Fehler». Welche ein Forum anbietet, wählen Sie beim Forum.',
                )
            }}
        </p>
        <ul class="mt-2 flex flex-wrap gap-2">
            <li
                v-for="prefix in prefixes"
                :key="prefix.id"
                class="flex items-center gap-1 rounded-full border border-ash-light py-0.5 pr-1 pl-2.5 text-[12px]"
            >
                <span
                    class="inline-block h-2.5 w-2.5 rounded-full"
                    :style="{ background: colorHex[prefix.color] ?? '#9aa0a6' }"
                    aria-hidden="true"
                />
                {{ prefixLabel(prefix) }}
                <Button size="sm" variant="link" @click="editPrefix(prefix)">{{
                    $t('Bearbeiten')
                }}</Button>
                <Button
                    size="sm"
                    variant="link"
                    :aria-label="
                        $t('Präfix «:label» löschen', {
                            label: prefixLabel(prefix),
                        })
                    "
                    @click="removePrefix(prefix)"
                    >×</Button
                >
            </li>
        </ul>
        <form
            class="mt-3 flex flex-wrap items-end gap-2"
            @submit.prevent="savePrefix"
        >
            <label class="block min-w-[180px] flex-1">
                <span :class="labelClasses"
                    >{{
                        editingPrefix ? $t('Präfix ändern') : $t('Neues Präfix')
                    }}
                    ({{ defaultLocale.toUpperCase() }})</span
                >
                <input
                    v-model="prefixForm.label[defaultLocale]"
                    type="text"
                    maxlength="30"
                    :placeholder="$t('z. B. Frage')"
                    :class="
                        controlClasses({
                            small: true,
                            error: !!prefixError(`label.${defaultLocale}`),
                        })
                    "
                />
            </label>
            <label
                v-for="l in otherLocales"
                :key="l.locale"
                class="block w-[120px]"
            >
                <span :class="labelClasses">{{ l.locale.toUpperCase() }}</span>
                <input
                    v-model="prefixForm.label[l.locale]"
                    type="text"
                    maxlength="30"
                    :class="controlClasses({ small: true })"
                />
            </label>
            <label class="block w-[120px]">
                <span :class="labelClasses">{{ $t('Farbe') }}</span>
                <select
                    v-model="prefixForm.color"
                    :class="controlClasses({ small: true })"
                >
                    <option v-for="c in colors" :key="c" :value="c">
                        {{ colorLabels[c] ?? c }}
                    </option>
                </select>
            </label>
            <Button type="submit" size="sm" :disabled="prefixForm.processing">{{
                editingPrefix ? $t('Speichern') : $t('Anlegen')
            }}</Button>
            <Button
                v-if="editingPrefix"
                size="sm"
                variant="secondary"
                @click="newPrefix"
                >{{ $t('Abbrechen') }}</Button
            >
            <p
                v-if="prefixError(`label.${defaultLocale}`)"
                class="text-danger w-full text-[11.5px] font-semibold"
            >
                {{ prefixError(`label.${defaultLocale}`) }}
            </p>
        </form>
    </section>

    <div
        v-if="nodes.length === 0"
        class="rounded-lg border border-ash-light bg-white"
    >
        <EmptyState
            :title="$t('Noch keine Foren')"
            :text="
                $t(
                    'Beginnen Sie mit einer Kategorie wie «Allgemein» und einem Forum darin, z. B. «Ankündigungen».',
                )
            "
            icon="comments"
            tone="lava"
        />
    </div>

    <ul
        v-else
        class="divide-y divide-ash-light rounded-lg border border-ash-light bg-white"
    >
        <li
            v-for="row in nodes"
            :key="row.id"
            class="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-3 py-2.5"
            :style="{ paddingLeft: `${12 + row.depth * 24}px` }"
        >
            <div class="min-w-0 flex-1">
                <p class="flex flex-wrap items-center gap-2">
                    <b class="font-semibold break-words text-basalt">{{
                        titleOf(row)
                    }}</b>
                    <StateTag
                        :tone="
                            row.type === 'board'
                                ? 'lava'
                                : row.type === 'category'
                                  ? 'grey'
                                  : 'warning'
                        "
                        >{{ row.type_label }}</StateTag
                    >
                    <StateTag v-if="row.closed" tone="danger">{{
                        $t('geschlossen')
                    }}</StateTag>
                </p>
                <p class="text-[11.5px] break-words text-basalt-light">
                    <template v-if="row.type === 'link'">{{
                        row.link_url
                    }}</template>
                    <template v-else>
                        <a
                            v-if="row.url"
                            :href="row.url"
                            target="_blank"
                            rel="noopener"
                            class="font-mono hover:underline"
                            >{{ row.url }}</a
                        >
                        <span v-else class="font-mono">{{ row.slug }}</span>
                        ·
                        {{ $tc(':count Thema|:count Themen', row.threads) }}
                        ·
                        {{ $tc(':count Beitrag|:count Beiträge', row.posts) }}
                    </template>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-1">
                <Button
                    size="sm"
                    variant="secondary"
                    :disabled="siblingsOf(row)[0]?.id === row.id"
                    :aria-label="
                        $t('Nach oben: :title', { title: titleOf(row) })
                    "
                    @click="move(row, 'up')"
                    >▲</Button
                >
                <Button
                    size="sm"
                    variant="secondary"
                    :disabled="siblingsOf(row).at(-1)?.id === row.id"
                    :aria-label="
                        $t('Nach unten: :title', { title: titleOf(row) })
                    "
                    @click="move(row, 'down')"
                    >▼</Button
                >
                <Button
                    v-if="row.type !== 'link'"
                    size="sm"
                    variant="secondary"
                    @click="create(row.id)"
                    >{{ $t('Darunter') }}</Button
                >
                <Button size="sm" variant="secondary" @click="edit(row)">{{
                    $t('Bearbeiten')
                }}</Button>
                <Button
                    v-if="row.type !== 'link'"
                    size="sm"
                    variant="secondary"
                    :href="row.permissions_url"
                    >{{ $t('Rechte') }}</Button
                >
                <Button size="sm" variant="danger" @click="remove(row)">{{
                    $t('Löschen')
                }}</Button>
            </div>
        </li>
    </ul>

    <Modal
        :open="open"
        :title="editing ? $t('Element bearbeiten') : $t('Neues Element')"
        @close="open = false"
    >
        <form class="flex flex-col gap-4" @submit.prevent="save">
            <fieldset>
                <legend :class="labelClasses">{{ $t('Art') }}</legend>
                <div class="grid gap-2 md:grid-cols-3">
                    <label
                        v-for="type in types"
                        :key="type.value"
                        class="flex cursor-pointer flex-col gap-1 rounded-lg border px-3 py-2.5 text-[12.5px]"
                        :class="
                            form.type === type.value
                                ? 'border-lava-dark bg-lava/10'
                                : 'border-ash-light bg-white'
                        "
                    >
                        <span class="flex items-center gap-2 font-semibold">
                            <input
                                v-model="form.type"
                                type="radio"
                                name="type"
                                :value="type.value"
                                class="accent-lava-dark"
                            />
                            {{ type.label }}
                        </span>
                        <span class="text-basalt-light">{{
                            typeHelp[type.value]
                        }}</span>
                    </label>
                </div>
                <p
                    v-if="form.errors.type"
                    class="text-danger mt-1 text-[11.5px] font-semibold"
                >
                    {{ form.errors.type }}
                </p>
            </fieldset>

            <label class="block">
                <span :class="labelClasses"
                    >{{ $t('Name') }} ({{ defaultLocale.toUpperCase() }})</span
                >
                <input
                    v-model="form.title[defaultLocale]"
                    type="text"
                    maxlength="120"
                    required
                    :class="
                        controlClasses({
                            error: !!fieldError(`title.${defaultLocale}`),
                        })
                    "
                />
                <span
                    v-if="fieldError(`title.${defaultLocale}`)"
                    class="text-danger mt-1 block text-[11.5px] font-semibold"
                    >{{ fieldError(`title.${defaultLocale}`) }}</span
                >
            </label>

            <label class="block">
                <span :class="labelClasses"
                    >{{ $t('Beschreibung') }} ({{
                        defaultLocale.toUpperCase()
                    }})</span
                >
                <textarea
                    v-model="form.description[defaultLocale]"
                    rows="2"
                    maxlength="500"
                    :class="controlClasses()"
                />
                <span class="mt-1 block text-[11.5px] text-basalt-light">{{
                    $t('Ein Satz unter dem Namen in der Übersicht.')
                }}</span>
            </label>

            <details
                v-if="otherLocales.length"
                class="rounded-md bg-paper px-3 py-2"
            >
                <summary class="cursor-pointer text-[12.5px] font-semibold">
                    {{ $t('Andere Sprachen') }}
                </summary>
                <div
                    v-for="l in otherLocales"
                    :key="l.locale"
                    class="mt-2 grid gap-2 md:grid-cols-2"
                >
                    <label class="block">
                        <span :class="labelClasses"
                            >{{ $t('Name') }} ({{
                                l.locale.toUpperCase()
                            }})</span
                        >
                        <input
                            v-model="form.title[l.locale]"
                            type="text"
                            maxlength="120"
                            :class="controlClasses()"
                        />
                    </label>
                    <label class="block">
                        <span :class="labelClasses"
                            >{{ $t('Beschreibung') }} ({{
                                l.locale.toUpperCase()
                            }})</span
                        >
                        <input
                            v-model="form.description[l.locale]"
                            type="text"
                            maxlength="500"
                            :class="controlClasses()"
                        />
                    </label>
                </div>
            </details>

            <label class="block">
                <span :class="labelClasses">{{ $t('Übergeordnet') }}</span>
                <select v-model="form.parent_id" :class="controlClasses()">
                    <option :value="null">{{ $t('oberste Ebene') }}</option>
                    <option
                        v-for="p in parentOptions"
                        :key="p.id"
                        :value="p.id"
                    >
                        {{ '— '.repeat(p.depth) }}{{ titleOf(p) }}
                    </option>
                </select>
                <span
                    v-if="form.errors.parent_id"
                    class="text-danger mt-1 block text-[11.5px] font-semibold"
                    >{{ form.errors.parent_id }}</span
                >
            </label>

            <label v-if="form.type === 'link'" class="block">
                <span :class="labelClasses">{{ $t('Ziel des Links') }}</span>
                <input
                    v-model="form.link_url"
                    type="text"
                    placeholder="https://"
                    :class="
                        controlClasses({
                            error: !!form.errors.link_url,
                            mono: true,
                        })
                    "
                />
                <span
                    v-if="form.errors.link_url"
                    class="text-danger mt-1 block text-[11.5px] font-semibold"
                    >{{ form.errors.link_url }}</span
                >
            </label>

            <label v-else class="block">
                <span :class="labelClasses">{{ $t('Adresse') }}</span>
                <input
                    v-model="form.slug"
                    type="text"
                    maxlength="80"
                    :placeholder="slugify(form.title[defaultLocale] ?? '')"
                    :class="
                        controlClasses({
                            error: !!form.errors.slug,
                            mono: true,
                        })
                    "
                />
                <span
                    v-if="form.errors.slug"
                    class="text-danger mt-1 block text-[11.5px] font-semibold"
                    >{{ form.errors.slug }}</span
                >
                <span class="mt-1 block text-[11.5px] text-basalt-light"
                    >{{ $t('Im Frontend:') }}
                    <span class="font-mono">{{ slugPreview }}</span></span
                >
            </label>

            <fieldset v-if="form.type === 'board'">
                <legend :class="labelClasses">{{ $t('Symbol') }}</legend>
                <div class="flex flex-wrap gap-1.5">
                    <label
                        v-for="icon in icons"
                        :key="icon.value"
                        class="flex h-10 w-10 cursor-pointer items-center justify-center rounded-md border text-basalt"
                        :class="
                            form.icon === icon.value
                                ? 'border-lava-dark bg-lava/10'
                                : 'border-ash-light hover:border-ash'
                        "
                        :title="icon.label"
                    >
                        <input
                            v-model="form.icon"
                            type="radio"
                            :value="icon.value"
                            class="sr-only"
                            :aria-label="icon.label"
                        />
                        <span
                            class="h-5 w-5 [&>svg]:h-5 [&>svg]:w-5"
                            v-html="icon.svg"
                        />
                    </label>
                </div>
            </fieldset>

            <label
                v-if="form.type === 'board'"
                class="flex items-start gap-2 text-[12.5px]"
            >
                <input
                    v-model="form.closed"
                    type="checkbox"
                    class="mt-0.5 accent-lava-dark"
                />
                <span
                    >{{ $t('Geschlossen') }}
                    <span class="block text-basalt-light">{{
                        $t(
                            'Nur wer moderieren darf, eröffnet Themen und antwortet — gut für Ankündigungen.',
                        )
                    }}</span></span
                >
            </label>

            <fieldset
                v-if="form.type === 'board' && prefixes.length"
                class="rounded-md bg-paper px-3 py-2"
            >
                <legend :class="labelClasses">{{ $t('Präfixe') }}</legend>
                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    <label
                        v-for="prefix in prefixes"
                        :key="prefix.id"
                        class="flex items-center gap-1.5 text-[12.5px]"
                    >
                        <input
                            v-model="form.prefixes"
                            type="checkbox"
                            :value="prefix.id"
                            class="accent-lava-dark"
                        />
                        {{ prefixLabel(prefix) }}
                    </label>
                </div>
                <label
                    v-if="form.prefixes.length"
                    class="mt-2 flex items-center gap-1.5 text-[12.5px]"
                >
                    <input
                        v-model="form.prefix_required"
                        type="checkbox"
                        class="accent-lava-dark"
                    />
                    {{ $t('Pflicht — jedes neue Thema braucht ein Präfix') }}
                </label>
            </fieldset>

            <fieldset
                v-if="form.type === 'board'"
                class="rounded-md bg-paper px-3 py-2"
            >
                <legend :class="labelClasses">{{ $t('Themenfelder') }}</legend>
                <p class="text-[11.5px] text-basalt-light">
                    {{
                        $t(
                            'Fragen, die beim Eröffnen eines Themas vor dem Text kommen — z. B. «Basalto-Version». Die Antworten stehen über dem Startbeitrag.',
                        )
                    }}
                </p>
                <div
                    v-for="(field, index) in form.fields"
                    :key="index"
                    class="mt-2 grid gap-2 rounded-md border border-ash-light bg-white p-2 md:grid-cols-[1fr_160px_auto]"
                >
                    <label class="block">
                        <span :class="labelClasses">{{ $t('Frage') }}</span>
                        <input
                            v-model="field.label[defaultLocale]"
                            type="text"
                            maxlength="60"
                            :class="
                                controlClasses({
                                    small: true,
                                    error: !!fieldError(
                                        `fields.${index}.label.${defaultLocale}`,
                                    ),
                                })
                            "
                        />
                    </label>
                    <label class="block">
                        <span :class="labelClasses">{{ $t('Art') }}</span>
                        <select
                            v-model="field.type"
                            :class="controlClasses({ small: true })"
                        >
                            <option
                                v-for="ft in field_types"
                                :key="ft"
                                :value="ft"
                            >
                                {{ fieldTypeLabels[ft] ?? ft }}
                            </option>
                        </select>
                    </label>
                    <div class="flex items-end gap-2">
                        <label class="flex items-center gap-1.5 text-[12px]">
                            <input
                                v-model="field.required"
                                type="checkbox"
                                class="accent-lava-dark"
                            />
                            {{ $t('Pflicht') }}
                        </label>
                        <Button
                            size="sm"
                            variant="link"
                            :aria-label="$t('Feld entfernen')"
                            @click="removeField(index)"
                            >×</Button
                        >
                    </div>
                    <label
                        v-if="field.type === 'select'"
                        class="block md:col-span-3"
                    >
                        <span :class="labelClasses">{{
                            $t('Auswahl (eine je Zeile)')
                        }}</span>
                        <textarea
                            :value="optionsText(field)"
                            rows="3"
                            :class="controlClasses({ small: true })"
                            @input="
                                setOptions(
                                    field,
                                    ($event.target as HTMLTextAreaElement)
                                        .value,
                                )
                            "
                        />
                    </label>
                </div>
                <div class="mt-2">
                    <Button
                        size="sm"
                        variant="secondary"
                        :disabled="form.fields.length >= 10"
                        @click="addField"
                        >{{ $t('Feld hinzufügen') }}</Button
                    >
                </div>
            </fieldset>

            <div class="flex justify-end gap-2">
                <Button variant="secondary" @click="open = false">{{
                    $t('Abbrechen')
                }}</Button>
                <Button type="submit" :disabled="form.processing">{{
                    editing ? $t('Speichern') : $t('Anlegen')
                }}</Button>
            </div>
        </form>
    </Modal>
</template>
