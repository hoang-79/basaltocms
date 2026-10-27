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
 * TB 53 (CR-009 Nr. 2): Verwaltung › Sites › Anmeldung für Kundenkonten — Google, Microsoft, GitHub und LinkedIn im
 * Frontend, zusätzlich zum Code per Mail. Das Client-Secret kommt nie zurück an den Browser. «Ausprobieren» meldet
 * einmal echt beim Anbieter an, ohne Kundenkonto — das Ergebnis steht danach oben auf dieser Seite.
 */
type ProviderKey = 'google' | 'microsoft' | 'github' | 'linkedin';
type Provider = {
    key: ProviderKey;
    label: string;
    icon: string;
    console: string;
    configured: boolean;
    enabled: boolean;
    client_id: string | null;
    has_secret: boolean;
    tenant: string;
    linked: number;
    updated_at: string | null;
    redirect_urls: string[];
    update_url: string;
    destroy_url: string;
    probe_url: string;
};

const props = defineProps<{
    site: { id: number; name: string; handle: string };
    available: boolean;
    opens_registration: boolean;
    providers: Provider[];
    login_url: string;
    back_url: string;
    probe: { ok: boolean; message: string; provider: ProviderKey } | null;
}>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);

type SocialForm = {
    enabled: boolean;
    client_id: string;
    client_secret: string;
    tenant: string;
};

const forms = Object.fromEntries(
    props.providers.map((provider) => [
        provider.key,
        useForm<SocialForm>({
            enabled: provider.configured ? provider.enabled : true,
            client_id: provider.client_id ?? '',
            client_secret: '',
            tenant: provider.tenant,
        }),
    ]),
) as Record<ProviderKey, ReturnType<typeof useForm<SocialForm>>>;

/** Der aktuelle Stand eines Anbieters (nach jedem Speichern neu geladen). */
const current = (key: ProviderKey): Provider =>
    props.providers.find((p) => p.key === key) as Provider;

/**
 * CR-013: Jeder Anbieter speichert selbst — Schalter und Konten sofort, Client-ID und Secret beim Verlassen (Enter).
 * Neu eingerichtet wird erst, wenn beide stehen; das Secret leert sich nach dem Speichern und bleibt verschlüsselt.
 */
const saves = Object.fromEntries(
    props.providers.map((provider) => {
        const form = forms[provider.key];
        const save = useAutosave(form, {
            url: provider.update_url,
            onLeave: ['client_id', 'client_secret'],
            only: ['errors', 'providers'],
            labels: {
                enabled: provider.label,
                client_id: t('Client-ID'),
                client_secret: t('Client-Secret'),
                tenant: t('Konten'),
            },
            enabled: () =>
                current(provider.key).configured ||
                (form.client_id.trim() !== '' &&
                    form.client_secret.trim() !== ''),
            onSaved: () => {
                if (form.client_secret !== '') {
                    form.client_secret = '';
                    save.rebase();
                }
            },
        });

        return [provider.key, save];
    }),
) as Record<ProviderKey, ReturnType<typeof useAutosave<SocialForm>>>;

const copied = ref('');

const placeholders: Record<ProviderKey, string> = {
    google: '123456789012-abc.apps.googleusercontent.com',
    microsoft: '00000000-0000-0000-0000-000000000000',
    github: 'Ov23liAbCdEfGhIjKlMn',
    linkedin: '78abcdefghijkl', // gitleaks:allow — Platzhalter im Eingabefeld, keine echte Client-ID
};

const where: Record<ProviderKey, string> = {
    google: 'Google Cloud Console › APIs und Dienste › Anmeldedaten › OAuth-Client-ID (Webanwendung) › Autorisierte Weiterleitungs-URIs.',
    microsoft:
        'Entra › App-Registrierungen › Neue Registrierung (Konten in einem beliebigen Verzeichnis und persönliche Microsoft-Konten) › Authentifizierung › Plattform «Web».',
    github: 'GitHub › Settings › Developer settings › OAuth Apps › New OAuth App › Authorization callback URL. GitHub nimmt nur eine Adresse je App.',
    linkedin:
        'LinkedIn Developers › App › Products › «Sign In with LinkedIn using OpenID Connect» › Auth › Authorized redirect URLs.',
};

function remove(provider: Provider): void {
    if (
        !window.confirm(
            t(
                ':provider für diese Site entfernen? Die Knöpfe verschwinden, das Secret ist danach weg.',
                { provider: provider.label },
            ),
        )
    ) {
        return;
    }
    router.delete(provider.destroy_url, { preserveScroll: true });
}

