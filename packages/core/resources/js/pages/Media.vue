<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import Button from '../components/Button.vue';
import { controlClasses, labelClasses } from '../components/control';
import EmptyState from '../components/EmptyState.vue';
import { t, tc } from '../i18n';
import {
    createUploadEntries,
    formatBytes,
    formatDate,
    kindOf,
    runUpload,
    uploadCounter,
    uploadRunning,
    type UploadEntry,
} from '../components/media';
import MediaDetail from '../components/MediaDetail.vue';
import Notice from '../components/Notice.vue';
import PageHeader from '../components/PageHeader.vue';
import StatusHex from '../components/StatusHex.vue';
import Table from '../components/Table.vue';
import type {
    BackendProps,
    MediaCounts,
    MediaDetail as MediaDetailData,
    MediaFilters,
    MediaFolder,
    MediaPagination,
    MediaQuota,
    MediaRow,
    MediaTypeFilter,
    MediaUrls,
    MediaView,
    PageLocaleOption,
} from '../types';
import { vDialog } from '../components/a11y';

/**
 * Die Mediathek (Mockup 14): drei Spalten ab 1024 px — Ordner, Kacheln oder Liste mit Suche und Dropzone, Detail.
 * Darunter gestapelt: Suche und Ordner-Auswahl oben, Kacheln zweispaltig, feste Leiste «Hochladen», Upload als
 * eigene Ansicht, Detail als Blatt. Der Mehrfach-Upload sendet jede Datei einzeln und zeigt ihren Stand.
 */
const props = withDefaults(
    defineProps<{
        folders?: MediaFolder[];
        counts?: MediaCounts;
        folder?: number | null;
        view?: MediaView;
        filters?: MediaFilters;
        media?: MediaRow[];
        pagination?: MediaPagination;
        selected?: MediaDetailData | null;
        variant_names?: string[];
        locales?: PageLocaleOption[];
        alt_required?: boolean;
        quota?: MediaQuota | null;
        storage_used?: number | null;
        trash_days?: number;
        can_manage?: boolean;
        /** TB 29: hochladen und Angaben pflegen (Stufe «bearbeiten») */
        can_upload?: boolean;
        urls?: MediaUrls;
        status?: string | null;
    }>(),
    {
        folders: () => [],
        counts: () => ({ all: 0, trash: 0 }),
        folder: null,
        view: 'library',
        filters: () => ({ q: '', type: '' }),
        media: () => [],
        pagination: () => ({ next: null, prev: null, page: 1, total: 0 }),
        selected: null,
        variant_names: () => [],
        locales: () => [],
        alt_required: false,
        quota: null,
        storage_used: null,
        trash_days: 30,
        can_manage: true,
        can_upload: true,
        urls: () => ({ index: '', upload: '', folders: '' }),
        status: null,
    },
);

const page = usePage<BackendProps>();
const isDesktop = useMediaQuery('(min-width: 1024px)');

const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);
const ownStatus = computed(() =>
    props.status && props.status !== page.props.backend.flash?.status
        ? props.status
        : null,
);

const indexUrl = computed(
    () => props.urls.index || page.url.split('?')[0] || '',
);

const lead = computed(() => {
    const n = props.counts.all;
    const parts = [tc(':count Datei|:count Dateien', n)];
    const used = props.storage_used ?? props.quota?.used ?? null;
    if (used !== null) {
        parts.push(t(':size im Speicher', { size: formatBytes(used) }));
    }
    parts.push(
        t('Varianten aus dem Theme: :names', {
            names: props.variant_names.length
                ? props.variant_names.join(', ')
                : '—',
        }),
    );

    return parts.join(' · ');
});

/* ── Kontingent ── */

const quotaPercent = computed(() =>
    props.quota && props.quota.limit > 0
        ? Math.round((props.quota.used / props.quota.limit) * 100)
        : 0,
);
const quotaFull = computed(
    () => props.quota !== null && props.quota.used >= props.quota.limit,
);
const quotaText = computed(() =>
    props.quota
        ? `${t('Speicher :used von :limit', {
              used: formatBytes(props.quota.used),
              limit: formatBytes(props.quota.limit),
          })} — ${
              quotaFull.value
                  ? t('Upload gesperrt.')
                  : t('Upload ab :limit gesperrt.', {
                        limit: formatBytes(props.quota.limit),
                    })
          }`
        : '',
);

/* ── Navigation: Ordner, Ansicht, Suche, Typ, Auswahl ── */

const q = ref(props.filters.q);
const type = ref<MediaTypeFilter>(props.filters.type);

watch(
    () => props.filters,
    (f) => {
        q.value = f.q;
        type.value = f.type;
    },
);

type VisitState = {
    folder: number | null;
    view: MediaView;
    q: string;
    type: MediaTypeFilter;
    media: number | null;
};

function visit(overrides: Partial<VisitState>, only?: string[]): void {
    const state: VisitState = {
        folder: props.folder,
        view: props.view,
        q: q.value.trim(),
        type: type.value,
        media: props.selected?.id ?? null,
        ...overrides,
    };
    const params: Record<string, string | number> = {};
    if (state.folder !== null) {
        params.folder = state.folder;
    }
    if (state.view === 'trash') {
        params.view = 'trash';
    }
    if (state.q) {
        params.q = state.q;
    }
    if (state.type) {
        params.type = state.type;
    }
    if (state.media !== null) {
        params.media = state.media;
    }
    router.get(indexUrl.value, params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only,
    });
}

