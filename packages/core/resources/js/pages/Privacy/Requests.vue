<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { useDraft } from '../../components/autosave';
import { computed } from 'vue';
import Button from '../../components/Button.vue';
import EmptyState from '../../components/EmptyState.vue';
import Field from '../../components/Field.vue';
import PageHeader from '../../components/PageHeader.vue';
import StateTag from '../../components/StateTag.vue';
import type { BackendProps } from '../../types';

/**
 * CR-011 TB 61: Verwaltung › Datenschutz — Anliegen aus dem Kundenkonto (Berichtigung, Einschränkung, Widerspruch,
 * Sonstiges). Frist ein Monat (Art. 12 Abs. 3 DSGVO); «Erledigen» schickt die Antwort per Mail an die Person.
 */
type Item = {
    id: number;
    kind: string;
    article: string;
    message: string;
    email: string;
    name: string | null;
    status: 'open' | 'done';
    received: string;
    due: string;
    overdue: boolean;
    handled: string | null;
    answer: string | null;
    answer_url: string | null;
};

const props = defineProps<{
    items: Item[];
    filter: 'offen' | 'alle';
    counts: { offen: number; alle: number };
    urls: { index: string };
}>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);

// Je offenes Anliegen ein Formular — beim Laden angelegt (eine neue Seite lädt die Komponente neu)
const forms = Object.fromEntries(
    props.items
        .filter((item) => item.answer_url !== null)
        .map((item) => [item.id, useForm({ answer: '' })]),
);
// CR-013: Die Antwort geht mit dem Knopf (und einer Mail); bis dahin bleibt sie als Entwurf im Browser
const drafts = Object.fromEntries(
    Object.entries(forms).map(([id, form]) => [
        id,
        useDraft(form, () => `datenschutz.antwort.${id}`, ['answer']),
    ]),
);

function send(item: Item): void {
    const form = forms[item.id];
    if (item.answer_url === null || form === undefined) {
        return;
    }
    form.post(item.answer_url, {
        preserveScroll: true,
        onSuccess: () => drafts[item.id]?.clear(),
    });
}

const tabClass = (active: boolean): string =>
    active
        ? 'border-lava font-semibold text-basalt'
        : 'border-transparent text-basalt-light hover:text-basalt';
</script>

<template>
    <Head :title="$t('Datenschutz')" />
    <PageHeader
        :title="$t('Datenschutz')"
        :crumb="$t(':site › Verwaltung', { site: siteName })"
        :lead="
            $t(
                'Anliegen aus dem Kundenkonto. Auskunft, Herunterladen, Berichtigen von Name und Adresse und Löschen erledigen die Personen selbst; hier bleibt, was eine Antwort braucht — innert eines Monats.',
            )
        "
    />

    <nav
        class="mb-4 flex flex-wrap gap-x-1 border-b border-ash-light"
        :aria-label="$t('Filter')"
    >
        <Link
            :href="urls.index"
            class="-mb-px border-b-2 px-3 py-2 text-[13px]"
            :class="tabClass(filter === 'offen')"
            :aria-current="filter === 'offen' ? 'page' : undefined"
            >{{ $t('Offen') }} ({{ counts.offen }})</Link
        >
        <Link
            :href="urls.index + '?filter=alle'"
            class="-mb-px border-b-2 px-3 py-2 text-[13px]"
            :class="tabClass(filter === 'alle')"
            :aria-current="filter === 'alle' ? 'page' : undefined"
            >{{ $t('Alle') }} ({{ counts.alle }})</Link
        >
    </nav>

    <div
        v-if="items.length === 0"
        class="rounded-lg border border-ash-light bg-white"
    >
        <EmptyState
            :title="
                filter === 'offen'
                    ? $t('Kein offenes Anliegen')
                    : $t('Noch kein Anliegen')
            "
            :text="
                $t(
                    'Anliegen zum Datenschutz aus dem Kundenkonto erscheinen hier mit Frist.',
                )
            "
            icon="user"
            tone="ash"
        />
    </div>

    <ul v-else class="flex flex-col gap-3">
        <li
            v-for="item in items"
            :key="item.id"
            class="rounded-lg border bg-white px-3.5 py-3"
            :class="
                item.overdue
                    ? 'border-danger'
                    : item.status === 'open'
                      ? 'border-lava/60'
                      : 'border-ash-light'
            "
        >
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <strong class="text-[13.5px]">{{ item.kind }}</strong>
                <span class="text-xs text-basalt-light">{{
                    item.article
                }}</span>
                <StateTag
                    :tone="
                        item.overdue
                            ? 'danger'
                            : item.status === 'open'
                              ? 'lava'
                              : 'success'
                    "
                    >{{
                        item.status === 'done'
                            ? $t('erledigt')
                            : item.overdue
                              ? $t('Frist verpasst')
                              : $t('offen')
                    }}</StateTag
                >
            </div>
            <p class="mt-1 text-xs text-basalt-light">
                {{ item.name ? item.name + ' · ' : '' }}{{ item.email }} ·
                {{ $t('Eingang :date', { date: item.received }) }} ·
                {{ $t('Antwort bis :date', { date: item.due }) }}
            </p>
            <p
                class="mt-2 rounded-md bg-paper px-3 py-2 text-[13px] break-words whitespace-pre-line"
            >
                {{ item.message }}
            </p>
            <div v-if="item.answer" class="mt-2 text-[12.5px]">
                <p class="font-semibold">
                    {{ $t('Antwort vom :date', { date: item.handled ?? '' }) }}
                </p>
                <p class="break-words whitespace-pre-line">{{ item.answer }}</p>
            </div>
            <form
                v-if="item.answer_url && forms[item.id]"
                class="mt-3 border-t border-ash-light pt-3"
                novalidate
                @submit.prevent="send(item)"
            >
                <Field
                    v-model="forms[item.id]!.answer"
                    :label="$t('Antwort an die Person')"
                    type="textarea"
                    :rows="3"
                    :maxlength="5000"
                    required
                    :error="forms[item.id]!.errors.answer"
                    :help="
                        $t('Geht per Mail an :email.', { email: item.email })
                    "
                />
                <div class="mt-2">
                    <Button
                        type="submit"
                        :disabled="forms[item.id]!.processing"
                        >{{ $t('Erledigen und antworten') }}</Button
                    >
                </div>
            </form>
        </li>
    </ul>
</template>
