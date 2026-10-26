<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '../components/Button.vue';
import { t } from '../i18n';
import Card from '../components/Card.vue';
import Notice from '../components/Notice.vue';
import PageHeader from '../components/PageHeader.vue';
import PairRow from '../components/PairRow.vue';
import StateTag from '../components/StateTag.vue';
import VendorAccount, {
    type VendorAccountData,
    type VendorInfo,
} from '../components/VendorAccount.vue';
import type { BackendProps } from '../types';

/**
 * Verwaltung › Lizenz (K-18, G-02, G-05; Mockup 19): Stand der Installation, Angaben des Schlüssels, Schlüssel prüfen
 * und eintragen. Geprüft wird offline gegen den Produktschlüssel im Kern — kein Aufruf nach Hause, nie eine Sperre.
 * TB 15 Scheibe 4 (CR-004 P-06): darunter das Kundenkonto beim Hersteller (nur Betreiber).
 */
type KeyInfo = {
    id: string;
    plan: string;
    licensee: string;
    domains: string[];
    seats: number | null;
    sites: number | null;
    issued_at: string | null;
    updates_until: string | null;
    updates_days: number | null;
    updates_expired: boolean;
    agency: string | null;
    customer: string | null;
    white_label: boolean;
};

const props = defineProps<{
    status: {
        plan: string;
        registered: boolean;
        compliant: boolean;
        label: string;
        users: number;
        sites: number;
        hints: string[];
        banner_from: string | null;
        banner_active: boolean;
        unregistered_since: string | null;
    };
    key: KeyInfo | null;
    source: 'backend' | 'env' | null;
    limits: { users: number | null; sites: number | null };
    can_edit: boolean;
    urls: { check: string; store: string; destroy: string; contract: string };
    connection: VendorInfo | null;
    vendor_account?: VendorAccountData | null;
}>();

const vendor = usePage<BackendProps>().props.backend.vendor;
const form = useForm({ key: '' });
const checked = ref<KeyInfo | null>(null);
const checkError = ref('');
const checking = ref(false);

const tone = computed(() =>
    !props.status.compliant
        ? 'danger'
        : props.status.registered
          ? 'success'
          : 'grey',
);

function limit(value: number, max: number | null): string {
    return `${value} / ${max ?? t('unbegrenzt')}`;
}

async function check(): Promise<void> {
    checked.value = null;
    checkError.value = '';
    form.clearErrors();
    checking.value = true;
    try {
        const token =
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                ?.content ?? '';
        const response = await fetch(props.urls.check, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                ),
            },
            body: JSON.stringify({ key: form.key }),
        });
        const data = (await response.json()) as {
            valid?: boolean;
            key?: KeyInfo;
            message?: string;
        };
        if (response.ok && data.valid && data.key) {
            checked.value = data.key;
        } else {
            checkError.value =
                data.message ?? t('Der Schlüssel liess sich nicht prüfen.');
        }
    } catch {
        checkError.value = t('Der Schlüssel liess sich nicht prüfen.');
    } finally {
        checking.value = false;
    }
}

function store(): void {
    form.put(props.urls.store, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            checked.value = null;
        },
    });
}

