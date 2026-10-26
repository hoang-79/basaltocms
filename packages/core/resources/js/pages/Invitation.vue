<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from '../components/Button.vue';
import Field from '../components/Field.vue';
import Notice from '../components/Notice.vue';
import GuestLayout from '../layouts/GuestLayout.vue';
import type { InvitationPageProps } from '../types-admin';

defineOptions({ layout: GuestLayout });

/**
 * Einladung annehmen (Bereich 8/18): wie die Anmeldung — Marke, Satz mit Site und Rolle, Feld «Ihr Name».
 * Ist der Link abgelaufen, ungültig oder schon benutzt, steht nur der Satz da und der Weg zur Anmeldung.
 */
const props = defineProps<InvitationPageProps>();

const form = useForm({ first_name: '', last_name: '' });

function accept(): void {
    if (props.accept_url) {
        form.post(props.accept_url);
    }
}
</script>

<template>
    <Head :title="$t('Einladung')" />
    <h1 class="mb-2.5 text-sm font-semibold">{{ $t('Einladung') }}</h1>
    <template v-if="error || !invitation || !accept_url">
        <Notice tone="warning">{{
            error ?? $t('Diese Einladung ist ungültig.')
        }}</Notice>
        <p class="mt-3 text-[12.5px] text-basalt-light">
            {{ $t('Haben Sie schon ein Konto?') }}
            <Link
                :href="login_url"
                class="text-lava-dark underline hover:text-basalt"
                >{{ $t('Zur Anmeldung') }}</Link
            >
        </p>
    </template>
    <template v-else>
        <p class="text-[13px] leading-relaxed">
            {{
                invitation.invited_by
                    ? $t(':person lädt Sie zu :site ein — Rolle :role.', {
                          person: invitation.invited_by,
                          site: invitation.site_name,
                          role: invitation.role_label,
                      })
                    : $t('Sie sind zu :site eingeladen — Rolle :role.', {
                          site: invitation.site_name,
                          role: invitation.role_label,
                      })
            }}
        </p>
        <p class="mt-1 text-[11.5px] text-basalt-light">
            {{ $t('Konto für') }}
            <span class="font-mono">{{ invitation.email }}</span
            ><template v-if="invitation.expires_at">
                ·
                {{
                    $t('gültig bis :date', { date: invitation.expires_at })
                }}</template
            >
        </p>
        <form class="mt-3" novalidate @submit.prevent="accept">
            <div class="grid gap-x-3 sm:grid-cols-2">
                <Field
                    v-model="form.first_name"
                    :label="$t('Vorname')"
                    autocomplete="given-name"
                    required
                    :error="form.errors.first_name"
                />
                <Field
                    v-model="form.last_name"
                    :label="$t('Nachname')"
                    autocomplete="family-name"
                    required
                    :error="form.errors.last_name"
                    class="sm:mt-0!"
                />
            </div>
            <p class="mt-1 text-[11.5px] text-basalt-light">
                {{ $t('So erscheinen Sie im Backend und im Protokoll.') }}
            </p>
            <div class="mt-3">
                <Button type="submit" wide :disabled="form.processing">{{
                    $t('Einladung annehmen')
                }}</Button>
            </div>
        </form>
        <p class="mt-3 text-[11.5px] text-basalt-light">
            {{
                $t(
                    'Kein Passwort: Danach richten Sie einen Passkey ein oder melden sich per Mail-Code an. Der Link ist nur einmal verwendbar.',
                )
            }}
        </p>
    </template>
</template>
