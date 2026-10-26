<script setup lang="ts">
import { Deferred, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from './Button.vue';
import Card from './Card.vue';
import Field from './Field.vue';
import Notice from './Notice.vue';
import PairRow from './PairRow.vue';
import StateTag from './StateTag.vue';

/**
 * Verwaltung › Lizenz › Kundenkonto beim Hersteller (TB 15 Scheibe 4, CR-004 P-06): nicht verbunden → E-Mail → Code
 * aus der Mail → verbunden. Verbunden: Lizenzen mit «Diesen Schlüssel verwenden», Rechnungen mit PDF, Marktplatz-Käufe,
 * «Aktualisieren» und «Trennen». Die Daten kommen aufgeschoben (`vendor_account`) — die Seite wartet nie auf den
 * Hersteller; signiert geprüft, sonst sichtbar «unsigniert» bzw. ein Fehler statt Daten.
 */
export type VendorInfo = {
    host: string;
    connected: boolean;
    email: string | null;
    customer_name: string | null;
    connected_at: string | null;
    pending_email: string | null;
    urls: {
        code: string;
        restart: string;
        connect: string;
        disconnect: string;
        use_key: string;
        refresh: string;
    };
};

export type VendorAccountData = {
    ok: boolean;
    signed: boolean;
    error: string | null;
    revoked: boolean;
    fetched_at: string | null;
    customer: { name: string; email: string } | null;
    licenses: {
        key_id: string;
        plan: string;
        licensee: string;
        client: string | null;
        domains: string[];
        issued_at: string | null;
        updates_until: string | null;
        updates_expired: boolean;
        status: string;
        status_label: string;
        can_use: boolean;
        installed: boolean;
    }[];
    invoices: {
        number: string;
        issued_at: string | null;
        due_at: string | null;
        total: string;
        status: string;
        status_label: string;
        pdf_url: string | null;
    }[];
    purchases: {
        number: string | null;
        title: string;
        kind: string;
        amount: string;
        status: string;
        status_label: string;
        paid_at: string | null;
        updates_until: string | null;
        installation: string | null;
        receipt_url: string | null;
    }[];
};

const props = defineProps<{
    vendor: VendorInfo;
    account?: VendorAccountData | null;
}>();

const page = usePage<{ errors: Record<string, string> }>();
const keyError = computed(() => page.props.errors?.vendor ?? '');
const emailForm = useForm({ email: '' });
const codeForm = useForm({ code: '' });

const tones: Record<string, 'success' | 'lava' | 'grey' | 'danger'> = {
    active: 'success',
    paid: 'success',
    open: 'lava',
    pending: 'lava',
    cancelled: 'grey',
    revoked: 'danger',
    refunded: 'danger',
};

function requestCode(): void {
    emailForm.post(props.vendor.urls.code, { preserveScroll: true });
}

function connect(): void {
    codeForm.post(props.vendor.urls.connect, {
        preserveScroll: true,
        onSuccess: () => codeForm.reset(),
    });
}

function restart(): void {
    router.delete(props.vendor.urls.restart, { preserveScroll: true });
}

function refresh(): void {
    router.get(
        props.vendor.urls.refresh,
        { neu: 1 },
        { preserveScroll: true, only: ['vendor_account'] },
    );
}

function disconnect(): void {
    router.delete(props.vendor.urls.disconnect, { preserveScroll: true });
}

function useKey(keyId: string): void {
    router.put(
        props.vendor.urls.use_key,
        { key_id: keyId },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Card
        :title="$t('Kundenkonto beim Hersteller')"
        :status="vendor.connected ? 'success' : null"
        class="mt-4"
    >
        <template #sub>
            <span>{{
                $t(
                    'Lizenzen, Rechnungen und Marktplatz-Käufe von :host — direkt hier, signiert geholt.',
                    { host: vendor.host },
                )
            }}</span>
        </template>

        <!-- 1 · nicht verbunden: E-Mail -->
        <form
            v-if="!vendor.connected && !vendor.pending_email"
            class="grid max-w-xl gap-2"
            @submit.prevent="requestCode"
        >
            <p class="text-[12.5px] text-basalt-light">
                {{
                    $t(
                        'Die E-Mail-Adresse Ihres Kundenkontos bei :host — dorthin geht ein Code. Die Lizenzprüfung bleibt offline; verbunden wird nur für diese Übersicht.',
                        { host: vendor.host },
                    )
                }}
            </p>
            <Field
                v-model="emailForm.email"
                :label="$t('E-Mail')"
                type="email"
                autocomplete="email"
                inputmode="email"
                required
                :error="emailForm.errors.email"
            />
            <div class="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    :disabled="
                        emailForm.email.trim() === '' || emailForm.processing
                    "
                    >{{ $t('Code anfordern') }}</Button
                >
            </div>
        </form>

        <!-- 2 · Code eingeben -->
        <form
            v-else-if="!vendor.connected"
            class="grid max-w-xl gap-2"
            @submit.prevent="connect"
        >
            <p class="text-[12.5px] text-basalt-light">
                {{
                    $t(
                        'Code aus der Mail an :email eingeben (sechs Ziffern).',
                        { email: vendor.pending_email ?? '' },
                    )
                }}
            </p>
            <p class="text-[12.5px] text-basalt-light">
                {{
                    $t(
                        'Noch kein Kundenkonto? Es entsteht mit diesem Code. Keine Mail? Spam-Ordner prüfen oder die Adresse korrigieren.',
                    )
                }}
            </p>
            <Field
                v-model="codeForm.code"
                :label="$t('Code')"
                autocomplete="one-time-code"
                inputmode="numeric"
                :maxlength="7"
                mono
                required
                :error="codeForm.errors.code"
            />
            <div class="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    :disabled="
                        codeForm.code.trim() === '' || codeForm.processing
                    "
                    >{{ $t('Verbinden') }}</Button
                >
                <Button variant="link" @click="restart">{{
                    $t('Andere Adresse')
                }}</Button>
            </div>
        </form>

        <!-- 3 · verbunden -->
        <div v-else class="grid gap-3">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <dl class="min-w-0 flex-1 basis-64">
                    <PairRow :label="$t('Kundenkonto')"
                        >{{ vendor.customer_name || vendor.email
                        }}<span
                            v-if="vendor.customer_name"
                            class="block font-normal text-basalt-light"
                            >{{ vendor.email }}</span
                        ></PairRow
                    >
                    <PairRow :label="$t('Verbunden seit')">{{
                        vendor.connected_at
                    }}</PairRow>
                </dl>
                <div class="flex flex-wrap gap-2">
                    <Button size="sm" variant="secondary" @click="refresh">{{
                        $t('Aktualisieren')
                    }}</Button>
                    <Button size="sm" variant="danger" @click="disconnect">{{
                        $t('Trennen')
                    }}</Button>
                </div>
            </div>

            <Notice v-if="keyError" tone="danger">{{ keyError }}</Notice>

            <Deferred data="vendor_account">
                <template #fallback>
                    <div
                        class="grid gap-2"
                        :aria-label="$t('Kundenkonto lädt …')"
                        aria-busy="true"
                    >
                        <div
                            v-for="i in 3"
                            :key="i"
                            class="h-12 animate-pulse rounded-md border border-ash-light bg-paper motion-reduce:animate-none"
                        ></div>
                    </div>
                </template>

                <template v-if="account">
                    <Notice
                        v-if="!account.ok"
                        :tone="account.revoked ? 'warning' : 'danger'"
                        :title="$t('Keine Daten vom Hersteller')"
                        >{{ account.error }}</Notice
                    >
                    <div v-else class="grid gap-4">
                        <p
                            class="flex flex-wrap items-center gap-2 text-[12px] text-basalt-light"
                        >
                            <StateTag
                                :tone="account.signed ? 'success' : 'warning'"
                                >{{
                                    account.signed
                                        ? $t('signiert')
                                        : $t('unsigniert')
                                }}</StateTag
                            >
                            <span v-if="account.fetched_at">{{
                                $t('Stand :date', { date: account.fetched_at })
                            }}</span>
                        </p>
                        <Notice v-if="!account.signed" tone="warning">{{
                            $t(
                                'Der Hersteller hat die Antwort nicht signiert — Angaben mit Vorsicht lesen. Schlüssel prüft die Installation beim Eintragen trotzdem selbst.',
                            )
                        }}</Notice>

                        <section aria-labelledby="konto-lizenzen">
                            <h3
                                id="konto-lizenzen"
                                class="mb-1.5 text-[12px] font-semibold tracking-wide text-basalt-light uppercase"
                            >
                                {{ $t('Lizenzen') }}
                            </h3>
                            <p
                                v-if="account.licenses.length === 0"
                                class="text-[12.5px] text-basalt-light"
                            >
                                {{
                                    $t('Keine Lizenzen in diesem Kundenkonto.')
                                }}
                            </p>
                            <ul v-else class="grid gap-2">
                                <li
                                    v-for="license in account.licenses"
                                    :key="license.key_id"
                                    class="flex flex-wrap items-start justify-between gap-2 rounded-md border border-ash-light px-3 py-2"
                                >
                                    <div class="min-w-0 flex-1 basis-60">
                                        <p
                                            class="flex flex-wrap items-center gap-1.5 text-[13px] font-semibold"
                                        >
                                            {{ license.plan }} ·
                                            {{
                                                license.client ??
                                                license.licensee
                                            }}
                                            <StateTag
                                                :tone="
                                                    tones[license.status] ??
                                                    'grey'
                                                "
                                                >{{
                                                    license.status_label
                                                }}</StateTag
                                            >
                                            <StateTag
                                                v-if="license.installed"
                                                tone="success"
                                                >{{
                                                    $t('eingetragen')
                                                }}</StateTag
                                            >
                                        </p>
                                        <p
                                            class="text-[12px] break-words text-basalt-light"
                                        >
                                            {{
                                                license.domains.length > 0
                                                    ? license.domains.join(', ')
                                                    : $t(
                                                          'alle Sites der Agentur',
                                                      )
                                            }}
                                            ·
                                            <span
                                                :class="
                                                    license.updates_expired
                                                        ? 'text-warning'
                                                        : ''
                                                "
                                                >{{
                                                    $t('Updates bis :date', {
                                                        date:
                                                            license.updates_until ??
                                                            '—',
                                                    })
                                                }}</span
                                            >
                                            ·
                                            <code class="font-mono">{{
                                                license.key_id
                                            }}</code>
                                        </p>
                                    </div>
                                    <Button
                                        v-if="license.can_use"
                                        size="sm"
                                        variant="secondary"
                                        @click="useKey(license.key_id)"
                                        >{{
                                            $t('Diesen Schlüssel verwenden')
                                        }}</Button
                                    >
                                </li>
                            </ul>
                        </section>

                        <section aria-labelledby="konto-rechnungen">
                            <h3
                                id="konto-rechnungen"
                                class="mb-1.5 text-[12px] font-semibold tracking-wide text-basalt-light uppercase"
                            >
                                {{ $t('Rechnungen') }}
                            </h3>
                            <p
                                v-if="account.invoices.length === 0"
                                class="text-[12.5px] text-basalt-light"
                            >
                                {{ $t('Keine Rechnungen.') }}
                            </p>
                            <ul v-else class="grid gap-1">
                                <li
                                    v-for="invoice in account.invoices"
                                    :key="invoice.number"
                                    class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-b border-ash-light py-1.5 text-[12.5px] last:border-b-0"
                                >
                                    <span
                                        class="flex flex-wrap items-center gap-1.5"
                                    >
                                        <b class="font-mono">{{
                                            invoice.number
                                        }}</b>
                                        <span class="text-basalt-light">{{
                                            invoice.issued_at
                                        }}</span>
                                        <StateTag
                                            :tone="
                                                tones[invoice.status] ?? 'grey'
                                            "
                                            >{{
                                                invoice.status_label
                                            }}</StateTag
                                        >
                                    </span>
                                    <span
                                        class="flex flex-wrap items-center gap-3"
                                    >
                                        <b>{{ invoice.total }}</b>
                                        <a
                                            v-if="invoice.pdf_url"
                                            :href="invoice.pdf_url"
                                            class="text-lava-dark underline hover:text-basalt"
                                            rel="noopener"
                                            >{{ $t('PDF') }}</a
                                        >
                                    </span>
                                </li>
                            </ul>
                        </section>

                        <section
                            v-if="account.purchases.length > 0"
                            aria-labelledby="konto-kaeufe"
                        >
                            <h3
                                id="konto-kaeufe"
                                class="mb-1.5 text-[12px] font-semibold tracking-wide text-basalt-light uppercase"
                            >
                                {{ $t('Käufe im Marktplatz') }}
                            </h3>
                            <ul class="grid gap-1">
                                <li
                                    v-for="(purchase, i) in account.purchases"
                                    :key="purchase.number ?? `kauf-${i}`"
                                    class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-b border-ash-light py-1.5 text-[12.5px] last:border-b-0"
                                >
                                    <span class="min-w-0 flex-1 basis-56">
                                        <b>{{ purchase.title }}</b>
                                        <span class="text-basalt-light">
                                            · {{ purchase.kind }}
                                            <template v-if="purchase.paid_at"
                                                >·
                                                {{
                                                    $t('gekauft am :date', {
                                                        date: purchase.paid_at,
                                                    })
                                                }}</template
                                            >
                                            <template
                                                v-if="purchase.updates_until"
                                                >·
                                                {{
                                                    $t('Updates bis :date', {
                                                        date: purchase.updates_until,
                                                    })
                                                }}</template
                                            >
                                            <template
                                                v-if="purchase.installation"
                                                >·
                                                {{
                                                    $t('für :installation', {
                                                        installation:
                                                            purchase.installation,
                                                    })
                                                }}</template
                                            >
                                        </span>
                                    </span>
                                    <span
                                        class="flex flex-wrap items-center gap-3"
                                    >
                                        <StateTag
                                            :tone="
                                                tones[purchase.status] ?? 'grey'
                                            "
                                            >{{
                                                purchase.status_label
                                            }}</StateTag
                                        >
                                        <b>{{ purchase.amount }}</b>
                                        <a
                                            v-if="purchase.receipt_url"
                                            :href="purchase.receipt_url"
                                            class="text-lava-dark underline hover:text-basalt"
                                            rel="noopener"
                                            >{{ $t('Quittung') }}</a
                                        >
                                    </span>
                                </li>
                            </ul>
                        </section>
                    </div>
                </template>
            </Deferred>
        </div>
    </Card>
</template>
