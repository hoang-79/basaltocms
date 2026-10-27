<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AccountFrame from '../../../components/AccountFrame.vue';
import { useAutosave } from '../../../components/autosave';
import Button from '../../../components/Button.vue';
import Card from '../../../components/Card.vue';
import CountrySelect from '../../../components/CountrySelect.vue';
import Field from '../../../components/Field.vue';
import Notice from '../../../components/Notice.vue';
import PairRow from '../../../components/PairRow.vue';
import StateTag from '../../../components/StateTag.vue';
import { t } from '../../../i18n';
import type {
    AccountHeader,
    AccountInvoiceRow,
    BillingAddress,
    BillingMethod,
    BillingPlan,
    BillingSubscription,
} from '../../../types-saas';

/**
 * TB 12 Scheibe 2 (M-21, Mockup 23 · Abo und Rechnungen): ohne Abo «Plan wählen» — Plan, Zusätze, Zahlungsart (Karte
 * über Stripe oder QR-Rechnung) und Rechnungsadresse; nichts läuft automatisch an. Mit Abo links das Abo (nächste
 * Rechnung, Zahlungsart, Adresse, MwSt.), rechts die Rechnungen mit PDF; darunter Plan wechseln (höher sofort, tiefer
 * zum Ende der Periode) und die Zusätze. Eine offene Rechnung sperrt nichts.
 */
const props = defineProps<{
    account: AccountHeader;
    tab: string;
    subscription: BillingSubscription;
    address: BillingAddress;
    vat: { rate: string | null };
    plans: BillingPlan[];
    selected: string | null;
    period_end: string | null;
    extra: { site: number; domain: number };
    stripe: boolean;
    payment_days: number;
    invoices: AccountInvoiceRow[];
    countries: { code: string; name: string; dial: string }[];
    /** CR-011 (TB 58): EU und EWR — dort verlangen Verbraucher ausdrücklich den sofortigen Beginn */
    eu_countries: string[];
    withdrawal_url: string;
    returned: boolean;
    urls: {
        choose: string;
        change: string;
        extras: string;
        address: string;
        method: string;
        portal: string;
    };
}>();

const page = usePage<{ errors: Record<string, string> }>();

const addressForm = useForm<BillingAddress>({ ...props.address });

const choice = useForm({
    plan:
        props.selected ??
        props.plans.find((p) => p.current)?.key ??
        props.plans[0]?.key ??
        '',
    method: (props.stripe ? 'stripe' : 'invoice') as BillingMethod,
    extra_sites: props.subscription.extras.sites,
    extra_domains: props.subscription.extras.domains,
    immediate_start: false,
});

/** Verbraucherin oder Verbraucher in EU/EWR: ohne Firma und ohne UID, Land in der EU oder im EWR */
const consumerInEu = computed(
    () =>
        props.eu_countries.includes(
            (addressForm.country ?? '').toUpperCase(),
        ) &&
        !(addressForm.company ?? '').trim() &&
        !(addressForm.vat_id ?? '').trim(),
);

const extrasForm = useForm({
    extra_sites: props.subscription.extras.sites,
    extra_domains: props.subscription.extras.domains,
});

const addressErrors = computed<Record<string, string>>(() => ({
    ...(addressForm.errors as Record<string, string>),
    ...(choice.errors as Record<string, string>),
}));

const chosenPlan = computed(() =>
    props.plans.find((p) => p.key === choice.plan),
);

/** Monatlich: Plan plus Zusätze, in CHF. */
const monthly = computed(
    () =>
        (chosenPlan.value?.price ?? 0) +
        Number(choice.extra_sites || 0) * props.extra.site +
        Number(choice.extra_domains || 0) * props.extra.domain,
);

const inTrial = computed(
    () => props.account.trial !== null && !props.account.trial.expired,
);

const addressLine = computed(() =>
    [
        props.address.company || props.address.name,
        props.address.street,
        `${props.address.postal_code} ${props.address.city}`.trim(),
    ]
        .filter((part) => part && part.trim() !== '')
        .join(', '),
);

function chf(amount: number): string {
    return `CHF ${amount.toFixed(2)}`;
}

