<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '../components/Button.vue';
import Card from '../components/Card.vue';
import LavaEditor from '../components/LavaEditor.vue';
import {
    findingsText,
    isValidThemePath,
    severityOf,
} from '../components/lavaEditor';
import type { Finding } from '../components/lavaEditor';
import Modal from '../components/Modal.vue';
import Notice from '../components/Notice.vue';
import PageHeader from '../components/PageHeader.vue';
import StateTag from '../components/StateTag.vue';
import { fileSize } from '../components/themes';
import { requestError } from '../http';
import { locale, t } from '../i18n';
import { http } from '@inertiajs/vue3';

/**
 * Lava-Editor im Backend (L-17, Mockup 17 Bild 3): links die Dateien des Themes, in der Mitte der Code mit Befunden
 * des Compilers, rechts die Vorschau mit den Entwürfen. Gespeichert wird nur fehlerfrei — als Entwurf; live geht er
 * über «Einreichen» und «Veröffentlichen» (Vier-Augen, wenn die Site es verlangt), jede Veröffentlichung ist eine
 * Fassung, die sich zurückholen lässt.
 *
 * TB 22 (Theme-Bibliothek): bearbeitet jedes eigene Theme der Site, nicht nur das aktive (`?theme=<name>`), dazu
 * Bilder und Schriften — die gelten sofort, ohne Entwurf.
 */
type FileRow = {
    path: string;
    draft: boolean;
    deleted: boolean;
    editable: boolean;
};
type DraftRow = {
    path: string;
    deleted: boolean;
    submitted: boolean;
    mine: boolean;
};
type VersionRow = {
    id: number;
    number: number;
    note: string | null;
    files: number;
    published_at: string | null;
};

type LibraryRow = { name: string; label: string; active: boolean };
type AssetRow = { path: string; size: number };

const props = defineProps<{
    database: boolean;
    theme: string;
    theme_label: string | null;
    is_active: boolean;
    active_theme: string;
    replaces_folder: boolean;
    library: LibraryRow[];
    assets: AssetRow[];
    asset_types: string[];
    files: FileRow[];
    file: {
        path: string;
        text: string;
        exists: boolean;
        base: string;
        live: string | null;
        editable: boolean;
    };
    drafts: DraftRow[];
    findings: Finding[];
    versions: VersionRow[];
    four_eyes: boolean;
    preview: boolean;
    urls: Record<string, string>;
}>();

const page = usePage();
const pageError = computed(
    () => (page.props.errors as Record<string, string>).editor ?? '',
);

const text = ref(props.file.text);
const base = ref(props.file.base);
const findings = ref<Finding[]>(props.findings);
const saving = ref(false);
const saveError = ref('');
const saved = ref('');
const editor = ref<InstanceType<typeof LavaEditor> | null>(null);
const frameKey = ref(0);

const dirty = computed(() => text.value !== props.file.text);
const errors = computed(() =>
    findings.value.filter((f) => severityOf(f.severity) === 'error'),
);
const current = computed(() => props.versions[0] ?? null);
const status = computed(() => findingsText(findings.value));

/** Adressen des Editors mit dem Theme, das offen ist — ausser es ist das der Site (die Vorgabe). */
function withTheme(url: string): string {
    if (!props.database || props.is_active) {
        return url;
    }
    return `${url}${url.includes('?') ? '&' : '?'}theme=${encodeURIComponent(props.theme)}`;
}

function fileUrl(path: string): string {
    return withTheme(`${props.urls.self}?datei=${encodeURIComponent(path)}`);
}

function switchTheme(name: string): void {
    const own = props.library.find((row) => row.name === name);
    router.visit(
        own && !own.active
            ? `${props.urls.self}?theme=${encodeURIComponent(name)}`
            : props.urls.self,
    );
}

