<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ApiTokens from '../components/ApiTokens.vue';
import Button from '../components/Button.vue';
import Field from '../components/Field.vue';
import Hex from '../components/Hex.vue';
import Notice from '../components/Notice.vue';
import NotificationSettings from '../components/NotificationSettings.vue';
import CountrySelect from '../components/CountrySelect.vue';
import { formatPostal } from '../components/address';
import { useAutosave } from '../components/autosave';
import { applyTheme } from '../components/theme';
import { missingDigits } from '../components/phone';
import { controlClasses } from '../components/control';
import { t } from '../i18n';
import PageHeader from '../components/PageHeader.vue';
import PhoneInput from '../components/PhoneInput.vue';
import StateTag from '../components/StateTag.vue';
import Tabs from '../components/Tabs.vue';
import TimezoneSelect from '../components/TimezoneSelect.vue';
import Toggle from '../components/Toggle.vue';
import { initials } from '../support';
import type { BackendProps, TabDef } from '../types';
import type { AdminSession, ProfilePageProps } from '../types-admin';

/**
 * Das eigene Profil (K-03): Name, Sprache der Oberfläche, Zeitzone; die eigenen Sitzungen mit «Abmelden» je
 * fremdem Gerät und «Alle anderen beenden»; API-Tokens je Site (M-17); Passkeys als eigener Bereich.
 */
const props = defineProps<ProfilePageProps>();

const page = usePage<BackendProps>();
const flash = computed(() => page.props.backend.flash?.status ?? null);

const personal = useForm({
    section: 'personal',
    first_name: props.user?.first_name ?? '',
    last_name: props.user?.last_name ?? '',
    phone_country: props.user?.phone_country ?? 'CH',
    phone: props.user?.phone ?? '',
});

const address = useForm({
    section: 'address',
    street: props.user?.street ?? '',
    address_extra: props.user?.address_extra ?? '',
    postal_code: props.user?.postal_code ?? '',
    city: props.user?.city ?? '',
    country: props.user?.country ?? 'CH',
});

const preferences = useForm({
    section: 'preferences',
    locale: props.user?.locale ?? 'de',
    timezone: props.user?.timezone ?? 'Europe/Zurich',
    date_format: props.user?.date_format ?? 'd.m.Y',
    time_format: props.user?.time_format ?? 'H:i',
    start_page: props.user?.start_page ?? 'dashboard',
    per_page: String(props.user?.per_page ?? 50),
    list_sort: props.user?.list_sort ?? 'newest',
    help_shortcuts: props.user?.help_shortcuts ?? true,
    theme: props.user?.theme ?? 'light',
});

/* TB 21: Profilbild — hochladen ersetzt das alte, entfernen zeigt wieder die Initialen */
const avatar = useForm<{ avatar: File | null }>({ avatar: null });
const avatarInput = ref<HTMLInputElement | null>(null);
function uploadAvatar(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    if (!file) {
        return;
    }
    avatar.avatar = file;
    avatar.post(props.urls.avatar_store, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            avatar.reset();
            if (avatarInput.value) {
                avatarInput.value.value = '';
            }
        },
    });
}
function removeAvatar(): void {
    router.delete(props.urls.avatar_destroy, { preserveScroll: true });
}

const postalCountry = computed(() =>
    (props.countries ?? []).find((x) => x.code === address.country),
);

/** Nur Ziffern in Ziffern-Ländern; das Feld zeigt sofort, was übrig bleibt (auch wenn der Wert gleich bleibt). */
function onPostal(event: Event): void {
    const input = event.target as HTMLInputElement;
    const clean = formatPostal(input.value, postalCountry.value);
    address.postal_code = clean;
    if (input.value !== clean) {
        input.value = clean;
    }
}

watch(
    () => address.country,
    () => {
        address.postal_code = formatPostal(
            address.postal_code,
            postalCountry.value,
        );
    },
);