function tryOut(provider: Provider): void {
    router.post(provider.probe_url);
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

const probeLabel = computed(
    () =>
        props.providers.find((p) => p.key === props.probe?.provider)?.label ??
        '',
);
</script>

<template>
    <Head :title="$t('Anmeldung für Kundenkonten')" />
    <PageHeader
        :title="$t('Anmeldung für Kundenkonten')"
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
                    'Ihre Kundinnen und Kunden melden sich im Frontend mit einem Konto bei Google, Microsoft, GitHub oder LinkedIn an — zusätzlich zum Code per Mail. Basalto übernimmt nur E-Mail-Adressen, die der Anbieter bestätigt hat.',
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

    <Notice
        v-if="probe"
        :tone="probe.ok ? 'success' : 'danger'"
        class="mb-4"
        :title="$t('Ausprobiert: :provider', { provider: probeLabel })"
    >
        {{ probe.message }}
    </Notice>
    <Notice v-if="!available" tone="warning" class="mb-4">
        {{
            $t(
                'Auf dieser Site nutzt noch kein Modul das Kundenkonto (Lizenzverkauf, Marktplatz, Mitglieder oder Forum). Die Knöpfe erscheinen, sobald eines eingeschaltet ist.',
            )
        }}
    </Notice>
    <Notice v-else-if="!opens_registration" tone="grey" class="mb-4">
        {{
            $t(
                'Neue Konten gibt es auf dieser Site nur auf Einladung. Über einen Anbieter melden sich deshalb nur bestehende Kundenkonten an.',
            )
        }}
    </Notice>
    <Notice v-else tone="grey" class="mb-4">
        {{
            $t(
                'Passkeys brauchen keine Einrichtung — Kundinnen und Kunden legen sie im Konto unter «Anmeldung» an.',
            )
        }}
        {{ $t('Die Knöpfe stehen über dem Feld für die E-Mail, etwa unter') }}
        <code class="font-mono break-all text-basalt">{{ login_url }}</code
        >.
        {{
            $t(
                'Gibt es zur bestätigten Adresse schon ein Konto, wird es verknüpft und bekommt eine Mail dazu; sonst entsteht ein neues.',
            )
        }}
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
                        ? $t(
                              'eingeschaltet · :count verknüpft · geändert :date',
                              {
                                  count: provider.linked,
                                  date: provider.updated_at ?? '',
                              },
                          )
                        : $t('ausgeschaltet — kein Knopf im Frontend')
                    : $t('nicht eingerichtet')
            "
        >
            <form novalidate @submit.prevent="saves[provider.key].flush()">
                <div class="mb-2.5 flex items-center gap-2.5">
                    <!-- eslint-disable-next-line vue/no-v-html -- Zeichen des Anbieters aus dem Kern -->
                    <span class="inline-flex" v-html="provider.icon" />
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
                            ? $t('Knopf im Frontend')
                            : $t('Ausgeschaltet')
                    }}</span>
                </div>
                <Field
                    v-model="forms[provider.key].client_id"
                    :label="$t('Client-ID')"
                    mono
                    required
                    autocomplete="off"
                    :placeholder="placeholders[provider.key]"
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
                    v-if="provider.key === 'microsoft'"
                    v-model="forms.microsoft.tenant"
                    type="select"
                    :label="$t('Konten')"
                    :options="[
                        {
                            value: 'common',
                            label: $t('Private und Firmenkonten'),
                        },
                        {
                            value: 'consumers',
                            label: $t(
                                'Nur private Konten (Outlook, Hotmail, Xbox)',
                            ),
                        },
                    ]"
                    :error="forms.microsoft.errors.tenant"
                    :help="
                        $t(
                            'Muss zur App-Registrierung passen. Bei Firmenkonten zählt die Adresse, mit der sich die Person anmeldet; Gastkonten gehen nicht.',
                        )
                    "
                />

                <h3 class="mt-3.5 mb-1 text-[12.5px] font-semibold">
                    {{ $t('Redirect-URL zum Eintragen') }}
                </h3>
                <p class="mb-1.5 text-[11.5px] text-basalt-light">
                    {{ $t(where[provider.key]) }}
                    <a
                        :href="provider.console"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="font-semibold text-lava-dark underline"
                        >{{
                            $t('Konsole von :provider öffnen', {
                                provider: provider.label,
                            })
                        }}</a
                    >
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

                <p
                    v-if="
                        !current(provider.key).configured &&
                        saves[provider.key].status.value === 'pending'
                    "
                    class="mt-3 text-[12px] text-basalt-light"
                >
                    {{
                        $t(
                            'Noch nicht gespeichert — es speichert sich, sobald Client-ID und Client-Secret stehen.',
                        )
                    }}
                </p>
                <div class="mt-3.5 flex flex-wrap items-center gap-2">
                    <Button
                        v-if="provider.configured && available"
                        variant="secondary"
                        :disabled="forms[provider.key].isDirty"
                        :title="
                            forms[provider.key].isDirty
                                ? $t(
                                      'Zuerst speichern — ausprobiert wird die gespeicherte Einrichtung.',
                                  )
                                : $t(
                                      'Einmal echt bei :provider anmelden, ohne dass ein Kundenkonto entsteht.',
                                      { provider: provider.label },
                                  )
                        "
                        @click="tryOut(provider)"
                        >{{ $t('Ausprobieren') }}</Button
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
