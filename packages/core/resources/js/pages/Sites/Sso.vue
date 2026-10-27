<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useAutosave } from '../../components/autosave';
import Button from '../../components/Button.vue';
import Card from '../../components/Card.vue';
import { controlClasses } from '../../components/control';
import Field from '../../components/Field.vue';
import Notice from '../../components/Notice.vue';
import PageHeader from '../../components/PageHeader.vue';
import Toggle from '../../components/Toggle.vue';
import { t } from '../../i18n';
import type { BackendProps } from '../../types';

/**
 * TB 13 Scheibe 4 (M-25): Verwaltung › Sites › Anmeldung über SSO — je Site Microsoft Entra ID und/oder Google.
 * Das Client-Secret kommt nie zurück an den Browser: die Seite weiss nur, ob eines gespeichert ist; leer gelassen
 * bleibt es. «Verbindung testen» fragt den Anbieter ohne Benutzer und gibt einen Hinweis — die echte Probe ist eine
 * Anmeldung im privaten Fenster über die Anmeldeadresse oben.
 */
type ProviderKey = 'entra' | 'google';
type Provider = {
    key: ProviderKey;
    label: string;
    configured: boolean;
    enabled: boolean;
    tenant_id: string | null;
    client_id: string | null;
    has_secret: boolean;
    hosted_domain: string | null;
    auto_create_role: string | null;
    updated_at: string | null;
    redirect_urls: string[];
    update_url: string;
    destroy_url: string;
    check_url: string;
};

const props = defineProps<{
    site: { id: number; name: string; handle: string };
    providers: Provider[];
    roles: { value: string; label: string }[];
    login_url: string;
    back_url: string;
    check: { provider: ProviderKey; ok: boolean; message: string } | null;
}>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);

type SsoForm = {
    enabled: boolean;
    tenant_id: string;
    client_id: string;
    client_secret: string;
    hosted_domain: string;
    auto_create_role: string;
};

const forms = Object.fromEntries(
    props.providers.map((provider) => [
        provider.key,
        useForm<SsoForm>({
            enabled: provider.configured ? provider.enabled : true,
            tenant_id: provider.tenant_id ?? '',
            client_id: provider.client_id ?? '',
            client_secret: '',
            hosted_domain: provider.hosted_domain ?? '',
            auto_create_role: provider.auto_create_role ?? '',
        }),
    ]),
) as Record<ProviderKey, ReturnType<typeof useForm<SsoForm>>>;

const current = (key: ProviderKey): Provider =>
    props.providers.find((p) => p.key === key) as Provider;

/**
 * CR-013: speichert selbst — Schalter und Rolle sofort; Tenant, Client-ID, Secret und erlaubte Domain beim Verlassen
 * (Enter), weil ein halber Wert die Anmeldung sperren würde. Neu eingerichtet wird erst mit allen Pflichtangaben.
 */
const saves = Object.fromEntries(
    props.providers.map((provider) => {
        const form = forms[provider.key];
        const save = useAutosave(form, {
            url: provider.update_url,
            onLeave: [
                'tenant_id',
                'client_id',
                'client_secret',
                'hosted_domain',
            ],
            only: ['errors', 'providers'],
            labels: {
                enabled: provider.label,
                tenant_id: t('Tenant-ID (Verzeichnis-ID)'),
                client_id: t('Client-ID'),
                client_secret: t('Client-Secret'),
                hosted_domain: t('Erlaubte Domain'),
                auto_create_role: t('Rolle für neue Benutzer'),
            },
            enabled: () =>
                current(provider.key).configured ||
                (form.client_id.trim() !== '' &&
                    form.client_secret.trim() !== '' &&
                    (provider.key !== 'entra' || form.tenant_id.trim() !== '')),
            onSaved: () => {
                if (form.client_secret !== '') {
                    form.client_secret = '';
                    save.rebase();
                }
            },
        });

        return [provider.key, save];
    }),
) as Record<ProviderKey, ReturnType<typeof useAutosave<SsoForm>>>;

const copied = ref('');

function roleOptions(provider: Provider): { value: string; label: string }[] {
    return [
        { value: '', label: t('Aus — nur bestehende Benutzer') },
        ...props.roles,
    ].filter(
        (option) =>
            option.value === '' ||
            provider.key === 'entra' ||
            forms[provider.key].hosted_domain.trim() !== '',
    );
}

function remove(provider: Provider): void {
    if (
        !window.confirm(
            t(
                ':provider für diese Site entfernen? Gespeicherte Angaben und das Secret sind danach weg.',
                {
                    provider: provider.label,
                },
            ),
        )
    ) {
        return;
    }
    router.delete(provider.destroy_url, { preserveScroll: true });
}

function test(provider: Provider): void {
    router.post(provider.check_url, {}, { preserveScroll: true });
}

