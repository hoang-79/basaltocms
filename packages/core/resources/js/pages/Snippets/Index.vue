<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import BlockList from '../../components/BlockList.vue';
import BlockPreview from '../../components/BlockPreview.vue';
import Button from '../../components/Button.vue';
import Card from '../../components/Card.vue';
import EmptyState from '../../components/EmptyState.vue';
import Modal from '../../components/Modal.vue';
import Notice from '../../components/Notice.vue';
import PageHeader from '../../components/PageHeader.vue';
import { inputOf, markOf, usePreviewOpen } from '../../components/guidedFields';
import { handleFrom, snippetSummary } from '../../components/layoutEditor';
import { locale, tc } from '../../i18n';
import type {
    BackendProps,
    BlockValue,
    MediaOption,
    SchemaBlock,
} from '../../types';

/**
 * Station F — Bausteine (M-28, Mockup 27 Bild 4): wiederverwendbare Blockgruppen je Site. Links die Liste mit
 * Verwendungsnachweis, rechts der Baustein im Block-Editor. Eine Änderung wirkt sofort auf allen Seiten, die ihn
 * tragen; «Auflösen» macht daraus eigene Blöcke in jeder Seite und entfernt ihn.
 */
type SnippetRow = {
    id: number;
    handle: string;
    name: string;
    blocks: BlockValue[];
    used_in: { id: number; title: string; type: string; locale: string }[];
    updated_at: string | null;
    updated_by: string | null;
};

const props = defineProps<{
    broken: string | null;
    can_edit: boolean;
    snippets: SnippetRow[];
    selected: string | null;
    schema: SchemaBlock[];
    parts: string[];
    media: MediaOption[];
    urls: Record<string, string>;
}>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);
const errors = computed(() => page.props.errors as Record<string, string>);

const current = ref<string | null>(
    props.selected ?? props.snippets[0]?.handle ?? null,
);
const snippet = computed(
    () => props.snippets.find((s) => s.handle === current.value) ?? null,
);
const clone = <T>(v: T): T => JSON.parse(JSON.stringify(v)) as T;
const form = ref({
    name: snippet.value?.name ?? '',
    handle: snippet.value?.handle ?? '',
    blocks: clone(snippet.value?.blocks ?? []),
});
const creating = ref(false);
const handleTouched = ref(false);
const nameTouched = ref(false);

/* TB 30: Live-Vorschau als Fenster, Fokus zwischen Formular und Vorschau, Name aus dem ersten Titel */
const mounted = ref(false);
onMounted(() => (mounted.value = true));
const previewOpen = usePreviewOpen();
const previewFocus = ref<string | null>(null);
const previewUrl = computed(
    () => page.props.backend.editor?.preview_url ?? null,
);
const previewPayload = computed(() => ({ content: form.value.blocks }));

function onFocusIn(event: FocusEvent): void {
    const id = (event.target as HTMLElement | null)?.id ?? '';
    const mark = markOf(id, 'blocks');
    if (mark !== null) {
        previewFocus.value = mark;
    }
}

function onPick(mark: string): void {
    const id = inputOf(mark, 'blocks');
    const input = id ? document.getElementById(id) : null;
    if (input) {
        input.scrollIntoView({ block: 'center', behavior: 'smooth' });
        input.focus({ preventScroll: true });
    }
}

/** Neuer Baustein: der erste Titel im ersten Block schlägt den Namen vor, solange niemand ihn selbst schreibt. */
watch(
    () => {
        const data = form.value.blocks[0]?.data as
            | Record<string, unknown>
            | undefined;

        return typeof data?.title === 'string' ? data.title : '';
    },
    (title) => {
        if (creating.value && !nameTouched.value && title.trim() !== '') {
            setName(title.trim());
        }
    },
);

watch([snippet, () => props.snippets], () => {
    if (!creating.value) {
        form.value = {
            name: snippet.value?.name ?? '',
            handle: snippet.value?.handle ?? '',
            blocks: clone(snippet.value?.blocks ?? []),
        };
    }
});

