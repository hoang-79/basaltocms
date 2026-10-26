<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from '../../components/Button.vue';
import { controlClasses } from '../../components/control';
import EmptyState from '../../components/EmptyState.vue';
import Field from '../../components/Field.vue';
import PageHeader from '../../components/PageHeader.vue';
import StateTag from '../../components/StateTag.vue';
import { t } from '../../i18n';
import type { CancellationsProps } from '../../types-extranet';

/**
 * Kündigungen über «Verträge hier kündigen» (CR-011 TB 59, § 312k BGB). Basalto kündigt selbst, wenn die E-Mail-Adresse
 * zu genau einem Vertrag passt; hier bleibt, was das Team zuordnen muss. «Erledigen» setzt das Ende des Vertrags oder
 * «kein Vertrag gefunden» — die Person erhält die Bestätigung per Mail. Versprochen sind zwei Werktage.
 */
const props = defineProps<CancellationsProps>();

type Item = CancellationsProps['items'][number];

const statusLabel = (status: Item['status']): string =>
    ({
        applied: t('gekündigt'),
        scheduled: t('vorgemerkt'),
        review: t('zuzuordnen'),
        done: t('erledigt'),
        rejected: t('kein Vertrag'),
        revoked: t('zurückgenommen'),
    })[status];

const statusTone = (
    status: Item['status'],
): 'grey' | 'lava' | 'success' | 'warning' =>
    status === 'review'
        ? 'lava'
        : status === 'applied' || status === 'done'
          ? 'success'
          : status === 'scheduled'
            ? 'warning'
            : 'grey';

// Je zuzuordnende Kündigung ein Formular — beim Laden angelegt (eine neue Seite lädt die Komponente neu)
const forms = Object.fromEntries(
    props.items
        .filter((item) => item.resolve_url !== null)
        .map((item) => [
            item.id,
            useForm({
                outcome: 'ended' as 'ended' | 'none',
                ends: props.today,
                note: '',
            }),
        ]),
);

const formFor = (item: Item) => forms[item.id];

function resolve(item: Item): void {
    if (item.resolve_url === null) {
        return;
    }
    formFor(item).post(item.resolve_url, { preserveScroll: true });
}

const tabClass = (active: boolean): string =>
    active
        ? 'border-lava font-semibold text-basalt'
        : 'border-transparent text-basalt-light hover:text-basalt';
</script>

