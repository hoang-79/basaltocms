<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import AccountFrame from '../../../components/AccountFrame.vue';
import AliasField from '../../../components/AliasField.vue';
import Button from '../../../components/Button.vue';
import Card from '../../../components/Card.vue';
import Field from '../../../components/Field.vue';
import Notice from '../../../components/Notice.vue';
import StateTag from '../../../components/StateTag.vue';
import { t, tc } from '../../../i18n';
import type {
    AccountHeader,
    CustomDomain,
    DomainQuota,
    DomainSite,
    DomainState,
    UsageRow,
} from '../../../types-saas';

/**
 * TB 12 Scheibe 3 (G-11, Mockup 23 · Domains): je Site der Alias (ändern, der alte leitet 30 Tage um) und die eigenen
 * Domains mit den DNS-Einträgen aus der Cloud-CLI, Stand und «Jetzt prüfen». Darunter «Weitere Domain» — über dem
 * Kontingent mit der Bestellung einer Zusatzdomain statt einer Sperre. Die DNS-Zeilen brechen um statt zu scrollen.
 */
const props = defineProps<{
    account: AccountHeader;
    tab: string;
    sites: DomainSite[];
    quota: UsageRow | null;
    domain_quota: DomainQuota;
    needs_extra: boolean;
    in_trial: boolean;
    can_manage: boolean;
    can_order: boolean;
    orders: string[];
    alias_domain: string;
    extra_price: number;
    urls: { alias_check: string; plan: string; billing: string };
}>();

const page = usePage<{ errors: Record<string, string> }>();

const aliasForms = reactive(
    Object.fromEntries(
        props.sites.map((site) => [
            site.id,
            useForm({ alias: site.alias?.name ?? '' }),
        ]),
    ),
);
const addForms = reactive(
    Object.fromEntries(
        props.sites.map((site) => [
            site.id,
            useForm({ host: '', order_extra: false }),
        ]),
    ),
);
const busy = ref<number | null>(null);

const stateLabels: Record<DomainState, string> = {
    waiting: t('wartet auf DNS'),
    certificate: t('Zertifikat wird ausgestellt'),
    active: t('aktiv'),
    error: t('Fehler'),
};
const stateTones: Record<DomainState, 'warning' | 'success' | 'danger'> = {
    waiting: 'warning',
    certificate: 'warning',
    active: 'success',
    error: 'danger',
};

function changeAlias(site: DomainSite): void {
    aliasForms[site.id].post(site.urls.alias, { preserveScroll: true });
}

function addDomain(site: DomainSite): void {
    addForms[site.id].post(site.urls.store, {
        preserveScroll: true,
        onSuccess: () => addForms[site.id].reset(),
    });
}

function check(domain: CustomDomain): void {
    busy.value = domain.id;
    router.post(
        domain.urls.check,
        {},
        { preserveScroll: true, onFinish: () => (busy.value = null) },
    );
}

function makePrimary(url: string): void {
    router.post(url, {}, { preserveScroll: true });
}