function submitChoice(): void {
    choice
        .transform((data) => ({
            ...data,
            extra_sites: Number(data.extra_sites || 0),
            extra_domains: Number(data.extra_domains || 0),
            ...addressForm.data(),
        }))
        .post(props.urls.choose, { preserveScroll: true });
}

function changePlan(plan: BillingPlan): void {
    router.post(
        props.urls.change,
        { plan: plan.key },
        { preserveScroll: true },
    );
}

function switchMethod(method: BillingMethod): void {
    router.post(props.urls.method, { method }, { preserveScroll: true });
}

function openPortal(): void {
    router.post(props.urls.portal);
}

/**
 * CR-013: Die Rechnungsadresse speichert selbst — nur im laufenden Abo (vorher gehört sie zur Wahl des Plans) und
 * erst, wenn Strasse, PLZ, Ort, Land und Name oder Firma stehen; E-Mail und UID beim Verlassen.
 */
const addressSave = useAutosave(addressForm, {
    url: props.urls.address,
    method: 'post',
    onLeave: ['email', 'vat_id'],
    labels: {
        company: t('Firma'),
        name: t('Name'),
        street: t('Strasse und Nr.'),
        postal_code: t('PLZ'),
        city: t('Ort'),
        country: t('Land'),
        email: t('E-Mail für Rechnungen'),
        vat_id: t('UID / MwSt.-Nummer'),
    },
    enabled: () =>
        props.subscription.active &&
        props.account.can_bill &&
        [
            addressForm.street,
            addressForm.postal_code,
            addressForm.city,
            addressForm.country,
        ].every((v) => (v ?? '').trim() !== '') &&
        ((addressForm.name ?? '').trim() !== '' ||
            (addressForm.company ?? '').trim() !== ''),
});

function saveExtras(): void {
    extrasForm
        .transform((data) => ({
            extra_sites: Number(data.extra_sites || 0),
            extra_domains: Number(data.extra_domains || 0),
        }))
        .post(props.urls.extras, { preserveScroll: true });
}

function planButton(plan: BillingPlan): string {
    if (plan.pending) {
        return t('Vorgemerkt ab :date', { date: props.period_end ?? '' });
    }
    if (plan.action === 'upgrade') {
        return t('Heraufstufen, anteilig');
    }
    if (plan.action === 'downgrade') {
        return t('Herabstufen ab :date', { date: props.period_end ?? '' });
    }

    return t('Plan wählen');
}

function statusTone(row: AccountInvoiceRow): 'success' | 'warning' | 'grey' {
    if (row.status === 'paid') {
        return 'success';
    }

    return row.status === 'open' ? 'warning' : 'grey';
}

function statusLabel(row: AccountInvoiceRow): string {
    if (row.status === 'paid') {
        return t('bezahlt');
    }
    if (row.status === 'cancelled') {
        return t('storniert');
    }

    return row.overdue
        ? t('offen · Frist vorbei')
        : t('offen · :days Tage', { days: props.payment_days });
}
</script>

