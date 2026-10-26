<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useAutosave } from '../../components/autosave';
import Button from '../../components/Button.vue';
import { controlClasses } from '../../components/control';
import Field from '../../components/Field.vue';
import Notice from '../../components/Notice.vue';
import PageHeader from '../../components/PageHeader.vue';
import Tabs from '../../components/Tabs.vue';
import Toggle from '../../components/Toggle.vue';
import { t } from '../../i18n';
import type {
    BackendProps,
    ModuleInfo,
    SiteFormData,
    SiteFormDomain,
    SiteLocale,
    TabDef,
} from '../../types';

/**
 * Site anlegen oder bearbeiten (Bereich 18) — vier echte Reiter: Allgemein · Sprachen · Domains · Module (E-26).
 * Fehler zählen je Reiter. Anlegen mit Knopf («Speichern und schliessen» sendet zusätzlich `close: true`);
 * beim Bearbeiten speichert sich jede Änderung selbst (Autosave) — neue Domains erst, wenn der Host vollständig ist.
 */
const props = defineProps<{
    site: SiteFormData;
    modules: ModuleInfo[];
    timezones: string[];
    themes: string[];
    available_locales: string[];
    submit_url: string;
    method: 'post' | 'put';
    back_url: string;
    /** Vollexport als ZIP (M-18) — nur für Betreiber und Konto-Verwaltung */
    export_url?: string | null;
    title: string;
    /** TB 12 Scheibe 1: auf der SaaS verwaltet das Konto Alias und Domains — hier nur lesen */
    managed_domains_url?: string | null;
    /** TB 13 Scheibe 4 (M-25): Anmeldung über SSO — nur für Betreiber und Konto-Verwaltung */
    sso_url?: string | null;
    /** TB 53: Anmeldung für Kundenkonten über Google, Microsoft, GitHub, LinkedIn */
    social_url?: string | null;
}>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);

const form = useForm<{
    name: string;
    handle: string;
    default_locale: string;
    timezone: string;
    theme: string | null;
    locales: SiteLocale[];
    domains: SiteFormDomain[];
    modules: Record<string, boolean>;
    media: { alt_required: boolean };
}>({
    name: props.site.name,
    handle: props.site.handle,
    default_locale: props.site.default_locale,
    timezone: props.site.timezone,
    theme: props.site.theme,
    locales: props.site.locales.map((l) => ({ ...l })),
    domains: props.site.domains.map((d) => ({ ...d })),
    modules: { ...props.site.modules },
    media: { alt_required: props.site.media?.alt_required ?? false },
});

const errors = computed(() => form.errors as unknown as Record<string, string>);

function countErrors(keys: string[]): number {
    return Object.keys(errors.value).filter((k) =>
        keys.some((p) => k === p || k.startsWith(`${p}.`)),
    ).length;
}

function rowErrors(prefix: string): string[] {
    return Object.entries(errors.value)
        .filter(([k]) => k === prefix || k.startsWith(`${prefix}.`))
        .map(([, v]) => v);
}

const tabs = computed<TabDef[]>(() => [
    {
        key: 'general',
        label: t('Allgemein'),
        errors: countErrors([
            'name',
            'handle',
            'default_locale',
            'timezone',
            'theme',
            'media',
        ]),
    },
    { key: 'locales', label: t('Sprachen'), errors: countErrors(['locales']) },
    { key: 'domains', label: t('Domains'), errors: countErrors(['domains']) },
    { key: 'modules', label: t('Module'), errors: countErrors(['modules']) },
]);
const tab = ref('general');
const errorTabs = computed(() => tabs.value.filter((tab) => tab.errors));

const themeOptions = computed(() => [
    { value: '', label: t('— Starter des Kerns —') },
    ...props.themes.map((theme) => ({ value: theme, label: theme })),
]);

/* ── Sprachen ── */

const unusedLocales = computed(() =>
    props.available_locales.filter(
        (l) => !form.locales.some((x) => x.locale === l),
    ),
);

function localeOptions(current: string): { value: string; label: string }[] {
    const all = props.available_locales.includes(current)
        ? props.available_locales
        : [current, ...props.available_locales];

    return all
        .filter((l) => l === current || unusedLocales.value.includes(l))
        .map((l) => ({ value: l, label: l.toUpperCase() }));
}

function fallbackOptions(current: string): { value: string; label: string }[] {
    return [
        { value: '', label: '—' },
        ...form.locales
            .filter((l) => l.locale !== current)
            .map((l) => ({ value: l.locale, label: l.locale.toUpperCase() })),
    ];
}

