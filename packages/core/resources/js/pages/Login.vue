<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { usePasskeyVerify } from '@laravel/passkeys/vue';
import Button from '../components/Button.vue';
import Field from '../components/Field.vue';
import Notice from '../components/Notice.vue';
import GuestLayout from '../layouts/GuestLayout.vue';

defineOptions({ layout: GuestLayout });

/**
 * Anmelden (Bereich 8): Passkey-Knopf nur, wenn der Browser es kann; sonst Mail-Code. Kein Passwort.
 * TB 13 Scheibe 4 (M-25): darüber «Mit Microsoft/Google anmelden», wenn die Site SSO eingerichtet hat.
 */
const props = defineProps<{
    codeUrl: string;
    passkey: { options: string; login: string };
    status: string | null;
    /** TB 11 Scheibe 4 (G-10): Schnellzugang der Demo — nur mit BASALTO_DEMO=true, sonst null */
    demo?: { role: string; label: string; hint: string; url: string }[] | null;
    /** TB 13 Scheibe 4 (M-25): SSO der Site — nur eingerichtete Anbieter, sonst leer */
    sso?: { provider: 'entra' | 'google'; label: string; url: string }[];
}>();

/* Fehler der SSO-Rückkehr kommen als Fehler «sso» zurück auf diese Seite */
const page = usePage<{ errors: Record<string, string> }>();
const ssoError = computed(() => page.props.errors?.sso ?? null);

/* CR-004 (P-07): Ziel aus der Funktionsschau der Demo («/admin/login?ziel=/admin/pages») — der Server prüft es */
const demoTarget = computed(
    () => new URLSearchParams(page.url.split('?')[1] ?? '').get('ziel') ?? null,
);

const form = useForm({ email: '' });

const { verify, isLoading, error, isSupported } = usePasskeyVerify({
    routes: { options: props.passkey.options, submit: props.passkey.login },
    onSuccess: (response) => {
        window.location.href = response.redirect ?? '/admin';
    },
});
</script>

<template>
    <Head :title="$t('Anmelden')" />
    <h1 class="mb-2.5 text-sm font-semibold">{{ $t('Anmelden') }}</h1>
    <Notice v-if="status" tone="success" class="mb-3">{{ status }}</Notice>
    <section v-if="demo" class="mb-4" aria-labelledby="demo-zugang">
        <h2 id="demo-zugang" class="mb-1 text-[13px] font-semibold">
            {{ $t('Schnellzugang zur Demo') }}
        </h2>
        <p class="mb-2.5 text-[11.5px] text-basalt-light">
            {{ $t('Ohne Passwort und ohne Code — nur in der Demo.') }}
        </p>
        <ul class="m-0 flex list-none flex-col gap-2 p-0">
            <li v-for="access in demo" :key="access.role">
                <Button
                    wide
                    :variant="
                        access.role === 'betreiber' ? 'primary' : 'secondary'
                    "
                    @click="
                        router.post(
                            access.url,
                            demoTarget ? { ziel: demoTarget } : {},
                        )
                    "
                    >{{ access.label }}</Button
                >
                <p class="text-ash-dark mt-1 mb-0 text-[11px]">
                    {{ access.hint }}
                </p>
            </li>
        </ul>
    </section>
    <Notice v-if="ssoError" tone="danger" class="mb-3">{{ ssoError }}</Notice>
    <ul
        v-if="sso && sso.length > 0"
        class="m-0 mb-2 flex list-none flex-col gap-2 p-0"
        :aria-label="$t('Anmelden mit dem Konto Ihrer Firma')"
    >
        <li v-for="button in sso" :key="button.provider">
            <!-- Gewöhnlicher Link: die Weiterleitung zum Anbieter verlässt Inertia -->
            <a
                :href="button.url"
                class="inline-flex w-full items-center justify-center gap-2 rounded-md border border-ash bg-white px-[13px] py-[11px] text-[13px] leading-tight font-semibold text-basalt hover:bg-paper"
            >
                <svg
                    v-if="button.provider === 'entra'"
                    width="14"
                    height="14"
                    viewBox="0 0 14 14"
                    aria-hidden="true"
                >
                    <rect width="6.5" height="6.5" fill="#F25022" />
                    <rect x="7.5" width="6.5" height="6.5" fill="#7FBA00" />
                    <rect y="7.5" width="6.5" height="6.5" fill="#00A4EF" />
                    <rect
                        x="7.5"
                        y="7.5"
                        width="6.5"
                        height="6.5"
                        fill="#FFB900"
                    />
                </svg>
                <svg
                    v-else
                    width="14"
                    height="14"
                    viewBox="0 0 48 48"
                    aria-hidden="true"
                >
                    <path
                        fill="#EA4335"
                        d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"
                    />
                    <path
                        fill="#4285F4"
                        d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"
                    />
                    <path
                        fill="#FBBC05"
                        d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"
                    />
                    <path
                        fill="#34A853"
                        d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"
                    />
                </svg>
                {{ button.label }}
            </a>
        </li>
    </ul>
    <Button
        v-if="isSupported"
        variant="secondary"
        wide
        :disabled="isLoading"
        @click="verify"
    >
        {{
            isLoading
                ? $t('Passkey wird geprüft …')
                : $t('Mit Passkey anmelden')
        }}
    </Button>
    <p v-if="error" class="text-danger mt-1 text-[11.5px] font-semibold">
        {{ error }}
    </p>
    <p
        v-if="isSupported || (sso && sso.length > 0)"
        class="text-ash-dark mt-3 mb-0.5 text-center text-[10px] tracking-[.1em] uppercase"
    >
        {{ $t('oder per Mail-Code') }}
    </p>
    <form novalidate @submit.prevent="form.post(codeUrl)">
        <Field
            v-model="form.email"
            :label="$t('E-Mail')"
            type="email"
            autocomplete="email"
            required
            :error="form.errors.email"
        />
        <div class="mt-3">
            <Button type="submit" wide :disabled="form.processing">{{
                $t('Code senden')
            }}</Button>
        </div>
    </form>
    <p class="mt-3 text-[11.5px] text-basalt-light">
        {{
            $t(
                'Kein Passwort: Sie erhalten einen sechsstelligen Code per Mail, zehn Minuten gültig. Ohne Mail-Server erzeugt :command einen Einmal-Link.',
                { command: 'php artisan basalto:login-link' },
            )
        }}
    </p>
</template>