<template>
    <Head :title="$t('Konto · Abo und Rechnungen')" />
    <AccountFrame :account="account" :tab="tab">
        <Notice v-if="returned" tone="success" class="mb-3">{{
            $t(
                'Danke — Stripe bestätigt die Zahlung in wenigen Sekunden; dann erscheint Ihr Plan hier. Laden Sie die Seite gleich neu.',
            )
        }}</Notice>
        <Notice
            v-for="(message, key) in page.props.errors"
            :key="key"
            tone="danger"
            class="mb-3"
            >{{ message }}</Notice
        >
        <Notice v-if="!account.can_bill" tone="grey" class="mb-3">{{
            $t(
                'Plan, Abo und Rechnungen verwalten Besitzer und Abrechnung des Kontos.',
            )
        }}</Notice>
        <Notice v-if="subscription.moving_to" tone="lava" class="mb-3">{{
            $t(
                'Ihr Plan läuft auf der Instanz «:name» — der Umzug Ihrer Sites ist vorgemerkt. Bis dahin bleibt alles erreichbar.',
                { name: subscription.moving_to },
            )
        }}</Notice>

        <!-- Ohne Abo: Plan wählen -->
        <Card
            v-if="!subscription.active && account.can_bill"
            :title="$t('Plan wählen')"
            status="lava"
        >
            <p class="mb-3 text-[12.5px] text-basalt-light">
                <template v-if="inTrial">{{
                    $t(
                        'Nichts läuft automatisch an. Gewählt in der Testphase gilt der Plan sofort; bezahlt wird ab dem Ende der Testphase am :date.',
                        { date: account.trial?.ends ?? '' },
                    )
                }}</template>
                <template v-else>{{
                    $t(
                        'Nichts läuft automatisch an: Erst mit Ihrer Wahl beginnt das Abo — monatlich, jederzeit wechselbar.',
                    )
                }}</template>
            </p>
            <form novalidate @submit.prevent="submitChoice">
                <div
                    role="radiogroup"
                    :aria-label="$t('Plan')"
                    class="grid gap-3 md:grid-cols-3"
                >
                    <label
                        v-for="plan in plans"
                        :key="plan.key"
                        class="flex cursor-pointer flex-col rounded-lg border bg-white px-3.5 py-3"
                        :class="
                            choice.plan === plan.key
                                ? 'border-lava-dark ring-2 ring-lava/25'
                                : 'border-ash-light hover:border-ash'
                        "
                    >
                        <input
                            v-model="choice.plan"
                            type="radio"
                            name="plan"
                            :value="plan.key"
                            class="sr-only"
                        />
                        <span
                            class="text-[10px] font-semibold tracking-[.1em] text-basalt-light uppercase"
                            >{{ plan.label
                            }}<span v-if="choice.plan === plan.key">
                                · {{ $t('gewählt') }}</span
                            ></span
                        >
                        <span class="mt-1 text-[22px] font-bold"
                            >CHF {{ plan.price
                            }}<small
                                class="text-[12px] font-normal text-basalt-light"
                            >
                                / {{ $t('Monat') }}</small
                            ></span
                        >
                        <span class="mt-1 text-[12px] text-basalt-light">{{
                            $tc(':count Site|:count Sites', plan.sites)
                        }}</span>
                    </label>
                </div>
                <p
                    v-if="choice.errors.plan"
                    class="text-danger mt-2 text-[11.5px] font-semibold"
                    role="alert"
                >
                    {{ choice.errors.plan }}
                </p>

                <div class="mt-3 grid gap-x-4 sm:grid-cols-2">
                    <Field
                        v-model="choice.extra_sites"
                        type="number"
                        :label="$t('Zusatz-Sites')"
                        :help="
                            $t('CHF :price je Site und Monat', {
                                price: extra.site,
                            })
                        "
                        :error="choice.errors.extra_sites"
                        inputmode="numeric"
                    />
                    <Field
                        v-model="choice.extra_domains"
                        type="number"
                        :label="$t('Zusatzdomains')"
                        :help="
                            $t('CHF :price je Domain und Monat', {
                                price: extra.domain,
                            })
                        "
                        :error="choice.errors.extra_domains"
                        inputmode="numeric"
                        class="sm:mt-0!"
                    />
                </div>

                <fieldset class="mt-3">
                    <legend
                        class="mb-1 text-[10.5px] font-semibold tracking-[.08em] text-basalt-light uppercase"
                    >
                        {{ $t('Zahlungsart') }}
                    </legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label
                            class="flex items-start gap-2 rounded-lg border bg-white px-3 py-2.5 text-[12.5px]"
                            :class="[
                                choice.method === 'stripe'
                                    ? 'border-lava-dark ring-2 ring-lava/25'
                                    : 'border-ash-light',
                                stripe
                                    ? 'cursor-pointer'
                                    : 'cursor-not-allowed opacity-60',
                            ]"
                        >
                            <input
                                v-model="choice.method"
                                type="radio"
                                name="method"
                                value="stripe"
                                :disabled="!stripe"
                                class="mt-0.5 accent-lava-dark"
                            />
                            <span
                                ><b>{{ $t('Karte über Stripe') }}</b
                                ><span class="block text-basalt-light">{{
                                    stripe
                                        ? $t(
                                              'Monatlich abgebucht, Beleg per Mail.',
                                          )
                                        : $t('Noch nicht eingerichtet.')
                                }}</span></span
                            >
                        </label>
                        <label
                            class="flex cursor-pointer items-start gap-2 rounded-lg border bg-white px-3 py-2.5 text-[12.5px]"
                            :class="
                                choice.method === 'invoice'
                                    ? 'border-lava-dark ring-2 ring-lava/25'
                                    : 'border-ash-light'
                            "
                        >
                            <input
                                v-model="choice.method"
                                type="radio"
                                name="method"
                                value="invoice"
                                class="mt-0.5 accent-lava-dark"
                            />
                            <span
                                ><b>{{ $t('QR-Rechnung') }}</b
                                ><span class="block text-basalt-light">{{
                                    $t(
                                        'Monatlich als PDF mit QR-Zahlteil, zahlbar innert :days Tagen.',
                                        { days: payment_days },
                                    )
                                }}</span></span
                            >
                        </label>
                    </div>
                    <p
                        v-if="choice.errors.method"
                        class="text-danger mt-2 text-[11.5px] font-semibold"
                        role="alert"
                    >
                        {{ choice.errors.method }}
                    </p>
                </fieldset>

                <h3 class="mt-4 mb-1 text-[13px] font-semibold">
                    {{ $t('Rechnungsadresse') }}
                </h3>
                <div class="grid gap-x-4 sm:grid-cols-2">
                    <Field
                        v-model="addressForm.company"
                        :label="$t('Firma')"
                        optional
                        autocomplete="organization"
                        :error="addressErrors.company"
                    />
                    <Field
                        v-model="addressForm.name"
                        :label="$t('Name')"
                        autocomplete="name"
                        :error="addressErrors.name"
                        class="sm:mt-0!"
                    />
                    <Field
                        v-model="addressForm.street"
                        :label="$t('Strasse und Nr.')"
                        required
                        autocomplete="street-address"
                        :error="addressErrors.street"
                    />
                    <div class="grid grid-cols-[110px_1fr] gap-x-3">
                        <Field
                            v-model="addressForm.postal_code"
                            :label="$t('PLZ')"
                            required
                            autocomplete="postal-code"
                            :error="addressErrors.postal_code"
                            class="mt-2.5!"
                        />
                        <Field
                            v-model="addressForm.city"
                            :label="$t('Ort')"
                            required
                            autocomplete="address-level2"
                            :error="addressErrors.city"
                            class="mt-2.5!"
                        />
                    </div>
                    <Field :label="$t('Land')" :error="addressErrors.country">
                        <template #default="{ id, invalid }">
                            <CountrySelect
                                :id="id"
                                v-model="addressForm.country"
                                :countries="countries"
                                :invalid="invalid"
                            />
                        </template>
                    </Field>
                    <Field
                        v-model="addressForm.email"
                        type="email"
                        :label="$t('E-Mail für Rechnungen')"
                        optional
                        autocomplete="email"
                        :error="addressErrors.email"
                    />
                    <Field
                        v-model="addressForm.vat_id"
                        :label="$t('UID / MwSt.-Nummer')"
                        optional
                        :error="addressErrors.vat_id"
                    />
                </div>

                <div
                    v-if="consumerInEu"
                    class="mt-4 flex items-start gap-2 text-[12.5px]"
                >
                    <input
                        id="sofort-beginnen"
                        v-model="choice.immediate_start"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 accent-lava-dark"
                        :aria-invalid="
                            choice.errors.immediate_start ? 'true' : undefined
                        "
                        :aria-describedby="
                            choice.errors.immediate_start
                                ? 'sofort-beginnen-fehler'
                                : undefined
                        "
                    />
                    <label for="sofort-beginnen">
                        {{
                            $t(
                                'Ich verlange, dass Basalto sofort beginnt. Mir ist bekannt, dass ich bei einem Widerruf innert 14 Tagen den Anteil der bis dahin erbrachten Leistung bezahle.',
                            )
                        }}
                        <a
                            :href="withdrawal_url"
                            target="_blank"
                            rel="noopener"
                            class="font-semibold text-lava-dark underline"
                            >{{ $t('Widerrufsbelehrung') }}</a
                        >
                    </label>
                </div>
                <p
                    v-if="choice.errors.immediate_start"
                    id="sofort-beginnen-fehler"
                    class="text-danger mt-1 text-[12px]"
                >
                    {{ choice.errors.immediate_start }}
                </p>
                <div
                    class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-ash-light pt-3"
                >
                    <p class="text-[13px]">
                        {{ $t('Monatlich') }}
                        <b>{{ chf(monthly) }}</b>
                        <span
                            v-if="vat.rate"
                            class="text-[11.5px] text-basalt-light"
                        >
                            ·
                            {{
                                $t('inkl. :rate MwSt.', {
                                    rate: vat.rate,
                                })
                            }}</span
                        >
                    </p>
                    <Button type="submit" :disabled="choice.processing">{{
                        choice.method === 'stripe'
                            ? $t('Weiter zu Stripe')
                            : $t('Plan wählen')
                    }}</Button>
                </div>
            </form>
        </Card>

        <!-- Mit Abo: Abo und Rechnungen (Mockup) -->
        <div
            v-if="subscription.active || invoices.length > 0"
            class="grid gap-4 md:grid-cols-2"
            :class="subscription.active ? '' : 'mt-4'"
        >
            <Card
                v-if="subscription.active"
                :title="$t('Abo')"
                :sub="
                    $t(':plan · monatlich', {
                        plan: account.plan?.label ?? '—',
                    })
                "
            >
                <dl>
                    <PairRow :label="$t('Nächste Rechnung')">{{
                        subscription.next
                            ? `${subscription.next.date} · ${subscription.next.amount}`
                            : '—'
                    }}</PairRow>
                    <PairRow :label="$t('Zahlungsart')">
                        <span class="break-words"
                            >{{
                                subscription.card ?? subscription.method_label
                            }}
                            <template v-if="account.can_bill">
                                <template
                                    v-if="subscription.method === 'stripe'"
                                    >·
                                    <button
                                        type="button"
                                        class="text-lava-dark underline hover:text-basalt"
                                        @click="openPortal"
                                    >
                                        {{ $t('ändern') }}</button
                                    >·
                                    <button
                                        type="button"
                                        class="text-lava-dark underline hover:text-basalt"
                                        @click="switchMethod('invoice')"
                                    >
                                        {{ $t('QR-Rechnung') }}
                                    </button></template
                                >
                                <template v-else-if="stripe"
                                    >·
                                    <button
                                        type="button"
                                        class="text-lava-dark underline hover:text-basalt"
                                        @click="switchMethod('stripe')"
                                    >
                                        {{ $t('Karte über Stripe') }}
                                    </button></template
                                >
                            </template></span
                        >
                    </PairRow>
                    <PairRow :label="$t('Rechnungsadresse')">{{
                        addressLine || '—'
                    }}</PairRow>
                    <PairRow :label="$t('MwSt.')">{{
                        [
                            address.vat_id,
                            vat.rate
                                ? $t(':rate ausgewiesen', { rate: vat.rate })
                                : $t('ohne MwSt. (nicht pflichtig)'),
                        ]
                            .filter(Boolean)
                            .join(' · ')
                    }}</PairRow>
                    <PairRow
                        v-if="
                            subscription.extras.sites > 0 ||
                            subscription.extras.domains > 0
                        "
                        :label="$t('Zusätze')"
                        >{{
                            $t(
                                ':sites Zusatz-Site(s) · :domains Zusatzdomain(s)',
                                {
                                    sites: subscription.extras.sites,
                                    domains: subscription.extras.domains,
                                },
                            )
                        }}</PairRow
                    >
                </dl>
                <Notice v-if="subscription.pending" tone="lava" class="mt-2">{{
                    $t(
                        'Wechsel auf :plan ab :date vorgemerkt — bis dahin gilt Ihr bisheriger Plan.',
                        {
                            plan: subscription.pending.label,
                            date: subscription.pending.from ?? '',
                        },
                    )
                }}</Notice>
            </Card>

            <Card v-if="account.can_bill" :title="$t('Rechnungen')">
                <ul
                    v-if="invoices.length > 0"
                    class="divide-y divide-ash-light"
                >
                    <li
                        v-for="row in invoices"
                        :key="row.id"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2"
                    >
                        <span class="min-w-0 flex-[1_1_12rem]">
                            <span class="block font-mono text-[12.5px]">{{
                                row.number
                            }}</span>
                            <span class="block text-[11.5px] text-basalt-light"
                                >{{ row.date }} · {{ row.text
                                }}<template v-if="row.period">
                                    · {{ row.period }}</template
                                ></span
                            >
                        </span>
                        <span class="text-[12.5px] whitespace-nowrap">{{
                            row.amount
                        }}</span>
                        <StateTag :tone="statusTone(row)">{{
                            statusLabel(row)
                        }}</StateTag>
                        <a
                            :href="row.url"
                            target="_blank"
                            rel="noopener"
                            class="ml-auto inline-flex min-h-7 items-center rounded-md border border-ash bg-white px-2.5 text-[12px] font-semibold text-basalt hover:bg-paper"
                            >{{
                                row.qr ? $t('PDF mit QR-Rechnung') : $t('PDF')
                            }}</a
                        >
                    </li>
                </ul>
                <p v-else class="text-[12.5px] text-basalt-light">
                    {{ $t('Noch keine Rechnungen.') }}
                </p>
                <p class="mt-2 text-[11.5px] text-basalt-light">
                    {{
                        $t(
                            'PDF mit QR-Rechnung (Swiss QR Code). Eine offene Rechnung sperrt nichts — nach :days Tagen erinnern wir.',
                            { days: payment_days },
                        )
                    }}
                </p>
            </Card>
        </div>

        <!-- Mit Abo: Plan wechseln, Zusätze, Adresse -->
        <template v-if="subscription.active && account.can_bill">
            <h2 class="mt-5 mb-2 text-[13px] font-semibold">
                {{ $t('Plan wechseln') }}
            </h2>
            <div class="grid gap-3 md:grid-cols-3">
                <section
                    v-for="plan in plans"
                    :key="plan.key"
                    class="flex flex-col rounded-lg border bg-white px-3.5 py-3"
                    :class="
                        plan.current
                            ? 'border-lava-dark ring-2 ring-lava/20'
                            : 'border-ash-light'
                    "
                    :aria-label="plan.label"
                >
                    <p
                        class="text-[10px] font-semibold tracking-[.1em] text-basalt-light uppercase"
                    >
                        {{ plan.label
                        }}<span v-if="plan.current">
                            · {{ $t('Ihr Plan') }}</span
                        >
                    </p>
                    <p class="mt-1 text-[22px] font-bold">
                        CHF {{ plan.price
                        }}<small
                            class="text-[12px] font-normal text-basalt-light"
                        >
                            / {{ $t('Monat') }}</small
                        >
                    </p>
                    <p class="mt-1 text-[12px] text-basalt-light">
                        {{ $tc(':count Site|:count Sites', plan.sites) }} ·
                        {{ $t('Instanz «:name»', { name: plan.instance }) }}
                    </p>
                    <div class="mt-auto pt-3">
                        <Button
                            v-if="plan.current && subscription.pending"
                            size="sm"
                            variant="secondary"
                            @click="changePlan(plan)"
                            >{{ $t('Bleiben, Wechsel zurücknehmen') }}</Button
                        >
                        <span
                            v-else-if="plan.current"
                            class="text-success text-[12px] font-semibold"
                            >{{ $t('Aktuell') }}</span
                        >
                        <span
                            v-else-if="plan.pending"
                            class="text-[12px] font-semibold text-lava-dark"
                            >{{ planButton(plan) }}</span
                        >
                        <Button
                            v-else
                            size="sm"
                            :variant="
                                plan.action === 'upgrade'
                                    ? 'primary'
                                    : 'secondary'
                            "
                            @click="changePlan(plan)"
                            >{{ planButton(plan) }}</Button
                        >
                    </div>
                </section>
            </div>
            <p class="mt-2 text-[11.5px] text-basalt-light">
                {{
                    $t(
                        'Heraufstufen wirkt sofort und anteilig (bei der QR-Rechnung ab der nächsten Rechnung), Herabstufen ab der nächsten Periode.',
                    )
                }}
            </p>

            <div
                class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]"
            >
                <Card :title="$t('Zusätze')">
                    <form novalidate @submit.prevent="saveExtras">
                        <div class="grid gap-x-4 sm:grid-cols-2">
                            <Field
                                v-model="extrasForm.extra_sites"
                                type="number"
                                :label="$t('Zusatz-Sites')"
                                :help="
                                    $t('CHF :price je Site und Monat', {
                                        price: extra.site,
                                    })
                                "
                                :error="extrasForm.errors.extra_sites"
                                inputmode="numeric"
                            />
                            <Field
                                v-model="extrasForm.extra_domains"
                                type="number"
                                :label="$t('Zusatzdomains')"
                                :help="
                                    $t('CHF :price je Domain und Monat', {
                                        price: extra.domain,
                                    })
                                "
                                :error="extrasForm.errors.extra_domains"
                                inputmode="numeric"
                                class="sm:mt-0!"
                            />
                        </div>
                        <div class="mt-3">
                            <Button
                                type="submit"
                                size="sm"
                                variant="secondary"
                                :disabled="extrasForm.processing"
                                >{{ $t('Zusätze speichern') }}</Button
                            >
                        </div>
                    </form>
                </Card>
                <Card :title="$t('Rechnungsadresse')">
                    <form novalidate @submit.prevent="addressSave.flush()">
                        <div class="grid gap-x-4 sm:grid-cols-2">
                            <Field
                                v-model="addressForm.company"
                                :label="$t('Firma')"
                                optional
                                autocomplete="organization"
                                :error="addressForm.errors.company"
                                :saved="addressSave.saved('company')"
                            />
                            <Field
                                v-model="addressForm.name"
                                :label="$t('Name')"
                                autocomplete="name"
                                :error="addressForm.errors.name"
                                :saved="addressSave.saved('name')"
                                class="sm:mt-0!"
                            />
                            <Field
                                v-model="addressForm.street"
                                :label="$t('Strasse und Nr.')"
                                required
                                autocomplete="street-address"
                                :error="addressForm.errors.street"
                                :saved="addressSave.saved('street')"
                            />
                            <div class="grid grid-cols-[110px_1fr] gap-x-3">
                                <Field
                                    v-model="addressForm.postal_code"
                                    :label="$t('PLZ')"
                                    required
                                    autocomplete="postal-code"
                                    :error="addressForm.errors.postal_code"
                                    :saved="addressSave.saved('postal_code')"
                                    class="mt-2.5!"
                                />
                                <Field
                                    v-model="addressForm.city"
                                    :label="$t('Ort')"
                                    required
                                    autocomplete="address-level2"
                                    :error="addressForm.errors.city"
                                    :saved="addressSave.saved('city')"
                                    class="mt-2.5!"
                                />
                            </div>
                            <Field
                                :label="$t('Land')"
                                :error="addressForm.errors.country"
                            >
                                <template #default="{ id, invalid }">
                                    <CountrySelect
                                        :id="id"
                                        v-model="addressForm.country"
                                        :countries="countries"
                                        :invalid="invalid"
                                    />
                                </template>
                            </Field>
                            <Field
                                v-model="addressForm.email"
                                type="email"
                                :label="$t('E-Mail für Rechnungen')"
                                optional
                                autocomplete="email"
                                :error="addressForm.errors.email"
                                :saved="addressSave.saved('email')"
                                :waiting="addressSave.waiting('email')"
                                v-bind="addressSave.leave('email')"
                            />
                            <Field
                                v-model="addressForm.vat_id"
                                :label="$t('UID / MwSt.-Nummer')"
                                optional
                                :error="addressForm.errors.vat_id"
                                :saved="addressSave.saved('vat_id')"
                                :waiting="addressSave.waiting('vat_id')"
                                v-bind="addressSave.leave('vat_id')"
                            />
                        </div>
                        <p
                            v-if="addressSave.status.value === 'pending'"
                            class="mt-3 text-[12px] text-basalt-light"
                        >
                            {{
                                $t(
                                    'Noch nicht gespeichert — es fehlen Strasse, PLZ, Ort, Land oder Name.',
                                )
                            }}
                        </p>
                    </form>
                </Card>
            </div>
        </template>
    </AccountFrame>
</template>
