<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import Button from '../../components/Button.vue';
import { controlClasses, labelClasses } from '../../components/control';
import Field from '../../components/Field.vue';
import Notice from '../../components/Notice.vue';
import PageHeader from '../../components/PageHeader.vue';
import {
    editableLocales,
    languagesText,
    roleChanged,
    visibleGroups,
} from '../../components/roles';
import StateTag from '../../components/StateTag.vue';
import { t } from '../../i18n';
import type { BackendProps } from '../../types';
import type {
    AdminRole,
    RoleLanguages,
    RolesPageProps,
} from '../../types-admin';

/**
 * Rollen-Editor (CR-005, R-08): links die Rollen mit Zahl der Personen und Vorschau «sieht», rechts die gewählte
 * Rolle — Matrix Bereich × Stufe (keine · ansehen · bearbeiten · löschen · alles) und Sprachen. Stufen sind echte
 * Optionsfelder (Pfeiltasten wechseln), die Reihe bricht mobil um statt zu scrollen. Gespeichert wird bewusst mit
 * «Speichern»: Rechte ändern sich nicht nebenbei.
 */
const props = defineProps<RolesPageProps>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);
const errors = computed<Record<string, string>>(
    () => (page.props.errors ?? {}) as Record<string, string>,
);

const selectedKey = ref<string>(
    props.selected ?? props.roles.find((r) => !r.locked)?.key ?? '',
);
// Nach «Anlegen» leitet der Server mit ?rolle=… zurück — die neue Rolle gleich öffnen
watch(
    () => props.selected,
    (key) => {
        if (key) {
            selectedKey.value = key;
            creating.value = false;
        }
    },
);
const selected = computed<AdminRole | null>(
    () => props.roles.find((r) => r.key === selectedKey.value) ?? null,
);
const groupLabel = (key: string): string =>
    props.groups.find((g) => g.key === key)?.label ?? key;
const sees = (role: { levels: Record<string, number> }): string => {
    const groups = visibleGroups(role.levels, props.areas);

    return groups.length === 0
        ? t('nichts')
        : groups.map(groupLabel).join(' · ');
};

type Draft = {
    name: string;
    description: string;
    levels: Record<string, number>;
    languages: RoleLanguages;
};

function draftOf(role: AdminRole | null): Draft {
    return {
        name: role?.name ?? '',
        description: role?.description ?? '',
        levels: { ...role?.levels },
        languages: {
            mode: role?.languages.mode ?? 'all',
            codes: [...(role?.languages.codes ?? [])],
        },
    };
}

const draft = reactive<Draft>(draftOf(selected.value));
const saving = ref(false);

// Neue Auswahl oder frische Daten vom Server: Entwurf neu aufsetzen
watch(selected, (role) => Object.assign(draft, draftOf(role)));

const editable = computed(
    () => props.can_manage && selected.value !== null && !selected.value.locked,
);
const dirty = computed(
    () =>
        selected.value !== null && roleChanged(draft, draftOf(selected.value)),
);
const draftLocales = computed(() =>
    editableLocales(draft.languages, props.locales),
);

function choose(role: AdminRole): void {
    if (
        dirty.value &&
        !window.confirm(t('Ungespeicherte Änderungen verwerfen?'))
    ) {
        return;
    }
    creating.value = false;
    selectedKey.value = role.key;
    void reveal();
}

const editor = ref<HTMLElement | null>(null);