function openFolder(id: number | null): void {
    visit({ folder: id, view: 'library', media: null });
}

function openTrash(): void {
    visit({ folder: null, view: 'trash', media: null });
}

function search(): void {
    visit({ q: q.value.trim(), media: null });
}

function setType(value: MediaTypeFilter): void {
    type.value = value;
    visit({ type: value, media: null });
}

const typeOptions = computed<{ value: MediaTypeFilter; label: string }[]>(
    () => [
        { value: '', label: t('Alle') },
        { value: 'image', label: t('Bilder') },
        { value: 'document', label: t('Dokumente') },
    ],
);

const filtered = computed(() => Boolean(props.filters.q || props.filters.type));

function select(row: MediaRow): void {
    if (props.selected?.id === row.id) {
        sheetOpen.value = !isDesktop.value;

        return;
    }
    visit({ media: row.id }, ['selected']);
}

/** Die Auswahl für schmale Fenster: Alle, Ordner, Papierkorb in einem Feld. */
const folderSelectValue = computed(() =>
    props.view === 'trash'
        ? 'trash'
        : props.folder === null
          ? 'all'
          : String(props.folder),
);

function changeFolderSelect(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    if (value === 'trash') {
        openTrash();
    } else {
        openFolder(value === 'all' ? null : Number(value));
    }
}

const activeFolderName = computed(() =>
    props.view === 'trash'
        ? t('Papierkorb')
        : (props.folders.find((f) => f.id === props.folder)?.name ?? t('Alle')),
);

/* ── Ordner anlegen, umbenennen, löschen (inline) ── */

const newFolderOpen = ref(false);
const newFolderName = ref('');
const newFolderInput = ref<HTMLInputElement | null>(null);
const renaming = ref<number | null>(null);
const renameValue = ref('');
/** Das Umbenennen-Feld steht in einem v-for — Vue sammelt den Ref als Liste, offen ist immer nur eines. */
const renameInput = ref<HTMLInputElement[] | HTMLInputElement | null>(null);

async function openNewFolder(): Promise<void> {
    newFolderOpen.value = true;
    await nextTick();
    newFolderInput.value?.focus();
}
const removingFolder = ref<number | null>(null);
const folderBusy = ref(false);

function createFolder(): void {
    const name = newFolderName.value.trim();
    if (!name || !props.urls.folders) {
        return;
    }
    folderBusy.value = true;
    router.post(
        props.urls.folders,
        { name, parent_id: props.view === 'library' ? props.folder : null },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                newFolderName.value = '';
                newFolderOpen.value = false;
            },
            onFinish: () => {
                folderBusy.value = false;
            },
        },
    );
}

async function startRename(f: MediaFolder): Promise<void> {
    renaming.value = f.id;
    renameValue.value = f.name;
    removingFolder.value = null;
    await nextTick();
    const input = Array.isArray(renameInput.value)
        ? renameInput.value[0]
        : renameInput.value;
    input?.focus();
    input?.select();
}

function renameFolder(f: MediaFolder): void {
    const name = renameValue.value.trim();
    if (!name || name === f.name) {
        renaming.value = null;

        return;
    }
    folderBusy.value = true;
    router.put(
        f.urls?.update ?? `${props.urls.folders}/${f.id}`,
        { name },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                renaming.value = null;
            },
            onFinish: () => {
                folderBusy.value = false;
            },
        },
    );
}

function deleteFolder(f: MediaFolder): void {
    removingFolder.value = null;
    folderBusy.value = true;
    router.delete(f.urls?.delete ?? `${props.urls.folders}/${f.id}`, {
        preserveScroll: true,
        onFinish: () => {
            folderBusy.value = false;
        },
    });
}

/* ── Mehrfach-Upload: Dropzone, Dateiauswahl, Stand je Datei ── */

const fileInput = ref<HTMLInputElement | null>(null);
const uploads = reactive<UploadEntry[]>([]);
const dragging = ref(false);
const mobileView = ref<'library' | 'upload'>('library');

const uploadActive = computed(() => uploadRunning(uploads));
const canUpload = computed(
    () =>
        props.can_upload &&
        Boolean(props.urls.upload) &&
        !quotaFull.value &&
        !uploadActive.value,
);

function pickFiles(event: Event): void {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';
    void uploadFiles(files);
}

function dropFiles(event: DragEvent): void {
    dragging.value = false;
    void uploadFiles(Array.from(event.dataTransfer?.files ?? []));
}

async function uploadFiles(files: File[]): Promise<void> {
    if (files.length === 0 || !canUpload.value) {
        return;
    }
    uploads.splice(0, uploads.length, ...createUploadEntries(files));
    mobileView.value = 'upload';
    await runUpload(props.urls.upload, files, uploads, props.folder);
    router.reload({
        only: ['media', 'counts', 'folders', 'quota', 'pagination'],
    });
}