async function save(remove = false): Promise<void> {
    saving.value = true;
    saveError.value = '';
    saved.value = '';
    try {
        const response = await http.getClient().request({
            method: 'put',
            url: props.urls.save,
            data: {
                path: props.file.path,
                lava_source: text.value,
                delete: remove,
                base: base.value,
                theme:
                    props.database && !props.is_active
                        ? props.theme
                        : undefined,
            },
            headers: { Accept: 'application/json' },
        });
        const data = JSON.parse(response.data) as {
            message: string;
            base: string;
        };
        base.value = data.base;
        saved.value = data.message;
        frameKey.value++;
        router.reload({ only: ['files', 'drafts', 'file', 'findings'] });
    } catch (error) {
        saveError.value = requestError(error);
        const e = error as { response?: { data?: unknown } };
        if (typeof e.response?.data === 'string') {
            try {
                const parsed = JSON.parse(e.response.data) as {
                    findings?: Finding[];
                };
                if (parsed.findings) {
                    findings.value = parsed.findings;
                }
            } catch {
                /* kein JSON */
            }
        }
    } finally {
        saving.value = false;
    }
}

const newOpen = ref(false);
const newPath = ref('');
const newPathValid = computed(
    () =>
        isValidThemePath(newPath.value) &&
        !props.files.some((f) => f.path === newPath.value),
);
function openNew(): void {
    if (newPathValid.value) {
        router.visit(fileUrl(newPath.value));
    }
}

const publishForm = useForm({ note: '' });
const releaseOpen = ref(false);
const importForm = useForm<{ package: File | null }>({ package: null });

function post(url: string, data: Record<string, unknown> = {}): void {
    router.post(withTheme(url), data as never, { preserveScroll: true });
}

const assetForm = useForm<{ file: File | null; folder: string }>({
    file: null,
    folder: 'assets',
});
const assetInput = ref<HTMLInputElement | null>(null);
function uploadAsset(): void {
    assetForm.post(withTheme(props.urls.asset), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            assetForm.reset('file');
            if (assetInput.value) {
                assetInput.value.value = '';
            }
        },
    });
}
const removingAsset = ref<string | null>(null);
function deleteAsset(): void {
    if (removingAsset.value) {
        router.delete(withTheme(props.urls.asset_destroy), {
            data: { path: removingAsset.value },
            preserveScroll: true,
            onFinish: () => (removingAsset.value = null),
        });
    }
}

function goToDefinition(target: { file: string; line: number }): void {
    router.visit(`${fileUrl(target.file)}#zeile-${target.line}`);
}

/** Datum und Zeit im Format der Sprache der Oberfläche (de-CH, en-GB, it-CH, fr-CH). */
function dateTime(value: string): string {
    return new Date(value).toLocaleString(
        `${locale()}-${locale() === 'en' ? 'GB' : 'CH'}`,
    );
}

const sourceLabel = computed(() =>
    props.database ? t('Datenbank') : t('Ordner'),
);

const previewPath = ref('/');
const previewUrl = computed(() =>
    withTheme(
        `${props.urls.preview_page}?pfad=${encodeURIComponent(previewPath.value || '/')}`,
    ),
);
const languageUrl = computed(() => withTheme(props.urls.language));
</script>