const postalHelp = computed(() => {
    const c = (props.countries ?? []).find((x) => x.code === address.country);

    return c?.postal_example
        ? t('Postleitzahl in :country, z. B. :example.', {
              country: c.name,
              example: c.postal_example,
          })
        : t('Postleitzahl wie im Land üblich.');
});
const withExample = (
    options: { value: string; label: string; example?: string }[],
) =>
    options.map((o) => ({
        value: o.value,
        label: o.example ? `${o.example} — ${o.label}` : o.label,
    }));
const fullName = computed(
    () =>
        `${personal.first_name} ${personal.last_name}`.trim() ||
        (props.user?.name ?? ''),
);

const sessions = computed<AdminSession[]>(() => props.sessions ?? []);
const others = computed(() => sessions.value.filter((s) => !s.current));

/* TB 21: Das Benutzermenü springt auf einen Reiter — `?reiter=einstellungen` usw. */
const tabByQuery: Record<string, string> = {
    profil: 'personal',
    anschrift: 'address',
    einstellungen: 'preferences',
    benachrichtigungen: 'notifications',
    sitzungen: 'sessions',
    sicherheit: 'sessions',
    'api-tokens': 'tokens',
    passkeys: 'passkeys',
};
function tabFromUrl(url: string): string {
    const query = new URLSearchParams(url.split('?')[1] ?? '');

    return tabByQuery[query.get('reiter') ?? ''] ?? 'personal';
}
const tab = ref(tabFromUrl(page.url));
watch(
    () => page.url,
    (url) => {
        tab.value = tabFromUrl(url);
    },
);
const count = (errors: Record<string, string | undefined>) =>
    Object.values(errors).filter(Boolean).length;
const tabs = computed<TabDef[]>(() => [
    {
        key: 'personal',
        label: t('Persönlich'),
        errors: count(personal.errors),
    },
    { key: 'address', label: t('Anschrift'), errors: count(address.errors) },
    {
        key: 'preferences',
        label: t('Einstellungen'),
        errors: count(preferences.errors),
    },
    // TB 27: was in Glocke und Mail kommt
    { key: 'notifications', label: t('Benachrichtigungen') },
    { key: 'sessions', label: t('Sitzungen'), count: sessions.value.length },
    {
        key: 'tokens',
        label: t('API-Tokens'),
        count: (props.api_tokens ?? []).length,
    },
    { key: 'passkeys', label: t('Passkeys') },
]);

/* Autosave je Abschnitt: Telefon und Anschrift erst, wenn sie vollständig (oder leer) sind — sonst blitzte beim Tippen ein Fehler auf */
const phoneComplete = computed(
    () =>
        personal.phone.trim() === '' ||
        missingDigits(
            personal.phone,
            (props.countries ?? []).find(
                (c) => c.code === personal.phone_country,
            ),
            props.countries ?? [],
        ) === 0,
);
const addressReady = computed(() => {
    const parts = [address.street, address.postal_code, address.city].map(
        (v) => v.trim() !== '',
    );
    const all = parts.every(Boolean) || parts.every((p) => !p);
    const c = postalCountry.value;
    const postalDone =
        !c?.postal_numeric ||
        !c.postal_example ||
        address.postal_code === '' ||
        address.postal_code.length === c.postal_example.length;

    return all && postalDone;
});
const personalSave = useAutosave(personal, {
    url: () => props.urls.update,
    enabled: () =>
        phoneComplete.value &&
        personal.first_name.trim() !== '' &&
        personal.last_name.trim() !== '',
    labels: {
        first_name: t('Vorname'),
        last_name: t('Nachname'),
        phone: t('Telefon'),
        phone_country: t('Telefon'),
    },
});
const addressSave = useAutosave(address, {
    url: () => props.urls.update,
    enabled: () => addressReady.value,
    labels: {
        street: t('Strasse'),
        address_extra: t('Zusatz'),
        postal_code: t('PLZ'),
        city: t('Ort'),
        country: t('Land'),
    },
});
const preferencesSave = useAutosave(preferences, {
    url: () => props.urls.update,
    delay: 300,
    labels: {
        locale: t('Sprache'),
        timezone: t('Zeitzone'),
        date_format: t('Datumsformat'),
        time_format: t('Zeitformat'),
        start_page: t('Startseite'),
        per_page: t('Einträge pro Seite'),
        list_sort: t('Sortierung der Listen'),
        help_shortcuts: t('Tastenkürzel der Hilfe'),
        theme: t('Erscheinungsbild'),
    },
    // Sprache der Oberfläche wirkt erst mit frischen Props — danach die Seite neu laden
    onSaved: (keys) => {
        // TB 21: Erscheinungsbild wirkt sofort, ohne Neuladen
        if (keys.includes('theme')) {
            applyTheme(preferences.theme);
        }
        if (keys.includes('locale')) {
            router.reload();
        }
    },
});

