<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '../components/Button.vue';
import { t } from '../i18n';
import Field from '../components/Field.vue';
import GuestLayout from '../layouts/GuestLayout.vue';

/**
 * Installer im Browser (K-05, Mockup 19): Datenbank → Site → Konto → Fertig — dieselben Fragen wie `basalto:install`.
 * Am Ende der Einmal-Link zur ersten Anmeldung, ohne Passwort und ohne Mail-Server.
 */
defineOptions({ layout: GuestLayout });

const props = defineProps<{
    database: {
        ok: boolean;
        driver: string;
        database: string;
        version: string | null;
        pending: number;
        error: string | null;
    } | null;
    defaults: { locale: string; timezone: string } | null;
    locales: Record<string, string>;
    timezones: string[];
    result: { url: string; site: string; email: string } | null;
    urls: { migrate: string; store: string } | null;
    license_url?: string | null;
}>();

const page = usePage<{ errors: Record<string, string> }>();
const migrating = ref(false);

const steps = computed(() => [
    t('Datenbank'),
    t('Site'),
    t('Konto'),
    t('Fertig'),
]);
const step = computed(() => {
    if (props.result) {
        return 3;
    }
    if (!props.database?.ok || (props.database?.pending ?? 0) > 0) {
        return 0;
    }

    return 1;
});

const form = useForm({
    handle: '',
    name: '',
    locale: props.defaults?.locale ?? 'de',
    timezone: props.defaults?.timezone ?? 'Europe/Zurich',
    user: '',
    email: '',
});

function slug(value: string): string {
    return value
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 40);
}

const handleTouched = ref(false);
function onName(value: string): void {
    form.name = value;
    if (!handleTouched.value) {
        form.handle = slug(value);
    }
}

function migrate(): void {
    if (!props.urls) {
        return;
    }
    migrating.value = true;
    router.post(
        props.urls.migrate,
        {},
        { preserveScroll: true, onFinish: () => (migrating.value = false) },
    );
}

function submit(): void {
    if (props.urls) {
        form.post(props.urls.store);
    }
}

const copied = ref(false);
async function copy(): Promise<void> {
    if (props.result) {
        await navigator.clipboard.writeText(props.result.url);
        copied.value = true;
    }
}
</script>