function remove(domain: CustomDomain): void {
    if (
        !window.confirm(
            t(
                ':host entfernen? Die Domain wird in der Cloud gelöscht; ist sie die Hauptadresse, wird es wieder der Alias.',
                { host: domain.host },
            ),
        )
    ) {
        return;
    }
    router.delete(domain.urls.destroy, { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('Konto · Domains')" />
    <AccountFrame :account="account" :tab="tab">
        <p class="mb-3 max-w-[70ch] text-[12.5px] text-basalt-light">
            {{
                $t(
                    'Alias sofort, eigene Domain nach DNS-Prüfung — in der Regel unter 15 Minuten.',
                )
            }}
        </p>
        <Notice v-if="page.props.errors.domain" tone="danger" class="mb-3">{{
            page.props.errors.domain
        }}</Notice>

        <section
            v-for="site in sites"
            :key="site.id"
            class="mb-6 last:mb-0"
            :aria-label="site.name"
        >
            <h2
                v-if="sites.length > 1"
                class="mb-2 text-[13px] font-semibold text-basalt"
            >
                {{ site.name }}
                <span class="font-normal text-basalt-light">
                    · {{ $t('Hauptadresse') }}
                    <span class="font-mono break-all">{{
                        site.primary ?? '—'
                    }}</span></span
                >
            </h2>
            <div class="grid gap-4 md:grid-cols-2">
                <Card :title="$t('Alias')">
                    <template #sub>
                        {{ $t('in jeder Stufe inklusive') }}
                        <template v-if="site.alias">
                            ·
                            {{
                                site.alias.primary
                                    ? $t('Hauptadresse')
                                    : $t('leitet auf :host um (301)', {
                                          host: site.primary ?? '',
                                      })
                            }}
                        </template>
                    </template>
                    <form
                        v-if="can_manage"
                        novalidate
                        @submit.prevent="changeAlias(site)"
                    >
                        <AliasField
                            v-model="aliasForms[site.id].alias"
                            :check-url="urls.alias_check"
                            :domain="alias_domain"
                            :site-name="site.name"
                            :current="site.alias?.name ?? ''"
                            :error="aliasForms[site.id].errors.alias"
                        />
                        <div class="mt-2.5 flex flex-wrap items-center gap-2">
                            <Button
                                type="submit"
                                variant="secondary"
                                size="sm"
                                :disabled="
                                    aliasForms[site.id].processing ||
                                    aliasForms[site.id].alias ===
                                        (site.alias?.name ?? '')
                                "
                                >{{ $t('Alias ändern') }}</Button
                            >
                            <Button
                                v-if="site.alias && !site.alias.primary"
                                variant="link"
                                size="sm"
                                @click="makePrimary(site.alias.primary_url)"
                                >{{
                                    $t('Alias wieder zur Hauptadresse')
                                }}</Button
                            >
                        </div>
                    </form>
                    <p v-else class="font-mono text-[12.5px] break-all">
                        {{ site.alias?.host ?? '—' }}
                    </p>
                    <p class="mt-2 text-[11.5px] text-basalt-light">
                        {{
                            $t(
                                'Mit verbundener eigener Domain leitet der Alias dorthin um (301).',
                            )
                        }}
                    </p>
                    <ul
                        v-if="site.old_aliases.length > 0"
                        class="mt-1.5 grid gap-0.5 text-[11.5px] text-basalt-light"
                    >
                        <li v-for="old in site.old_aliases" :key="old.host">
                            <span class="font-mono break-all">{{
                                old.host
                            }}</span>
                            ·
                            {{
                                $t('leitet um bis :date', {
                                    date: old.until ?? '',
                                })
                            }}
                        </li>
                    </ul>
                </Card>

                <Card
                    v-for="domain in site.domains"
                    :key="domain.id"
                    :status="
                        domain.state === 'active'
                            ? 'success'
                            : domain.state === 'error'
                              ? 'danger'
                              : 'warning'
                    "
                >
                    <template #title>
                        <span class="min-w-0 break-all"
                            >{{ $t('Eigene Domain') }} · {{ domain.host }}</span
                        >
                    </template>
                    <template #sub>
                        <StateTag :tone="stateTones[domain.state]">{{
                            stateLabels[domain.state]
                        }}</StateTag>
                        <span
                            v-for="h in domain.hosts"
                            :key="h.id"
                            class="font-mono break-all"
                            >{{ h.host
                            }}<b v-if="h.primary" class="font-sans text-basalt">
                                · {{ $t('Hauptadresse') }}</b
                            ></span
                        >
                    </template>
                    <Notice
                        v-if="domain.state === 'error' && domain.error"
                        tone="danger"
                        class="mb-2"
                        :title="$t('Die Cloud meldet')"
                        ><span class="break-words">{{
                            domain.error
                        }}</span></Notice
                    >
                    <template v-if="!domain.verified">
                        <p class="mb-1.5 text-[11.5px] text-basalt-light">
                            {{
                                $t(
                                    'Setzen Sie diese Einträge bei Ihrem Anbieter (z. B. Infomaniak, Hostpoint, Cloudflare, Swizzonic) — genau so, wie sie hier stehen.',
                                )
                            }}
                        </p>
                        <ul
                            v-if="domain.records.length > 0"
                            class="grid gap-1 rounded-md border border-ash-light bg-paper/60 p-2 font-mono text-[11px]"
                            :aria-label="$t('DNS-Einträge')"
                        >
                            <li
                                v-for="(r, i) in domain.records"
                                :key="i"
                                class="grid gap-0.5 border-b border-ash-light pb-1 last:border-b-0 last:pb-0"
                            >
                                <div class="flex gap-2">
                                    <span class="w-12 shrink-0 font-semibold">{{
                                        r.type
                                    }}</span>
                                    <span class="min-w-0 break-all">{{
                                        r.name
                                    }}</span>
                                </div>
                                <div
                                    class="flex flex-wrap items-baseline gap-x-2 pl-14"
                                >
                                    <span class="min-w-0 break-all"
                                        >→ {{ r.value }}</span
                                    >
                                    <span
                                        v-if="r.found !== null"
                                        class="ml-auto shrink-0 font-sans"
                                        :class="
                                            r.found
                                                ? 'text-success'
                                                : 'text-warning'
                                        "
                                        >{{
                                            r.found
                                                ? $t('gefunden ✓')
                                                : $t('noch nicht gefunden')
                                        }}</span
                                    >
                                </div>
                            </li>
                        </ul>
                        <p v-else class="text-[12px] text-basalt-light">
                            {{
                                $t(
                                    'Die Cloud hat noch keine DNS-Einträge genannt — wir fragen bei der nächsten Prüfung erneut.',
                                )
                            }}
                        </p>
                    </template>
                    <div
                        v-if="can_manage"
                        class="mt-2.5 flex flex-wrap items-center gap-2"
                    >
                        <Button
                            v-if="!domain.verified"
                            size="sm"
                            :disabled="busy === domain.id"
                            @click="check(domain)"
                            >{{
                                busy === domain.id
                                    ? $t('Wird geprüft …')
                                    : $t('Jetzt prüfen')
                            }}</Button
                        >
                        <template v-for="h in domain.hosts" :key="h.id">
                            <Button
                                v-if="domain.verified && !h.primary"
                                variant="secondary"
                                size="sm"
                                @click="makePrimary(h.primary_url)"
                                >{{
                                    $t(':host zur Hauptadresse', {
                                        host: h.host,
                                    })
                                }}</Button
                            >
                        </template>
                        <Button
                            variant="danger"
                            size="sm"
                            @click="remove(domain)"
                            >{{ $t('Entfernen') }}</Button
                        >
                    </div>
                    <p
                        v-if="!domain.verified"
                        class="mt-1.5 text-[11.5px] text-basalt-light"
                    >
                        {{
                            domain.checked
                                ? $t(
                                      'zuletzt geprüft :when · wir prüfen alle 10 Minuten weiter',
                                      { when: domain.checked },
                                  )
                                : $t('wir prüfen alle 10 Minuten')
                        }}
                    </p>
                    <p class="mt-1.5 text-[11.5px] text-basalt-light">
                        {{
                            domain.verified
                                ? $t(
                                      'Verbunden mit Zertifikat. Der Alias und die übrigen Adressen leiten auf die Hauptadresse um (301).',
                                  )
                                : $t(
                                      'Apex und www zählen als eine Domain. Nach dem Zertifikat schalten wir um und mailen Ihnen.',
                                  )
                        }}
                    </p>
                </Card>
            </div>

            <Notice tone="grey" class="mt-4">
                <h3 class="mb-1 text-[12.5px] font-semibold text-basalt">
                    {{ $t('Weitere Domain') }}
                </h3>
                <p class="mb-2">
                    {{
                        tc(
                            'Ihr Plan enthält :count eigene Domain (Apex und www zählen als eine).|Ihr Plan enthält :count eigene Domains (Apex und www zählen als eine).',
                            domain_quota.included,
                        )
                    }}
                    {{
                        $t('Belegt: :used von :limit.', {
                            used: domain_quota.used,
                            limit: domain_quota.limit,
                        })
                    }}
                    <template v-if="orders.length > 0">
                        {{
                            tc(
                                ':count Zusatzdomain bestellt — sie erscheint im Abo.|:count Zusatzdomains bestellt — sie erscheinen im Abo.',
                                orders.length,
                            )
                        }}
                    </template>
                </p>
                <p v-if="!can_manage">
                    {{
                        $t(
                            'Domains fügen Besitzer und Verwaltung des Kontos hinzu.',
                        )
                    }}
                </p>
                <template v-else-if="needs_extra && in_trial">
                    <p>
                        {{
                            $t(
                                'In der Testphase gibt es noch keine eigene Domain — wählen Sie zuerst einen Plan (ab Standard ist eine Domain inklusive).',
                            )
                        }}
                    </p>
                    <div class="mt-2">
                        <Button
                            variant="secondary"
                            size="sm"
                            :href="urls.plan"
                            >{{ $t('Plan ansehen') }}</Button
                        >
                    </div>
                </template>
                <form
                    v-else
                    novalidate
                    class="max-w-[560px]"
                    @submit.prevent="addDomain(site)"
                >
                    <Field
                        v-model="addForms[site.id].host"
                        :label="$t('Ihre Domain')"
                        placeholder="mueller-garten.ch"
                        mono
                        inputmode="url"
                        autocomplete="off"
                        :help="
                            $t(
                                'Ohne https:// — www und die Domain ohne www legen wir beide an. Sie bringen die Domain mit (Registrierung bei Ihrem Anbieter).',
                            )
                        "
                        :error="addForms[site.id].errors.host"
                    />
                    <div v-if="needs_extra" class="mt-2.5">
                        <p class="mb-1.5">
                            {{
                                $t(
                                    'Ihr Kontingent ist belegt. Weitere Domain für CHF :price/Monat hinzufügen oder auf einen grösseren Plan wechseln (Pro: 5 Domains).',
                                    { price: extra_price },
                                )
                            }}
                        </p>
                        <label
                            v-if="can_order"
                            class="flex items-start gap-2 text-[12.5px] text-basalt"
                        >
                            <input
                                v-model="addForms[site.id].order_extra"
                                type="checkbox"
                                class="mt-0.5 accent-lava-dark"
                            />
                            <span>{{
                                $t(
                                    'Zusatzdomain für CHF :price/Monat bestellen — sie erscheint im Abo.',
                                    { price: extra_price },
                                )
                            }}</span>
                        </label>
                        <p v-else>
                            {{
                                $t(
                                    'Eine Zusatzdomain bestellen Besitzer oder Abrechnung des Kontos.',
                                )
                            }}
                        </p>
                        <p
                            v-if="addForms[site.id].errors.order_extra"
                            class="text-danger mt-1 text-[12px] font-semibold"
                        >
                            {{ addForms[site.id].errors.order_extra }}
                        </p>
                    </div>
                    <div class="mt-2.5 flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="
                                addForms[site.id].processing ||
                                (needs_extra && !can_order)
                            "
                            >{{
                                addForms[site.id].processing
                                    ? $t('Domain wird angelegt …')
                                    : $t('Domain hinzufügen')
                            }}</Button
                        >
                        <Button
                            v-if="needs_extra"
                            variant="secondary"
                            size="sm"
                            :href="urls.plan"
                            >{{ $t('Plan ansehen') }}</Button
                        >
                    </div>
                </form>
            </Notice>
        </section>
    </AccountFrame>
</template>