<template>
    <Head :title="$t('Kündigungen')" />
    <PageHeader
        :title="$t('Kündigungen')"
        :crumb="$t('Extranet › Kündigungen')"
        :lead="
            $t(
                'Eingänge über «Verträge hier kündigen». Passt die E-Mail-Adresse zu genau einem Vertrag, kündigt Basalto selbst. Den Rest ordnen Sie hier zu — versprochen ist die Bestätigung innert zwei Werktagen.',
            )
        "
    />

    <nav
        class="mb-4 flex flex-wrap gap-x-1 border-b border-ash-light"
        :aria-label="$t('Filter')"
    >
        <Link
            :href="urls.index + '?filter=review'"
            class="-mb-px border-b-2 px-3 py-2 text-[13px]"
            :class="tabClass(filter === 'review')"
            :aria-current="filter === 'review' ? 'page' : undefined"
            >{{ $t('Zuzuordnen') }} ({{ counts.review }})</Link
        >
        <Link
            :href="urls.index"
            class="-mb-px border-b-2 px-3 py-2 text-[13px]"
            :class="tabClass(filter === 'all')"
            :aria-current="filter === 'all' ? 'page' : undefined"
            >{{ $t('Alle') }} ({{ counts.all }})</Link
        >
    </nav>

    <div
        v-if="items.length === 0"
        class="rounded-lg border border-ash-light bg-white"
    >
        <EmptyState
            :title="
                filter === 'review'
                    ? $t('Nichts zuzuordnen')
                    : $t('Noch keine Kündigung')
            "
            :text="
                $t(
                    'Jede Kündigung über «Verträge hier kündigen» erscheint hier mit Eingang, Angaben und Stand.',
                )
            "
            icon="log"
            tone="ash"
        />
    </div>

    <ul v-else class="flex flex-col gap-3">
        <li
            v-for="item in items"
            :key="item.id"
            class="rounded-lg border bg-white px-3.5 py-3"
            :class="
                item.status === 'review' ? 'border-lava/60' : 'border-ash-light'
            "
        >
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <strong class="font-mono text-[13px]">{{ item.number }}</strong>
                <StateTag :tone="statusTone(item.status)">{{
                    statusLabel(item.status)
                }}</StateTag>
                <span class="text-xs text-basalt-light"
                    >{{ $t('Eingang :date', { date: item.received }) }} ·
                    {{ item.site }}</span
                >
            </div>
            <dl
                class="mt-2 grid gap-x-4 gap-y-0.5 text-[12.5px] sm:grid-cols-[10rem_minmax(0,1fr)]"
            >
                <dt class="text-basalt-light">{{ $t('Vertrag') }}</dt>
                <dd class="break-words">{{ item.contract }}</dd>
                <dt class="text-basalt-light">{{ $t('Person') }}</dt>
                <dd class="break-words">{{ item.name }} · {{ item.email }}</dd>
                <dt class="text-basalt-light">{{ $t('Art') }}</dt>
                <dd>
                    {{
                        item.kind === 'extraordinary'
                            ? $t('Ausserordentliche Kündigung')
                            : $t('Ordentliche Kündigung')
                    }}
                </dd>
                <template v-if="item.reason">
                    <dt class="text-basalt-light">{{ $t('Grund') }}</dt>
                    <dd class="break-words">{{ item.reason }}</dd>
                </template>
                <template v-if="item.reference">
                    <dt class="text-basalt-light">{{ $t('Kennung') }}</dt>
                    <dd class="break-words">{{ item.reference }}</dd>
                </template>
                <dt class="text-basalt-light">{{ $t('Gewünschtes Ende') }}</dt>
                <dd>{{ item.wish ?? $t('zum nächstmöglichen Zeitpunkt') }}</dd>
                <template v-if="item.ends">
                    <dt class="text-basalt-light">{{ $t('Ende') }}</dt>
                    <dd class="font-semibold">{{ item.ends }}</dd>
                </template>
                <template v-if="item.note">
                    <dt class="text-basalt-light">{{ $t('Hinweis') }}</dt>
                    <dd class="break-words">{{ item.note }}</dd>
                </template>
            </dl>

            <form
                v-if="item.resolve_url"
                class="mt-3 grid gap-x-4 border-t border-ash-light pt-3 md:grid-cols-[minmax(0,14rem)_minmax(0,1fr)]"
                novalidate
                @submit.prevent="resolve(item)"
            >
                <fieldset class="min-w-0">
                    <legend class="mb-1 text-xs font-semibold">
                        {{ $t('Erledigen') }}
                    </legend>
                    <label class="flex items-center gap-2 py-1 text-[12.5px]">
                        <input
                            v-model="formFor(item).outcome"
                            type="radio"
                            value="ended"
                            class="accent-lava-dark"
                        />
                        {{ $t('Gekündigt — Vertrag endet am') }}
                    </label>
                    <label class="flex items-center gap-2 py-1 text-[12.5px]">
                        <input
                            v-model="formFor(item).outcome"
                            type="radio"
                            value="none"
                            class="accent-lava-dark"
                        />
                        {{ $t('Kein Vertrag gefunden') }}
                    </label>
                </fieldset>
                <div class="min-w-0">
                    <Field
                        v-if="formFor(item).outcome === 'ended'"
                        :label="$t('Ende des Vertrags')"
                        :error="formFor(item).errors.ends"
                    >
                        <template #default="{ id, invalid, describedby }">
                            <input
                                :id="id"
                                v-model="formFor(item).ends"
                                type="date"
                                required
                                :aria-invalid="invalid || undefined"
                                :aria-describedby="describedby"
                                :class="controlClasses({ error: invalid })"
                            />
                        </template>
                    </Field>
                    <Field
                        v-model="formFor(item).note"
                        :label="$t('Hinweis an die Person')"
                        type="textarea"
                        :rows="2"
                        :maxlength="1000"
                        :required="formFor(item).outcome === 'none'"
                        :error="formFor(item).errors.note"
                        :help="
                            $t(
                                'Steht in der Bestätigung per Mail — bei «kein Vertrag» Pflicht.',
                            )
                        "
                    />
                    <div class="mt-2">
                        <Button
                            type="submit"
                            :disabled="formFor(item).processing"
                            >{{ $t('Erledigen und bestätigen') }}</Button
                        >
                    </div>
                </div>
            </form>
        </li>
    </ul>
</template>
