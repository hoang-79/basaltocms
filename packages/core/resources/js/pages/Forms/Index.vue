<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '../../components/Button.vue';
import EmptyState from '../../components/EmptyState.vue';
import Hex from '../../components/Hex.vue';
import Notice from '../../components/Notice.vue';
import PageHeader from '../../components/PageHeader.vue';
import StateTag from '../../components/StateTag.vue';
import Table from '../../components/Table.vue';
import { t, tc } from '../../i18n';
import type { BackendProps } from '../../types';
import type { FormRow } from '../../types-forms';

/**
 * Formulare der Site (Bereich 16/1): Theme-Formulare (Station H) und Site-Formulare aus dem Baukasten (Bereich 29) in
 * einer Liste, ein Eingangskorb je Formular. «Eingebunden» sagt, wo das Formular steht (Block in einer Seite, Lava-Teil
 * in einem Layout); eine nicht zugestellte Mail steht als Warnung an der Zeile.
 */
const props = defineProps<{
    forms: FormRow[];
    urls: { create: string };
}>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);

const totals = computed(() => ({
    new: props.forms.reduce((n, f) => n + f.new, 0),
    all: props.forms.reduce((n, f) => n + f.submissions, 0),
    spam: props.forms.reduce((n, f) => n + f.spam, 0),
}));

function sourceText(form: FormRow): string {
    return form.source === 'theme'
        ? `${t('Theme')} · form ${form.handle} · ${form.location}`
        : `Site · ${t('Baukasten')} · ${tc(':count Feld|:count Felder', form.fields)}`;
}
</script>

<template>
    <Head :title="$t('Formulare')" />
    <PageHeader
        :title="$t('Formulare')"
        :crumb="$t(':site › Inhalte', { site: siteName })"
        :lead="
            $t(
                'Alle Formulare der Site mit ihren Eingängen — aus dem Theme (Station H) und aus dem Baukasten.',
            )
        "
    >
        <template #actions>
            <Button :href="urls.create">{{ $t('Neues Formular') }}</Button>
        </template>
    </PageHeader>

    <div
        v-if="forms.length === 0"
        class="rounded-lg border border-ash-light bg-white"
    >
        <EmptyState
            :title="$t('Noch kein Formular')"
            :text="
                $t(
                    'Das Theme bringt keines mit — der Baukasten legt ein Formular der Site an.',
                )
            "
            icon="forms"
            tone="ash"
        >
            <Button :href="urls.create">{{ $t('Neues Formular') }}</Button>
        </EmptyState>
    </div>

    <Table v-else>
        <template #head>
            <tr>
                <th class="px-3 py-2">{{ $t('Formular · Quelle') }}</th>
                <th class="px-3 py-2">{{ $t('Eingänge') }}</th>
                <th class="px-3 py-2 text-right">{{ $t('Spam') }}</th>
                <th class="px-3 py-2">{{ $t('Aktionen') }}</th>
                <th class="px-3 py-2">{{ $t('Eingebunden') }}</th>
                <th class="px-3 py-2">{{ $t('Zuletzt') }}</th>
                <th class="px-3 py-2">
                    <span class="sr-only">{{ $t('Öffnen') }}</span>
                </th>
            </tr>
        </template>
        <tr
            v-for="form in forms"
            :key="form.handle"
            class="align-top"
            :class="form.new > 0 ? 'bg-lava/5' : ''"
        >
            <td class="px-3 py-2.5">
                <div class="flex items-start gap-2">
                    <Hex
                        icon="forms"
                        :tone="form.source === 'site' ? 'basalt' : 'ash'"
                        :title="
                            form.source === 'site'
                                ? $t('Site-Formular')
                                : $t('Theme-Formular')
                        "
                    />
                    <div class="min-w-0">
                        <Link
                            :href="form.urls.edit"
                            class="text-[13.5px] font-semibold break-words hover:underline"
                            >{{ form.label }}</Link
                        >
                        <p
                            class="text-[11.5px] break-words text-basalt-light"
                            :class="form.source === 'theme' ? 'font-mono' : ''"
                        >
                            {{ sourceText(form) }}
                        </p>
                    </div>
                </div>
            </td>
            <td
                :data-label="$t('Eingänge')"
                class="px-3 py-2.5 text-[12.5px] whitespace-nowrap"
            >
                <b v-if="form.new > 0" class="font-semibold text-lava-dark">{{
                    $t(':count neu', { count: form.new })
                }}</b>
                <span v-else class="text-basalt-light">{{
                    $t(':count neu', { count: 0 })
                }}</span>
                · {{ form.submissions }}
            </td>
            <td
                :data-label="$t('Spam')"
                class="px-3 py-2.5 font-mono text-[11px] md:text-right"
            >
                {{ form.spam }}
            </td>
            <td :data-label="$t('Aktionen')" class="px-3 py-2.5">
                <span class="flex flex-wrap gap-1">
                    <StateTag v-for="a in form.actions" :key="a">{{
                        a
                    }}</StateTag>
                    <span
                        v-if="form.actions.length === 0"
                        class="text-ash-dark text-xs"
                        >{{ $t('keine') }}</span
                    >
                </span>
                <p
                    v-if="form.mail_failed > 0"
                    class="text-danger mt-1 text-[11.5px] font-semibold"
                >
                    {{
                        $tc(
                            ':count Mail nicht zugestellt|:count Mails nicht zugestellt',
                            form.mail_failed,
                        )
                    }}
                    —
                    <Link :href="form.urls.inbox" class="underline">{{
                        $t('im Eingang erneut senden')
                    }}</Link>
                </p>
            </td>
            <td
                :data-label="$t('Eingebunden')"
                class="px-3 py-2.5 text-[12px] text-basalt-light"
            >
                <span
                    v-for="e in form.embedded"
                    :key="e"
                    class="block break-words"
                    >{{ e }}</span
                >
                <span v-if="form.embedded.length === 0" class="text-ash-dark">{{
                    $t('noch nirgends')
                }}</span>
            </td>
            <td
                :data-label="$t('Zuletzt')"
                class="px-3 py-2.5 text-[12px] whitespace-nowrap text-basalt-light"
            >
                {{ form.last ?? '—' }}
            </td>
            <td class="px-3 py-2.5 md:text-right">
                <span
                    class="inline-flex flex-wrap items-center gap-x-1.5 gap-y-1 md:justify-end"
                >
                    <Button
                        variant="secondary"
                        size="sm"
                        :href="form.urls.inbox"
                        >{{ $t('Eingänge') }}</Button
                    >
                    <Button variant="link" size="sm" :href="form.urls.edit">{{
                        form.source === 'theme'
                            ? $t('Ansehen')
                            : $t('Bearbeiten')
                    }}</Button>
                </span>
            </td>
        </tr>
        <template #foot>
            {{ $tc(':count Formular|:count Formulare', forms.length) }} ·
            {{ $t(':count neu', { count: totals.new }) }} ·
            {{ $tc(':count Eingang|:count Eingänge', totals.all) }} ·
            {{ $t(':count Spam', { count: totals.spam }) }} ·
            {{
                $t(
                    'Theme-Formulare (Station H) und Site-Formulare (Baukasten) in einer Liste, ein Eingangskorb je Formular',
                )
            }}
        </template>
    </Table>

    <Notice tone="grey" class="mt-3">
        {{
            $t(
                'Der Spam-Zähler ist sichtbar, die Spam-Eingänge selbst nicht — sie werden nie gespeichert, nur gezählt. Honeypot, Zeitprüfung und Limit je IP sind an und nicht abschaltbar.',
            )
        }}
    </Notice>
</template>
