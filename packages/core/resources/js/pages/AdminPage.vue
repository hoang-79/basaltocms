<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminBoard from '../components/admin/AdminBoard.vue';
import AdminForm from '../components/admin/AdminForm.vue';
import AdminTable from '../components/admin/AdminTable.vue';
import { pageErrors, sectionKey } from '../components/admin/state';
import type {
    AdminAction,
    AdminPageData,
    RunAction,
} from '../components/admin/types';
import Button from '../components/Button.vue';
import Modal from '../components/Modal.vue';
import Notice from '../components/Notice.vue';
import PageHeader from '../components/PageHeader.vue';

/**
 * Eine Verwaltungsseite aus Beschreibung (CR-018, TB 99, E-3): Ein Plugin beschreibt sie in PHP
 * (`Basalto\Core\Backend\Schema\AdminPage`), diese Seite zeichnet sie mit den Komponenten des Kerns — Kennzahlen,
 * Hinweise, Text, Listen, Formulare, Boards (CR-020) und Knöpfe. Kein JavaScript aus dem Plugin; Texte kommen übersetzt.
 */
const props = defineProps<{ page: AdminPageData }>();

// CR-020: Fehler ohne Feld (Verschieben, Sammelaktionen, Knöpfe) oben als Hinweis — sonst sähe sie niemand
const inertia = usePage();
const errors = computed(() =>
    pageErrors(
        props.page.sections,
        (inertia.props.errors ?? {}) as Record<string, string>,
    ),
);

const asking = ref<{
    action: AdminAction;
    data: Record<string, unknown>;
    onSuccess?: () => void;
} | null>(null);
const busy = ref(false);

function execute(
    action: AdminAction,
    data: Record<string, unknown> = {},
    onSuccess?: () => void,
): void {
    if (action.method === 'get') {
        router.visit(action.url);

        return;
    }
    busy.value = true;
    router.visit(action.url, {
        method: action.method,
        data: { ...action.data, ...data } as Record<
            string,
            FormDataConvertible
        >,
        preserveScroll: true,
        onSuccess: () => onSuccess?.(),
        onFinish: () => {
            busy.value = false;
            asking.value = null;
        },
    });
}

const run: RunAction = (action, data = {}, onSuccess) => {
    if (action.confirm) {
        asking.value = { action, data, onSuccess };

        return;
    }
    execute(action, data, onSuccess);
};

const toneOf = {
    neutral: 'text-basalt',
    success: 'text-success',
    warning: 'text-warning',
    danger: 'text-danger',
} as const;
</script>

<template>
    <Head :title="props.page.title" />
    <PageHeader
        :title="props.page.title"
        :crumb="props.page.crumb"
        :lead="props.page.lead"
    >
        <template v-if="props.page.actions.length > 0" #actions>
            <Button
                v-for="a in props.page.actions"
                :key="a.label"
                size="sm"
                :variant="a.variant"
                @click="run(a)"
                >{{ a.label }}</Button
            >
        </template>
    </PageHeader>

    <div class="flex flex-col gap-5">
        <Notice v-if="errors.length > 0" tone="danger" role="alert">
            <p v-for="message in errors" :key="message">{{ message }}</p>
        </Notice>
        <template
            v-for="(section, i) in props.page.sections"
            :key="sectionKey(section, i)"
        >
            <dl
                v-if="section.kind === 'stats'"
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
            >
                <div
                    v-for="s in section.items"
                    :key="s.label"
                    class="rounded-lg border border-ash-light bg-white px-4 py-3"
                >
                    <dt class="text-[11.5px] text-basalt-light uppercase">
                        {{ s.label }}
                    </dt>
                    <dd
                        class="text-[22px] font-semibold tabular-nums"
                        :class="toneOf[s.tone]"
                    >
                        {{ s.value }}
                    </dd>
                    <dd v-if="s.hint" class="text-[11.5px] text-basalt-light">
                        {{ s.hint }}
                    </dd>
                </div>
            </dl>
            <Notice
                v-else-if="section.kind === 'notice'"
                :tone="section.tone"
                :title="section.title ?? ''"
                >{{ section.text }}</Notice
            >
            <section v-else-if="section.kind === 'text'">
                <h2 v-if="section.title" class="mb-1 text-[14px] font-semibold">
                    {{ section.title }}
                </h2>
                <p class="text-[13px] whitespace-pre-line">
                    {{ section.text }}
                </p>
            </section>
            <AdminTable
                v-else-if="section.kind === 'table'"
                :table="section"
                :run="run"
            />
            <AdminForm v-else-if="section.kind === 'form'" :section="section" />
            <AdminBoard v-else-if="section.kind === 'board'" :board="section" />
        </template>
    </div>

    <Modal
        :open="asking !== null"
        :wide="false"
        :title="asking?.action.label ?? ''"
        @close="asking = null"
    >
        <div v-if="asking" class="flex flex-col gap-3 text-[13px]">
            <p>{{ asking.action.confirm }}</p>
            <div class="flex flex-wrap justify-end gap-2">
                <Button variant="secondary" @click="asking = null">{{
                    $t('Abbrechen')
                }}</Button>
                <Button
                    :variant="
                        asking.action.variant === 'danger'
                            ? 'danger'
                            : 'primary'
                    "
                    :disabled="busy"
                    @click="
                        execute(asking.action, asking.data, asking.onSuccess)
                    "
                    >{{ asking.action.label }}</Button
                >
            </div>
        </div>
    </Modal>
</template>