function clearUploads(): void {
    uploads.splice(0, uploads.length);
    mobileView.value = 'library';
}

function uploadProgress(entry: UploadEntry): number {
    return entry.state === 'done' ? 100 : entry.state === 'uploading' ? 55 : 0;
}

/* ── Kacheln und Liste ── */

const layout = ref<'tiles' | 'list'>('tiles');

function meta(row: MediaRow): string {
    const parts: string[] = [];
    if (row.width && row.height) {
        parts.push(`${row.width} × ${row.height}`);
    } else if (row.mime === 'image/svg+xml') {
        parts.push(t('Vektor'));
    } else if (!row.is_image) {
        parts.push(kindOf(row.mime, row.name));
    }
    parts.push(formatBytes(row.size));

    return parts.join(' · ');
}

function altMissingCount(row: MediaRow): number {
    return props.alt_required ? (row.alt_missing?.length ?? 0) : 0;
}

/** Die ganze Mediathek ist leer (Mockup 14/2): zwei Spalten, der Leerzustand ist die Dropzone, kein Detail. */
const libraryEmpty = computed(
    () =>
        props.counts.all === 0 &&
        props.view === 'library' &&
        !filtered.value &&
        props.media.length === 0,
);

/* ── Mobil: Detail als Blatt ── */

const sheetOpen = ref(false);

watch(
    () => props.selected?.id,
    (id) => {
        if (id && !isDesktop.value) {
            sheetOpen.value = true;
        }
    },
    { immediate: true },
);

watch(isDesktop, (desktop) => {
    if (desktop) {
        sheetOpen.value = false;
    }
});

const segment =
    'rounded px-2 py-0.5 text-[11.5px] font-semibold transition-colors';
const folderOp =
    'rounded px-1 text-[11px] text-basalt-light hover:bg-paper hover:text-basalt';
</script>