const sessionError = ref('');

function endSession(session: AdminSession): void {
    sessionError.value = '';
    router.delete(props.urls.session_end.replace('__ID__', session.id), {
        preserveScroll: true,
        onError: (errors) => {
            sessionError.value = Object.values(errors)[0] ?? '';
        },
    });
}

function endOthers(): void {
    sessionError.value = '';
    router.delete(props.urls.sessions_end_others, {
        preserveScroll: true,
        onError: (errors) => {
            sessionError.value = Object.values(errors)[0] ?? '';
        },
    });
}
</script>

<template>
    <Head :title="$t('Profil')" />
    <PageHeader
        :title="$t('Profil')"
        :crumb="$t('Konto › Profil')"
        :lead="
            $t(
                'Ihre Angaben und wie das Backend für Sie aussieht. Jede Änderung speichert sich selbst — ein Häkchen am Feld zeigt es.',
            )
        "
    >
        <template #actions>
            <span class="flex items-center gap-2 text-[12.5px]">
                <Hex
                    tone="lava-dark"
                    size="md"
                    :label="initials(fullName)"
                    :image="user?.avatar_url ?? null"
                />
                <span class="min-w-0">
                    <span class="block font-semibold">{{ fullName }}</span>
                    <span
                        class="block text-[11.5px] break-all text-basalt-light"
                        >{{ user?.email }}</span
                    >
                </span>
            </span>
        </template>
    </PageHeader>

    <Notice v-if="status && status !== flash" tone="success" class="mb-3.5">{{
        status
    }}</Notice>

    <div
        class="max-w-3xl rounded-lg border border-ash-light bg-white px-3.5 py-3 max-md:border-0 max-md:bg-transparent max-md:p-0"
    >
        <Tabs v-model="tab" :tabs="tabs">
            <template #personal>
                <div
                    class="mb-4 flex flex-wrap items-center gap-4 border-b border-ash-light pb-4"
                >
                    <Hex
                        tone="lava-dark"
                        size="xl"
                        :label="initials(fullName)"
                        :image="user?.avatar_url ?? null"
                    />
                    <div class="min-w-0">
                        <p class="text-[13px] font-semibold">
                            {{ $t('Profilbild') }}
                        </p>
                        <p class="mb-2 text-[12px] text-basalt-light">
                            {{
                                $t(
                                    'JPEG, PNG oder WebP bis 5 MB — wird quadratisch zugeschnitten. Ohne Bild erscheinen Ihre Initialen.',
                                )
                            }}
                        </p>
                        <div class="flex flex-wrap items-center gap-2">
                            <label
                                class="inline-flex min-h-8 cursor-pointer items-center rounded-md border border-ash bg-white px-3 text-[12.5px] font-semibold text-basalt focus-within:outline-2 focus-within:outline-lava-dark hover:bg-paper"
                            >
                                {{
                                    user?.avatar_url
                                        ? $t('Bild ersetzen')
                                        : $t('Bild wählen')
                                }}
                                <input
                                    ref="avatarInput"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    class="sr-only"
                                    :disabled="avatar.processing"
                                    @change="uploadAvatar"
                                />
                            </label>
                            <Button
                                v-if="user?.avatar_url"
                                variant="link"
                                size="sm"
                                @click="removeAvatar"
                                >{{ $t('Bild entfernen') }}</Button
                            >
                        </div>
                        <p
                            v-if="avatar.errors.avatar"
                            class="text-danger mt-1 text-[11.5px] font-semibold"
                            role="alert"
                        >
                            {{ avatar.errors.avatar }}
                        </p>
                    </div>
                </div>
                <form novalidate @submit.prevent="personalSave.flush()">
                    <div class="grid gap-x-4 md:grid-cols-2">
                        <Field
                            v-model="personal.first_name"
                            :saved="personalSave.saved('first_name')"
                            :label="$t('Vorname')"
                            autocomplete="given-name"
                            required
                            :error="personal.errors.first_name"
                        />
                        <Field
                            v-model="personal.last_name"
                            :saved="personalSave.saved('last_name')"
                            :label="$t('Nachname')"
                            autocomplete="family-name"
                            required
                            :error="personal.errors.last_name"
                            class="md:mt-0!"
                        />
                        <Field
                            :model-value="user?.email ?? ''"
                            :label="$t('E-Mail')"
                            type="email"
                            disabled
                            :help="$t('Die Adresse ändert die Verwaltung.')"
                        />
                    </div>
                    <Field
                        :label="$t('Telefon')"
                        :saved="personalSave.saved('phone')"
                        optional
                        :error="
                            personal.errors.phone ??
                            personal.errors.phone_country
                        "
                        :help="
                            $t(
                                'Land über die Flagge wählen (mit Suche); die Vorwahl steht fest davor. «+49 …» eingefügt stellt das Land selbst um.',
                            )
                        "
                    >
                        <template #default="{ id, invalid }">
                            <PhoneInput
                                :id="id"
                                v-model="personal.phone"
                                v-model:country="personal.phone_country"
                                :countries="countries ?? []"
                                :invalid="invalid"
                            />
                        </template>
                    </Field>
                </form>
            </template>

            <template #address>
                <form novalidate @submit.prevent="addressSave.flush()">
                    <Field
                        v-model="address.street"
                        :saved="addressSave.saved('street')"
                        :label="$t('Strasse und Nr.')"
                        autocomplete="address-line1"
                        :error="address.errors.street"
                    />
                    <Field
                        v-model="address.address_extra"
                        :saved="addressSave.saved('address_extra')"
                        :label="$t('Zusatz')"
                        optional
                        autocomplete="address-line2"
                        :placeholder="$t('Postfach, c/o, Stockwerk')"
                        :error="address.errors.address_extra"
                    />
                    <div class="grid gap-x-4 md:grid-cols-[10rem_1fr]">
                        <Field
                            :label="$t('PLZ')"
                            :saved="addressSave.saved('postal_code')"
                            :error="address.errors.postal_code"
                            class="mt-2.5!"
                        >
                            <template #default="{ id, invalid, describedby }">
                                <input
                                    :id="id"
                                    :aria-describedby="describedby"
                                    :value="address.postal_code"
                                    type="text"
                                    autocomplete="postal-code"
                                    :inputmode="
                                        postalCountry?.postal_numeric
                                            ? 'numeric'
                                            : 'text'
                                    "
                                    :placeholder="
                                        postalCountry?.postal_example ?? ''
                                    "
                                    :aria-invalid="invalid ? 'true' : undefined"
                                    :class="controlClasses({ error: invalid })"
                                    @input="onPostal"
                                />
                            </template>
                        </Field>
                        <Field
                            v-model="address.city"
                            :saved="addressSave.saved('city')"
                            :label="$t('Ort')"
                            autocomplete="address-level2"
                            :error="address.errors.city"
                            class="mt-2.5!"
                        />
                    </div>
                    <Field
                        :label="$t('Land')"
                        :saved="addressSave.saved('country')"
                        :error="address.errors.country"
                        :help="postalHelp"
                    >
                        <template #default="{ id, invalid }">
                            <CountrySelect
                                :id="id"
                                v-model="address.country"
                                :countries="countries ?? []"
                                :invalid="invalid"
                            />
                        </template>
                    </Field>
                    <div
                        class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-ash-light pt-3"
                    >
                        <span class="text-[11.5px] text-basalt-light">{{
                            $t(
                                'Freiwillig — wenn Sie eine Anschrift angeben, dann vollständig. Gespeichert wird automatisch, sobald sie vollständig ist.',
                            )
                        }}</span>
                    </div>
                </form>
            </template>

            <template #preferences>
                <form novalidate @submit.prevent="preferencesSave.flush()">
                    <div class="grid gap-x-4 md:grid-cols-2">
                        <Field
                            v-model="preferences.locale"
                            :saved="preferencesSave.saved('locale')"
                            :label="$t('Sprache der Oberfläche')"
                            type="select"
                            :options="locales ?? []"
                            :error="preferences.errors.locale"
                        />
                        <Field
                            :label="$t('Zeitzone')"
                            :saved="preferencesSave.saved('timezone')"
                            :error="preferences.errors.timezone"
                            :help="
                                $t(
                                    'Zeiten im Backend erscheinen in dieser Zone. Geplante Veröffentlichungen rechnen in der Zeitzone der Site.',
                                )
                            "
                            class="md:mt-0!"
                        >
                            <template #default="{ id, invalid }">
                                <TimezoneSelect
                                    :id="id"
                                    v-model="preferences.timezone"
                                    :zones="timezones ?? []"
                                    :invalid="invalid"
                                    :hour12="
                                        preferences.time_format === 'g:i A'
                                    "
                                />
                            </template>
                        </Field>
                        <Field
                            v-model="preferences.date_format"
                            :saved="preferencesSave.saved('date_format')"
                            :label="$t('Datumsformat')"
                            type="select"
                            :options="withExample(date_formats ?? [])"
                            :error="preferences.errors.date_format"
                        />
                        <Field
                            v-model="preferences.time_format"
                            :saved="preferencesSave.saved('time_format')"
                            :label="$t('Zeitformat')"
                            type="select"
                            :options="withExample(time_formats ?? [])"
                            :error="preferences.errors.time_format"
                        />
                        <Field
                            v-model="preferences.start_page"
                            :saved="preferencesSave.saved('start_page')"
                            :label="$t('Startseite nach der Anmeldung')"
                            type="select"
                            :options="start_pages ?? []"
                            :error="preferences.errors.start_page"
                        />
                        <Field
                            v-model="preferences.theme"
                            :saved="preferencesSave.saved('theme')"
                            :label="$t('Erscheinungsbild')"
                            type="select"
                            :options="themes ?? []"
                            :help="
                                $t(
                                    'Hell, dunkel oder wie im Betriebssystem eingestellt — gilt nur für Sie.',
                                )
                            "
                            :error="preferences.errors.theme"
                            class="md:mt-0!"
                        />
                    </div>
                    <h3
                        class="mt-5 mb-1 text-[12px] font-semibold tracking-wide text-basalt-light uppercase"
                    >
                        {{ $t('Listen und Bedienung') }}
                    </h3>
                    <div class="grid gap-x-4 md:grid-cols-2">
                        <Field
                            v-model="preferences.per_page"
                            :saved="preferencesSave.saved('per_page')"
                            :label="$t('Einträge pro Seite')"
                            type="select"
                            :options="per_page_options ?? []"
                            :help="
                                $t(
                                    'Inhalte, Medien, Formular-Eingänge und Protokoll.',
                                )
                            "
                            :error="preferences.errors.per_page"
                        />
                        <Field
                            v-model="preferences.list_sort"
                            :saved="preferencesSave.saved('list_sort')"
                            :label="$t('Sortierung der Listen')"
                            type="select"
                            :options="list_sorts ?? []"
                            :help="
                                $t('Inhalte nach Titel, Medien nach Dateiname.')
                            "
                            :error="preferences.errors.list_sort"
                            class="md:mt-0!"
                        />
                    </div>
                    <div
                        class="mt-4 flex items-start justify-between gap-4 rounded-md bg-paper px-3 py-2.5"
                    >
                        <div>
                            <p
                                id="hilfe-kuerzel-titel"
                                class="text-[13px] font-semibold"
                            >
                                {{ $t('Tastenkürzel der Hilfe') }}
                            </p>
                            <p class="text-[12px] text-basalt-light">
                                {{
                                    $t(
                                        '? und F1 öffnen die Hilfe, / sucht darin. Aus, wenn die Tasten mit einem Hilfsmittel kollidieren.',
                                    )
                                }}
                            </p>
                        </div>
                        <Toggle
                            v-model="preferences.help_shortcuts"
                            :label="$t('Tastenkürzel der Hilfe')"
                        />
                    </div>
                </form>
            </template>

            <template #sessions>
                <p
                    v-if="sessions.length === 0"
                    class="py-2 text-[12.5px] text-basalt-light"
                >
                    {{ $t('Keine Sitzung gespeichert.') }}
                </p>
                <ul v-else class="divide-y divide-ash-light text-[12.5px]">
                    <li
                        v-for="s in sessions"
                        :key="s.id"
                        class="flex flex-wrap items-center justify-between gap-2 py-2"
                    >
                        <span class="min-w-0">
                            <span class="font-semibold">{{ s.agent }}</span>
                            <StateTag
                                v-if="s.current"
                                tone="lava"
                                class="ml-1"
                                >{{ $t('diese Sitzung') }}</StateTag
                            >
                            <span class="block text-[11.5px] text-basalt-light">
                                <span class="font-mono">{{ s.ip ?? '—' }}</span>
                                ·
                                {{
                                    $t('zuletzt aktiv :date', {
                                        date: s.last_active ?? '—',
                                    })
                                }}
                            </span>
                        </span>
                        <Button
                            v-if="!s.current"
                            variant="secondary"
                            size="sm"
                            @click="endSession(s)"
                            >{{ $t('Abmelden') }}</Button
                        >
                    </li>
                </ul>
                <p
                    v-if="sessionError"
                    class="text-danger mt-1 text-[11.5px] font-semibold"
                    role="alert"
                >
                    {{ sessionError }}
                </p>
                <div
                    class="mt-2 flex flex-wrap items-center justify-between gap-2 border-t border-ash-light pt-3"
                >
                    <span class="text-[11.5px] text-basalt-light">{{
                        $t(
                            'Ein fremdes Gerät? Beenden Sie die Sitzung und richten Sie einen neuen Passkey ein.',
                        )
                    }}</span>
                    <Button
                        variant="secondary"
                        size="sm"
                        :disabled="others.length === 0"
                        @click="endOthers"
                        >{{ $t('Alle anderen beenden') }}</Button
                    >
                </div>
            </template>

            <template #tokens>
                <ApiTokens
                    :tokens="api_tokens ?? []"
                    :sites="api_sites ?? []"
                    :abilities="api_abilities ?? []"
                    :store-url="urls.tokens_store"
                    :destroy-url="urls.token_destroy"
                    :api-base="urls.api_base"
                />
            </template>

            <template #notifications>
                <NotificationSettings
                    v-if="
                        notification_groups &&
                        notification_settings &&
                        urls.notifications
                    "
                    :groups="notification_groups"
                    :settings="notification_settings"
                    :url="urls.notifications"
                    :email="user?.email ?? ''"
                />
            </template>
            <template #passkeys>
                <p class="py-2 text-[12.5px] text-basalt-light">
                    {{
                        $t(
                            'Passkeys ersetzen Passwort und Mail-Code. Registrieren und ansehen auf einer eigenen Seite.',
                        )
                    }}
                </p>
                <Link
                    :href="urls.passkeys"
                    class="text-[12.5px] font-semibold text-lava-dark underline hover:text-basalt"
                    >{{ $t('Passkeys verwalten →') }}</Link
                >
                <p
                    class="mt-4 border-t border-ash-light pt-3 text-[12.5px] text-basalt-light"
                >
                    {{
                        $t(
                            'Was speichert Basalto über Sie? Die Auskunft enthält Profil, Rollen, Ihre Einträge im Protokoll und Formulareingänge mit Ihrer Adresse (DSG/DSGVO).',
                        )
                    }}
                </p>
                <a
                    :href="urls.export"
                    class="text-[12.5px] font-semibold text-lava-dark underline hover:text-basalt"
                    >{{ $t('Meine Daten herunterladen (JSON)') }}</a
                >
            </template>
        </Tabs>
    </div>
</template>
