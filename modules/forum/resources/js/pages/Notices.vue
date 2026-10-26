<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { t } from '@core/i18n';
import Button from '@core/components/Button.vue';
import { controlClasses, labelClasses } from '@core/components/control';
import EmptyState from '@core/components/EmptyState.vue';
import Modal from '@core/components/Modal.vue';
import PageHeader from '@core/components/PageHeader.vue';
import StateTag from '@core/components/StateTag.vue';
import type { NoticeRow, NoticesProps } from '../types';

/**
 * Extranet › Community › Hinweise (CR-006 FO-17), Vorbild WoltLab: Banner oben im Forum mit Bedingungen — Bereich,
 * Zielgruppe, Gruppen, Zeitraum. Angemeldete blenden einen Hinweis für sich aus, wenn er ausblendbar ist.
 */
const props = defineProps<NoticesProps>();

const tones: { value: NoticeRow['tone']; label: string }[] = [
    { value: 'info', label: t('Information') },
    { value: 'warnung', label: t('Warnung') },
    { value: 'erfolg', label: t('Erfolg') },
];
const audiences: { value: NoticeRow['audience']; label: string }[] = [
    { value: 'all', label: t('Alle') },
    { value: 'guests', label: t('Nur Gäste') },
    { value: 'members', label: t('Nur Angemeldete') },
];
const blank = () => ({
    text: Object.fromEntries(props.locales.map((l) => [l, ''])) as Record<
        string,
        string
    >,
    tone: 'info' as NoticeRow['tone'],
    area: '*',
    audience: 'all' as NoticeRow['audience'],
    groups: [] as number[],
    dismissible: true,
    is_active: true,
    position: 0,
    starts_at: null as string | null,
    ends_at: null as string | null,
});
const editing = ref<NoticeRow | null>(null);
const open = ref(false);
const form = useForm(blank());
function create(): void {
    editing.value = null;
    form.defaults(blank());
    form.reset();
    form.clearErrors();
    open.value = true;
}
function edit(notice: NoticeRow): void {
    editing.value = notice;
    const values = {
        ...blank(),
        ...notice,
        text: { ...blank().text, ...notice.text },
    };
    form.defaults(values);
    form.reset();
    form.clearErrors();
    open.value = true;
}
function save(): void {
    const done = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };
    if (editing.value) {
        form.put(editing.value.update_url, done);
    } else {
        form.post(props.urls.store, done);
    }
}
function remove(notice: NoticeRow): void {
    if (window.confirm(t('Diesen Hinweis löschen?'))) {
        router.delete(notice.destroy_url, { preserveScroll: true });
    }
}
const areaLabel = (value: string): string =>
    props.areas.find((a) => a.value === value)?.label ?? value;
const audienceLabel = (value: string): string =>
    audiences.find((a) => a.value === value)?.label ?? value;
const error = (key: string): string =>
    (form.errors as Record<string, string | undefined>)[key] ?? '';
const toneClass: Record<NoticeRow['tone'], string> = {
    info: 'border-ash-light bg-paper text-basalt',
    warnung: 'border-danger/50 bg-danger/10 text-basalt',
    erfolg: 'border-success/50 bg-success/10 text-basalt',
};
const preview = computed(() => form.text[props.locales[0] ?? 'de'] ?? '');
</script>

