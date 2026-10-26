<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AccountFrame from '../../../components/AccountFrame.vue';
import AliasField from '../../../components/AliasField.vue';
import Button from '../../../components/Button.vue';
import Card from '../../../components/Card.vue';
import Field from '../../../components/Field.vue';
import { formatBytes } from '../../../components/media';
import Notice from '../../../components/Notice.vue';
import PairRow from '../../../components/PairRow.vue';
import { t } from '../../../i18n';
import type { AccountHeader, SaasTheme } from '../../../types-saas';

/**
 * TB 12 Scheibe 1 (Mockup 23 · Sites): die Sites des Kontos — Alias, eigene Domains, Speicher, Benutzer; «Zum Backend»
 * und «Site ansehen». Darunter «Weitere Site» mit Alias im Rahmen des Kontingents; darüber hinaus der Hinweis auf Plan
 * oder Zusatz-Site — nichts Bestehendes wird gesperrt.
 */
type AccountSite = {
    id: number;
    name: string;
    handle: string;
    alias: string | null;
    url: string;
    custom_domains: { host: string; verified: boolean }[];
    live: boolean;
    theme: string | null;
    locales: string[];
    users: number;
    storage: number;
    current: boolean;
    open_url: string;
};

const props = defineProps<{
    account: AccountHeader;
    tab: string;
    sites: AccountSite[];
    can_add: boolean;
    can_create: boolean;
    limit: number;
    themes: SaasTheme[];
    default_theme: string | null;
    default_timezone: string;
    locales: Record<string, string>;
    alias_domain: string;
    extra_site_price: number;
    urls: { store: string; alias: string; plan: string };
}>();

const page = usePage<{ errors: Record<string, string> }>();

const form = useForm({
    name: '',
    alias: '',
    locale: 'de',
    timezone: props.default_timezone,
    theme: props.default_theme ?? '',
});

const localeOptions = computed(() =>
    Object.entries(props.locales).map(([value, label]) => ({ value, label })),
);
const themeOptions = computed(() =>
    props.themes.map((th) => ({ value: th.name, label: th.label })),
);

function submit(): void {
    form.transform((data) => ({
        name: data.name,
        alias: data.alias,
        locales: [data.locale],
        timezone: data.timezone,
        theme: data.theme,
    })).post(props.urls.store, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function open(site: AccountSite): void {
    router.post(site.open_url);
}
</script>

<template>
    <Head :title="$t('Konto · Sites')" />
    <AccountFrame :account="account" :tab="tab">
        <Notice v-if="page.props.errors.site" tone="danger" class="mb-3">{{
            page.props.errors.site
        }}</Notice>
        <div class="grid gap-4 md:grid-cols-2">
            <Card
                v-for="site in sites"
                :key="site.id"
                :status="site.live ? 'lava' : 'warning'"
            >
                <template #title>
                    <span class="min-w-0 break-words">{{ site.name }}</span>
                </template>
                <p class="mb-2 text-[11.5px] text-basalt-light">
                    <code class="font-mono">{{ site.handle }}</code>
                    ·
                    {{ site.live ? $t('live') : $t('noch nicht live') }}
                    <span v-if="site.theme"> · {{ site.theme }}</span>
                </p>
                <dl>
                    <PairRow :label="$t('Alias')">
                        <span class="font-mono text-[12px] break-all">{{
                            site.alias ?? '—'
                        }}</span>
                    </PairRow>
                    <PairRow :label="$t('Eigene Domain')">
                        <span
                            v-if="site.custom_domains.length === 0"
                            class="font-normal text-basalt-light"
                            >{{ $t('keine') }}</span
                        >
                        <span
                            v-for="d in site.custom_domains"
                            :key="d.host"
                            class="block font-mono text-[12px] break-all"
                            >{{ d.host }}
                            <span
                                :class="
                                    d.verified ? 'text-success' : 'text-warning'
                                "
                                >{{
                                    d.verified ? '✓' : $t('nicht geprüft')
                                }}</span
                            ></span
                        >
                    </PairRow>
                    <PairRow :label="$t('Sprachen')">{{
                        site.locales.map((l) => l.toUpperCase()).join(' · ')
                    }}</PairRow>
                    <PairRow :label="$t('Speicher')">{{
                        formatBytes(site.storage)
                    }}</PairRow>
                    <PairRow :label="$t('Benutzer der Site')">{{
                        site.users
                    }}</PairRow>
                </dl>
                <div class="mt-3 flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        :variant="site.current ? 'secondary' : 'primary'"
                        @click="open(site)"
                        >{{ $t('Zum Backend') }}</Button
                    >
                    <a
                        :href="site.url"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex min-h-7 items-center rounded-md border border-ash bg-white px-2.5 text-[12px] font-semibold text-basalt hover:bg-paper"
                        >{{ $t('Site ansehen') }}</a
                    >
                </div>
            </Card>

            <Card :title="$t('Weitere Site')">
                <template v-if="!can_create">
                    <p class="text-[12.5px] text-basalt-light">
                        {{
                            $t(
                                'Sites legen Besitzer und Verwaltung des Kontos an.',
                            )
                        }}
                    </p>
                </template>
                <template v-else-if="!can_add">
                    <p class="text-[12.5px] text-basalt-light">
                        {{
                            $tc(
                                'Ihr Plan enthält :count Site. Für eine weitere wählen Sie einen grösseren Plan oder eine Zusatz-Site für CHF :price/Monat.|Ihr Plan enthält :count Sites. Für eine weitere wählen Sie einen grösseren Plan oder eine Zusatz-Site für CHF :price/Monat.',
                                limit,
                                { price: extra_site_price },
                            )
                        }}
                    </p>
                    <div class="mt-2.5">
                        <Button
                            variant="secondary"
                            size="sm"
                            :href="urls.plan"
                            >{{ $t('Plan ansehen') }}</Button
                        >
                    </div>
                </template>
                <form v-else novalidate @submit.prevent="submit">
                    <Field
                        v-model="form.name"
                        :label="$t('Site-Name')"
                        required
                        :error="form.errors.name"
                    />
                    <AliasField
                        v-model="form.alias"
                        :check-url="urls.alias"
                        :domain="alias_domain"
                        :site-name="form.name"
                        :error="form.errors.alias"
                    />
                    <div class="mt-2.5 grid gap-x-4 gap-y-2.5 sm:grid-cols-2">
                        <Field
                            class="mt-0!"
                            v-model="form.locale"
                            :label="$t('Sprache')"
                            type="select"
                            :options="localeOptions"
                            :error="
                                (form.errors as Record<string, string>).locales
                            "
                        />
                        <Field
                            class="mt-0!"
                            v-model="form.theme"
                            :label="$t('Theme')"
                            type="select"
                            :options="themeOptions"
                            :error="form.errors.theme"
                        />
                    </div>
                    <div class="mt-3">
                        <Button type="submit" :disabled="form.processing">{{
                            form.processing
                                ? $t('Site wird angelegt …')
                                : $t('Site anlegen')
                        }}</Button>
                    </div>
                </form>
                <p class="mt-2.5 text-[11.5px] text-basalt-light">
                    <Link
                        :href="urls.plan"
                        class="text-lava-dark underline hover:text-basalt"
                        >{{ $t('Kontingente ansehen') }}</Link
                    >
                </p>
            </Card>
        </div>
    </AccountFrame>
</template>