function setDefault(locale: string): void {
    form.default_locale = locale;
    form.locales = form.locales.map((l) => ({
        ...l,
        is_default: l.locale === locale,
        is_required: l.locale === locale ? true : l.is_required,
        fallback_locale: l.locale === locale ? null : l.fallback_locale,
    }));
}

function addLocale(): void {
    const next = unusedLocales.value[0];
    if (!next) {
        return;
    }
    const first = form.locales.length === 0;
    form.locales = [
        ...form.locales,
        {
            locale: next,
            is_default: first,
            is_required: first,
            fallback_locale: first ? null : form.default_locale || null,
        },
    ];
    if (first) {
        setDefault(next);
    }
}

function removeLocale(index: number): void {
    const row = form.locales[index];
    if (!row || row.is_default) {
        return;
    }
    form.locales = form.locales
        .filter((_, i) => i !== index)
        .map((l) => ({
            ...l,
            fallback_locale:
                l.fallback_locale === row.locale ? null : l.fallback_locale,
        }));
}

function updateLocale(index: number, patch: Partial<SiteLocale>): void {
    form.locales = form.locales.map((l, i) =>
        i === index ? { ...l, ...patch } : l,
    );
}

/* ── Domains ── */

const kinds = computed(() => [
    { value: 'alias', label: t('Alias') },
    { value: 'custom', label: t('Eigene Domain') },
    { value: 'redirect', label: t('Weiterleitung') },
]);

function addDomain(): void {
    form.domains = [
        ...form.domains,
        {
            id: null,
            host: '',
            kind: 'custom',
            is_primary: form.domains.length === 0,
        },
    ];
}

function setPrimary(index: number): void {
    form.domains = form.domains.map((d, i) => ({
        ...d,
        is_primary: i === index,
    }));
}

function removeDomain(index: number): void {
    const wasPrimary = form.domains[index]?.is_primary ?? false;
    form.domains = form.domains.filter((_, i) => i !== index);
    if (wasPrimary && form.domains.length > 0) {
        setPrimary(0);
    }
}

function updateDomain(index: number, patch: Partial<SiteFormDomain>): void {
    form.domains = form.domains.map((d, i) =>
        i === index ? { ...d, ...patch } : d,
    );
}

/* ── Module ── */

function setModule(handle: string, on: boolean): void {
    form.modules = { ...form.modules, [handle]: on };
}

/* ── Senden ── */

const editing = props.method === 'put';
const handlePattern = /^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])?$/;
const hostPattern = /^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/;

/* Autosave nur beim Bearbeiten — und erst, wenn Pflichtfelder und Hosts vollständig sind (sonst blitzte beim Tippen ein Fehler auf) */
const autosave = editing
    ? useAutosave(form, {
          url: () => props.submit_url,
          delay: 1000,
          enabled: () =>
              form.name.trim() !== '' &&
              handlePattern.test(form.handle) &&
              form.domains.every((d) =>
                  hostPattern.test(d.host.trim().toLowerCase()),
              ),
          labels: {
              name: t('Name'),
              handle: t('Handle'),
              timezone: t('Zeitzone'),
              theme: t('Theme'),
              media: t('Alt-Text Pflicht'),
              default_locale: t('Standardsprache'),
              locales: t('Sprachen'),
              domains: t('Domains'),
              modules: t('Module'),
          },
      })
    : null;

function saved(key: string): boolean {
    return autosave?.saved(key) ?? false;
}

function submit(): void {
    if (autosave) {
        autosave.flush();
    } else {
        save(false);
    }
}

function save(close: boolean): void {
    form.transform((data) => (close ? { ...data, close: true } : data));
    const options = { preserveScroll: true };
    if (props.method === 'put') {
        form.put(props.submit_url, options);
    } else {
        form.post(props.submit_url, options);
    }
}

const small = controlClasses({ small: true });
const smallMono = controlClasses({ small: true, mono: true });
/* «Export herunterladen» als gewöhnlicher Link: der Browser lädt das ZIP, Inertia bleibt draussen */
const exportClasses =
    'inline-flex items-center justify-center whitespace-nowrap rounded-md border border-ash bg-white px-[13px] py-2 text-[13px] font-semibold leading-tight text-basalt hover:bg-paper';
</script>