async function copy(url: string): Promise<void> {
    if (!navigator.clipboard) {
        return;
    }
    await navigator.clipboard.writeText(url);
    copied.value = url;
    window.setTimeout(() => {
        copied.value = copied.value === url ? '' : copied.value;
    }, 2000);
}

const secretClasses = (error: boolean): string =>
    controlClasses({ error, mono: true });
</script>

<template>
    <Head :title="$t('Anmeldung über SSO')" />
    <PageHeader
        :title="$t('Anmeldung über SSO')"
        :crumb="
            $t(':site › Verwaltung › Sites › :name', {
                site: siteName,
                name: site.name,
            })
        "
    >
        <template #lead>
            {{
                $t(
                    'Backend-Benutzer dieser Site melden sich mit dem Konto ihrer Firma an — Microsoft Entra ID oder Google. Angemeldet wird nur, wer hier schon eine Rolle hat; Passkey und Mail-Code bleiben.',
                )
            }}
        </template>
        <template #actions>
            <Link
                :href="back_url"
                class="inline-flex items-center justify-center rounded-md border border-ash bg-white px-[13px] py-2 text-[13px] leading-tight font-semibold whitespace-nowrap text-basalt hover:bg-paper"
                >{{ $t('Zurück zur Site') }}</Link
            >
        </template>
    </PageHeader>

    <Notice tone="grey" class="mb-4">
        {{ $t('Anmeldeadresse dieser Site:') }}
        <code class="font-mono break-all text-basalt">{{ login_url }}</code>
        <Button variant="link" size="sm" @click="copy(login_url)">{{
            copied === login_url ? $t('Kopiert') : $t('Kopieren')
        }}</Button>
        <span class="mt-1 block">{{
            $t(
                'Unter der Domain der Site erscheinen die Knöpfe auch ohne Zusatz. Eine Liste aller Sites zeigt die Anmeldeseite nie.',
            )
        }}</span>
    </Notice>

    <div class="grid gap-4 lg:grid-cols-2">
        <Card
            v-for="provider in providers"
            :key="provider.key"
            :title="provider.label"
            :status="
                provider.configured
                    ? provider.enabled
                        ? 'success'
                        : 'warning'
                    : null
            "
            :sub="
                provider.configured
                    ? provider.enabled
                        ? $t('eingeschaltet · geändert :date', {
                              date: provider.updated_at ?? '',
                          })
                        : $t(
                              'ausgeschaltet — die Knöpfe fehlen auf der Anmeldeseite',
                          )
                    : $t('nicht eingerichtet')
            "
        >
            <form novalidate @submit.prevent="saves[provider.key].flush()">
                <div class="mb-2.5 flex items-center gap-2.5">
                    <Toggle
                        v-model="forms[provider.key].enabled"
                        :label="
                            $t('Anmeldung über :provider', {
                                provider: provider.label,
                            })
                        "
                    />
                    <span class="text-[12.5px]">{{
                        forms[provider.key].enabled
                            ? $t('Knopf auf der Anmeldeseite')
                            : $t('Ausgeschaltet')
                    }}</span>
                </div>
                <Field
                    v-if="provider.key === 'entra'"
                    v-model="forms.entra.tenant_id"
                    :label="$t('Tenant-ID (Verzeichnis-ID)')"
                    mono
                    required
                    autocomplete="off"
                    placeholder="00000000-0000-0000-0000-000000000000"
                    :error="forms.entra.errors.tenant_id"
                    :saved="saves['entra'].saved('tenant_id')"
                    :waiting="saves['entra'].waiting('tenant_id')"
                    v-bind="saves['entra'].leave('tenant_id')"
                    :help="
                        $t(
                            'Fest ein Tenant — «common» geht nicht. Entra › Übersicht › Mandanten-ID.',
                        )
                    "
                />
                <Field
                    v-model="forms[provider.key].client_id"
                    :label="$t('Client-ID')"
                    mono
                    required
                    autocomplete="off"
                    :placeholder="
                        provider.key === 'entra'
                            ? '00000000-0000-0000-0000-000000000000'
                            : '123456789012-abc.apps.googleusercontent.com'
                    "
                    :error="forms[provider.key].errors.client_id"
                    :saved="saves[provider.key].saved('client_id')"
                    :waiting="saves[provider.key].waiting('client_id')"
                    v-bind="saves[provider.key].leave('client_id')"
                />
                <Field
                    :label="$t('Client-Secret')"
                    :error="forms[provider.key].errors.client_secret"
                    :saved="saves[provider.key].saved('client_secret')"
                    :waiting="saves[provider.key].waiting('client_secret')"
                    v-bind="saves[provider.key].leave('client_secret')"
                    :help="
                        provider.has_secret
                            ? $t(
                                  'Gespeichert und verschlüsselt — leer lassen, um es zu behalten.',
                              )
                            : $t(
                                  'Wird verschlüsselt gespeichert und nie wieder angezeigt.',
                              )
                    "
                >
                    <template #default="{ id, invalid, describedby }">
                        <input
                            :id="id"
                            v-model="forms[provider.key].client_secret"
                            type="password"
                            autocomplete="new-password"
                            spellcheck="false"
                            :placeholder="provider.has_secret ? '••••••••' : ''"
                            :class="secretClasses(invalid)"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedby"
                        />
                    </template>
                </Field>
                <Field
                    v-if="provider.key === 'google'"
                    v-model="forms.google.hosted_domain"
                    :label="$t('Erlaubte Domain')"
                    optional
                    mono
                    autocomplete="off"
                    placeholder="firma.ch"
                    :error="forms.google.errors.hosted_domain"
                    :saved="saves['google'].saved('hosted_domain')"
                    :waiting="saves['google'].waiting('hosted_domain')"
                    v-bind="saves['google'].leave('hosted_domain')"
                    :help="
                        $t(
                            'Nur Konten dieser Google-Workspace-Domain. Leer: jedes Google-Konto mit bestätigter E-Mail, die hier einen Benutzer hat.',
                        )
                    "
                />
                <Field
                    v-model="forms[provider.key].auto_create_role"
                    type="select"
                    :label="$t('Rolle für neue Benutzer')"
                    :options="roleOptions(provider)"
                    :error="forms[provider.key].errors.auto_create_role"
                    :saved="saves[provider.key].saved('auto_create_role')"
                    :help="
                        provider.key === 'google'
                            ? $t(
                                  'Ab Werk aus. Mit einer Rolle bekommt jedes Konto der erlaubten Domain beim ersten Mal Zugang — nur mit erlaubter Domain, nie als Betreiber oder Administrator.',
                              )
                            : $t(
                                  'Ab Werk aus. Mit einer Rolle bekommt jedes Konto des Tenants beim ersten Mal Zugang — nie als Betreiber oder Administrator.',
                              )
                    "
                />

                <h3 class="mt-3.5 mb-1 text-[12.5px] font-semibold">
                    {{ $t('Redirect-URL zum Eintragen') }}
                </h3>
                <p class="mb-1.5 text-[11.5px] text-basalt-light">
                    {{
                        provider.key === 'entra'
                            ? $t(
                                  'In Entra › App-Registrierung › Authentifizierung › Plattform «Web». Je Adresse, unter der das Backend erreichbar ist, eine Zeile.',
                              )
                            : $t(
                                  'In der Google Cloud Console › Anmeldedaten › OAuth-Client › Autorisierte Weiterleitungs-URIs. Je Adresse eine Zeile.',
                              )
                    }}
                </p>
                <ul class="m-0 list-none p-0">
                    <li
                        v-for="url in provider.redirect_urls"
                        :key="url"
                        class="flex flex-wrap items-center justify-between gap-x-2 border-t border-ash-light py-1.5 first:border-t-0"
                    >
                        <code
                            class="min-w-0 font-mono text-[11.5px] break-all"
                            >{{ url }}</code
                        >
                        <Button variant="link" size="sm" @click="copy(url)">{{
                            copied === url ? $t('Kopiert') : $t('Kopieren')
                        }}</Button>
                    </li>
                </ul>

                <Notice
                    v-if="check && check.provider === provider.key"
                    :tone="check.ok ? 'success' : 'danger'"
                    class="mt-3"
                    :title="$t('Verbindung testen')"
                >
                    {{ check.message }}
                </Notice>

                <p
                    v-if="
                        !current(provider.key).configured &&
                        saves[provider.key].status.value === 'pending'
                    "
                    class="mt-3 text-[12px] text-basalt-light"
                >
                    {{
                        $t(
                            'Noch nicht gespeichert — es speichert sich, sobald alle Pflichtangaben stehen.',
                        )
                    }}
                </p>
                <div class="mt-3.5 flex flex-wrap items-center gap-2">
                    <Button
                        v-if="provider.configured"
                        variant="secondary"
                        :disabled="forms[provider.key].isDirty"
                        :title="
                            forms[provider.key].isDirty
                                ? $t(
                                      'Zuerst speichern — getestet wird die gespeicherte Einrichtung.',
                                  )
                                : $t(
                                      'Fragt :provider ohne Benutzer — nur ein Hinweis.',
                                      {
                                          provider: provider.label,
                                      },
                                  )
                        "
                        @click="test(provider)"
                        >{{ $t('Verbindung testen') }}</Button
                    >
                    <Button
                        v-if="provider.configured"
                        variant="link"
                        class="ml-auto"
                        @click="remove(provider)"
                        >{{ $t('Entfernen') }}</Button
                    >
                </div>
            </form>
        </Card>
    </div>
</template>
