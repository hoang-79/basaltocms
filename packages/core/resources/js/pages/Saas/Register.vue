<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { solvePow } from '../../components/pow';
import { computed, ref, watch } from 'vue';
import AliasField from '../../components/AliasField.vue';
import Button from '../../components/Button.vue';
import Card from '../../components/Card.vue';
import CodeInput from '../../components/CodeInput.vue';
import Field from '../../components/Field.vue';
import Hex from '../../components/Hex.vue';
import Notice from '../../components/Notice.vue';
import PairRow from '../../components/PairRow.vue';
import ResendCode from '../../components/ResendCode.vue';
import { browserTimezone } from '../../components/timezone';
import { t } from '../../i18n';
import { isEmbedded } from '../../components/embed';
import PlatformLayout from '../../layouts/PlatformLayout.vue';
import type { SaasTheme } from '../../types-saas';

/**
 * TB 12 Scheibe 1 (M-21, G-11, Mockup 22): Selbstregistrierung — Konto (Mail-Code, kein Passwort) → Site mit Alias,
 * Sprachen und Zeitzone → Theme → Fertig. Der Server führt den Schritt (Sitzung); Site und Theme sind ein Formular in
 * zwei Ansichten, damit «‹ Zurück» nichts verliert. Am Ende stehen Adresse, Dauer und Testphase — nicht im Kleingedruckten.
 */
defineOptions({ layout: PlatformLayout });

const embedded = isEmbedded();

const props = defineProps<{
    step: 'account' | 'code' | 'site' | 'done';
    account: { name: string; company: string; email: string };
    spam: {
        honeypot: string;
        field: string;
        token: string;
        pow?: { url: string; area: string; field: string } | null;
    };
    legal: { agb: string; datenschutz: string };
    site: { name: string; alias: string; locale: string; timezone: string };
    locales: Record<string, string>;
    timezones: string[];
    themes: (SaasTheme & { preview_url: string | null })[];
    default_theme: string | null;
    alias_domain: string;
    trial_days: number;
    start_price: number;
    done: {
        site_name: string | null;
        site_url: string | null;
        live: boolean;
        backend_url: string;
        publish_url: string;
        trial_ends: string | null;
        seconds: number | null;
        domains_url: string;
    } | null;
    urls: {
        start: string;
        verify: string;
        resend: string;
        reset: string;
        alias: string;
        complete: string;
        login: string;
    };
    status: string | null;
    resend: { wait: number; seconds: number } | null;
}>();

const page = usePage<{ errors: Record<string, string> }>();

/* ── Schritt 1 · Konto ── */
const accountForm = useForm({
    name: props.account.name,
    company: props.account.company,
    email: props.account.email,
    terms: false,
    honeypot: '',
});

/* TB 50: die Aufgabe des Spamschutzes — gestartet, sobald jemand das Formular berührt, gelöst vor dem Absenden */
let powJob: Promise<string> | null = null;
const powState = ref<'bereit' | 'laeuft' | 'fertig' | 'fehler'>('bereit');
function startPow(): Promise<string> | null {
    const pow = props.spam.pow;
    if (!pow) {
        return null;
    }
    if (!powJob) {
        powState.value = 'laeuft';
        powJob = solvePow(pow.url, pow.area)
            .then((value) => {
                powState.value = 'fertig';

                return value;
            })
            .catch((error: unknown) => {
                powState.value = 'fehler';
                powJob = null;
                throw error;
            });
    }

    return powJob;
}

/** Honigtopf, Zeit-Token und Aufgabe unter den Namen, die der Server erwartet (Forms\SpamGuard). */
async function submitAccount(): Promise<void> {
    let pow = '';
    try {
        pow = (await startPow()) ?? '';
    } catch {
        return;
    }
    accountForm
        .transform((data) => ({
            name: data.name,
            company: data.company,
            email: data.email,
            terms: data.terms,
            [props.spam.honeypot]: data.honeypot,
            [props.spam.field]: props.spam.token,
            ...(props.spam.pow ? { [props.spam.pow.field]: pow } : {}),
        }))
        .post(props.urls.start);
}