<template>
    <Head :title="$t('Medien')" />
    <PageHeader
        :title="$t('Medien')"
        :crumb="$t(':site · Inhalte', { site: siteName })"
        :lead="lead"
    >
        <template #actions>
            <div
                class="flex gap-0.5 rounded-lg border border-ash-light bg-white p-[3px]"
                role="group"
                :aria-label="$t('Darstellung')"
            >
                <button
                    type="button"
                    :class="[
                        segment,
                        layout === 'tiles'
                            ? 'bg-basalt text-paper'
                            : 'text-basalt-light hover:text-basalt',
                    ]"
                    :aria-pressed="layout === 'tiles'"
                    @click="layout = 'tiles'"
                >
                    {{ $t('Kacheln') }}
                </button>
                <button
                    type="button"
                    :class="[
                        segment,
                        layout === 'list'
                            ? 'bg-basalt text-paper'
                            : 'text-basalt-light hover:text-basalt',
                    ]"
                    :aria-pressed="layout === 'list'"
                    @click="layout = 'list'"
                >
                    {{ $t('Liste') }}
                </button>
            </div>
            <Button
                v-if="isDesktop"
                :disabled="!canUpload"
                :title="quotaFull ? $t('Speicher voll') : undefined"
                @click="fileInput?.click()"
                >{{ $t('Hochladen') }}</Button
            >
        </template>
    </PageHeader>
    <input
        ref="fileInput"
        type="file"
        class="hidden"
        multiple
        accept="image/jpeg,image/png,image/webp,image/gif,image/svg+xml,application/pdf"
        @change="pickFiles"
    />

    <Notice v-if="ownStatus" tone="success" class="mb-4">{{
        ownStatus
    }}</Notice>
    <Notice
        v-if="quota && quotaPercent >= 90"
        :tone="quotaFull ? 'danger' : 'warning'"
        class="mb-4"
    >
        {{ quotaText }}
        <span class="font-mono">({{ quotaPercent }} %)</span>
    </Notice>

    <!-- Mobil: Upload als eigene Ansicht -->
    <div v-if="!isDesktop && mobileView === 'upload' && uploads.length > 0">
        <button
            type="button"
            class="mb-2 text-[12.5px] text-lava-dark underline"
            @click="clearUploads"
        >
            ← {{ $t('Medien') }}
        </button>
        <p class="mb-2 text-[12.5px] text-basalt-light">
            {{ uploadActive ? $t('Upload läuft') : $t('Upload beendet') }}:
            {{ uploadCounter(uploads) }}
        </p>
        <ul class="flex flex-col gap-2">
            <li
                v-for="(u, i) in uploads"
                :key="`${u.name}-${i}`"
                class="rounded-lg border bg-white px-3 py-2.5"
                :class="
                    u.state === 'rejected'
                        ? 'border-danger/35'
                        : u.state === 'done' &&
                            u.media?.variants_state === 'pending'
                          ? 'border-warning/50'
                          : 'border-ash-light'
                "
            >
                <div class="truncate text-[13px] font-semibold">
                    {{ u.name }}
                </div>
                <div class="text-[11.5px] text-basalt-light">
                    {{ formatBytes(u.size) }}
                    {{ u.state === 'done' ? `· ${$t('hochgeladen')}` : '' }}
                </div>
                <div
                    v-if="u.state !== 'rejected'"
                    class="mt-1.5 h-1 overflow-hidden rounded bg-ash-light"
                    role="progressbar"
                    :aria-valuenow="uploadProgress(u)"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="h-full bg-lava transition-[width]"
                        :style="{ width: `${uploadProgress(u)}%` }"
                    />
                </div>
                <p
                    v-if="u.state === 'rejected'"
                    class="text-danger mt-1 text-[11.5px] font-semibold"
                >
                    {{ u.error }}
                </p>
                <p
                    v-else-if="u.media?.variants_state === 'pending'"
                    class="text-warning mt-1 text-[11.5px]"
                >
                    {{ $t('Varianten werden erzeugt …') }}
                </p>
            </li>
        </ul>
    </div>

    <!-- Bibliothek: Ordner · Kacheln · Detail -->
    <div
        v-else
        class="grid gap-4"
        :class="
            libraryEmpty
                ? 'lg:grid-cols-[160px_1fr]'
                : 'lg:grid-cols-[160px_1fr_320px]'
        "
    >
        <!-- Ordner (Desktop) -->
        <aside v-if="isDesktop">
            <ul class="rounded-lg border border-ash-light bg-white">
                <li>
                    <button
                        type="button"
                        class="flex w-full items-center justify-between border-l-[3px] px-2.5 py-[7px] text-left text-[12.5px] hover:bg-paper"
                        :class="
                            view === 'library' && folder === null
                                ? 'border-lava font-semibold'
                                : 'border-transparent'
                        "
                        @click="openFolder(null)"
                    >
                        {{ $t('Alle') }}
                        <span
                            class="font-mono text-[10.5px] text-basalt-light"
                            >{{ counts.all }}</span
                        >
                    </button>
                </li>
                <li
                    v-for="f in folders"
                    :key="f.id"
                    class="group border-t border-ash-light"
                >
                    <div
                        v-if="renaming === f.id"
                        class="px-2 py-1"
                        :style="{ paddingLeft: `${8 + f.depth * 12}px` }"
                    >
                        <input
                            ref="renameInput"
                            v-model="renameValue"
                            type="text"
                            :class="controlClasses({ small: true })"
                            :aria-label="$t('Ordner umbenennen')"
                            @keydown.enter.prevent="renameFolder(f)"
                            @keydown.escape="renaming = null"
                            @blur="renameFolder(f)"
                        />
                    </div>
                    <div
                        v-else-if="removingFolder === f.id"
                        class="px-2.5 py-1.5 text-[11.5px]"
                        role="alert"
                    >
                        {{
                            $t(
                                '«:name» löschen? Dateien wandern nach «Alle».',
                                {
                                    name: f.name,
                                },
                            )
                        }}
                        <span class="mt-1 flex gap-2">
                            <button
                                type="button"
                                class="text-danger font-semibold underline"
                                :disabled="folderBusy"
                                @click="deleteFolder(f)"
                            >
                                {{ $t('Löschen') }}
                            </button>
                            <button
                                type="button"
                                class="underline"
                                @click="removingFolder = null"
                            >
                                {{ $t('Abbrechen') }}
                            </button>
                        </span>
                    </div>
                    <div
                        v-else
                        class="flex items-center border-l-[3px] text-[12.5px] hover:bg-paper"
                        :class="
                            view === 'library' && folder === f.id
                                ? 'border-lava font-semibold'
                                : 'border-transparent'
                        "
                    >
                        <button
                            type="button"
                            class="min-w-0 flex-1 truncate py-[7px] pr-1 text-left"
                            :style="{ paddingLeft: `${10 + f.depth * 12}px` }"
                            :title="f.name"
                            @click="openFolder(f.id)"
                        >
                            {{ f.name }}
                        </button>
                        <span
                            class="hidden gap-0.5 pr-1 group-focus-within:flex group-hover:flex"
                        >
                            <button
                                type="button"
                                :class="folderOp"
                                :title="$t('Umbenennen')"
                                :aria-label="$t('Ordner umbenennen')"
                                @click="startRename(f)"
                            >
                                ✎
                            </button>
                            <button
                                type="button"
                                :class="folderOp"
                                :title="$t('Löschen')"
                                :aria-label="$t('Ordner löschen')"
                                @click="removingFolder = f.id"
                            >
                                ✕
                            </button>
                        </span>
                        <span
                            class="pr-2.5 font-mono text-[10.5px] text-basalt-light group-focus-within:hidden group-hover:hidden"
                            >{{ f.count }}</span
                        >
                    </div>
                </li>
                <li class="border-t border-ash-light">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between border-l-[3px] px-2.5 py-[7px] text-left text-[12.5px] hover:bg-paper"
                        :class="
                            view === 'trash'
                                ? 'border-lava font-semibold'
                                : 'border-transparent'
                        "
                        @click="openTrash"
                    >
                        {{ $t('Papierkorb') }}
                        <span
                            class="font-mono text-[10.5px] text-basalt-light"
                            >{{ counts.trash }}</span
                        >
                    </button>
                </li>
            </ul>
            <div class="mt-2">
                <div v-if="newFolderOpen" class="flex flex-col gap-1">
                    <input
                        ref="newFolderInput"
                        v-model="newFolderName"
                        type="text"
                        :class="controlClasses({ small: true })"
                        :placeholder="$t('Name des Ordners')"
                        :aria-label="$t('Name des Ordners')"
                        @keydown.enter.prevent="createFolder"
                        @keydown.escape="newFolderOpen = false"
                    />
                    <span class="flex gap-2">
                        <Button
                            size="sm"
                            :disabled="folderBusy || !newFolderName.trim()"
                            @click="createFolder"
                            >{{ $t('Anlegen') }}</Button
                        >
                        <Button
                            variant="link"
                            size="sm"
                            @click="newFolderOpen = false"
                            >{{ $t('Abbrechen') }}</Button
                        >
                    </span>
                    <span
                        v-if="folder !== null && view === 'library'"
                        class="text-[10.5px] text-basalt-light"
                        >{{
                            $t('in «:folder»', { folder: activeFolderName })
                        }}</span
                    >
                </div>
                <Button
                    v-else
                    variant="link"
                    size="sm"
                    :disabled="!urls.folders"
                    @click="openNewFolder"
                    >+ {{ $t('Ordner') }}</Button
                >
            </div>
        </aside>

        <!-- Kacheln / Liste -->
        <section class="min-w-0">
            <!-- Suche, Ordner-Auswahl (schmal), Typ -->
            <div class="flex flex-wrap items-center gap-2">
                <form
                    class="relative min-w-0 flex-1 basis-44"
                    role="search"
                    @submit.prevent="search"
                >
                    <input
                        v-model="q"
                        type="search"
                        :class="controlClasses({ small: true })"
                        class="pr-7"
                        :placeholder="
                            $t('Suchen in «:folder» …', {
                                folder: activeFolderName,
                            })
                        "
                        :aria-label="$t('Mediathek durchsuchen')"
                        @keydown.enter.prevent="search"
                    />
                    <span
                        class="text-ash-dark pointer-events-none absolute top-1/2 right-2 -translate-y-1/2 text-xs"
                        aria-hidden="true"
                        >⌕</span
                    >
                </form>
                <template v-if="!isDesktop">
                    <label class="sr-only" for="media-folder">{{
                        $t('Ordner')
                    }}</label>
                    <select
                        id="media-folder"
                        :value="folderSelectValue"
                        :class="controlClasses({ small: true })"
                        class="w-auto max-w-[9rem]"
                        @change="changeFolderSelect"
                    >
                        <option value="all">
                            {{ $t('Alle') }} ({{ counts.all }})
                        </option>
                        <option
                            v-for="f in folders"
                            :key="f.id"
                            :value="String(f.id)"
                        >
                            {{ '– '.repeat(f.depth) }}{{ f.name }} ({{
                                f.count
                            }})
                        </option>
                        <option value="trash">
                            {{ $t('Papierkorb') }} ({{ counts.trash }})
                        </option>
                    </select>
                </template>
                <div
                    class="flex gap-0.5 rounded-lg border border-ash-light bg-white p-[3px]"
                    role="group"
                    :aria-label="$t('Typ')"
                >
                    <button
                        v-for="t in typeOptions"
                        :key="t.value"
                        type="button"
                        :class="[
                            segment,
                            type === t.value
                                ? 'bg-paper text-basalt shadow-[inset_0_-2px_0_var(--color-lava)]'
                                : 'text-basalt-light hover:text-basalt',
                        ]"
                        :aria-pressed="type === t.value"
                        @click="setType(t.value)"
                    >
                        {{ t.label }}
                    </button>
                </div>
            </div>

            <!-- Dropzone (Bibliothek) -->
            <div
                v-if="view === 'library' && isDesktop && !libraryEmpty"
                class="mt-2.5 rounded-lg border border-dashed px-3 py-3 text-center text-[12.5px] transition-colors"
                :class="
                    dragging
                        ? 'border-lava bg-lava/5'
                        : quotaFull
                          ? 'text-ash-dark border-ash-light bg-paper'
                          : 'border-ash bg-paper text-basalt-light'
                "
                @dragover.prevent="dragging = true"
                @dragleave="dragging = false"
                @drop.prevent="dropFiles"
            >
                <template v-if="quotaFull">
                    {{ $t('Speicher voll — Upload gesperrt.') }}
                </template>
                <template v-else>
                    {{ $t('Dateien hierher ziehen oder') }}
                    <button
                        type="button"
                        class="font-semibold text-basalt underline"
                        :disabled="!canUpload"
                        @click="fileInput?.click()"
                    >
                        {{ $t('auswählen') }}
                    </button>
                    <span class="mt-0.5 block text-[11px]">{{
                        $t(
                            'Mehrfach-Upload · JPG, PNG, WebP, GIF, SVG, PDF · bis 10 MB je Datei',
                        )
                    }}</span>
                </template>
            </div>

            <!-- Upload im Gang (Desktop) -->
            <div v-if="isDesktop && uploads.length > 0" class="mt-2">
                <div
                    class="flex flex-wrap items-center justify-between gap-2 text-[11.5px] text-basalt-light"
                >
                    <span
                        >{{
                            uploadActive
                                ? $t('Upload läuft')
                                : $t('Upload beendet')
                        }}:
                        <span
                            :class="
                                uploads.some((u) => u.state === 'rejected')
                                    ? 'text-danger font-semibold'
                                    : ''
                            "
                            >{{ uploadCounter(uploads) }}</span
                        ></span
                    >
                    <button
                        v-if="!uploadActive"
                        type="button"
                        class="underline"
                        @click="clearUploads"
                    >
                        {{ $t('Ausblenden') }}
                    </button>
                </div>
                <ul
                    class="mt-1 divide-y divide-ash-light rounded-lg border border-ash-light bg-white"
                >
                    <li
                        v-for="(u, i) in uploads"
                        :key="`${u.name}-${i}`"
                        class="flex flex-wrap items-center gap-x-3 gap-y-0.5 px-3 py-1.5 text-[12px]"
                    >
                        <StatusHex
                            :status="
                                u.state === 'done'
                                    ? u.media?.variants_state === 'pending'
                                        ? 'warning'
                                        : 'success'
                                    : u.state === 'rejected'
                                      ? 'danger'
                                      : u.state === 'uploading'
                                        ? 'lava'
                                        : 'draft'
                            "
                        />
                        <span class="min-w-0 flex-1 truncate font-semibold">{{
                            u.name
                        }}</span>
                        <span
                            class="font-mono text-[10.5px] text-basalt-light"
                            >{{ formatBytes(u.size) }}</span
                        >
                        <span
                            v-if="u.state !== 'rejected'"
                            class="h-1 w-24 overflow-hidden rounded bg-ash-light"
                            role="progressbar"
                            :aria-valuenow="uploadProgress(u)"
                            aria-valuemin="0"
                            aria-valuemax="100"
                        >
                            <span
                                class="block h-full bg-lava transition-[width]"
                                :style="{ width: `${uploadProgress(u)}%` }"
                            />
                        </span>
                        <span
                            v-if="u.state === 'rejected'"
                            class="text-danger basis-full font-semibold"
                            >{{ u.error }}</span
                        >
                        <span
                            v-else-if="u.media?.variants_state === 'pending'"
                            class="text-warning text-[11px]"
                            >{{ $t('Varianten werden erzeugt …') }}</span
                        >
                        <span
                            v-else-if="u.state === 'done'"
                            class="text-success text-[11px]"
                            >{{ $t('hochgeladen') }}</span
                        >
                    </li>
                </ul>
            </div>

            <!-- Leer: Papierkorb, kein Treffer, leerer Ordner, leere Mediathek (Mockup 14/2) -->
            <div
                v-if="media.length === 0"
                class="mt-2.5 rounded-lg border bg-white transition-colors"
                :class="dragging ? 'border-lava' : 'border-ash-light'"
                @dragover.prevent="dragging = true"
                @dragleave="dragging = false"
                @drop.prevent="dropFiles"
            >
                <EmptyState
                    v-if="view === 'trash'"
                    :title="$t('Der Papierkorb ist leer')"
                    :text="
                        $tc(
                            'Gelöschte Dateien bleiben hier :count Tag und lassen sich wiederherstellen.|Gelöschte Dateien bleiben hier :count Tage und lassen sich wiederherstellen.',
                            trash_days,
                        )
                    "
                    icon="check"
                    tone="success"
                />
                <EmptyState
                    v-else-if="filtered"
                    :title="$t('Kein Treffer')"
                    :text="
                        $t('Nichts in «:folder» passt zu Suche und Typ.', {
                            folder: activeFolderName,
                        })
                    "
                    icon="media"
                    tone="ash"
                >
                    <Button
                        variant="link"
                        size="sm"
                        @click="
                            q = '';
                            setType('');
                        "
                        >{{ $t('Filter zurücksetzen') }}</Button
                    >
                </EmptyState>
                <EmptyState
                    v-else-if="folder !== null"
                    :title="
                        $t('«:folder» ist leer', { folder: activeFolderName })
                    "
                    :text="
                        $t(
                            'Ziehen Sie Dateien hierher oder verschieben Sie welche über das Feld «Ordner» im Detail.',
                        )
                    "
                    icon="folder"
                    tone="ash"
                >
                    <Button
                        :disabled="!canUpload"
                        @click="fileInput?.click()"
                        >{{ $t('Hochladen') }}</Button
                    >
                </EmptyState>
                <EmptyState
                    v-else
                    :title="$t('Noch keine Dateien')"
                    :text="
                        $t(
                            'Ziehen Sie Bilder hierher — Varianten entstehen von selbst.',
                        )
                    "
                    icon="media"
                    tone="ash"
                >
                    <Button
                        :disabled="!canUpload"
                        @click="fileInput?.click()"
                        >{{ $t('Hochladen') }}</Button
                    >
                </EmptyState>
            </div>

            <!-- Kacheln -->
            <div
                v-else-if="layout === 'tiles'"
                class="mt-2.5 grid grid-cols-2 gap-2.5 sm:grid-cols-[repeat(auto-fill,minmax(150px,1fr))]"
                role="listbox"
                :aria-label="$t('Dateien')"
            >
                <button
                    v-for="m in media"
                    :key="m.id"
                    type="button"
                    role="option"
                    :aria-selected="selected?.id === m.id"
                    class="rounded-lg border bg-white p-2 text-left transition-colors"
                    :class="
                        selected?.id === m.id
                            ? 'border-lava-dark shadow-[0_0_0_2px_var(--color-lava-light)]'
                            : m.variants_state === 'pending'
                              ? 'border-warning/50 hover:border-warning'
                              : 'border-ash-light hover:border-ash'
                    "
                    @click="select(m)"
                >
                    <div
                        class="flex h-[70px] items-center justify-center overflow-hidden rounded bg-paper"
                    >
                        <img
                            v-if="m.url"
                            :src="m.url"
                            :alt="m.name"
                            class="h-full w-full object-cover"
                            loading="lazy"
                        />
                        <span
                            v-else
                            class="hex flex h-[27px] w-[30px] items-center justify-center bg-ash-light text-[8.5px] font-bold text-basalt-light"
                            aria-hidden="true"
                            >{{ kindOf(m.mime, m.name) }}</span
                        >
                    </div>
                    <div
                        class="mt-1.5 truncate text-[11.5px] font-semibold"
                        :title="m.name"
                    >
                        {{ m.name }}
                    </div>
                    <template v-if="m.variants_state === 'pending'">
                        <div class="text-warning text-[10.5px]">
                            {{ $t('Varianten werden erzeugt …') }}
                        </div>
                        <div
                            class="mt-1 h-1 w-full overflow-hidden rounded bg-ash-light"
                            aria-hidden="true"
                        >
                            <div class="bg-warning h-full w-3/5" />
                        </div>
                    </template>
                    <div v-else class="text-[10.5px] text-basalt-light">
                        {{ meta(m) }}
                    </div>
                    <div
                        v-if="altMissingCount(m) > 0"
                        class="text-danger mt-1 inline-flex items-center gap-1 text-[10.5px] font-semibold"
                        :title="
                            $t('Alt-Text fehlt: :locales', {
                                locales: m.alt_missing
                                    .map((l) => l.toUpperCase())
                                    .join(', '),
                            })
                        "
                    >
                        <span
                            class="hex bg-danger inline-flex h-3 w-[13px] items-center justify-center font-mono text-[9px] font-bold text-white"
                            aria-hidden="true"
                            >{{ altMissingCount(m) }}</span
                        >
                        {{ $t('Alt-Text fehlt') }}
                    </div>
                </button>
            </div>

            <!-- Liste -->
            <Table v-else class="mt-2.5">
                <template #head>
                    <tr>
                        <th class="px-3 py-2">{{ $t('Datei') }}</th>
                        <th class="px-3 py-2">{{ $t('Masse') }}</th>
                        <th class="px-3 py-2">{{ $t('Grösse') }}</th>
                        <th class="px-3 py-2">{{ $t('Zustand') }}</th>
                        <th class="px-3 py-2">{{ $t('Hochgeladen') }}</th>
                    </tr>
                </template>
                <tr
                    v-for="m in media"
                    :key="m.id"
                    :class="selected?.id === m.id ? 'bg-lava/5' : ''"
                >
                    <td class="px-3 py-2.5" :data-label="$t('Datei')">
                        <button
                            type="button"
                            class="flex max-w-full min-w-0 items-center gap-2 text-left"
                            @click="select(m)"
                        >
                            <img
                                v-if="m.url"
                                :src="m.url"
                                :alt="m.name"
                                class="h-7 w-9 shrink-0 rounded object-cover"
                                loading="lazy"
                            />
                            <span
                                v-else
                                class="hex flex h-[18px] w-5 shrink-0 items-center justify-center bg-ash-light text-[7px] font-bold text-basalt-light"
                                aria-hidden="true"
                                >{{ kindOf(m.mime, m.name) }}</span
                            >
                            <span
                                class="max-w-[18rem] truncate font-semibold"
                                :class="
                                    selected?.id === m.id
                                        ? 'text-lava-dark'
                                        : ''
                                "
                                >{{ m.name }}</span
                            >
                        </button>
                    </td>
                    <td
                        class="px-3 py-2.5 whitespace-nowrap text-basalt-light"
                        :data-label="$t('Masse')"
                    >
                        {{
                            m.width && m.height
                                ? `${m.width} × ${m.height}`
                                : m.mime === 'image/svg+xml'
                                  ? $t('Vektor')
                                  : kindOf(m.mime, m.name)
                        }}
                    </td>
                    <td
                        class="px-3 py-2.5 font-mono text-[11.5px] whitespace-nowrap text-basalt-light"
                        :data-label="$t('Grösse')"
                    >
                        {{ formatBytes(m.size) }}
                    </td>
                    <td class="px-3 py-2.5" :data-label="$t('Zustand')">
                        <StatusHex
                            v-if="m.variants_state === 'pending'"
                            status="warning"
                            :label="$t('Varianten werden erzeugt …')"
                        />
                        <StatusHex
                            v-else-if="altMissingCount(m) > 0"
                            status="danger"
                            :label="
                                $t('Alt-Text fehlt (:count)', {
                                    count: altMissingCount(m),
                                })
                            "
                        />
                        <StatusHex
                            v-else-if="m.variants_state === 'ready'"
                            status="success"
                            :label="$t('bereit')"
                        />
                        <StatusHex v-else status="none" :label="$t('Datei')" />
                    </td>
                    <td
                        class="px-3 py-2.5 text-[12px] whitespace-nowrap text-basalt-light"
                        :data-label="$t('Hochgeladen')"
                    >
                        {{ m.created_at ? formatDate(m.created_at) : '—'
                        }}{{ m.uploaded_by ? ` · ${m.uploaded_by}` : '' }}
                    </td>
                </tr>
                <template #foot>
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <span>{{
                            $t(':count auf dieser Seite · :total insgesamt', {
                                count: media.length,
                                total: pagination.total,
                            })
                        }}</span>
                        <span
                            v-if="pagination.prev || pagination.next"
                            class="inline-flex items-center gap-2.5"
                        >
                            <Link
                                v-if="pagination.prev"
                                :href="pagination.prev"
                                preserve-state
                                class="underline"
                                >← {{ $t('zurück') }}</Link
                            >
                            <span class="font-mono">{{
                                $t('Seite :page', { page: pagination.page })
                            }}</span>
                            <Link
                                v-if="pagination.next"
                                :href="pagination.next"
                                preserve-state
                                class="underline"
                                >{{ $t('weiter') }} →</Link
                            >
                        </span>
                    </div>
                </template>
            </Table>

            <!-- Seitennavigation (Kacheln) -->
            <p
                v-if="layout === 'tiles' && media.length > 0"
                class="mt-2.5 flex flex-wrap items-center justify-between gap-2 text-[11.5px] text-basalt-light"
            >
                <span>{{
                    $t(':count auf dieser Seite · :total insgesamt', {
                        count: media.length,
                        total: pagination.total,
                    })
                }}</span>
                <span
                    v-if="pagination.prev || pagination.next"
                    class="inline-flex items-center gap-2.5"
                >
                    <Link
                        v-if="pagination.prev"
                        :href="pagination.prev"
                        preserve-state
                        class="underline"
                        >← {{ $t('zurück') }}</Link
                    >
                    <span class="font-mono">{{
                        $t('Seite :page', { page: pagination.page })
                    }}</span>
                    <Link
                        v-if="pagination.next"
                        :href="pagination.next"
                        preserve-state
                        class="underline"
                        >{{ $t('weiter') }} →</Link
                    >
                </span>
            </p>
        </section>

        <!-- Detail (Desktop) -->
        <aside v-if="isDesktop && !libraryEmpty" class="min-w-0">
            <MediaDetail
                v-if="selected"
                :key="selected.id"
                :detail="selected"
                :locales="locales"
                :alt-required="alt_required"
                :variant-names="variant_names"
                :folders="folders"
                :view="view"
                :trash-days="trash_days"
                :can-manage="can_manage"
                :can-edit="can_upload"
            />
            <div
                v-else
                class="rounded-lg border border-dashed border-ash-light bg-white px-4 py-8 text-center text-[12.5px] text-basalt-light"
            >
                <span :class="labelClasses" class="mb-1 block">{{
                    $t('Detail')
                }}</span>
                {{
                    $t(
                        'Wählen Sie eine Datei — hier stehen Alt-Text je Sprache, Fokuspunkt, Varianten und Verwendung.',
                    )
                }}
            </div>
        </aside>
    </div>

    <!-- Mobil: feste Leiste unten, Detail als Blatt -->
    <div
        v-if="!isDesktop && mobileView === 'library' && !libraryEmpty"
        class="sticky bottom-0 z-30 -mx-4 mt-4 flex gap-2 border-t border-ash-light bg-paper px-4 py-2.5"
    >
        <Button wide :disabled="!canUpload" @click="fileInput?.click()">{{
            quotaFull ? $t('Speicher voll') : $t('Hochladen')
        }}</Button>
    </div>
    <div
        v-if="sheetOpen && !isDesktop && selected"
        class="fixed inset-0 z-40 flex items-end bg-basalt/40"
        @click.self="sheetOpen = false"
    >
        <div
            v-dialog="() => (sheetOpen = false)"
            class="scroll-y max-h-[88vh] w-full rounded-t-2xl border-t border-ash bg-white px-3 pt-2 pb-5"
            role="dialog"
            aria-modal="true"
            :aria-label="selected.name"
        >
            <div
                class="mx-auto mb-2 h-1 w-9 rounded bg-ash"
                aria-hidden="true"
            />
            <button
                type="button"
                class="mb-2 text-[12.5px] text-lava-dark underline"
                @click="sheetOpen = false"
            >
                ← {{ $t('Medien') }}
            </button>
            <MediaDetail
                :key="selected.id"
                :detail="selected"
                :locales="locales"
                :alt-required="alt_required"
                :variant-names="variant_names"
                :folders="folders"
                :view="view"
                :trash-days="trash_days"
                :can-manage="can_manage"
                :can-edit="can_upload"
            />
        </div>
    </div>
</template>
