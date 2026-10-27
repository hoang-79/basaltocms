<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useDraft } from '../../components/autosave';
import Button from '../../components/Button.vue';
import Card from '../../components/Card.vue';
import Field from '../../components/Field.vue';
import PairRow from '../../components/PairRow.vue';
import PageHeader from '../../components/PageHeader.vue';
import Table from '../../components/Table.vue';
import type { CustomerProps } from '../../types-extranet';

/**
 * Die Kundenakte im Hersteller-Extranet (CR-004 P-11): Kopf mit Name, E-Mail, Firma und Art, die Fakten der Quelle,
 * danach alles zu derselben E-Mail-Adresse aus allen Quellen (je Abschnitt eine Karte mit Tabelle). Ab 1024 px
 * stehen rechts die Notizen des Teams; wer die Kunden bearbeiten darf, schreibt dort eine neue Notiz.
 */
const props = defineProps<CustomerProps>();

const form = useForm({ email: props.customer.email, body: '' });
// CR-013: Die Notiz entsteht mit dem Knopf; bis dahin bleibt der Text als Entwurf im Browser
const draft = useDraft(form, () => `extranet.notiz.${props.customer.email}`, [
    'body',
]);

function saveNote(): void {
    form.post(props.urls.note, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('body');
            draft.clear();
        },
    });
}
</script>

<template>
    <Head :title="customer.name" />
    <PageHeader :title="customer.name">
        <template #crumb>
            <Link :href="urls.index" class="underline hover:text-basalt"
                >← {{ $t('Alle Kunden') }}</Link
            >
            · {{ $t('Extranet › Kunden') }}
        </template>
        <template #lead>
            <span class="break-all">{{ customer.email }}</span
            ><template v-if="customer.company">
                · {{ customer.company }}</template
            ><template v-if="customer.source_label">
                · {{ customer.source_label }}</template
            >
        </template>
    </PageHeader>

    <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div class="flex min-w-0 flex-col gap-4">
            <Card v-if="customer.facts.length > 0" :title="$t('Angaben')">
                <dl>
                    <PairRow
                        v-for="[label, value] in customer.facts"
                        :key="label"
                        :label="label"
                        >{{ value }}</PairRow
                    >
                </dl>
            </Card>

            <section
                v-for="(section, index) in sections"
                :key="`${section.source}-${index}`"
                class="min-w-0"
                :aria-label="section.title"
            >
                <div
                    class="mb-1.5 flex flex-wrap items-baseline justify-between gap-x-2"
                >
                    <h2 class="text-sm font-semibold">{{ section.title }}</h2>
                    <span class="text-[11px] text-basalt-light">{{
                        section.source
                    }}</span>
                </div>
                <div
                    v-if="section.rows.length === 0"
                    class="rounded-lg border border-ash-light bg-white px-3.5 py-3 text-[12.5px] text-basalt-light"
                >
                    {{ section.empty }}
                </div>
                <Table v-else>
                    <template #head>
                        <tr>
                            <th
                                v-for="column in section.columns"
                                :key="column"
                                class="px-3 py-2"
                            >
                                {{ column }}
                            </th>
                        </tr>
                    </template>
                    <tr
                        v-for="(row, rowIndex) in section.rows"
                        :key="rowIndex"
                        class="align-top"
                    >
                        <td
                            v-for="(cell, cellIndex) in row"
                            :key="cellIndex"
                            :data-label="section.columns[cellIndex] ?? ''"
                            class="px-3 py-2.5 text-[12.5px] break-words"
                            :class="cellIndex === 0 ? 'font-semibold' : ''"
                        >
                            {{ cell }}
                        </td>
                    </tr>
                </Table>
            </section>
        </div>

        <Card :title="$t('Notizen')" class="min-w-0">
            <template #right>{{
                $tc(':count Notiz|:count Notizen', notes.length)
            }}</template>
            <form v-if="can_note" class="mb-3" @submit.prevent="saveNote">
                <Field
                    v-model="form.body"
                    type="textarea"
                    :label="$t('Notiz')"
                    :rows="3"
                    :maxlength="5000"
                    required
                    :error="form.errors.body ?? form.errors.email ?? ''"
                />
                <div class="mt-2 flex justify-end">
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="form.processing || form.body.trim() === ''"
                        >{{ $t('Speichern') }}</Button
                    >
                </div>
            </form>
            <p
                v-if="notes.length === 0"
                class="text-[12.5px] text-basalt-light"
            >
                {{ $t('Noch keine Notizen zu diesem Kunden.') }}
            </p>
            <ul v-else class="divide-y divide-ash-light">
                <li
                    v-for="note in notes"
                    :key="note.id"
                    class="py-2 first:pt-0 last:pb-0"
                >
                    <p
                        class="text-[12.5px] break-words whitespace-pre-line text-basalt"
                    >
                        {{ note.body }}
                    </p>
                    <p class="mt-0.5 text-[11px] text-basalt-light">
                        {{ note.author
                        }}<template v-if="note.date">
                            · {{ note.date }}</template
                        >
                    </p>
                </li>
            </ul>
        </Card>
    </div>
</template>