<template>
    <Head :title="$t('Installation')" />
    <div class="grid gap-4">
        <ol
            class="flex flex-wrap gap-x-3 gap-y-1 text-[12px]"
            :aria-label="$t('Schritte')"
        >
            <li
                v-for="(label, i) in steps"
                :key="label"
                :aria-current="i === step ? 'step' : undefined"
                :class="
                    i === step
                        ? 'font-semibold text-basalt'
                        : i < step
                          ? 'text-success'
                          : 'text-ash-dark'
                "
            >
                {{ i < step ? '✓' : `${i + 1}.` }} {{ label }}
            </li>
        </ol>

        <!-- 1 · Datenbank -->
        <section v-if="step === 0 && database" aria-labelledby="schritt-db">
            <h1 id="schritt-db" class="mb-2 text-[15px] font-semibold">
                {{ $t('Installation · Datenbank') }}
            </h1>
            <p v-if="!database.ok" class="text-danger text-[13px]" role="alert">
                {{ database.error }}
            </p>
            <template v-else>
                <p class="text-[13px]">
                    {{ $t('Verbunden:') }} {{ database.driver }}
                    <span v-if="database.version">{{ database.version }}</span>
                    · <code class="font-mono">{{ database.database }}</code>
                </p>
                <p class="text-ash-dark mt-1 text-[13px]">
                    {{
                        $tc(
                            ':count Migration offen — die Tabellen werden jetzt angelegt.|:count Migrationen offen — die Tabellen werden jetzt angelegt.',
                            database.pending,
                        )
                    }}
                </p>
                <Button
                    class="mt-3"
                    wide
                    :disabled="migrating"
                    @click="migrate"
                    >{{
                        migrating
                            ? $t('Einrichten …')
                            : $t('Datenbank einrichten')
                    }}</Button
                >
            </template>
        </section>

        <!-- 2 · Site und 3 · Konto -->
        <form
            v-else-if="step === 1"
            class="grid gap-3"
            aria-labelledby="schritt-site"
            @submit.prevent="submit"
        >
            <h1 id="schritt-site" class="text-[15px] font-semibold">
                {{ $t('Installation · Site und Konto') }}
            </h1>
            <p
                v-if="page.props.errors.database"
                class="text-danger text-[13px]"
                role="alert"
            >
                {{ page.props.errors.database }}
            </p>
            <Field
                :label="$t('Name der Site')"
                :model-value="form.name"
                :error="form.errors.name"
                required
                autocomplete="organization"
                @update:model-value="onName"
            />
            <Field
                v-model="form.handle"
                :label="$t('Site (Handle)')"
                mono
                required
                :error="form.errors.handle"
                :help="
                    $t(
                        'Kleinbuchstaben, Ziffern, Bindestriche — erscheint in Adressen und Befehlen.',
                    )
                "
                @update:model-value="handleTouched = true"
            />
            <div class="grid gap-3 sm:grid-cols-2">
                <Field
                    v-model="form.locale"
                    :label="$t('Standardsprache')"
                    type="select"
                    :options="
                        Object.entries(locales).map(([value, label]) => ({
                            value,
                            label,
                        }))
                    "
                    :error="form.errors.locale"
                />
                <Field
                    v-model="form.timezone"
                    :label="$t('Zeitzone')"
                    type="select"
                    :options="timezones"
                    :error="form.errors.timezone"
                />
            </div>
            <Field
                v-model="form.user"
                :label="$t('Ihr Name')"
                required
                autocomplete="name"
                :error="form.errors.user"
            />
            <Field
                v-model="form.email"
                :label="$t('Betreiber-Mail')"
                type="email"
                required
                autocomplete="email"
                :error="form.errors.email"
                :help="
                    $t(
                        'Dieses Konto wird Betreiber. Angemeldet werden Sie gleich per Einmal-Link — kein Passwort.',
                    )
                "
            />
            <p v-if="license_url" class="text-ash-dark text-[12px]">
                {{ $t('Mit der Installation gilt der Basalto-Lizenzvertrag.') }}
                <a
                    :href="license_url"
                    class="underline"
                    target="_blank"
                    rel="noopener"
                    >{{ $t('Lizenzvertrag lesen') }}</a
                >
            </p>
            <Button type="submit" wide :disabled="form.processing">{{
                $t('Installation abschliessen')
            }}</Button>
        </form>

        <!-- 4 · Fertig -->
        <section v-else-if="result" aria-labelledby="schritt-fertig">
            <h1 id="schritt-fertig" class="mb-2 text-[15px] font-semibold">
                {{
                    $t('Fertig — :site ist eingerichtet', { site: result.site })
                }}
            </h1>
            <p class="text-[13px]">
                {{ $t('Einmal-Link zur ersten Anmeldung für') }}
                <b>{{ result.email }}</b>
                {{ $t('(60 Minuten gültig, nur einmal verwendbar):') }}
            </p>
            <p
                class="mt-2 rounded-sm bg-paper p-2 font-mono text-[11.5px] break-all"
            >
                {{ result.url }}
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                <Button :href="result.url">{{ $t('Jetzt anmelden') }}</Button>
                <Button variant="secondary" @click="copy">{{
                    copied ? $t('Kopiert') : $t('Link kopieren')
                }}</Button>
            </div>
            <p class="text-ash-dark mt-3 text-[12px]">
                {{
                    $t(
                        'Danach im Backend einen Passkey einrichten. Weitere Links:',
                    )
                }}
                <code class="font-mono">php artisan basalto:login-link</code>.
                {{ $t('Der Installer ist ab jetzt geschlossen.') }}
            </p>
        </section>
    </div>
</template>