function remove(): void {
    router.delete(props.urls.destroy, { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('Lizenz')" />
    <PageHeader :title="$t('Lizenz')" :crumb="$t('Verwaltung · Lizenz')">
        <template #lead>
            {{
                $t(
                    'Geprüft offline gegen den öffentlichen Schlüssel im Kern-Paket —',
                )
            }}
            <b>{{ $t('kein Aufruf nach Hause') }}</b
            >.
            {{
                $t('Stündlich durch den Scheduler; die Konsole zeigt dasselbe')
            }}
            (<code class="font-mono">php artisan basalto:license</code>).
        </template>
    </PageHeader>

    <Notice
        v-if="!status.compliant"
        tone="danger"
        class="mb-4"
        :title="
            status.unregistered_since
                ? $t('Unregistriert seit :date', {
                      date: status.unregistered_since,
                  })
                : $t('Unregistriert')
        "
    >
        <span v-for="(hint, i) in status.hints" :key="i" class="block">{{
            hint
        }}</span>
        <span v-if="status.banner_from" class="mt-1 block">
            {{
                status.banner_active
                    ? $t('Das Frontend zeigt seit :date eine Fussleiste.', {
                          date: status.banner_from,
                      })
                    : $t(
                          'Ohne gültigen Schlüssel zeigt das Frontend ab :date eine Fussleiste.',
                          { date: status.banner_from },
                      )
            }}
            {{ $t('Alles läuft weiter — nichts wird gesperrt.') }}
        </span>
    </Notice>

    <div class="grid gap-4 lg:grid-cols-2">
        <Card
            :title="
                key
                    ? $t(':plan-Lizenz', { plan: key.plan })
                    : $t('Free · ohne Schlüssel')
            "
            :class="tone === 'danger' ? 'border-danger' : ''"
        >
            <p class="mb-3 flex flex-wrap gap-2">
                <StateTag :tone="status.registered ? 'success' : 'grey'">{{
                    status.registered
                        ? $t('registriert')
                        : $t('nicht registriert')
                }}</StateTag>
                <StateTag :tone="status.compliant ? 'success' : 'danger'">{{
                    status.compliant ? $t('regelkonform') : $t('Verstoss')
                }}</StateTag>
            </p>
            <dl>
                <PairRow v-if="key" :label="$t('Lizenznehmer')">{{
                    key.licensee
                }}</PairRow>
                <PairRow v-if="key?.agency" :label="$t('Agentur')"
                    >{{ key.agency
                    }}{{
                        key.customer
                            ? ` · ${$t('Kunde :customer', { customer: key.customer })}`
                            : ''
                    }}{{ key.white_label ? ' · White-Label' : '' }}</PairRow
                >
                <PairRow v-if="key" :label="$t('Domains')">{{
                    key.domains.length > 0
                        ? key.domains.join(', ')
                        : $t('alle Sites der Agentur')
                }}</PairRow>
                <PairRow :label="$t('Benutzer')">{{
                    limit(status.users, limits.users)
                }}</PairRow>
                <PairRow :label="$t('Sites')">{{
                    limit(status.sites, limits.sites)
                }}</PairRow>
                <PairRow v-if="key" :label="$t('Ausgestellt')">{{
                    key.issued_at
                }}</PairRow>
                <PairRow :label="$t('Updates')">
                    <template v-if="key?.updates_until">
                        {{ $t('bis :date', { date: key.updates_until }) }}
                        <span
                            :class="
                                key.updates_expired
                                    ? 'text-warning'
                                    : 'text-success'
                            "
                            >·
                            {{
                                key.updates_expired
                                    ? $t(
                                          'Anspruch abgelaufen — die Software läuft weiter',
                                      )
                                    : $tc(
                                          'noch :count Tag|noch :count Tage',
                                          key.updates_days ?? 0,
                                      )
                            }}</span
                        >
                    </template>
                    <template v-else>{{
                        $t('per Composer, ohne Anspruch')
                    }}</template>
                </PairRow>
                <PairRow v-if="key" :label="$t('Schlüssel-ID')"
                    ><code class="font-mono">{{ key.id }}</code></PairRow
                >
                <PairRow v-if="source" :label="$t('Eingetragen')">{{
                    source === 'backend'
                        ? $t('im Backend')
                        : $t('in .env (BASALTO_LICENSE_KEY)')
                }}</PairRow>
            </dl>
            <ul
                v-if="status.compliant && status.hints.length > 0"
                class="text-ash-dark mt-3 grid gap-1 text-[12.5px]"
            >
                <li v-for="(hint, i) in status.hints" :key="i">{{ hint }}</li>
            </ul>
            <div class="mt-3 flex flex-wrap gap-2">
                <a
                    :href="vendor.license_url"
                    class="inline-flex min-h-8 items-center rounded-md border border-ash-light px-3 text-[12.5px] font-semibold hover:border-basalt"
                    rel="noopener"
                    target="_blank"
                    >{{
                        key
                            ? $t('Verlängern auf :vendor', {
                                  vendor: vendor.host,
                              })
                            : $t('Lizenz auf :vendor', { vendor: vendor.host })
                    }}</a
                >
                <Link
                    :href="urls.contract"
                    class="inline-flex min-h-8 items-center px-1 text-[12.5px] underline"
                    >{{ $t('Lizenzvertrag lesen') }}</Link
                >
                <Button
                    v-if="can_edit && source === 'backend'"
                    size="sm"
                    variant="secondary"
                    @click="remove"
                    >{{ $t('Schlüssel entfernen') }}</Button
                >
            </div>
        </Card>

        <Card
            :title="$t('Schlüssel eintragen')"
            :sub="$t('oder in .env als BASALTO_LICENSE_KEY')"
        >
            <form class="grid gap-2" @submit.prevent="store">
                <label
                    for="lizenz-schluessel"
                    class="text-[12px] font-semibold"
                    >{{ $t('Schlüssel') }}</label
                >
                <textarea
                    id="lizenz-schluessel"
                    v-model="form.key"
                    rows="4"
                    spellcheck="false"
                    autocomplete="off"
                    class="border-ash-dark w-full rounded-sm border px-2 py-1.5 font-mono text-[12px] break-all"
                    :class="
                        form.errors.key || checkError ? 'border-danger' : ''
                    "
                    :aria-invalid="!!(form.errors.key || checkError)"
                    aria-describedby="lizenz-hilfe"
                    :disabled="!can_edit"
                    placeholder="BSL1.…"
                ></textarea>
                <p
                    id="lizenz-hilfe"
                    class="text-[12px]"
                    :class="
                        form.errors.key || checkError
                            ? 'text-danger'
                            : 'text-ash-dark'
                    "
                    role="status"
                >
                    <template v-if="form.errors.key">{{
                        form.errors.key
                    }}</template>
                    <template v-else-if="checkError">{{ checkError }}</template>
                    <template v-else-if="checked">{{
                        $t(
                            'Gültig: :plan-Lizenz für :licensee:domains, Updates bis :until.',
                            {
                                plan: checked.plan,
                                licensee: checked.licensee,
                                domains:
                                    checked.domains.length > 0
                                        ? ` (${checked.domains.join(', ')})`
                                        : '',
                                until:
                                    checked.updates_until ?? $t('unbegrenzt'),
                            },
                        )
                    }}</template>
                    <template v-else
                        >{{ $t('Dieselbe Prüfung wie') }}
                        <code class="font-mono"
                            >php artisan basalto:license --check=…</code
                        >.</template
                    >
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        variant="secondary"
                        type="button"
                        :disabled="form.key.trim() === '' || checking"
                        @click="check"
                        >{{ $t('Jetzt prüfen') }}</Button
                    >
                    <Button
                        type="submit"
                        :disabled="
                            !can_edit ||
                            form.key.trim() === '' ||
                            form.processing
                        "
                        >{{ $t('Eintragen') }}</Button
                    >
                </div>
                <p v-if="!can_edit" class="text-ash-dark text-[12px]">
                    {{ $t('Nur Betreiber tragen Schlüssel ein.') }}
                </p>
            </form>
        </Card>
    </div>

    <VendorAccount
        v-if="connection"
        :vendor="connection"
        :account="vendor_account"
    />
</template>