<template>
    <Head :title="$t('Hinweise')" />
    <PageHeader
        :title="$t('Hinweise')"
        :crumb="$t('Community')"
        :lead="
            $t(
                'Banner oben im Forum: Wartungsfenster, Regeln, Willkommen — für alle, nur Gäste, bestimmte Gruppen oder ein Forum.',
            )
        "
    >
        <template #actions>
            <Button :href="urls.overview" variant="secondary">{{
                $t('Übersicht')
            }}</Button>
            <Button @click="create">{{ $t('Neuer Hinweis') }}</Button>
        </template>
    </PageHeader>

    <EmptyState
        v-if="!notices.length"
        tone="ash"
        icon="comments"
        :title="$t('Noch keine Hinweise')"
        :text="
            $t(
                'Legen Sie einen Hinweis an — er erscheint sofort oben im Forum.',
            )
        "
    />
    <ul v-else class="flex flex-col gap-3">
        <li
            v-for="n in notices"
            :key="n.id"
            class="flex flex-col gap-2 rounded-lg border border-ash-light bg-white p-4"
        >
            <div
                class="rounded-md border px-3 py-2 text-[13px] whitespace-pre-line"
                :class="toneClass[n.tone]"
            >
                {{ n.text[locales[0] ?? 'de'] ?? Object.values(n.text)[0] }}
            </div>
            <p
                class="flex flex-wrap items-center gap-2 text-[12px] text-basalt-light"
            >
                <StateTag :tone="n.is_active ? 'success' : 'grey'">{{
                    n.is_active ? $t('aktiv') : $t('aus')
                }}</StateTag>
                <span>{{ areaLabel(n.area) }}</span>
                <span>· {{ audienceLabel(n.audience) }}</span>
                <span v-if="n.groups.length"
                    >·
                    {{
                        groups
                            .filter((g) => n.groups.includes(g.id))
                            .map((g) => g.name)
                            .join(', ')
                    }}</span
                >
                <span v-if="n.starts_at || n.ends_at"
                    >· {{ n.starts_at ?? '…' }} – {{ n.ends_at ?? '…' }}</span
                >
                <span v-if="n.dismissible">· {{ $t('ausblendbar') }}</span>
            </p>
            <div class="flex flex-wrap gap-2">
                <Button size="sm" variant="secondary" @click="edit(n)">{{
                    $t('Bearbeiten')
                }}</Button>
                <Button size="sm" variant="danger" @click="remove(n)">{{
                    $t('Löschen')
                }}</Button>
            </div>
        </li>
    </ul>

    <Modal
        :open="open"
        :title="editing ? $t('Hinweis bearbeiten') : $t('Neuer Hinweis')"
        @close="open = false"
    >
        <form class="grid gap-3 md:grid-cols-2" @submit.prevent="save">
            <label
                v-for="locale in locales"
                :key="locale"
                class="block md:col-span-2"
            >
                <span :class="labelClasses"
                    >{{ $t('Text') }} ({{ locale.toUpperCase() }})</span
                >
                <textarea
                    v-model="form.text[locale]"
                    rows="3"
                    maxlength="1000"
                    :class="
                        controlClasses({ error: !!error(`text.${locale}`) })
                    "
                />
                <span
                    v-if="error(`text.${locale}`)"
                    class="text-danger mt-1 block text-[11.5px]"
                    >{{ error(`text.${locale}`) }}</span
                >
            </label>
            <label class="block">
                <span :class="labelClasses">{{ $t('Ton') }}</span>
                <select v-model="form.tone" :class="controlClasses({})">
                    <option v-for="o in tones" :key="o.value" :value="o.value">
                        {{ o.label }}
                    </option>
                </select>
            </label>
            <label class="block">
                <span :class="labelClasses">{{ $t('Bereich') }}</span>
                <select v-model="form.area" :class="controlClasses({})">
                    <option v-for="o in areas" :key="o.value" :value="o.value">
                        {{ o.label }}
                    </option>
                </select>
            </label>
            <label class="block">
                <span :class="labelClasses">{{ $t('Für wen') }}</span>
                <select v-model="form.audience" :class="controlClasses({})">
                    <option
                        v-for="o in audiences"
                        :key="o.value"
                        :value="o.value"
                    >
                        {{ o.label }}
                    </option>
                </select>
            </label>
            <label class="block">
                <span :class="labelClasses">{{ $t('Reihenfolge') }}</span>
                <input
                    v-model.number="form.position"
                    type="number"
                    min="0"
                    :class="controlClasses({})"
                />
            </label>
            <fieldset class="md:col-span-2">
                <legend :class="labelClasses">
                    {{ $t('Nur für diese Gruppen (leer = alle)') }}
                </legend>
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-[13px]">
                    <label
                        v-for="g in groups"
                        :key="g.id"
                        class="inline-flex items-center gap-1.5"
                    >
                        <input
                            v-model="form.groups"
                            type="checkbox"
                            :value="g.id"
                        />
                        {{ g.name }}
                    </label>
                </div>
            </fieldset>
            <label class="block">
                <span :class="labelClasses">{{ $t('Ab (freiwillig)') }}</span>
                <input
                    v-model="form.starts_at"
                    type="date"
                    :class="controlClasses({})"
                />
            </label>
            <label class="block">
                <span :class="labelClasses">{{ $t('Bis (freiwillig)') }}</span>
                <input
                    v-model="form.ends_at"
                    type="date"
                    :class="controlClasses({ error: !!error('ends_at') })"
                />
                <span
                    v-if="error('ends_at')"
                    class="text-danger mt-1 block text-[11.5px]"
                    >{{ error('ends_at') }}</span
                >
            </label>
            <label class="inline-flex items-center gap-2 text-[13px]">
                <input v-model="form.dismissible" type="checkbox" />
                {{ $t('Angemeldete dürfen ihn ausblenden') }}
            </label>
            <label class="inline-flex items-center gap-2 text-[13px]">
                <input v-model="form.is_active" type="checkbox" />
                {{ $t('Aktiv') }}
            </label>
            <div class="md:col-span-2">
                <span :class="labelClasses">{{ $t('Vorschau') }}</span>
                <div
                    class="rounded-md border px-3 py-2 text-[13px] whitespace-pre-line"
                    :class="toneClass[form.tone]"
                >
                    {{ preview || $t('Ihr Text erscheint hier.') }}
                </div>
            </div>
            <div class="flex flex-wrap gap-2 md:col-span-2">
                <Button type="submit" :disabled="form.processing">{{
                    $t('Speichern')
                }}</Button>
                <Button variant="secondary" @click="open = false">{{
                    $t('Abbrechen')
                }}</Button>
            </div>
        </form>
    </Modal>
</template>
