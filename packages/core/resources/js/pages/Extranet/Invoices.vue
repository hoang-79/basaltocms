<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import Button from '../../components/Button.vue';
import EmptyState from '../../components/EmptyState.vue';
import Field from '../../components/Field.vue';
import Notice from '../../components/Notice.vue';
import PageHeader from '../../components/PageHeader.vue';
import StateTag from '../../components/StateTag.vue';
import Table from '../../components/Table.vue';
import { t } from '../../i18n';
import { intlLocale } from '../../support';
import type { BackendProps } from '../../types';
import type { InvoiceRow, InvoicesProps } from '../../types-extranet';

/**
 * Rechnungen im Hersteller-Extranet (CR-004 P-12): SaaS-Abos und Lizenzverkauf in einer Liste, gefiltert über Chips
 * (alle · offen · überfällig · bezahlt). Je Zeile PDF, Zahlungseingang verbuchen, Erinnerung, erneut senden und
 * «Gutschrift…» — das Formular dazu steht ab 1024 px rechts, sonst unter der Tabelle. Darunter die letzten
 * Gutschriften mit eigener Nummer und PDF. Welche Aktion erlaubt ist, entscheidet der Server über die URLs.
 */
const props = defineProps<InvoicesProps>();

const page = usePage<BackendProps>();
const errors = computed<Record<string, string>>(
    () => (page.props.errors ?? {}) as Record<string, string>,
);

/** Fehler, die keinem Feld des Gutschrift-Formulars gehören (z. B. «Rechnung … ist nicht offen.»). */
const generalErrors = computed(() =>
    Object.entries(errors.value)
        .filter(([key]) => key !== 'amount' && key !== 'reason')
        .map(([, message]) => message),
);

const chips = computed(() => [
    { value: null as string | null, label: t('Alle') },
    ...props.filters.map((f) => ({
        value: f.value as string | null,
        label: f.label,
    })),
]);