<template>
    <Head :title="$t('Theme-Editor')" />
    <PageHeader
        :title="$t('Theme-Editor')"
        :crumb="
            $t('Einrichtung · Theme «:theme» (:source)', {
                theme: theme_label ?? theme,
                source: sourceLabel,
            })
        "
    >
        <template #lead>
            <template v-if="database">
                {{ $t('Fassung :number', { number: current?.number ?? '–' }) }}
                ·
                <b>{{
                    drafts.length > 0
                        ? $tc(':count Entwurf|:count Entwürfe', drafts.length)
                        : $t('keine Entwürfe')
                }}</b>
                · {{ $t('geprüft:') }}
                <span
                    :class="errors.length > 0 ? 'text-danger' : 'text-success'"
                    >{{ status }}</span
                >
            </template>
            <template v-else>
                {{ $t('Das Theme liegt im Ordner') }}
                <span class="font-mono">themes/{{ theme }}</span
                >.
                {{
                    $t(
                        'Zum Bearbeiten im Backend übernimmt die Site es in die Datenbank — die Dateien im Ordner bleiben unverändert.',
                    )
                }}
            </template>
        </template>
        <template v-if="database" #actions>
            <Button variant="secondary" size="sm" @click="editor?.check()">{{
                $t('Jetzt prüfen')
            }}</Button>
            <Button
                size="sm"
                :disabled="
                    !file.editable ||
                    saving ||
                    errors.some((f) => f.file === file.path) ||
                    (!dirty && file.exists)
                "
                @click="save()"
            >
                {{ saving ? $t('Speichert …') : $t('Als Entwurf speichern') }}
            </Button>
        </template>
    </PageHeader>

    <div
        v-if="library.length > 0"
        class="mb-4 flex flex-wrap items-center gap-3 text-sm"
    >
        <label class="flex items-center gap-2">
            {{ $t('Theme') }}
            <select
                class="rounded border border-ash bg-white px-2 py-1"
                :value="database ? theme : ''"
                @change="
                    switchTheme(($event.target as HTMLSelectElement).value)
                "
            >
                <option v-if="!library.some((row) => row.active)" value="">
                    {{ $t(':name (Ordner, aktiv)', { name: active_theme }) }}
                </option>
                <option
                    v-for="row in library"
                    :key="row.name"
                    :value="row.name"
                >
                    {{
                        row.active
                            ? $t(':name (aktiv)', { name: row.label })
                            : row.label
                    }}
                </option>
            </select>
        </label>
        <Link :href="urls.themes" class="underline">{{
            $t('Alle Themes')
        }}</Link>
    </div>
    <Notice v-if="database && !is_active" class="mb-4">
        {{
            $t(
                'Dieses Theme ist nicht aktiv — Änderungen sehen Sie in der Vorschau; die Site zeigt sie erst, wenn Sie es unter «Themes» wählen.',
            )
        }}
    </Notice>
    <Notice v-if="pageError" tone="danger" class="mb-4" role="alert">{{
        pageError
    }}</Notice>
    <Notice v-if="preview" class="mb-4" role="status">
        {{ $t('Die Vorschau mit den Entwürfen ist in dieser Sitzung an.') }}
        <Link :href="withTheme(urls.preview_end)" class="underline">{{
            $t('Vorschau beenden')
        }}</Link>
    </Notice>

    <Card
        v-if="!database"
        :title="$t('In die Datenbank übernehmen')"
        class="mb-6"
    >
        <p class="mb-3 text-sm">
            {{
                $t(
                    'Danach ändert die Site ihr Theme hier: Dateien als Entwürfe, geprüft vor dem Speichern, Vorschau, Freigabe und Fassungen, dazu eigene Bilder und Schriften. «Zurück zum Ordner» ist jederzeit möglich. Für ein neues Theme neben dem aktiven legen Sie unter «Themes» eine Kopie an.',
                )
            }}
        </p>
        <div class="flex flex-wrap gap-3">
            <Button @click="post(urls.adopt)">{{
                $t('In die Datenbank übernehmen')
            }}</Button>
            <Button variant="secondary" :href="urls.themes">{{
                $t('Zu den Themes')
            }}</Button>
        </div>
    </Card>

    <div v-else class="grid gap-4 xl:grid-cols-[190px_minmax(0,1fr)_320px]">
        <nav :aria-label="$t('Dateien des Themes')">
            <ul class="space-y-0.5 text-sm">
                <li v-for="f in files" :key="f.path">
                    <Link
                        :href="fileUrl(f.path)"
                        class="flex items-center justify-between gap-2 rounded px-2 py-1 font-mono text-[12.5px] break-all hover:bg-paper"
                        :class="
                            f.path === file.path
                                ? 'bg-paper font-semibold text-lava-dark'
                                : ''
                        "
                        :aria-current="
                            f.path === file.path ? 'page' : undefined
                        "
                    >
                        <span :class="f.deleted ? 'line-through' : ''">{{
                            f.path
                        }}</span>
                        <StateTag v-if="f.draft" tone="warning">{{
                            f.deleted ? $t('weg') : $t('Entwurf')
                        }}</StateTag>
                    </Link>
                </li>
            </ul>
            <div class="mt-3">
                <Button variant="link" size="sm" @click="newOpen = true">{{
                    $t('+ Datei')
                }}</Button>
            </div>
        </nav>

        <div class="min-w-0">
            <p class="mb-2 flex flex-wrap items-center gap-2 text-sm">
                <span class="font-mono">{{ file.path }}</span>
                <StateTag v-if="!file.exists" tone="lava">{{
                    $t('neu')
                }}</StateTag>
                <StateTag
                    v-else-if="file.live === null || file.text !== file.live"
                    tone="warning"
                    >{{ $t('Entwurf') }}</StateTag
                >
                <StateTag v-else tone="success">{{ $t('live') }}</StateTag>
                <span v-if="dirty" class="text-basalt-light"
                    >· {{ $t('ungespeichert') }}</span
                >
            </p>
            <LavaEditor
                v-if="file.editable"
                ref="editor"
                v-model="text"
                :path="file.path"
                :language-url="languageUrl"
                :label="$t('Quelltext :path', { path: file.path })"
                @findings="findings = $event"
                @definition="goToDefinition"
            />
            <Notice v-else tone="grey">{{
                $t(
                    'Diese Datei ist nicht als Text bearbeitbar — Bilder und Schriften verwalten Sie unten unter «Bilder und Schriften».',
                )
            }}</Notice>

            <Notice v-if="saveError" tone="danger" class="mt-3" role="alert">
                {{ saveError }}
                <span class="mt-1 block text-[12px]">{{
                    $t(
                        'Speichern ist gesperrt, bis die Prüfung fehlerfrei ist — ein Theme mit Fehler geht nie live.',
                    )
                }}</span>
            </Notice>
            <Notice
                v-else-if="saved"
                tone="success"
                class="mt-3"
                role="status"
                >{{ saved }}</Notice
            >

            <ul
                v-if="findings.length > 0"
                class="mt-3 space-y-1 text-sm"
                :aria-label="$t('Befunde')"
            >
                <li v-for="(f, i) in findings" :key="i" class="flex gap-2">
                    <StateTag
                        :tone="
                            severityOf(f.severity) === 'error'
                                ? 'danger'
                                : 'warning'
                        "
                    >
                        {{
                            severityOf(f.severity) === 'error'
                                ? $t('E')
                                : $t('W')
                        }}
                    </StateTag>
                    <button
                        v-if="f.file === file.path"
                        type="button"
                        class="font-mono text-[12.5px] underline"
                        @click="editor?.goToLine(f.line)"
                    >
                        {{ f.file }}:{{ f.line }}:{{ f.column }}
                    </button>
                    <Link
                        v-else
                        :href="fileUrl(f.file)"
                        class="font-mono text-[12.5px] underline"
                    >
                        {{ f.file }}:{{ f.line }}:{{ f.column }}
                    </Link>
                    <span>{{ f.message }}</span>
                </li>
            </ul>

            <div class="mt-3 flex flex-wrap gap-3">
                <Button
                    v-if="
                        file.exists &&
                        file.live !== null &&
                        file.text !== file.live
                    "
                    variant="secondary"
                    size="sm"
                    @click="post(urls.discard, { path: file.path })"
                >
                    {{ $t('Entwurf dieser Datei verwerfen') }}
                </Button>
                <Button
                    v-if="file.exists && file.path !== 'theme.lava'"
                    variant="danger"
                    size="sm"
                    :disabled="saving"
                    @click="save(true)"
                >
                    {{ $t('Datei löschen (als Entwurf)') }}
                </Button>
            </div>
        </div>

        <aside :aria-label="$t('Vorschau')">
            <div
                class="overflow-hidden rounded-lg border border-ash-light bg-white"
            >
                <div
                    class="flex items-center justify-between gap-2 border-b border-ash-light px-3 py-1.5 text-[12px]"
                >
                    <label class="flex items-center gap-1">
                        {{ $t('Vorschau') }}
                        <input
                            v-model.lazy="previewPath"
                            class="w-32 rounded border border-ash px-1 font-mono text-[12px]"
                            :aria-label="$t('Pfad der Seite in der Vorschau')"
                        />
                    </label>
                    <a
                        :href="previewUrl"
                        target="_blank"
                        rel="noopener"
                        class="underline"
                        >{{ $t('im neuen Tab') }}</a
                    >
                </div>
                <iframe
                    v-if="drafts.length > 0"
                    :key="frameKey"
                    :src="previewUrl"
                    :title="$t('Vorschau der Seite mit den Entwürfen')"
                    class="h-[520px] w-full"
                />
                <p v-else class="p-3 text-sm text-basalt-light">
                    {{
                        $t(
                            'Ohne Entwürfe zeigt die Vorschau dasselbe wie die Site. Nach dem ersten Speichern erscheint sie hier.',
                        )
                    }}
                </p>
            </div>
            <p class="mt-2 text-[12px] text-basalt-light">
                {{
                    $t(
                        'Aktualisiert nach jedem Speichern; Laufzeitgrenzen gelten auch hier.',
                    )
                }}
            </p>
            <p class="mt-1 text-[12px]">
                <a :href="withTheme(urls.preview)" class="underline">{{
                    $t('Ganze Site mit den Entwürfen durchsehen')
                }}</a>
                {{ $t('(nur in dieser Sitzung)') }}
            </p>
        </aside>
    </div>

    <div v-if="database" class="mt-8 grid gap-4 lg:grid-cols-2">
        <Card
            :title="$t('Freigabe')"
            :status="drafts.length > 0 ? 'warning' : 'success'"
        >
            <p v-if="drafts.length === 0" class="text-sm">
                {{
                    $t('Keine offenen Entwürfe — live ist Fassung :number.', {
                        number: current?.number ?? '',
                    })
                }}
            </p>
            <template v-else>
                <ul class="mb-3 space-y-1 text-sm">
                    <li
                        v-for="d in drafts"
                        :key="d.path"
                        class="flex items-center gap-2"
                    >
                        <span
                            class="font-mono text-[12.5px]"
                            :class="d.deleted ? 'line-through' : ''"
                            >{{ d.path }}</span
                        >
                        <StateTag v-if="d.submitted" tone="lava">{{
                            $t('eingereicht')
                        }}</StateTag>
                    </li>
                </ul>
                <p
                    v-if="four_eyes"
                    class="mb-3 text-[12.5px] text-basalt-light"
                >
                    {{
                        $t(
                            'Vier-Augen-Prinzip: Wer Entwürfe geschrieben oder eingereicht hat, gibt sie nicht selbst frei.',
                        )
                    }}
                </p>
                <form
                    class="space-y-3"
                    @submit.prevent="
                        publishForm.post(withTheme(urls.publish), {
                            preserveScroll: true,
                        })
                    "
                >
                    <label class="block text-sm">
                        {{ $t('Notiz zur Fassung (freiwillig)') }}
                        <input
                            v-model="publishForm.note"
                            maxlength="500"
                            class="mt-1 w-full rounded border border-ash px-2 py-1"
                        />
                    </label>
                    <div class="flex flex-wrap gap-3">
                        <Button
                            variant="secondary"
                            size="sm"
                            @click="post(urls.submit)"
                            >{{ $t('Einreichen') }}</Button
                        >
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="
                                publishForm.processing || errors.length > 0
                            "
                            >{{ $t('Veröffentlichen') }}</Button
                        >
                        <Button
                            variant="link"
                            size="sm"
                            @click="post(urls.discard)"
                            >{{ $t('Alle verwerfen') }}</Button
                        >
                    </div>
                </form>
            </template>
        </Card>

        <Card :title="$t('Fassungen')">
            <ul class="space-y-2 text-sm">
                <li
                    v-for="v in versions"
                    :key="v.id"
                    class="flex flex-wrap items-center justify-between gap-2"
                >
                    <span>
                        <b>{{ $t('Fassung :number', { number: v.number }) }}</b>
                        ·
                        {{ v.published_at ? dateTime(v.published_at) : '' }}
                        · {{ $tc(':count Datei|:count Dateien', v.files) }}
                        <span v-if="v.note" class="block text-basalt-light">{{
                            v.note
                        }}</span>
                    </span>
                    <Button
                        v-if="v.id !== current?.id"
                        variant="secondary"
                        size="sm"
                        @click="post(urls.restore.replace(/\/0$/, `/${v.id}`))"
                    >
                        {{ $t('Als Entwurf zurückholen') }}
                    </Button>
                    <StateTag v-else tone="success">{{ $t('live') }}</StateTag>
                </li>
            </ul>
        </Card>

        <Card :title="$t('Paket')">
            <p class="mb-3 text-sm">
                {{
                    $t(
                        'Export als ZIP mit Manifest und Prüfsummen (unsigniert). Ein Import wird geprüft und kommt als Entwurf — live geht er wie jede Änderung.',
                    )
                }}
            </p>
            <form
                class="flex flex-wrap items-end gap-3"
                @submit.prevent="
                    importForm.post(withTheme(urls.import), {
                        forceFormData: true,
                        preserveScroll: true,
                    })
                "
            >
                <a
                    :href="withTheme(urls.export)"
                    download
                    class="inline-flex items-center rounded-md border border-ash bg-white px-3 py-1.5 text-sm text-basalt hover:bg-paper"
                    >{{ $t('Exportieren') }}</a
                >
                <label class="text-sm">
                    <span class="sr-only">{{ $t('Theme-Paket (ZIP)') }}</span>
                    <input
                        type="file"
                        accept=".zip,application/zip"
                        class="text-sm"
                        @change="
                            importForm.package =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    />
                </label>
                <Button
                    type="submit"
                    size="sm"
                    :disabled="!importForm.package || importForm.processing"
                    >{{ $t('Importieren') }}</Button
                >
            </form>
            <p
                v-if="importForm.errors.package"
                class="text-danger mt-2 text-sm"
            >
                {{ importForm.errors.package }}
            </p>
        </Card>

        <Card :title="$t('Bilder und Schriften')">
            <p class="mb-3 text-sm">
                {{
                    $t(
                        'Gelten sofort, ohne Entwurf — im Theme als Pfad verwenden, z. B. assets/bilder/held.webp. Erlaubt: :types, je Datei höchstens 5 MB.',
                        { types: asset_types.join(', ') },
                    )
                }}
            </p>
            <ul v-if="assets.length > 0" class="mb-3 space-y-1 text-sm">
                <li
                    v-for="asset in assets"
                    :key="asset.path"
                    class="flex flex-wrap items-center justify-between gap-2"
                >
                    <span class="font-mono text-[12.5px] break-all">{{
                        asset.path
                    }}</span>
                    <span class="flex items-center gap-3">
                        <span class="text-[12px] text-basalt-light">{{
                            fileSize(asset.size, locale())
                        }}</span>
                        <button
                            type="button"
                            class="text-danger text-[12.5px] underline"
                            @click="removingAsset = asset.path"
                        >
                            {{ $t('Löschen') }}
                        </button>
                    </span>
                </li>
            </ul>
            <p
                v-else-if="replaces_folder"
                class="mb-3 text-[12.5px] text-basalt-light"
            >
                {{
                    $t(
                        'Noch keine eigenen — Bilder und Schriften kommen aus dem Ordner themes/:name.',
                        { name: theme },
                    )
                }}
            </p>
            <form
                class="flex flex-wrap items-end gap-3"
                @submit.prevent="uploadAsset"
            >
                <label class="text-sm">
                    {{ $t('Ordner') }}
                    <input
                        v-model="assetForm.folder"
                        class="mt-1 block w-40 rounded border border-ash px-2 py-1 font-mono text-[12.5px]"
                    />
                </label>
                <label class="text-sm">
                    <span class="sr-only">{{ $t('Bild oder Schrift') }}</span>
                    <input
                        ref="assetInput"
                        type="file"
                        :accept="
                            asset_types.map((type) => `.${type}`).join(',')
                        "
                        class="text-sm"
                        @change="
                            assetForm.file =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    />
                </label>
                <Button
                    type="submit"
                    size="sm"
                    :disabled="!assetForm.file || assetForm.processing"
                    >{{ $t('Hochladen') }}</Button
                >
            </form>
            <p
                v-if="assetForm.errors.file || assetForm.errors.folder"
                class="text-danger mt-2 text-sm"
                role="alert"
            >
                {{ assetForm.errors.file ?? assetForm.errors.folder }}
            </p>
        </Card>

        <Card v-if="replaces_folder" :title="$t('Zurück zum Ordner')">
            <p class="mb-3 text-sm">
                {{ $t('Die Site nutzt dann wieder') }}
                <span class="font-mono">themes/{{ theme }}</span
                >.
                {{
                    $t(
                        'Ihre Fassung bleibt mit Entwürfen und Fassungen als eigenes Theme in der Bibliothek.',
                    )
                }}
            </p>
            <Button variant="danger" size="sm" @click="releaseOpen = true">{{
                $t('Zurück zum Ordner …')
            }}</Button>
        </Card>
    </div>

    <Modal
        :open="newOpen"
        :title="$t('Neue Datei')"
        :subtitle="$t('Pfad im Theme, z. B. templates/aktion.lava')"
        :wide="false"
        @close="newOpen = false"
    >
        <form @submit.prevent="openNew">
            <label class="block text-sm">
                {{ $t('Pfad') }}
                <input
                    v-model="newPath"
                    class="mt-1 w-full rounded border border-ash px-2 py-1 font-mono"
                />
            </label>
            <p v-if="newPath && !newPathValid" class="text-danger mt-2 text-sm">
                {{
                    $t(
                        'Erlaubt: Ordner mit «/», Endung .lava, .html, .css, .js, .json, .svg, .txt oder .md — und noch nicht vorhanden.',
                    )
                }}
            </p>
            <div class="mt-4 flex gap-3">
                <Button type="submit" :disabled="!newPathValid">{{
                    $t('Öffnen')
                }}</Button>
                <Button variant="secondary" @click="newOpen = false">{{
                    $t('Abbrechen')
                }}</Button>
            </div>
        </form>
    </Modal>

    <Modal
        :open="releaseOpen"
        :title="$t('Zurück zum Ordner?')"
        :wide="false"
        @close="releaseOpen = false"
    >
        <p class="text-sm">
            {{
                $t(
                    'Die Site zeigt sofort das Theme aus dem Ordner. Ihre Fassung bleibt unter neuem Namen in der Bibliothek — wählbar und bearbeitbar wie jedes eigene Theme.',
                )
            }}
        </p>
        <div class="mt-4 flex gap-3">
            <Button variant="danger" @click="post(urls.release)">{{
                $t('Zurück zum Ordner')
            }}</Button>
            <Button variant="secondary" @click="releaseOpen = false">{{
                $t('Abbrechen')
            }}</Button>
        </div>
    </Modal>

    <Modal
        :open="removingAsset !== null"
        :title="$t('«:path» löschen?', { path: removingAsset ?? '' })"
        :wide="false"
        @close="removingAsset = null"
    >
        <p class="text-sm">
            {{
                $t(
                    'Die Datei ist sofort weg. Verweist das Theme noch auf sie, fehlt sie auf der Site.',
                )
            }}
        </p>
        <div class="mt-4 flex gap-3">
            <Button variant="danger" @click="deleteAsset">{{
                $t('Löschen')
            }}</Button>
            <Button variant="secondary" @click="removingAsset = null">{{
                $t('Abbrechen')
            }}</Button>
        </div>
    </Modal>
</template>