/** Unter 1024 px steht der Editor unter der Liste — dorthin mitnehmen. */
async function reveal(): Promise<void> {
    await nextTick();
    if (window.matchMedia('(max-width: 1023px)').matches) {
        editor.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function save(): void {
    if (selected.value === null) {
        return;
    }
    saving.value = true;
    router.put(
        selected.value.urls.update,
        {
            name: draft.name,
            description: draft.description,
            levels: draft.levels,
            languages: draft.languages,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}

function reset(): void {
    const role = selected.value;
    if (
        role?.urls.reset &&
        window.confirm(
            t('«:name» auf die Vorlage zurücksetzen?', { name: role.name }),
        )
    ) {
        router.post(role.urls.reset, {}, { preserveScroll: true });
    }
}

function destroy(): void {
    const role = selected.value;
    if (
        role?.urls.destroy &&
        window.confirm(t('Rolle «:name» löschen?', { name: role.name }))
    ) {
        router.delete(role.urls.destroy, {
            preserveScroll: true,
            onSuccess: () => {
                selectedKey.value =
                    props.roles.find((r) => !r.locked)?.key ?? '';
            },
        });
    }
}

/** Alle Bereiche einer Gruppe auf eine Stufe setzen. */
function setGroup(group: string, level: number): void {
    for (const area of props.areas) {
        if (area.group === group) {
            draft.levels[area.key] = level;
        }
    }
}

// «Auswahl» beginnt mit den Sprachen, die bisher galten — nicht mit einer leeren Liste
watch(
    () => draft.languages.mode,
    (mode, before) => {
        if (
            mode === 'only' &&
            before !== 'only' &&
            draft.languages.codes.length === 0
        ) {
            draft.languages.codes = editableLocales(
                { mode: before ?? 'all', codes: [] },
                props.locales,
            );
        }
    },
);

function toggleLocale(code: string, on: boolean): void {
    draft.languages.codes = on
        ? [...new Set([...draft.languages.codes, code])]
        : draft.languages.codes.filter((c) => c !== code);
}

/* ── Neue Rolle oder Kopie ── */

const creating = ref(false);
const form = useForm({ name: '', from: '' as string });

function openCreate(from: AdminRole | null = null): void {
    form.clearErrors();
    form.name = from ? t('Kopie von :name', { name: from.name }) : '';
    form.from = from?.key ?? '';
    creating.value = true;
    void reveal();
}

function create(): void {
    form.transform((data) => ({ ...data, from: data.from || null })).post(
        props.urls.store,
        {
            preserveScroll: true,
            onSuccess: () => {
                creating.value = false;
                form.reset();
            },
        },
    );
}

const levelClass = (active: boolean, disabled: boolean): string =>
    [
        'inline-flex cursor-pointer items-center rounded-full border px-2.5 py-0.5 text-[11.5px] font-semibold',
        'peer-focus-visible:ring-2 peer-focus-visible:ring-lava-dark peer-focus-visible:ring-offset-1',
        active
            ? 'border-basalt bg-basalt text-white'
            : 'border-ash bg-white text-basalt-light hover:border-basalt-light',
        disabled ? 'cursor-not-allowed opacity-70' : '',
    ].join(' ');
const selectClass = controlClasses({ small: true });
</script>

<template>
    <Head :title="$t('Rollen')" />
    <PageHeader
        :title="$t('Rollen')"
        :crumb="$t(':site › Verwaltung › Benutzer', { site: siteName })"
        :lead="
            $t(
                'Eine Rolle legt je Bereich fest, was jemand darf — keine, ansehen, bearbeiten, löschen oder alles — und in welchen Sprachen. Was eine Rolle nicht sieht, erscheint nicht im Menü.',
            )
        "
    >
        <template #actions>
            <Button variant="secondary" :href="urls.users">{{
                $t('Benutzer')
            }}</Button>
            <Button
                v-if="can_manage"
                :disabled="creating"
                @click="openCreate()"
                >{{ $t('Neue Rolle') }}</Button
            >
        </template>
    </PageHeader>

    <Notice v-if="errors.role" tone="danger" class="mb-3.5">{{
        errors.role
    }}</Notice>

    <div class="grid items-start gap-4 lg:grid-cols-[300px_minmax(0,1fr)]">
        <nav :aria-label="$t('Rollen')" class="min-w-0">
            <ul class="flex flex-col gap-2">
                <li v-for="role in roles" :key="role.key">
                    <button
                        type="button"
                        class="w-full rounded-lg border bg-white px-3 py-2.5 text-left hover:border-basalt-light"
                        :class="
                            selectedKey === role.key && !creating
                                ? 'border-basalt shadow-[inset_3px_0_0_var(--color-lava)]'
                                : 'border-ash-light'
                        "
                        :aria-current="
                            selectedKey === role.key && !creating
                                ? 'true'
                                : undefined
                        "
                        @click="choose(role)"
                    >
                        <span class="flex flex-wrap items-center gap-1.5">
                            <strong class="text-[13.5px] font-semibold">{{
                                role.name
                            }}</strong>
                            <StateTag v-if="role.locked">{{
                                $t('unveränderlich')
                            }}</StateTag>
                            <StateTag v-else-if="role.customized" tone="lava">{{
                                $t('angepasst')
                            }}</StateTag>
                            <StateTag
                                v-else-if="!role.template"
                                tone="success"
                                >{{ $t('eigene') }}</StateTag
                            >
                        </span>
                        <span
                            class="mt-0.5 block text-[11.5px] text-basalt-light"
                        >
                            {{
                                $tc(
                                    ':count Person|:count Personen',
                                    role.people,
                                )
                            }}
                            · {{ $t('sieht: :groups', { groups: sees(role) }) }}
                        </span>
                        <span class="block text-[11.5px] text-basalt-light">
                            {{
                                $t('Sprachen: :list', {
                                    list: languagesText(
                                        role.languages,
                                        locales,
                                    ),
                                })
                            }}
                        </span>
                    </button>
                </li>
            </ul>
        </nav>

        <section
            v-if="creating"
            ref="editor"
            class="min-w-0 scroll-mt-4 rounded-lg border border-ash-light bg-white px-3.5 py-3"
            :aria-label="$t('Neue Rolle')"
        >
            <h2 class="text-sm font-semibold">{{ $t('Neue Rolle') }}</h2>
            <form
                class="mt-2 flex flex-col gap-3"
                novalidate
                @submit.prevent="create"
            >
                <Field
                    v-model="form.name"
                    :label="$t('Name')"
                    required
                    :maxlength="120"
                    :error="form.errors.name"
                />
                <label class="block">
                    <span :class="labelClasses">{{ $t('Ausgehend von') }}</span>
                    <select v-model="form.from" :class="selectClass">
                        <option value="">{{ $t('leer — darf nichts') }}</option>
                        <option v-for="r in roles" :key="r.key" :value="r.key">
                            {{ r.name }}
                        </option>
                    </select>
                </label>
                <div class="flex flex-wrap gap-2">
                    <Button type="submit" :disabled="form.processing">{{
                        $t('Anlegen')
                    }}</Button>
                    <Button variant="secondary" @click="creating = false">{{
                        $t('Abbrechen')
                    }}</Button>
                </div>
            </form>
        </section>

        <section
            v-else-if="selected"
            ref="editor"
            class="min-w-0 scroll-mt-4 rounded-lg border border-ash-light bg-white"
            :aria-label="selected.name"
        >
            <div
                class="flex flex-wrap items-start justify-between gap-2 border-b border-ash-light px-3.5 py-3"
            >
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold">{{ selected.name }}</h2>
                    <p class="mt-0.5 text-xs text-basalt-light">
                        {{
                            selected.locked
                                ? $t(
                                      'Darf alles und lässt sich nicht ändern — so kann sich keine Site aussperren. Jede Site behält mindestens einen Betreiber.',
                                  )
                                : selected.template
                                  ? $t(
                                        'Vorlage — Ihre Anpassungen gelten für alle Sites dieser Installation und lassen sich zurücksetzen.',
                                    )
                                  : $t(
                                        'Eigene Rolle — löschbar, solange niemand sie hat.',
                                    )
                        }}
                    </p>
                </div>
                <div v-if="can_manage" class="flex flex-wrap gap-1">
                    <Button
                        variant="link"
                        size="sm"
                        @click="openCreate(selected)"
                        >{{ $t('Kopieren') }}</Button
                    >
                    <Button
                        v-if="selected.urls.reset"
                        variant="link"
                        size="sm"
                        @click="reset"
                        >{{ $t('Zurücksetzen') }}</Button
                    >
                    <Button
                        v-if="selected.urls.destroy"
                        variant="link"
                        size="sm"
                        @click="destroy"
                        >{{ $t('Löschen') }}</Button
                    >
                </div>
            </div>

            <form
                class="flex flex-col gap-4 px-3.5 py-3"
                novalidate
                @submit.prevent="save"
            >
                <div v-if="editable" class="grid gap-3">
                    <Field
                        v-model="draft.name"
                        :label="$t('Name')"
                        required
                        :maxlength="120"
                        :error="errors.name"
                    />
                    <Field
                        v-model="draft.description"
                        :label="$t('Beschreibung')"
                        type="textarea"
                        :rows="2"
                        optional
                        :maxlength="500"
                        :error="errors.description"
                    />
                </div>

                <fieldset>
                    <legend :class="labelClasses">{{ $t('Sprachen') }}</legend>
                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-[12.5px]">
                        <label
                            v-for="mode in [
                                { value: 'all', label: $t('alle') },
                                {
                                    value: 'except_default',
                                    label: $t('alle ausser der Quellsprache'),
                                },
                                { value: 'only', label: $t('Auswahl') },
                            ] as const"
                            :key="mode.value"
                            class="inline-flex items-center gap-1.5"
                        >
                            <input
                                v-model="draft.languages.mode"
                                type="radio"
                                :value="mode.value"
                                :disabled="!editable"
                            />
                            {{ mode.label }}
                        </label>
                    </div>
                    <div
                        v-if="draft.languages.mode === 'only'"
                        class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-[12.5px]"
                    >
                        <label
                            v-for="locale in locales"
                            :key="locale.code"
                            class="inline-flex items-center gap-1.5"
                        >
                            <input
                                type="checkbox"
                                :checked="
                                    draft.languages.codes.includes(locale.code)
                                "
                                :disabled="!editable"
                                @change="
                                    toggleLocale(
                                        locale.code,
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            />
                            {{ locale.code.toUpperCase() }}
                            <span
                                v-if="locale.is_default"
                                class="text-basalt-light"
                                >({{ $t('Quellsprache') }})</span
                            >
                        </label>
                    </div>
                    <p
                        v-if="errors['languages.codes']"
                        class="text-danger mt-1 text-xs"
                    >
                        {{ errors['languages.codes'] }}
                    </p>
                    <p class="mt-1 text-xs text-basalt-light">
                        {{
                            draftLocales.length === 0
                                ? $t('Bearbeitet in keiner Sprache Inhalte.')
                                : $t('Bearbeitet Inhalte in: :list', {
                                      list: draftLocales
                                          .map((c) => c.toUpperCase())
                                          .join(', '),
                                  })
                        }}
                    </p>
                </fieldset>

                <div v-for="group in groups" :key="group.key">
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-2 border-b border-ash-light pb-1"
                    >
                        <h3 class="text-[13px] font-semibold">
                            {{ group.label }}
                        </h3>
                        <span
                            v-if="editable"
                            class="flex flex-wrap items-center gap-1 text-[11.5px] text-basalt-light"
                        >
                            {{ $t('alle auf') }}
                            <Button
                                v-for="level in [
                                    levels[0],
                                    levels[1],
                                    levels[2],
                                ]"
                                :key="level.value"
                                variant="link"
                                size="sm"
                                @click="setGroup(group.key, level.value)"
                                >{{ level.label }}</Button
                            >
                        </span>
                    </div>
                    <ul>
                        <li
                            v-for="area in areas.filter(
                                (a) => a.group === group.key,
                            )"
                            :key="area.key"
                            class="flex flex-col gap-1 border-b border-ash-light/60 py-1.5 md:flex-row md:items-center md:justify-between"
                        >
                            <span class="text-[12.5px]">
                                {{ area.label }}
                                <span
                                    v-if="area.module"
                                    class="text-[11px] text-basalt-light"
                                    >· {{ $t('Modul') }}</span
                                >
                            </span>
                            <fieldset class="flex flex-wrap gap-1">
                                <legend class="sr-only">
                                    {{
                                        $t('Stufe für :area', {
                                            area: area.label,
                                        })
                                    }}
                                </legend>
                                <label
                                    v-for="level in levels"
                                    :key="level.value"
                                    class="relative"
                                >
                                    <input
                                        v-model="draft.levels[area.key]"
                                        type="radio"
                                        class="peer sr-only"
                                        :name="`level-${area.key}`"
                                        :value="level.value"
                                        :disabled="!editable"
                                    />
                                    <span
                                        :class="
                                            levelClass(
                                                (draft.levels[area.key] ??
                                                    0) === level.value,
                                                !editable,
                                            )
                                        "
                                        >{{ level.label }}</span
                                    >
                                </label>
                            </fieldset>
                        </li>
                    </ul>
                </div>

                <div
                    class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-paper px-3 py-2"
                >
                    <p class="text-xs text-basalt-light">
                        {{
                            $t('Vorschau — sieht: :groups', {
                                groups: sees(draft),
                            })
                        }}
                        ·
                        {{
                            $tc(
                                ':count Person|:count Personen',
                                selected.people,
                            )
                        }}
                    </p>
                    <Button
                        v-if="editable"
                        type="submit"
                        :disabled="!dirty || saving"
                        >{{ $t('Speichern') }}</Button
                    >
                </div>
            </form>
        </section>
    </div>
</template>