<template>
    <Head :title="title" />
    <PageHeader
        :title="title"
        :crumb="$t(':site › Verwaltung › Sites', { site: siteName })"
    >
        <template v-if="export_url || sso_url || social_url" #actions>
            <Link v-if="sso_url" :href="sso_url" :class="exportClasses">{{
                $t('Anmeldung über SSO')
            }}</Link>
            <Link v-if="social_url" :href="social_url" :class="exportClasses">{{
                $t('Anmeldung für Kundenkonten')
            }}</Link>
            <a
                v-if="export_url"
                :href="export_url"
                :class="exportClasses"
                download
                :title="
                    $t(
                        'JSON + Medien, dasselbe Format wie der Import — ohne Formulareingänge',
                    )
                "
                >{{ $t('Export herunterladen') }}</a
            >
        </template>
    </PageHeader>

    <form
        class="rounded-lg border border-ash-light bg-white px-4 pt-3.5 pb-3.5"
        novalidate
        @submit.prevent="submit"
    >
        <div class="text-sm font-semibold">
            {{ site.id === null ? $t('Neue Site') : site.name }}
        </div>
        <p class="mt-0.5 text-xs text-basalt-light">
            {{
                $t(
                    'Allgemein · Sprachen · Domains · Module — jeder Reiter wechselt den Inhalt; Fehler stehen am Feld und am Reiter.',
                )
            }}
            <template v-if="editing">
                {{ $t('Änderungen speichern sich selbst.') }}</template
            >
        </p>
        <Notice v-if="errorTabs.length > 0" tone="danger" class="mt-2.5">
            {{ $t('Bitte prüfen:') }}
            {{
                errorTabs
                    .map((tab) => `${tab.label} (${tab.errors})`)
                    .join(', ')
            }}
        </Notice>

        <Tabs v-model="tab" :tabs="tabs" class="mt-3.5">
            <template #general>
                <div class="grid gap-x-4 md:grid-cols-2">
                    <Field
                        v-model="form.name"
                        :saved="saved('name')"
                        :label="$t('Name')"
                        :error="errors.name"
                        required
                        autocomplete="organization"
                    />
                    <Field
                        v-model="form.handle"
                        :saved="saved('handle')"
                        :label="$t('Handle')"
                        mono
                        :error="errors.handle"
                        :disabled="Boolean(managed_domains_url) && editing"
                        required
                        :placeholder="$t('meine-site')"
                    >
                        <template #help>
                            {{ $t('a–z, 0–9, Bindestrich · Alias') }}
                            <code class="font-mono"
                                >{{
                                    form.handle || '‹handle›'
                                }}.basaltocms.com</code
                            >
                        </template>
                    </Field>
                    <Field
                        v-model="form.timezone"
                        :saved="saved('timezone')"
                        :label="$t('Zeitzone')"
                        type="select"
                        :options="timezones"
                        :error="errors.timezone"
                    />
                    <Field
                        :model-value="form.theme ?? ''"
                        :saved="saved('theme')"
                        :label="$t('Theme')"
                        type="select"
                        :options="themeOptions"
                        :error="errors.theme"
                        :help="
                            $t(
                                'Starter des Kerns oder ein Theme aus themes/… im Repo.',
                            )
                        "
                        @update:model-value="form.theme = $event || null"
                    />
                </div>
                <p
                    v-if="errors.default_locale"
                    class="text-danger mt-2 text-[11.5px] font-semibold"
                >
                    {{ errors.default_locale }}
                </p>
                <div
                    class="mt-3 flex items-center justify-between gap-2.5 border-t border-ash-light pt-3 text-[13px]"
                >
                    <div>
                        <label for="alt-required" class="font-semibold">{{
                            $t('Alt-Text Pflicht')
                        }}</label
                        ><span
                            v-if="saved('media')"
                            class="text-success ml-1.5 text-[11.5px] font-semibold"
                            >✓ {{ $t('gespeichert') }}</span
                        >
                        <p class="text-[11.5px] text-basalt-light">
                            {{
                                $t(
                                    'Veröffentlichung ohne Alt-Text wird abgewiesen — die Mediathek markiert Bilder ohne Alt-Text je Sprache.',
                                )
                            }}
                        </p>
                        <p
                            v-if="errors['media.alt_required']"
                            class="text-danger text-[11.5px] font-semibold"
                        >
                            {{ errors['media.alt_required'] }}
                        </p>
                    </div>
                    <Toggle
                        id="alt-required"
                        v-model="form.media.alt_required"
                        :label="$t('Alt-Text Pflicht')"
                    />
                </div>
            </template>

            <template #locales>
                <p class="text-[11.5px] text-basalt-light">
                    {{
                        $t(
                            'Die Standardsprache ist immer Pflicht — der Haken ist gesetzt und gesperrt.',
                        )
                    }}<span
                        v-if="saved('locales') || saved('default_locale')"
                        class="text-success ml-1.5 font-semibold"
                        >✓ {{ $t('gespeichert') }}</span
                    >
                </p>
                <div
                    v-for="(row, i) in form.locales"
                    :key="`${row.locale}-${i}`"
                    class="border-b border-ash-light py-2 text-[12.5px]"
                >
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                        <label class="sr-only" :for="`locale-${i}`">{{
                            $t('Sprache')
                        }}</label>
                        <select
                            :id="`locale-${i}`"
                            :value="row.locale"
                            :class="[smallMono, 'w-24']"
                            @change="
                                updateLocale(i, {
                                    locale: ($event.target as HTMLSelectElement)
                                        .value,
                                })
                            "
                        >
                            <option
                                v-for="o in localeOptions(row.locale)"
                                :key="o.value"
                                :value="o.value"
                            >
                                {{ o.label }}
                            </option>
                        </select>
                        <label class="flex items-center gap-1.5">
                            <input
                                type="radio"
                                name="default_locale"
                                :value="row.locale"
                                :checked="row.is_default"
                                class="accent-lava-dark"
                                @change="setDefault(row.locale)"
                            />
                            {{ $t('Standard') }}
                        </label>
                        <label class="flex items-center gap-1.5">
                            <input
                                type="checkbox"
                                :checked="row.is_default || row.is_required"
                                :disabled="row.is_default"
                                class="accent-lava-dark"
                                @change="
                                    updateLocale(i, {
                                        is_required: (
                                            $event.target as HTMLInputElement
                                        ).checked,
                                    })
                                "
                            />
                            {{ $t('Pflicht') }}
                        </label>
                        <label class="flex items-center gap-1.5">
                            <span class="text-basalt-light">{{
                                $t('Fallback')
                            }}</span>
                            <select
                                :value="row.fallback_locale ?? ''"
                                :disabled="row.is_default"
                                :class="[small, 'w-20']"
                                @change="
                                    updateLocale(i, {
                                        fallback_locale:
                                            ($event.target as HTMLSelectElement)
                                                .value || null,
                                    })
                                "
                            >
                                <option
                                    v-for="o in fallbackOptions(row.locale)"
                                    :key="o.value"
                                    :value="o.value"
                                >
                                    {{ o.label }}
                                </option>
                            </select>
                        </label>
                        <Button
                            v-if="!row.is_default"
                            variant="link"
                            size="sm"
                            class="ml-auto"
                            @click="removeLocale(i)"
                            >{{ $t('Entfernen') }}</Button
                        >
                    </div>
                    <p
                        v-for="(message, k) in rowErrors(`locales.${i}`)"
                        :key="k"
                        class="text-danger mt-1 text-[11.5px] font-semibold"
                    >
                        {{ message }}
                    </p>
                </div>
                <p
                    v-if="errors.locales"
                    class="text-danger mt-2 text-[11.5px] font-semibold"
                >
                    {{ errors.locales }}
                </p>
                <div class="mt-2">
                    <Button
                        variant="link"
                        size="sm"
                        :disabled="unusedLocales.length === 0"
                        @click="addLocale"
                        >+ {{ $t('Sprache') }}</Button
                    >
                </div>
            </template>

            <template #domains>
                <div v-if="managed_domains_url" class="text-[12.5px]">
                    <p class="text-basalt-light">
                        {{
                            $t(
                                'Alias und eigene Domains verwaltet das Konto — der Alias ist sofort erreichbar, eigene Domains bindet das Konto ohne Eingriff an.',
                            )
                        }}
                    </p>
                    <ul class="mt-2 space-y-0.5">
                        <li
                            v-for="d in form.domains"
                            :key="d.host"
                            class="font-mono text-xs"
                        >
                            {{ d.host }}
                        </li>
                    </ul>
                    <div class="mt-2">
                        <Button
                            variant="secondary"
                            size="sm"
                            :href="managed_domains_url"
                            >{{ $t('Konto › Domains') }}</Button
                        >
                    </div>
                </div>
                <template v-else>
                    <p class="text-[11.5px] text-basalt-light">
                        {{ $t('Alias') }}
                        <code class="font-mono"
                            >{{
                                form.handle || '‹handle›'
                            }}.basaltocms.com</code
                        >
                        ·
                        {{
                            $t(
                                'Eine eigene Domain wird erreichbar, sobald ihr DNS auf den Server zeigt.',
                            )
                        }}<template v-if="editing">
                            {{ ' '
                            }}{{
                                $t(
                                    'Eine neue Domain speichert sich, sobald der Host vollständig ist.',
                                )
                            }}</template
                        ><span
                            v-if="saved('domains')"
                            class="text-success ml-1.5 font-semibold"
                            >✓ {{ $t('gespeichert') }}</span
                        >
                    </p>
                    <div
                        v-for="(row, i) in form.domains"
                        :key="row.id ?? `neu-${i}`"
                        class="border-b border-ash-light py-2 text-[12.5px]"
                    >
                        <div
                            class="flex flex-wrap items-center gap-x-3 gap-y-1.5"
                        >
                            <label class="sr-only" :for="`domain-${i}`">{{
                                $t('Host')
                            }}</label>
                            <input
                                :id="`domain-${i}`"
                                type="text"
                                :value="row.host"
                                placeholder="www.example.ch"
                                :class="[smallMono, 'w-56']"
                                @input="
                                    updateDomain(i, {
                                        host: (
                                            $event.target as HTMLInputElement
                                        ).value,
                                    })
                                "
                            />
                            <select
                                :value="row.kind"
                                :class="[small, 'w-36']"
                                :aria-label="
                                    $t('Art der Domain :number', {
                                        number: i + 1,
                                    })
                                "
                                @change="
                                    updateDomain(i, {
                                        kind: (
                                            $event.target as HTMLSelectElement
                                        ).value,
                                    })
                                "
                            >
                                <option
                                    v-for="k in kinds"
                                    :key="k.value"
                                    :value="k.value"
                                >
                                    {{ k.label }}
                                </option>
                            </select>
                            <label class="flex items-center gap-1.5">
                                <input
                                    type="radio"
                                    name="primary_domain"
                                    :value="i"
                                    :checked="row.is_primary"
                                    class="accent-lava-dark"
                                    @change="setPrimary(i)"
                                />
                                {{ $t('primär') }}
                            </label>
                            <Button
                                variant="link"
                                size="sm"
                                class="ml-auto"
                                @click="removeDomain(i)"
                                >{{ $t('Entfernen') }}</Button
                            >
                        </div>
                        <p
                            v-for="(message, k) in rowErrors(`domains.${i}`)"
                            :key="k"
                            class="text-danger mt-1 text-[11.5px] font-semibold"
                        >
                            {{ message }}
                        </p>
                    </div>
                    <p
                        v-if="form.domains.length === 0"
                        class="text-ash-dark py-2 text-xs"
                    >
                        {{
                            $t(
                                'Noch keine Domain — der Alias genügt zum Start.',
                            )
                        }}
                    </p>
                    <p
                        v-if="errors.domains"
                        class="text-danger mt-2 text-[11.5px] font-semibold"
                    >
                        {{ errors.domains }}
                    </p>
                    <div class="mt-2">
                        <Button variant="link" size="sm" @click="addDomain"
                            >+ {{ $t('Domain') }}</Button
                        >
                    </div>
                </template>
            </template>

            <template #modules>
                <p v-if="modules.length === 0" class="text-ash-dark text-xs">
                    {{ $t('Keine Module installiert.') }}
                </p>
                <div
                    v-for="m in modules"
                    :key="m.handle"
                    class="flex items-center justify-between gap-2.5 border-b border-ash-light py-2 text-[13px]"
                >
                    <div>
                        {{ m.label }}
                        <span
                            class="ml-1 font-mono text-[10.5px] text-basalt-light"
                            >{{ m.handle }}</span
                        ><span
                            v-if="saved(`modules.${m.handle}`)"
                            class="text-success ml-1.5 text-[11.5px] font-semibold"
                            >✓ {{ $t('gespeichert') }}</span
                        >
                        <p
                            v-if="!m.switchable"
                            class="text-[11.5px] text-basalt-light"
                        >
                            {{ $t('immer an') }}
                        </p>
                        <p
                            v-if="errors[`modules.${m.handle}`]"
                            class="text-danger text-[11.5px] font-semibold"
                        >
                            {{ errors[`modules.${m.handle}`] }}
                        </p>
                    </div>
                    <Toggle
                        :model-value="
                            m.switchable
                                ? (form.modules[m.handle] ?? false)
                                : true
                        "
                        :disabled="!m.switchable"
                        :label="m.label"
                        @update:model-value="setModule(m.handle, $event)"
                    />
                </div>
                <p
                    v-if="errors.modules"
                    class="text-danger mt-2 text-[11.5px] font-semibold"
                >
                    {{ errors.modules }}
                </p>
            </template>
        </Tabs>

        <div
            class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-ash-light pt-3"
        >
            <Button v-if="editing" variant="link" :href="back_url"
                >← {{ $t('Zu den Sites') }}</Button
            >
            <Button v-else variant="link" :href="back_url">{{
                $t('Abbrechen')
            }}</Button>
            <div v-if="!editing" class="flex flex-wrap gap-2">
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