const dirty = computed(
    () =>
        creating.value ||
        (snippet.value !== null &&
            JSON.stringify(form.value) !==
                JSON.stringify({
                    name: snippet.value.name,
                    handle: snippet.value.handle,
                    blocks: snippet.value.blocks,
                })),
);

function startNew(): void {
    creating.value = true;
    handleTouched.value = false;
    nameTouched.value = false;
    current.value = null;
    form.value = { name: '', handle: '', blocks: [] };
}

function select(handle: string): void {
    creating.value = false;
    current.value = handle;
}

function setName(name: string): void {
    form.value = {
        ...form.value,
        name,
        handle:
            creating.value && !handleTouched.value
                ? handleFrom(name)
                : form.value.handle,
    };
}

function save(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            current.value = form.value.handle;
            creating.value = false;
        },
    };
    if (snippet.value && !creating.value) {
        router.put(
            `${props.urls.store}/${snippet.value.id}`,
            form.value as never,
            options,
        );
    } else {
        router.post(props.urls.store ?? '', form.value as never, options);
    }
}

const dissolveOpen = ref<SnippetRow | null>(null);
function dissolve(): void {
    if (dissolveOpen.value) {
        router.post(
            `${props.urls.store}/${dissolveOpen.value.id}/aufloesen`,
            {},
            {
                preserveScroll: true,
                onFinish: () => (dissolveOpen.value = null),
            },
        );
    }
}
function remove(row: SnippetRow): void {
    router.delete(`${props.urls.store}/${row.id}`, { preserveScroll: true });
}

function entryUrl(u: { id: number; locale: string }): string {
    return (props.urls.entry ?? '')
        .replace('__id__', String(u.id))
        .replace('__locale__', u.locale);
}

const allowed = computed(() => props.schema.map((b) => b.name));
const usedLabel = (n: number): string => tc(':count Seite|:count Seiten', n);

/** Datum im Format der Sprache der Oberfläche (de-CH, en-GB, it-CH, fr-CH). */
function date(value: string): string {
    return new Date(value).toLocaleDateString(
        `${locale()}-${locale() === 'en' ? 'GB' : 'CH'}`,
    );
}
</script>