/* ── Schritt 1b · Code ── */
const codeForm = useForm({ code: '' });

/** TB 72: sind alle sechs Ziffern da, geht der Code von selbst los — ohne «Bestätigen». */
function submitCode(): void {
    if (codeForm.processing || codeForm.code.length !== 6) {
        return;
    }
    codeForm.post(props.urls.verify, { onError: () => codeForm.reset('code') });
}

/* ── Schritt 2/3 · Site und Theme ── */
const zones = computed(() => props.timezones);
const detected = browserTimezone() ?? '';
const siteForm = useForm({
    name: props.site.name,
    alias: props.site.alias,
    default_locale: props.site.locale,
    extra_locales: [] as string[],
    timezone: zones.value.includes(detected) ? detected : props.site.timezone,
    theme: props.default_theme ?? '',
});
const view = ref<'site' | 'theme'>('site');
const aliasTouched = ref(props.site.alias !== '');

function slug(value: string): string {
    return value
        .toLowerCase()
        .replace(/ä/g, 'ae')
        .replace(/ö/g, 'oe')
        .replace(/ü/g, 'ue')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 40);
}

watch(
    () => siteForm.name,
    (name) => {
        if (!aliasTouched.value) {
            siteForm.alias = slug(name);
        }
    },
);
watch(
    () => siteForm.alias,
    (alias) => {
        if (alias !== slug(siteForm.name)) {
            aliasTouched.value = true;
        }
    },
);

// Dieselbe Seite über alle Schritte: Inertia behält die Komponente — beim Wechsel in den Schritt «Site» die Vorschläge
// des Servers übernehmen (Name aus der Firma, freier Alias, Theme), beim Wechsel zum Code das Feld leeren
watch(
    () => props.step,
    (step) => {
        if (step === 'site') {
            siteForm.name = props.site.name;
            siteForm.alias = props.site.alias;
            siteForm.default_locale = props.site.locale;
            siteForm.theme = props.default_theme ?? '';
            aliasTouched.value = props.site.alias !== '';
            view.value = 'site';
        }
        if (step === 'code') {
            codeForm.reset();
        }
    },
);

const localeOptions = computed(() =>
    Object.entries(props.locales).map(([value, label]) => ({ value, label })),
);
const otherLocales = computed(() =>
    localeOptions.value.filter((o) => o.value !== siteForm.default_locale),
);

function toggleLocale(code: string, on: boolean): void {
    siteForm.extra_locales = on
        ? [...siteForm.extra_locales, code]
        : siteForm.extra_locales.filter((c) => c !== code);
}

const siteErrorKeys = ['name', 'alias', 'locales', 'timezone'];
function siteHasErrors(): boolean {
    return Object.keys(siteForm.errors).some((k) =>
        siteErrorKeys.some((p) => k === p || k.startsWith(`${p}.`)),
    );
}

function next(): void {
    siteForm.clearErrors();
    if (siteForm.name.trim() === '') {
        siteForm.setError('name', t('Bitte geben Sie der Site einen Namen.'));
    }
    if (!/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/.test(siteForm.alias)) {
        siteForm.setError(
            'alias',
            t(
                '3–40 Zeichen: Kleinbuchstaben a–z, Ziffern und Bindestrich, nicht am Anfang oder Ende.',
            ),
        );
    }
    if (!siteHasErrors()) {
        view.value = 'theme';
    }
}

function createSite(): void {
    siteForm
        .transform((data) => ({
            name: data.name,
            alias: data.alias,
            locales: [
                data.default_locale,
                ...data.extra_locales.filter((c) => c !== data.default_locale),
            ],
            timezone: data.timezone,
            theme: data.theme,
        }))
        .post(props.urls.complete, {
            onError: () => {
                if (siteHasErrors()) {
                    view.value = 'site';
                }
            },
        });
}

/* ── Schritte oben ── */
const steps = computed(() => [t('Konto'), t('Site'), t('Theme'), t('Fertig')]);
const stepIndex = computed(() => {
    if (props.step === 'done') {
        return 3;
    }
    if (props.step === 'site') {
        return view.value === 'theme' ? 2 : 1;
    }

    return 0;
});