function applyFilter(filter: string | null): void {
    router.get(props.urls.index, filter ? { filter } : {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

const statusTone = (
    row: InvoiceRow,
): 'danger' | 'warning' | 'success' | 'grey' => {
    if (row.status === 'paid') {
        return 'success';
    }
    if (row.status === 'cancelled') {
        return 'grey';
    }

    return row.overdue ? 'danger' : 'warning';
};

const busy = ref<string | null>(null);
const keyOf = (row: InvoiceRow): string => `${row.source}-${row.id}`;

/** Eine Aktion per POST — mit Bestätigung, wenn eine Frage mitkommt. */
function act(row: InvoiceRow, url: string | null, question?: string): void {
    if (url === null || (question && !window.confirm(question))) {
        return;
    }
    busy.value = keyOf(row);
    router.post(
        url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = null;
            },
        },
    );
}

function markPaid(row: InvoiceRow): void {
    act(
        row,
        row.urls.paid,
        t('Zahlungseingang für :number verbuchen?', { number: row.number }),
    );
}

function remind(row: InvoiceRow): void {
    act(
        row,
        row.urls.remind,
        t('Erinnerung zu :number an :email senden?', {
            number: row.number,
            email: row.email,
        }),
    );
}

function resend(row: InvoiceRow): void {
    act(row, row.urls.send);
}

/** Betrag in Rappen/Cent als Währung der Rechnung. */
function money(cents: number, currency: string): string {
    try {
        return new Intl.NumberFormat(intlLocale(), {
            style: 'currency',
            currency,
        }).format(cents / 100);
    } catch {
        return `${currency} ${(cents / 100).toFixed(2)}`;
    }
}

const crediting = ref<InvoiceRow | null>(null);
const form = useForm({ amount: '', reason: '' });
const aside = ref<HTMLElement | null>(null);

async function openCredit(row: InvoiceRow): Promise<void> {
    crediting.value = row;
    form.reset();
    form.clearErrors();
    form.amount = (row.credit_left / 100).toFixed(2);
    await nextTick();
    if (window.matchMedia('(max-width: 1023px)').matches) {
        aside.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    aside.value?.querySelector<HTMLInputElement>('input')?.focus();
}

function closeCredit(): void {
    crediting.value = null;
    form.reset();
    form.clearErrors();
}

function submitCredit(): void {
    const url = crediting.value?.urls.credit;
    if (!url) {
        return;
    }
    form.post(url, {
        preserveScroll: true,
        onSuccess: () => closeCredit(),
    });
}

const linkClass =
    'inline-flex items-center px-1 py-[5px] text-xs font-semibold text-lava-dark underline hover:text-basalt';
</script>

<template>
    <Head :title="$t('Rechnungen')" />
    <PageHeader
        :title="$t('Rechnungen')"
        :crumb="$t('Extranet › Rechnungen')"
        :lead="
            $t(
                'Rechnungen aus SaaS-Abos und Lizenzverkauf in einer Liste — PDF, Zahlungseingang, Erinnerung und Gutschrift.',
            )
        "
    />

    <Notice
        v-for="(message, i) in generalErrors"
        :key="i"
        tone="danger"
        class="mb-3.5"
        >{{ message }}</Notice
    >

    <div
        class="mb-3 flex flex-wrap items-center gap-1.5"
        role="group"
        :aria-label="$t('Filter')"
    >
        <button
            v-for="chip in chips"
            :key="chip.value ?? 'all'"
            type="button"
            class="rounded-full border px-2.5 py-[3px] text-[12px]"
            :class="
                chip.value === current.filter
                    ? 'border-lava-dark bg-lava/10 font-semibold text-lava-dark'
                    : 'border-ash-light bg-white text-basalt-light hover:border-ash hover:text-basalt'
            "
            :aria-pressed="chip.value === current.filter"
            @click="applyFilter(chip.value)"
        >
            {{ chip.label }}
        </button>
        <span class="ml-auto text-[11.5px] text-basalt-light">{{
            $tc(':count Rechnung|:count Rechnungen', rows.length)
        }}</span>
    </div>

    <div
        class="grid items-start gap-4"
        :class="crediting ? 'lg:grid-cols-[minmax(0,1fr)_340px]' : ''"
    >
        <div class="min-w-0">
            <div
                v-if="rows.length === 0"
                class="rounded-lg border border-ash-light bg-white"
            >
                <EmptyState
                    :title="
                        current.filter
                            ? $t('Keine Rechnung mit diesem Filter')
                            : $t('Noch keine Rechnung')
                    "
                    :text="
                        current.filter
                            ? $t('Mit «Alle» sehen Sie jede Rechnung.')
                            : $t(
                                  'Rechnungen aus SaaS-Abos und Lizenzkäufen erscheinen hier.',
                              )
                    "
                    icon="forms"
                    tone="ash"
                />
            </div>
            <Table v-else>
                <template #head>
                    <tr>
                        <th class="px-3 py-2">{{ $t('Nummer') }}</th>
                        <th class="px-3 py-2">{{ $t('Kunde') }}</th>
                        <th class="px-3 py-2">{{ $t('Datum') }}</th>
                        <th class="px-3 py-2">{{ $t('Betrag') }}</th>
                        <th class="px-3 py-2">{{ $t('Status') }}</th>
                        <th class="px-3 py-2">
                            <span class="sr-only">{{ $t('Aktionen') }}</span>
                        </th>
                    </tr>
                </template>
                <tr
                    v-for="row in rows"
                    :key="keyOf(row)"
                    class="align-top"
                    :class="
                        crediting && keyOf(crediting) === keyOf(row)
                            ? 'bg-paper shadow-[inset_3px_0_0_var(--color-lava)]'
                            : ''
                    "
                >
                    <td :data-label="$t('Nummer')" class="px-3 py-2.5">
                        <p class="font-mono text-[12.5px] font-semibold">
                            {{ row.number }}
                        </p>
                        <p class="text-[11.5px] text-basalt-light">
                            {{ row.source_label }}
                        </p>
                    </td>
                    <td :data-label="$t('Kunde')" class="px-3 py-2.5">
                        <p class="text-[13px] font-semibold">
                            {{ row.customer }}
                        </p>
                        <p class="text-[11.5px] break-all text-basalt-light">
                            {{ row.email || '—' }}
                        </p>
                    </td>
                    <td
                        :data-label="$t('Datum')"
                        class="px-3 py-2.5 text-xs md:whitespace-nowrap"
                    >
                        {{ row.issued }}
                        <p
                            v-if="row.due"
                            class="text-[11.5px]"
                            :class="
                                row.overdue
                                    ? 'text-danger'
                                    : 'text-basalt-light'
                            "
                        >
                            {{ $t('fällig :date', { date: row.due }) }}
                        </p>
                    </td>
                    <td
                        :data-label="$t('Betrag')"
                        class="px-3 py-2.5 font-mono text-[12.5px] md:whitespace-nowrap"
                    >
                        {{ row.amount }}
                        <p
                            v-if="row.credited"
                            class="font-sans text-[11.5px] text-basalt-light"
                        >
                            {{
                                $t('gutgeschrieben :amount', {
                                    amount: row.credited,
                                })
                            }}
                        </p>
                    </td>
                    <td :data-label="$t('Status')" class="px-3 py-2.5">
                        <StateTag :tone="statusTone(row)">{{
                            row.status_label
                        }}</StateTag>
                    </td>
                    <td :data-label="$t('Aktionen')" class="px-3 py-2">
                        <div class="flex flex-wrap items-center gap-x-1">
                            <a
                                :href="row.urls.pdf"
                                target="_blank"
                                rel="noopener"
                                :class="linkClass"
                                :aria-label="
                                    $t('PDF von :number', {
                                        number: row.number,
                                    })
                                "
                                >{{ $t('PDF') }}</a
                            >
                            <Button
                                v-if="row.urls.paid"
                                variant="link"
                                size="sm"
                                :disabled="busy === keyOf(row)"
                                @click="markPaid(row)"
                                >{{ $t('Bezahlt verbuchen') }}</Button
                            >
                            <Button
                                v-if="row.urls.remind"
                                variant="link"
                                size="sm"
                                :disabled="busy === keyOf(row)"
                                @click="remind(row)"
                                >{{ $t('Erinnern') }}</Button
                            >
                            <Button
                                v-if="row.urls.send"
                                variant="link"
                                size="sm"
                                :disabled="busy === keyOf(row)"
                                @click="resend(row)"
                                >{{ $t('Erneut senden') }}</Button
                            >
                            <Button
                                v-if="row.urls.credit"
                                variant="link"
                                size="sm"
                                :disabled="busy === keyOf(row)"
                                @click="openCredit(row)"
                                >{{ $t('Gutschrift…') }}</Button
                            >
                        </div>
                    </td>
                </tr>
            </Table>
        </div>

        <section
            v-if="crediting"
            ref="aside"
            class="min-w-0 scroll-mt-4 rounded-lg border border-ash-light bg-white"
            :aria-label="$t('Gutschrift erstellen')"
        >
            <div class="px-3.5 pt-3">
                <h2 class="text-sm font-semibold">
                    {{
                        $t('Gutschrift zu :number', {
                            number: crediting.number,
                        })
                    }}
                </h2>
                <p class="mt-0.5 text-xs text-basalt-light">
                    {{ crediting.customer }} · {{ crediting.amount }}
                </p>
            </div>
            <form
                class="px-3.5 pt-2 pb-3"
                novalidate
                @submit.prevent="submitCredit"
            >
                <Field
                    v-model="form.amount"
                    :label="$t('Betrag')"
                    autocomplete="off"
                    mono
                    required
                    :error="form.errors.amount"
                    :help="
                        $t('höchstens :amount', {
                            amount: money(
                                crediting.credit_left,
                                crediting.currency,
                            ),
                        })
                    "
                />
                <Field
                    v-model="form.reason"
                    :label="$t('Grund')"
                    type="textarea"
                    required
                    :maxlength="500"
                    :error="form.errors.reason"
                    :help="$t('Steht auf der Gutschrift und im Protokoll.')"
                />
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <Button
                        type="submit"
                        :disabled="
                            form.processing ||
                            form.amount.trim() === '' ||
                            form.reason.trim() === ''
                        "
                        >{{ $t('Gutschrift erstellen und senden') }}</Button
                    >
                    <Button variant="link" size="sm" @click="closeCredit">{{
                        $t('Abbrechen')
                    }}</Button>
                </div>
            </form>
        </section>
    </div>

    <section class="mt-6" :aria-labelledby="'gutschriften'">
        <h2 id="gutschriften" class="mb-2 text-[15px] font-semibold">
            {{ $t('Gutschriften') }}
        </h2>
        <div
            v-if="credits.length === 0"
            class="rounded-lg border border-ash-light bg-white"
        >
            <EmptyState
                :title="$t('Noch keine Gutschrift')"
                :text="
                    $t('Gutschriften erhalten eine eigene Nummer und ein PDF.')
                "
                icon="forms"
                tone="ash"
            />
        </div>
        <Table v-else>
            <template #head>
                <tr>
                    <th class="px-3 py-2">{{ $t('Nummer') }}</th>
                    <th class="px-3 py-2">{{ $t('zu Rechnung') }}</th>
                    <th class="px-3 py-2">{{ $t('Datum') }}</th>
                    <th class="px-3 py-2">{{ $t('Betrag') }}</th>
                    <th class="px-3 py-2">{{ $t('Grund') }}</th>
                    <th class="px-3 py-2">
                        <span class="sr-only">{{ $t('PDF') }}</span>
                    </th>
                </tr>
            </template>
            <tr v-for="credit in credits" :key="credit.id" class="align-top">
                <td
                    :data-label="$t('Nummer')"
                    class="px-3 py-2.5 font-mono text-[12.5px] font-semibold"
                >
                    {{ credit.number }}
                </td>
                <td
                    :data-label="$t('zu Rechnung')"
                    class="px-3 py-2.5 font-mono text-[12.5px]"
                >
                    {{ credit.invoice }}
                </td>
                <td
                    :data-label="$t('Datum')"
                    class="px-3 py-2.5 text-xs md:whitespace-nowrap"
                >
                    {{ credit.date }}
                </td>
                <td
                    :data-label="$t('Betrag')"
                    class="px-3 py-2.5 font-mono text-[12.5px] md:whitespace-nowrap"
                >
                    {{ credit.amount }}
                </td>
                <td
                    :data-label="$t('Grund')"
                    class="px-3 py-2.5 text-[12.5px] break-words"
                >
                    {{ credit.reason }}
                </td>
                <td :data-label="$t('PDF')" class="px-3 py-2">
                    <a
                        :href="credit.pdf"
                        target="_blank"
                        rel="noopener"
                        :class="linkClass"
                        :aria-label="
                            $t('PDF von :number', { number: credit.number })
                        "
                        >{{ $t('PDF') }}</a
                    >
                </td>
            </tr>
        </Table>
    </section>
</template>