<template>
    <Head :title="$t('Bausteine')" />
    <PageHeader
        :title="$t('Bausteine')"
        :crumb="$t(':site › Einrichtung', { site: siteName })"
        :station="{ letter: 'F', keyword: 'block' }"
    >
        <template #lead>{{
            $t(
                'Wiederverwendbare Blockgruppen je Site — im Editor über «+ Baustein» einsetzbar, zentral änderbar: eine Änderung wirkt überall, wo der Baustein steht.',
            )
        }}</template>
        <template #actions>
            <Button v-if="can_edit" size="sm" @click="startNew">{{
                $t('+ Baustein')
            }}</Button>
        </template>
    </PageHeader>

    <Notice v-if="errors.snippet" tone="danger" class="mb-3" role="alert">{{
        errors.snippet
    }}</Notice>
    <Notice v-if="broken" tone="danger" class="mb-3" role="alert">{{
        broken
    }}</Notice>
    <Notice v-if="!can_edit" tone="grey" class="mb-3">{{
        $t(
            'Ihre Rolle erlaubt, Bausteine anzusehen, nicht zu ändern. Einsetzen geht im Editor jeder Seite über «+ Baustein».',
        )
    }}</Notice>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="min-w-0" :aria-label="$t('Bausteine der Site')">
            <ul
                v-if="snippets.length"
                class="divide-y divide-ash-light rounded-lg border border-ash-light bg-white"
            >
                <li
                    v-for="s in snippets"
                    :key="s.id"
                    class="px-3 py-2"
                    :class="
                        s.handle === current && !creating ? 'bg-lava/5' : ''
                    "
                >
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span
                            class="hex inline-flex h-3 w-[13px] bg-basalt"
                            aria-hidden="true"
                        />
                        <button
                            type="button"
                            class="min-w-0 text-left"
                            @click="select(s.handle)"
                        >
                            <span class="block text-[13px] font-semibold">{{
                                s.name
                            }}</span>
                            <span
                                class="block text-[11.5px] text-basalt-light"
                                >{{ snippetSummary(s.blocks, schema) }}</span
                            >
                        </button>
                        <span class="ml-auto text-[12px]">{{
                            usedLabel(s.used_in.length)
                        }}</span>
                    </div>
                    <div
                        class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11.5px] text-basalt-light"
                    >
                        <span class="font-mono">{{ s.handle }}</span>
                        <span v-if="s.updated_by"
                            >{{ s.updated_by }} ·
                            {{ s.updated_at ? date(s.updated_at) : '' }}</span
                        >
                        <span v-if="can_edit" class="ml-auto flex gap-1">
                            <Button
                                variant="secondary"
                                size="sm"
                                @click="select(s.handle)"
                                >{{ $t('Bearbeiten') }}</Button
                            >
                            <Button
                                v-if="s.used_in.length"
                                variant="link"
                                size="sm"
                                @click="dissolveOpen = s"
                                >{{ $t('Auflösen') }}</Button
                            >
                            <Button
                                v-else
                                variant="link"
                                size="sm"
                                @click="remove(s)"
                                >{{ $t('Löschen') }}</Button
                            >
                        </span>
                    </div>
                </li>
            </ul>
            <div v-else class="rounded-lg border border-ash-light bg-white">
                <EmptyState
                    :title="$t('Noch keine Bausteine')"
                    :text="
                        $t(
                            'Ein Baustein ist eine Gruppe von Blöcken, die auf mehreren Seiten gleich steht — etwa ein Aufruf oder eine Kontaktleiste.',
                        )
                    "
                    icon="module"
                    tone="ash"
                />
            </div>
            <p class="mt-1.5 text-[11.5px] text-basalt-light">
                {{
                    $tc(
                        ':count Baustein · ein Baustein enthält keine Bausteine.|:count Bausteine · ein Baustein enthält keine Bausteine.',
                        snippets.length,
                    )
                }}
            </p>
        </section>

        <section class="min-w-0" :aria-label="$t('Baustein bearbeiten')">
            <Card
                v-if="snippet || creating"
                :title="
                    creating
                        ? $t('Neuer Baustein')
                        : $t('Baustein «:name»', { name: snippet?.name ?? '' })
                "
                :sub="
                    creating
                        ? $t(
                              'erscheint nach dem Speichern im Editor unter «+ Baustein»',
                          )
                        : $t('in :pages · Änderung wirkt überall', {
                              pages: usedLabel(snippet?.used_in.length ?? 0),
                          })
                "
            >
                <div class="mb-3 grid gap-3 sm:grid-cols-2">
                    <label
                        class="block text-[12px] font-semibold tracking-wide text-basalt-light uppercase"
                    >
                        {{ $t('Name') }}
                        <input
                            :value="form.name"
                            :disabled="!can_edit"
                            :placeholder="$t('z. B. Kontaktleiste')"
                            class="mt-1 block w-full rounded-md border border-ash px-2 py-1 text-[13px] normal-case"
                            @input="
                                nameTouched = true;
                                setName(
                                    ($event.target as HTMLInputElement).value,
                                );
                            "
                        />
                        <span
                            class="mt-1 block text-[11.5px] font-normal tracking-normal text-basalt-light normal-case"
                            >{{
                                $t(
                                    'So heisst er im Editor unter «+ Baustein» — leer übernimmt er den ersten Titel.',
                                )
                            }}</span
                        >
                        <span
                            v-if="errors.name"
                            class="text-danger block text-[12px] normal-case"
                            >{{ errors.name }}</span
                        >
                    </label>
                    <label
                        class="block text-[12px] font-semibold tracking-wide text-basalt-light uppercase"
                    >
                        {{ $t('Kennung') }}
                        <input
                            v-model="form.handle"
                            :disabled="!can_edit"
                            class="mt-1 block w-full rounded-md border border-ash px-2 py-1 font-mono text-[13px] normal-case"
                            @input="handleTouched = true"
                        />
                        <span
                            class="mt-1 block text-[11.5px] font-normal tracking-normal text-basalt-light normal-case"
                            >{{
                                $t(
                                    'Entsteht aus dem Namen; Seiten verweisen damit auf den Baustein.',
                                )
                            }}</span
                        >
                        <span
                            v-if="errors.handle"
                            class="text-danger block text-[12px] normal-case"
                            >{{ errors.handle }}</span
                        >
                    </label>
                </div>
                <div @focusin="onFocusIn">
                    <BlockList
                        v-model="form.blocks"
                        :schema="schema"
                        :allowed="allowed"
                        :media="media"
                        :errors="errors"
                        path="blocks"
                        :paste-url="urls.paste ?? ''"
                        :scope="$t('Bausteine')"
                        references="parts"
                    />
                </div>
                <BlockPreview
                    v-if="
                        mounted &&
                        previewOpen &&
                        previewUrl &&
                        form.blocks.length > 0
                    "
                    :url="previewUrl"
                    :payload="previewPayload"
                    :focus="previewFocus"
                    start-at-blocks
                    @pick="onPick"
                    @close="previewOpen = false"
                />
                <div
                    v-if="can_edit"
                    class="mt-4 flex flex-wrap justify-end gap-2 border-t border-ash-light pt-3"
                >
                    <Button
                        v-if="creating"
                        variant="secondary"
                        size="sm"
                        class="mr-auto"
                        @click="
                            creating = false;
                            current = snippets[0]?.handle ?? null;
                        "
                        >{{ $t('Abbrechen') }}</Button
                    >
                    <Button
                        v-if="
                            !previewOpen && previewUrl && form.blocks.length > 0
                        "
                        variant="secondary"
                        size="sm"
                        @click="previewOpen = true"
                        >{{ $t('Live-Vorschau') }}</Button
                    >
                    <Button
                        size="sm"
                        :disabled="!dirty || !form.name || !form.handle"
                        @click="save"
                        >{{ $t('Speichern') }}</Button
                    >
                </div>
                <div
                    v-if="snippet && snippet.used_in.length && !creating"
                    class="mt-3"
                >
                    <p
                        class="text-[12px] font-semibold tracking-wide text-basalt-light uppercase"
                    >
                        {{ $t('Steht auf') }}
                    </p>
                    <ul class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-[13px]">
                        <li
                            v-for="u in snippet.used_in"
                            :key="`${u.id}-${u.locale}`"
                        >
                            <Link :href="entryUrl(u)" class="underline">{{
                                u.title
                            }}</Link>
                            <span
                                class="font-mono text-[11px] text-basalt-light"
                                >{{ u.locale }}</span
                            >
                        </li>
                    </ul>
                </div>
            </Card>
            <div v-else class="rounded-lg border border-ash-light bg-white">
                <EmptyState
                    :title="$t('Keinen Baustein gewählt')"
                    :text="$t('Links einen Baustein wählen oder «+ Baustein».')"
                    icon="module"
                    tone="ash"
                />
            </div>
        </section>
    </div>

    <Modal
        :open="dissolveOpen !== null"
        :title="$t('«:name» auflösen?', { name: dissolveOpen?.name ?? '' })"
        :wide="false"
        @close="dissolveOpen = null"
    >
        <p class="text-sm">
            {{
                $t(
                    'In :pages werden die Blöcke zu eigenen Blöcken — nichts geht verloren. Danach ist der Baustein weg.',
                    { pages: usedLabel(dissolveOpen?.used_in.length ?? 0) },
                )
            }}
        </p>
        <div class="mt-4 flex gap-2">
            <Button variant="danger" @click="dissolve">{{
                $t('Auflösen')
            }}</Button>
            <Button variant="secondary" @click="dissolveOpen = null">{{
                $t('Abbrechen')
            }}</Button>
        </div>
    </Modal>
</template>