const duration = computed(() => {
    const seconds = props.done?.seconds ?? null;
    if (seconds === null) {
        return null;
    }

    return seconds < 120
        ? t('angelegt in :seconds Sekunden', { seconds })
        : t('angelegt in :minutes Minuten', {
              minutes: Math.round(seconds / 60),
          });
});

const codeError = computed(() => page.props.errors.code ?? '');
/** Fehler des Servers für Felder, die es im Formular nur umgeformt gibt (`locales`). */
const siteErrors = computed(() => siteForm.errors as Record<string, string>);

/** Zustimmung als ein Satz (übersetzbar), die beiden Texte darin als Links. */
const termsParts = computed(() => {
    const mark = '\u0000';
    const sentence = t('Ich stimme den :agb und der :privacy zu.', {
        agb: `${mark}agb${mark}`,
        privacy: `${mark}privacy${mark}`,
    });

    return sentence.split(mark).map((piece, i) => {
        if (piece === 'agb') {
            return { key: i, text: t('AGB'), href: props.legal.agb };
        }
        if (piece === 'privacy') {
            return {
                key: i,
                text: t('Datenschutzerklärung'),
                href: props.legal.datenschutz,
            };
        }

        return { key: i, text: piece, href: null };
    });
});
</script>

<template>
    <Head :title="$t('Registrieren')" />
    <ol
        class="mx-auto mb-5 flex max-w-[560px] flex-wrap justify-center gap-x-4 gap-y-1 text-[12.5px]"
        :aria-label="$t('Schritte')"
    >
        <li
            v-for="(label, i) in steps"
            :key="label"
            :aria-current="i === stepIndex ? 'step' : undefined"
            :class="
                i === stepIndex
                    ? 'font-semibold text-basalt'
                    : i < stepIndex
                      ? 'text-success'
                      : 'text-ash-dark'
            "
        >
            {{ i < stepIndex ? '✓' : `${i + 1}.` }} {{ label }}
        </li>
    </ol>

    <Notice
        v-if="status && step !== 'done'"
        tone="success"
        class="mx-auto mb-4 max-w-[560px]"
        >{{ status }}</Notice
    >

    <!-- 1 · Konto -->
    <section
        v-if="step === 'account'"
        class="mx-auto max-w-[560px] rounded-lg border border-ash-light bg-white px-5 py-[18px]"
        aria-labelledby="schritt-konto"
    >
        <h1 id="schritt-konto" class="text-[15px] font-semibold">
            {{ $t('Konto') }}
        </h1>
        <p class="mt-1 mb-2 text-[12.5px] text-basalt-light">
            {{
                $t(
                    'Kein Passwort — Sie erhalten einen Code per Mail. :days Tage testen, nichts läuft automatisch an.',
                    { days: trial_days },
                )
            }}
        </p>
        <form
            novalidate
            @submit.prevent="submitAccount"
            @focusin.once="startPow()"
        >
            <div class="grid gap-x-4 gap-y-2.5 md:grid-cols-2">
                <Field
                    class="mt-0!"
                    v-model="accountForm.name"
                    :label="$t('Ihr Name')"
                    autocomplete="name"
                    required
                    :error="accountForm.errors.name"
                />
                <Field
                    class="mt-0!"
                    v-model="accountForm.company"
                    :label="$t('Firma')"
                    autocomplete="organization"
                    optional
                    :error="accountForm.errors.company"
                />
            </div>
            <Field
                v-model="accountForm.email"
                :label="$t('E-Mail')"
                type="email"
                autocomplete="email"
                required
                :error="accountForm.errors.email"
            />
            <!-- Honigtopf: für Menschen unsichtbar, Maschinen füllen ihn aus -->
            <div class="sr-only" aria-hidden="true">
                <label :for="`hp-${spam.honeypot}`">Website</label>
                <input
                    :id="`hp-${spam.honeypot}`"
                    v-model="accountForm.honeypot"
                    type="text"
                    tabindex="-1"
                    autocomplete="off"
                />
            </div>
            <div class="mt-3 flex items-start gap-2 text-[12.5px]">
                <input
                    id="terms"
                    v-model="accountForm.terms"
                    type="checkbox"
                    class="mt-0.5 h-4 w-4 accent-lava-dark"
                    :aria-invalid="
                        accountForm.errors.terms ? 'true' : undefined
                    "
                    :aria-describedby="
                        accountForm.errors.terms ? 'terms-error' : undefined
                    "
                />
                <label for="terms">
                    <template v-for="part in termsParts" :key="part.key">
                        <a
                            v-if="part.href"
                            :href="part.href"
                            target="_blank"
                            rel="noopener"
                            class="text-lava-dark underline"
                            >{{ part.text }}</a
                        >
                        <template v-else>{{ part.text }}</template>
                    </template>
                </label>
            </div>
            <p
                v-if="accountForm.errors.terms"
                id="terms-error"
                class="text-danger mt-1 text-[11.5px] font-semibold"
                role="alert"
            >
                {{ accountForm.errors.terms }}
            </p>
            <p
                v-if="spam.pow"
                class="mt-3 text-[11.5px] text-basalt-light"
                :class="{ 'text-danger font-semibold': powState === 'fehler' }"
                role="status"
            >
                {{
                    powState === 'fertig'
                        ? $t('Spamschutz: geprüft — ohne Rätsel')
                        : powState === 'laeuft'
                          ? $t('Spamschutz prüft im Hintergrund …')
                          : powState === 'fehler'
                            ? $t(
                                  'Spamschutz: Prüfung fehlgeschlagen — bitte Seite neu laden',
                              )
                            : $t(
                                  'Spamschutz: läuft beim Ausfüllen, ohne Rätsel',
                              )
                }}
            </p>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <Button type="submit" :disabled="accountForm.processing">{{
                    $t('Code senden')
                }}</Button>
                <a
                    v-if="embedded"
                    :href="urls.login"
                    target="_top"
                    class="text-[12.5px] text-lava-dark underline hover:text-basalt"
                    >{{ $t('Schon ein Konto? Anmelden') }}</a
                >
                <Link
                    v-else
                    :href="urls.login"
                    class="text-[12.5px] text-lava-dark underline hover:text-basalt"
                    >{{ $t('Schon ein Konto? Anmelden') }}</Link
                >
            </div>
        </form>
    </section>

    <!-- 1b · Code -->
    <section
        v-else-if="step === 'code'"
        class="mx-auto max-w-[420px] rounded-lg border border-ash-light bg-white px-5 py-[18px]"
        aria-labelledby="schritt-code"
    >
        <h1 id="schritt-code" class="text-[15px] font-semibold">
            {{ $t('Code eingeben') }}
        </h1>
        <p class="mt-1 text-[12.5px] text-basalt-light">
            {{
                $t(
                    'Wir haben einen sechsstelligen Code an :email geschickt. Der Link in der Mail geht auch.',
                    { email: account.email },
                )
            }}
        </p>
        <form novalidate class="mt-4" @submit.prevent="submitCode">
            <p
                id="code-label"
                class="mb-2 text-center text-[12.5px] font-medium text-basalt"
            >
                {{ $t('Sechsstelliger Code') }}
            </p>
            <CodeInput
                id="code"
                v-model="codeForm.code"
                :invalid="!!(codeForm.errors.code || codeError)"
                :disabled="codeForm.processing"
                describedby="code-label code-help"
                @complete="submitCode"
            />
            <p
                v-if="codeForm.errors.code || codeError"
                class="text-danger mt-2 text-center text-xs"
                role="alert"
            >
                {{ codeForm.errors.code || codeError }}
            </p>
            <p
                id="code-help"
                class="mt-2 text-center text-[11.5px] text-basalt-light"
            >
                {{
                    codeForm.processing
                        ? $t('Wird geprüft …')
                        : $t('Sobald alle Ziffern stehen, geht es weiter.')
                }}
            </p>
        </form>
        <div
            class="mt-4 flex flex-wrap justify-center gap-x-4 gap-y-1 text-[12px]"
        >
            <ResendCode
                v-if="resend"
                :url="urls.resend"
                :wait="resend.wait"
                :seconds="resend.seconds"
                class="text-lava-dark hover:text-basalt"
            />
            <button
                type="button"
                class="text-lava-dark underline hover:text-basalt"
                @click="router.post(urls.reset)"
            >
                {{ $t('Andere Adresse') }}
            </button>
        </div>
        <p
            v-if="page.props.errors.resend"
            class="text-danger mt-1 text-center text-[12px]"
            role="alert"
        >
            {{ page.props.errors.resend }}
        </p>
    </section>

    <!-- 2 · Site -->
    <section
        v-else-if="step === 'site' && view === 'site'"
        class="mx-auto max-w-[560px] rounded-lg border border-ash-light bg-white px-5 py-[18px]"
        aria-labelledby="schritt-site"
    >
        <h1 id="schritt-site" class="text-[15px] font-semibold">
            {{ $t('Ihre erste Site') }}
        </h1>
        <form novalidate class="mt-2" @submit.prevent="next">
            <Field
                v-model="siteForm.name"
                :label="$t('Site-Name')"
                required
                autocomplete="organization"
                :error="siteForm.errors.name"
            />
            <AliasField
                v-model="siteForm.alias"
                :check-url="urls.alias"
                :domain="alias_domain"
                :site-name="siteForm.name"
                :error="siteForm.errors.alias"
            />
            <div class="mt-2.5 grid gap-x-4 gap-y-2.5 md:grid-cols-2">
                <Field
                    class="mt-0!"
                    v-model="siteForm.default_locale"
                    :label="$t('Sprache')"
                    type="select"
                    :options="localeOptions"
                    :error="siteErrors.locales"
                />
                <Field
                    class="mt-0!"
                    v-model="siteForm.timezone"
                    :label="$t('Zeitzone')"
                    type="select"
                    :options="zones"
                    :error="siteForm.errors.timezone"
                    :help="
                        siteForm.timezone === detected
                            ? $t('im Browser erkannt')
                            : ''
                    "
                />
            </div>
            <fieldset class="mt-2.5">
                <legend
                    class="mb-1 text-[9.5px] font-semibold tracking-[.1em] text-basalt-light uppercase"
                >
                    {{ $t('Weitere Sprachen') }}
                    <span
                        class="text-ash-dark ml-1 font-normal tracking-normal normal-case"
                        >{{ $t('freiwillig') }}</span
                    >
                </legend>
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-[12.5px]">
                    <label
                        v-for="o in otherLocales"
                        :key="o.value"
                        class="flex items-center gap-1.5"
                    >
                        <input
                            type="checkbox"
                            class="h-4 w-4 accent-lava-dark"
                            :checked="siteForm.extra_locales.includes(o.value)"
                            @change="
                                toggleLocale(
                                    o.value,
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />
                        {{ o.label }}
                    </label>
                </div>
            </fieldset>
            <div class="mt-4 flex justify-end">
                <Button type="submit">{{ $t('Weiter') }}</Button>
            </div>
        </form>
    </section>

    <!-- 3 · Theme -->
    <section
        v-else-if="step === 'site' && view === 'theme'"
        aria-labelledby="schritt-theme"
    >
        <h1 id="schritt-theme" class="mb-2.5 text-[15px] font-semibold">
            {{ $t('Theme wählen — später jederzeit wechselbar') }}
        </h1>
        <div
            role="radiogroup"
            :aria-label="$t('Theme')"
            class="grid gap-3 sm:grid-cols-2 md:grid-cols-3"
        >
            <label
                v-for="theme in themes"
                :key="theme.name"
                class="flex cursor-pointer flex-col rounded-lg border bg-white p-3"
                :class="
                    siteForm.theme === theme.name
                        ? 'border-lava-dark ring-2 ring-lava/25'
                        : 'border-ash-light hover:border-ash'
                "
            >
                <input
                    v-model="siteForm.theme"
                    type="radio"
                    name="theme"
                    :value="theme.name"
                    class="sr-only"
                />
                <img
                    v-if="theme.preview_url"
                    :src="theme.preview_url"
                    alt=""
                    class="h-[110px] w-full rounded border border-ash-light bg-paper object-cover"
                />
                <span
                    v-else
                    class="flex h-[110px] items-center justify-center rounded border border-ash-light bg-paper"
                    aria-hidden="true"
                    ><Hex icon="theme" tone="ash" size="lg"
                /></span>
                <span class="mt-2 text-[13.5px] font-semibold"
                    >{{ theme.label
                    }}<span
                        v-if="siteForm.theme === theme.name"
                        class="font-normal text-lava-dark"
                    >
                        · {{ $t('gewählt') }}</span
                    ></span
                >
                <span
                    v-if="theme.description"
                    class="mt-0.5 text-[12px] text-basalt-light"
                    >{{ theme.description }}</span
                >
            </label>
        </div>
        <p
            v-if="siteForm.errors.theme"
            class="text-danger mt-2 text-[11.5px] font-semibold"
            role="alert"
        >
            {{ siteForm.errors.theme }}
        </p>
        <div class="mt-4 flex flex-wrap justify-between gap-2">
            <Button variant="secondary" @click="view = 'site'">{{
                $t('‹ Zurück')
            }}</Button>
            <Button
                :disabled="siteForm.processing || siteForm.theme === ''"
                @click="createSite"
                >{{
                    siteForm.processing
                        ? $t('Site wird angelegt …')
                        : $t('Site anlegen')
                }}</Button
            >
        </div>
    </section>

    <!-- 4 · Fertig -->
    <section
        v-else-if="step === 'done' && done"
        class="mx-auto max-w-[560px]"
        aria-labelledby="schritt-fertig"
    >
        <Card :status="done.live ? 'success' : 'warning'">
            <h1 id="schritt-fertig" class="text-[15px] font-semibold">
                {{
                    done.live
                        ? $t('Ihre Site ist live.')
                        : $t('Ihre Site ist angelegt.')
                }}
            </h1>
            <p class="my-2 text-[14px]">
                <a
                    v-if="done.site_url"
                    :href="done.site_url"
                    class="font-mono break-all text-basalt underline"
                    target="_blank"
                    rel="noopener"
                    >{{ done.site_url }}</a
                >
                <span v-if="duration" class="text-basalt-light">
                    — {{ duration }}</span
                >
            </p>
            <Notice v-if="!done.live" tone="warning" class="mb-2">
                {{
                    $t(
                        'Noch nicht live — die Prüfung hat etwas gefunden. Unter Einrichtung › Veröffentlichen sehen Sie, was fehlt.',
                    )
                }}
            </Notice>
            <dl>
                <PairRow :label="$t('Testphase')">{{
                    $t(':days Tage · bis :date', {
                        days: trial_days,
                        date: done.trial_ends ?? '—',
                    })
                }}</PairRow>
                <PairRow :label="$t('Danach')">{{
                    $t(
                        'Start CHF :price/Monat — Sie entscheiden am Ende der Testphase, nichts läuft automatisch an.',
                        { price: start_price },
                    )
                }}</PairRow>
                <PairRow :label="$t('Eigene Domain')">{{
                    $t('unter Konto › Domains, ab Plan Standard')
                }}</PairRow>
            </dl>
            <div class="mt-3.5 flex flex-wrap gap-2">
                <!-- Ins Backend der neuen Site immer im ganzen Fenster — auch aus dem Modal der Website -->
                <a
                    :href="done.live ? done.backend_url : done.publish_url"
                    target="_top"
                    class="inline-flex min-h-8 items-center rounded-md border border-transparent bg-lava-dark px-3 text-[12.5px] font-semibold text-white hover:brightness-90"
                    >{{ $t('Zum Backend') }}</a
                >
                <a
                    v-if="done.site_url"
                    :href="done.site_url"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex min-h-8 items-center rounded-md border border-ash bg-white px-3 text-[12.5px] font-semibold text-basalt hover:bg-paper"
                    >{{ $t('Site ansehen') }}</a
                >
            </div>
            <p class="mt-2.5 text-[11.5px] text-basalt-light">
                {{
                    $t(
                        'Sie sind angemeldet. Im Backend zeigt die Einrichtung, was das Theme schon erledigt hat und was Ihre ersten Schritte sind.',
                    )
                }}
            </p>
        </Card>
    </section>
</template>
