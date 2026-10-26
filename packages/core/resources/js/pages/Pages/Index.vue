<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    computed,
    h,
    nextTick,
    reactive,
    ref,
    watch,
    type FunctionalComponent,
} from 'vue';
import {
    announceError,
    announceSaved,
    announceSaving,
    autosaveToast,
} from '../../components/autosave';
import Button from '../../components/Button.vue';
import { controlClasses, labelClasses } from '../../components/control';
import EmptyState from '../../components/EmptyState.vue';
import Hex from '../../components/Hex.vue';
import Notice from '../../components/Notice.vue';
import PageHeader from '../../components/PageHeader.vue';
import {
    needsTarget,
    redirectSourceLabel,
    redirectStatusOptions,
    redirectStatusTone,
    redirectStatusWord,
    shortReferrer,
    tabFromUrl,
} from '../../components/redirects';
import StateTag from '../../components/StateTag.vue';
import StatusHex from '../../components/StatusHex.vue';
import Table from '../../components/Table.vue';
import Tabs from '../../components/Tabs.vue';
import TranslationMark from '../../components/TranslationMark.vue';
import {
    isSelectable,
    pagesCount,
    pruneSelection,
    toggleAll,
    toggleSelection,
} from '../../components/workflow';
import {
    pageTypeIcon,
    pathOf,
    versionLabels,
    versionStatus,
} from '../../support';
import type {
    BackendProps,
    PageCounts,
    PageFilters,
    PageLocaleOption,
    PageNotFoundRow,
    PageRedirectRow,
    PageTrashRow,
    PageTreeRow,
    PageTypeOption,
    StatusOption,
    TabDef,
} from '../../types';
import type { BulkAction, WorkflowPageRow } from '../../types-workflow';
import { t } from '../../i18n';
import { vDialog } from '../../components/a11y';

/**
 * Die Station «Seiten» (Mockup 28/1): der Seitenbaum der Site mit Typ-Symbol im Sechseck (Seiten dunkel, Struktur hell),
 * Pfad, Statuspunkten je Sprache, «Geändert» und Operationen als Symbolknöpfe je Zeile; daneben Weiterleitungen und
 * Papierkorb als echte Reiter mit Zahl. Der Reiter «Umleitungen» legt neue an (auch per CSV) und ändert sie direkt in
 * der Zeile (speichert beim Verlassen oder mit Enter, Escape bricht ab); «404-Treffer» zeigt Adressen ohne Ziel und
 * macht mit einem Klick eine Umleitung daraus. `?tab=not_found` öffnet den Reiter (Cockpit-Aufgabe).
 * Rechts der Reiter die Filterzeile (Status, Sprache, Suche): Treffer bleiben,
 * Vorfahren eines Treffers stehen gedimmt. Verschieben per Dialog «Verschieben nach …» (mit nach oben / nach unten)
 * oder durch Ziehen (HTML5, zwischen Geschwistern und auf eine Elternseite). Je Zeile die Freigabe der Standardsprache
 * und «Kopie anlegen»; Seiten mit Inhalt lassen sich auswählen und gesammelt einreichen, veröffentlichen, löschen.
 */
const props = defineProps<{
    tree: WorkflowPageRow[];
    redirects: PageRedirectRow[];
    not_found?: PageNotFoundRow[];
    import_errors?: string[];
    trash: PageTrashRow[];
    types: PageTypeOption[];
    locales: PageLocaleOption[];
    filters: PageFilters;
    status_options: StatusOption[];
    counts: PageCounts;
    can_edit: boolean;
    can_publish?: boolean;
    urls: {
        create: string;
        bulk?: string;
        redirect_store?: string;
        redirect_import?: string;
    };
}>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);
const moveError = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.move ?? null;
});
const bulkError = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.bulk ?? errors?.workflow ?? errors?.ids ?? null;
});

/* Platzhalter, solange die PHP-Seite die neuen Props noch nicht liefert */
const emptyFilters: PageFilters = { status: '', locale: '', q: '' };
const notFound = computed<PageNotFoundRow[]>(() => props.not_found ?? []);
const counts = computed<PageCounts>(
    () =>
        props.counts ?? {
            tree: props.tree.length,
            redirects: props.redirects.length,
            not_found: notFound.value.length,
            trash: props.trash.length,
        },
);

const tabs = computed<TabDef[]>(() => [
    { key: 'tree', label: t('Baum'), count: counts.value.tree },
    {
        key: 'redirects',
        label: t('Umleitungen'),
        count: counts.value.redirects,
    },
    {
        key: 'not_found',
        label: t('404-Treffer'),
        count: counts.value.not_found ?? notFound.value.length,
    },
    { key: 'trash', label: t('Papierkorb'), count: counts.value.trash },
]);
const tabKeys = ['tree', 'redirects', 'not_found', 'trash'];
/* `?tab=not_found` (Cockpit-Aufgabe) öffnet den Reiter direkt */
const tab = ref(tabFromUrl(page.url, tabKeys, 'tree'));
watch(
    () => page.url,
    (url) => {
        if (url.includes('tab=')) {
            tab.value = tabFromUrl(url, tabKeys, tab.value);
        }
    },
);

/* ── Filter (Status, Sprache, Suche) ── */

const statusOptions = computed<StatusOption[]>(() => [
    { value: '', label: t('Status: Alle') },
    ...(props.status_options ??
        Object.entries(versionLabels).map(([value, label]) => ({
            value,
            label,
        }))),
]);
const localeOptions = computed<StatusOption[]>(() => [
    { value: '', label: t('Sprache: Alle') },
    ...props.locales.map((l) => ({
        value: l.locale,
        label: l.locale.toUpperCase(),
    })),
]);

const status = ref((props.filters ?? emptyFilters).status);
const locale = ref((props.filters ?? emptyFilters).locale);
const q = ref((props.filters ?? emptyFilters).q);
watch(
    () => props.filters,
    (f) => {
        status.value = f?.status ?? '';
        locale.value = f?.locale ?? '';
        q.value = f?.q ?? '';
    },
);

const filtered = computed(() => {
    const f = props.filters ?? emptyFilters;

    return Boolean(f.status || f.locale || f.q);
});

function apply(): void {
    const params: Record<string, string> = {};
    if (status.value) {
        params.status = status.value;
    }
    if (locale.value) {
        params.locale = locale.value;
    }
    if (q.value.trim()) {
        params.q = q.value.trim();
    }
    router.get(pathOf(page.url), params, {
        preserveState: true,
        replace: true,
    });
}

function resetFilters(): void {
    status.value = '';
    locale.value = '';
    q.value = '';
    apply();
}

/** Vorfahre eines Treffers, selbst kein Treffer: bleibt stehen, aber gedimmt. */
function isDimmed(row: PageTreeRow): boolean {
    return (row.visible ?? true) && !(row.match ?? true);
}

/* ── Auf- und Zuklappen ── */

const collapsed = ref<Set<number>>(new Set());

function toggle(id: number): void {
    const next = new Set(collapsed.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    collapsed.value = next;
}

function collapseAll(): void {
    collapsed.value = new Set(
        props.tree.filter((r) => r.has_children).map((r) => r.id),
    );
}

function expandAll(): void {
    collapsed.value = new Set();
}

/** Die sichtbaren Zeilen: ohne Treffer im Filter weg, unter einer zugeklappten Zeile verschwindet alles, was tiefer liegt. */
const visibleRows = computed(() => {
    const rows: WorkflowPageRow[] = [];
    let hideBelow: number | null = null;
    for (const row of props.tree) {
        if (hideBelow !== null && row.depth > hideBelow) {
            continue;
        }
        hideBelow = collapsed.value.has(row.id) ? row.depth : null;
        if (!(row.visible ?? true)) {
            continue;
        }
        rows.push(row);
    }

    return rows;
});

/* ── Verschieben ── */

function siblingsOf(parentId: number | null, except?: number): PageTreeRow[] {
    return props.tree.filter(
        (r) => r.parent_id === parentId && r.id !== except,
    );
}

function indexAmongSiblings(row: PageTreeRow): number {
    return siblingsOf(row.parent_id).findIndex((r) => r.id === row.id);
}

function isLastSibling(row: PageTreeRow): boolean {
    const siblings = siblingsOf(row.parent_id);

    return siblings[siblings.length - 1]?.id === row.id;
}

/** Ob `candidate` die Zeile selbst oder eine ihrer Unterseiten ist — dorthin darf sie nicht. */
function isSelfOrDescendant(row: PageTreeRow, candidate: PageTreeRow): boolean {
    if (candidate.id === row.id) {
        return true;
    }
    const start = props.tree.findIndex((r) => r.id === row.id);
    for (let i = start + 1; i < props.tree.length; i++) {
        const r = props.tree[i];
        if (!r || r.depth <= row.depth) {
            break;
        }
        if (r.id === candidate.id) {
            return true;
        }
    }

    return false;
}

function canHoldChildren(row: PageTreeRow): boolean {
    return row.type === 'page' || row.type === 'folder';
}

function move(
    row: PageTreeRow,
    parentId: number | null,
    position: number,
    onSuccess?: () => void,
): void {
    router.post(
        row.urls.move,
        { parent_id: parentId, position },
        { preserveScroll: true, preserveState: true, onSuccess },
    );
}

/* Dialog «Verschieben nach …» */

const moving = ref<number | null>(null);
const targetParent = ref<number | null>(null);
const targetAfter = ref<number | null>(null);

function openMove(row: PageTreeRow): void {
    moving.value = row.id;
    targetParent.value = row.parent_id;
    const index = indexAmongSiblings(row);
    targetAfter.value =
        index > 0 ? (siblingsOf(row.parent_id)[index - 1]?.id ?? null) : null;
}

function closeMove(): void {
    moving.value = null;
}

/** Einen Platz nach oben oder unten; der offene Dialog folgt der neuen Stelle. */
function moveBy(row: PageTreeRow, delta: number): void {
    move(
        row,
        row.parent_id,
        Math.max(0, indexAmongSiblings(row) + delta),
        () => {
            const fresh = props.tree.find((r) => r.id === row.id);
            if (fresh && moving.value === row.id) {
                openMove(fresh);
            }
        },
    );
}

const movingRow = computed(
    () => props.tree.find((r) => r.id === moving.value) ?? null,
);

const parentChoices = computed(() => {
    const row = movingRow.value;
    if (!row) {
        return [];
    }

    return props.tree.filter(
        (r) => canHoldChildren(r) && !isSelfOrDescendant(row, r),
    );
});

const positionChoices = computed(() =>
    movingRow.value ? siblingsOf(targetParent.value, movingRow.value.id) : [],
);

function setTargetParent(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    targetParent.value = value === '' ? null : Number(value);
    targetAfter.value = null;
}

function setTargetAfter(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    targetAfter.value = value === '' ? null : Number(value);
}

function submitMove(): void {
    const row = movingRow.value;
    if (!row) {
        return;
    }
    const after = positionChoices.value.findIndex(
        (r) => r.id === targetAfter.value,
    );
    move(row, targetParent.value, after + 1);
    closeMove();
}

/* Ziehen (HTML5): oberes Drittel = davor, unteres Drittel = danach, Mitte = hinein (Seite, Ordner) */

type DropPlace = 'before' | 'after' | 'inside';

const dragging = ref<PageTreeRow | null>(null);
const dropTarget = ref<{ id: number; place: DropPlace } | null>(null);

function onDragStart(row: PageTreeRow, event: DragEvent): void {
    dragging.value = row;
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(row.id));
    }
}

function onDragOver(row: PageTreeRow, event: DragEvent): void {
    const source = dragging.value;
    if (!source || isSelfOrDescendant(source, row)) {
        dropTarget.value = null;

        return;
    }
    event.preventDefault();
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const ratio = (event.clientY - rect.top) / Math.max(rect.height, 1);
    let place: DropPlace;
    if (canHoldChildren(row) && ratio >= 0.3 && ratio <= 0.7) {
        place = 'inside';
    } else {
        place = ratio < 0.5 ? 'before' : 'after';
    }
    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
    }
    dropTarget.value = { id: row.id, place };
}

function onDragLeave(row: PageTreeRow): void {
    if (dropTarget.value?.id === row.id) {
        dropTarget.value = null;
    }
}

function onDrop(row: PageTreeRow): void {
    const source = dragging.value;
    const target = dropTarget.value;
    dragging.value = null;
    dropTarget.value = null;
    if (!source || !target || target.id !== row.id) {
        return;
    }
    if (target.place === 'inside') {
        move(source, row.id, siblingsOf(row.id, source.id).length);

        return;
    }
    const siblings = siblingsOf(row.parent_id, source.id);
    const index = siblings.findIndex((r) => r.id === row.id);
    move(source, row.parent_id, target.place === 'before' ? index : index + 1);
}

function onDragEnd(): void {
    dragging.value = null;
    dropTarget.value = null;
}

function dropClass(row: PageTreeRow): string {
    const target = dropTarget.value;
    if (!target || target.id !== row.id) {
        return '';
    }
    if (target.place === 'before') {
        return 'shadow-[inset_0_2px_0_var(--color-lava)]';
    }
    if (target.place === 'after') {
        return 'shadow-[inset_0_-2px_0_var(--color-lava)]';
    }

    return 'bg-lava/10';
}

/* ── Löschen (inline bestätigen, kein Dialog des Browsers), Anzeige ── */

const deleting = ref<number | null>(null);
const removingRedirect = ref<number | null>(null);

function destroy(row: PageTreeRow): void {
    deleting.value = null;
    router.delete(row.urls.delete, { preserveScroll: true });
}

function removeRedirect(r: PageRedirectRow): void {
    removingRedirect.value = null;
    router.delete(r.delete_url, { preserveScroll: true, preserveState: true });
}

function statusTitle(locale: string, status: string | null): string {
    const label = status ? (versionLabels[status] ?? status) : t('fehlt');

    return `${locale.toUpperCase()} · ${label}`;
}

/* ── Massenaktionen und Kopieren ── */

const selection = ref<number[]>([]);
const confirmingBulkDelete = ref(false);
const bulkBusy = ref(false);
const copying = ref<number | null>(null);

const selectionHasChildren = computed(() =>
    props.tree.some((r) => selection.value.includes(r.id) && r.has_children),
);
const canBulk = computed(() => props.can_edit && Boolean(props.urls.bulk));
const selectableRows = computed(() => visibleRows.value.filter(isSelectable));
const allSelected = computed(
    () =>
        selectableRows.value.length > 0 &&
        selectableRows.value.every((r) => selection.value.includes(r.id)),
);

watch(
    () => props.tree,
    (rows) => {
        selection.value = pruneSelection(selection.value, rows);
        if (selection.value.length === 0) {
            confirmingBulkDelete.value = false;
        }
    },
);

function toggleRow(row: WorkflowPageRow): void {
    selection.value = toggleSelection(selection.value, row.id);
}

function toggleAllRows(): void {
    selection.value = toggleAll(selection.value, visibleRows.value);
}

function clearSelection(): void {
    selection.value = [];
    confirmingBulkDelete.value = false;
}

function bulk(action: BulkAction): void {
    if (!props.urls.bulk || selection.value.length === 0) {
        return;
    }
    router.post(
        props.urls.bulk,
        { ids: selection.value, action },
        {
            preserveScroll: true,
            onStart: () => {
                bulkBusy.value = true;
            },
            onFinish: () => {
                bulkBusy.value = false;
            },
            onSuccess: () => {
                if (!bulkError.value) {
                    clearSelection();
                }
            },
        },
    );
}

function copy(row: WorkflowPageRow): void {
    copying.value = null;
    if (row.urls.copy) {
        router.post(row.urls.copy, {}, { preserveScroll: true });
    }
}

/* ── Umleitungen: anlegen, CSV-Import, in der Zeile ändern ── */

const statusChoices = redirectStatusOptions();

/** Ohne Ziel bei 410 — das Feld ist dann ausgegraut, sein Inhalt geht nicht mit. */
function withoutTargetFor410<T extends { to: string; status: number }>(
    data: T,
): T {
    return { ...data, to: needsTarget(data.status) ? data.to.trim() : '' };
}

const newRedirect = useForm({ from: '', to: '', status: 301 }).transform(
    withoutTargetFor410,
);
/* Ein Fehler gilt, bis das Feld geändert wird — nicht bis zum nächsten Absenden */
watch(
    () => newRedirect.from,
    () => newRedirect.clearErrors('from'),
);
watch(
    () => [newRedirect.to, newRedirect.status],
    () => newRedirect.clearErrors('to', 'status'),
);

function createRedirect(): void {
    if (!props.urls.redirect_store) {
        return;
    }
    newRedirect.post(props.urls.redirect_store, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            newRedirect.reset();
            newRedirect.clearErrors();
        },
    });
}

const importInput = ref<HTMLInputElement | null>(null);
const importForm = useForm<{ file: File | null }>({ file: null });

/** Datei gewählt → gleich importieren (wie das Hochladen in Medien); das Ergebnis kommt als Meldung und Fehlerliste. */
function importCsv(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    if (!file || !props.urls.redirect_import) {
        return;
    }
    importForm.file = file;
    importForm.post(props.urls.redirect_import, {
        preserveScroll: true,
        preserveState: true,
        forceFormData: true,
        onFinish: () => {
            importForm.file = null;
            input.value = '';
        },
    });
}

const importErrors = computed<string[]>(() => props.import_errors ?? []);

/* In der Zeile ändern: Felder statt Text, speichert beim Verlassen der Zeile oder mit Enter, Escape bricht ab */
const editing = ref<number | null>(null);
const editDraft = reactive({ from: '', to: '', status: 301 });
const editErrors = ref<Record<string, string>>({});
const editSaving = ref(false);
const savedRedirect = ref<number | null>(null);
let editFailedJson: string | null = null;
let savedTimer: ReturnType<typeof setTimeout> | undefined;
/* Wer ein Feld ändert, sieht dessen alten Fehler nicht mehr */
watch(
    () => ({ ...editDraft }),
    (now, before) => {
        const next = { ...editErrors.value };
        for (const key of ['from', 'to', 'status'] as const) {
            if (now[key] !== before[key]) {
                delete next[key];
            }
        }
        editErrors.value = next;
    },
);

function startEdit(r: PageRedirectRow): void {
    editing.value = r.id;
    editDraft.from = r.from;
    editDraft.to = r.to;
    editDraft.status = r.status;
    editErrors.value = {};
    editFailedJson = null;
    removingRedirect.value = null;
    void nextTick(() => {
        document.getElementById(`redirect-from-${r.id}`)?.focus();
    });
}

/** Bearbeiten beenden; mit Zeile (Escape, «Abbrechen») kehrt der Fokus zu ihrem Knopf «Bearbeiten» zurück. */
function cancelEdit(r?: PageRedirectRow): void {
    editing.value = null;
    editErrors.value = {};
    // Zurück auf den gespeicherten Stand: eine Ablehnung von vorhin gilt nicht mehr (wie in useAutosave)
    if (editFailedJson !== null && autosaveToast.status === 'error') {
        autosaveToast.status = 'idle';
        autosaveToast.seq++;
    }
    editFailedJson = null;
    if (r) {
        void nextTick(() => {
            document.getElementById(`redirect-edit-${r.id}`)?.focus();
        });
    }
}

function saveEdit(r: PageRedirectRow): void {
    if (editing.value !== r.id || editSaving.value) {
        return;
    }
    const payload = withoutTargetFor410({
        from: editDraft.from.trim(),
        to: editDraft.to,
        status: editDraft.status,
    });
    if (
        payload.from === r.from &&
        payload.to === r.to &&
        payload.status === r.status
    ) {
        cancelEdit();

        return;
    }
    // Nach einer Ablehnung erst bei einer neuen Änderung wieder senden
    const json = JSON.stringify(payload);
    if (json === editFailedJson) {
        return;
    }
    editSaving.value = true;
    announceSaving();
    router.put(r.update_url, payload, {
        preserveScroll: true,
        preserveState: true,
        async: true,
        only: ['redirects', 'not_found', 'counts', 'errors'],
        onSuccess: () => {
            if (editing.value === r.id) {
                cancelEdit();
            }
            announceSaved(t('Umleitung :from', { from: payload.from }));
            savedRedirect.value = r.id;
            clearTimeout(savedTimer);
            savedTimer = setTimeout(() => (savedRedirect.value = null), 2600);
        },
        onError: (errors) => {
            editFailedJson = json;
            if (editing.value === r.id) {
                editErrors.value = errors;
            }
            announceError(
                Object.values(errors)[0] ??
                    t('bitte das markierte Feld prüfen'),
            );
        },
        onFinish: () => {
            editSaving.value = false;
        },
    });
}

/**
 * Fokus verlässt die Zeile (nicht nur ein Feld darin) → speichern. Geprüft wird erst nach dem Neuzeichnen: Beim Öffnen
 * verschwindet der Knopf «Bearbeiten» mit dem Fokus (Chrome meldet dann ein `focusout` ohne Ziel), danach steht der
 * Fokus im Feld der Zeile.
 */
function onEditFocusOut(r: PageRedirectRow, event: FocusEvent): void {
    if (editing.value !== r.id) {
        return;
    }
    const row = event.currentTarget as HTMLElement | null;
    setTimeout(() => {
        if (editing.value !== r.id) {
            return;
        }
        if (row?.isConnected && row.contains(document.activeElement)) {
            return;
        }
        saveEdit(r);
    }, 0);
}

/* ── 404-Treffer: Umleitung anlegen (Zeile verschwindet danach) oder verwerfen ── */

const hitOpen = ref<number | null>(null);
const hitForm = useForm({ from: '', to: '', status: 301 }).transform(
    withoutTargetFor410,
);
const dismissing = ref<number | null>(null);
watch(
    () => [hitForm.to, hitForm.status],
    () => hitForm.clearErrors(),
);
const hitErrors = computed(() =>
    [hitForm.errors.from, hitForm.errors.to, hitForm.errors.status].filter(
        (e): e is string => Boolean(e),
    ),
);

function openHit(hit: PageNotFoundRow): void {
    hitOpen.value = hit.id;
    hitForm.from = hit.path;
    hitForm.to = '';
    hitForm.status = 301;
    hitForm.clearErrors();
    void nextTick(() => {
        document.getElementById(`hit-to-${hit.id}`)?.focus();
    });
}

function closeHit(): void {
    hitOpen.value = null;
    hitForm.clearErrors();
}

function createFromHit(): void {
    if (!props.urls.redirect_store) {
        return;
    }
    hitForm.post(props.urls.redirect_store, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            hitOpen.value = null;
            hitForm.reset();
        },
    });
}

function dismissHit(hit: PageNotFoundRow): void {
    router.delete(hit.dismiss_url, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            dismissing.value = hit.id;
        },
        onFinish: () => {
            dismissing.value = null;
        },
    });
}

const smallMono = (error?: string): string =>
    controlClasses({ small: true, mono: true, error: Boolean(error) });

/* ── Operationen als Symbolknöpfe (Mockup 28/1): Strichsymbole im 16×16-Raster, keine Emoji ── */

type OpName =
    | 'content'
    | 'settings'
    | 'preview'
    | 'review'
    | 'child'
    | 'copy'
    | 'move'
    | 'delete';

const opIcons: Record<OpName, string> = {
    content: 'M11.5 2.5l2 2L5 13H3v-2z',
    settings: 'M2 4h12M2 8h12M2 12h12 M5 2.5v3M11 6.5v3M7 10.5v3',
    preview: 'M7 11.5a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9z M10.3 10.3 14 14',
    review: 'M3 8.5l3 3 7-7',
    child: 'M8 3v10M3 8h10',
    copy: 'M5.5 5.5h8v8h-8z M2.5 10.5v-8h8',
    move: 'M8 2v12 M5 5l3-3 3 3 M5 11l3 3 3-3',
    delete: 'M4 4l8 8M12 4l-8 8',
};

const OpIcon: FunctionalComponent<{ name: OpName }> = (p) =>
    h(
        'svg',
        {
            viewBox: '0 0 16 16',
            class: 'h-4 w-4',
            fill: 'none',
            stroke: 'currentColor',
            'stroke-width': 1.6,
            'stroke-linecap': 'round',
            'stroke-linejoin': 'round',
            'aria-hidden': 'true',
        },
        [h('path', { d: opIcons[p.name] })],
    );

const small = controlClasses({ small: true });
const opButton =
    'inline-flex h-6 w-6 shrink-0 items-center justify-center rounded border border-ash-light bg-white text-basalt transition-colors hover:border-ash hover:bg-paper';
const opButtonDanger =
    'inline-flex h-6 w-6 shrink-0 items-center justify-center rounded border border-ash-light bg-white text-basalt transition-colors hover:border-danger hover:bg-danger/5 hover:text-danger';
</script>

<template>
    <Head :title="$t('Seiten')" />
    <PageHeader
        :title="$t('Seiten')"
        :crumb="$t(':site › Inhalte', { site: siteName })"
        :lead="
            $t(
                'Der Seitenbaum der Site mit Typen: Seite, Weiterleitung, Fehlerseiten, Feed, Ordner. Je Zeile die Operationen, je Sprache ein Statuspunkt.',
            )
        "
    >
        <template #actions>
            <Button
                v-if="tree.some((r) => r.has_children)"
                variant="secondary"
                size="sm"
                @click="collapsed.size > 0 ? expandAll() : collapseAll()"
                >{{
                    collapsed.size > 0
                        ? $t('Alle öffnen')
                        : $t('Alle schliessen')
                }}</Button
            >
            <Button v-if="can_edit" :href="urls.create">{{ $t('Neu') }}</Button>
        </template>
    </PageHeader>

    <Notice v-if="moveError" tone="danger" class="mb-4">{{ moveError }}</Notice>
    <Notice v-if="bulkError" tone="danger" class="mb-4">{{ bulkError }}</Notice>

    <Tabs v-model="tab" :tabs="tabs" tools-for="tree">
        <template #tools>
            <form
                class="flex flex-wrap items-center gap-2 text-[12.5px]"
                :aria-label="$t('Filter')"
                @submit.prevent="apply"
            >
                <label class="sr-only" for="pages-status">{{
                    $t('Status')
                }}</label>
                <select
                    id="pages-status"
                    v-model="status"
                    :class="[small, 'w-auto!']"
                    @change="apply"
                >
                    <option
                        v-for="o in statusOptions"
                        :key="o.value"
                        :value="o.value"
                    >
                        {{ o.label }}
                    </option>
                </select>
                <label class="sr-only" for="pages-locale">{{
                    $t('Sprache')
                }}</label>
                <select
                    id="pages-locale"
                    v-model="locale"
                    :class="[small, 'w-auto!']"
                    @change="apply"
                >
                    <option
                        v-for="o in localeOptions"
                        :key="o.value"
                        :value="o.value"
                    >
                        {{ o.label }}
                    </option>
                </select>
                <label class="sr-only" for="pages-q">{{ $t('Suchen') }}</label>
                <input
                    id="pages-q"
                    v-model="q"
                    type="search"
                    :placeholder="$t('⌕ Suchen … (Enter)')"
                    :class="[small, 'w-40! max-w-full']"
                />
                <Button
                    v-if="filtered"
                    variant="link"
                    size="sm"
                    @click="resetFilters"
                    >{{ $t('Filter zurücksetzen') }}</Button
                >
            </form>
        </template>

        <template #tree>
            <div
                v-if="tree.length === 0"
                class="rounded-lg border border-ash-light bg-white"
            >
                <EmptyState
                    :title="$t('Noch keine Seite')"
                    :text="
                        $t(
                            'Der Seed des Themes (Station I) legt die ersten an — oder «Neu».',
                        )
                    "
                    icon="pages"
                    tone="ash"
                >
                    <Button v-if="can_edit" :href="urls.create">{{
                        $t('Neu')
                    }}</Button>
                </EmptyState>
            </div>
            <div
                v-else-if="filtered && visibleRows.length === 0"
                class="rounded-lg border border-ash-light bg-white"
            >
                <EmptyState
                    :title="$t('Keine Seite mit diesem Filter')"
                    :text="$t(':count insgesamt.', { count: tree.length })"
                    icon="pages"
                    tone="ash"
                >
                    <Button
                        variant="secondary"
                        size="sm"
                        @click="resetFilters"
                        >{{ $t('Filter zurücksetzen') }}</Button
                    >
                </EmptyState>
            </div>
            <div
                v-if="canBulk && selection.length > 0"
                class="fixed inset-x-3 bottom-3 z-30 rounded-lg border border-basalt bg-basalt px-3 py-2 text-[12.5px] text-paper shadow-lg md:sticky md:inset-x-auto md:top-0 md:bottom-auto md:z-20 md:mb-2 md:shadow-none"
                role="region"
                :aria-label="$t('Massenaktion')"
            >
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                    <span class="font-semibold">{{
                        $t(':count ausgewählt', { count: selection.length })
                    }}</span>
                    <span class="flex flex-wrap items-center gap-1.5">
                        <Button
                            variant="secondary"
                            size="sm"
                            :disabled="bulkBusy"
                            @click="bulk('submit')"
                            >{{ $t('Einreichen') }}</Button
                        >
                        <Button
                            v-if="can_publish"
                            variant="secondary"
                            size="sm"
                            :disabled="bulkBusy"
                            @click="bulk('publish')"
                            >{{ $t('Veröffentlichen') }}</Button
                        >
                        <Button
                            variant="secondary"
                            size="sm"
                            :disabled="bulkBusy"
                            :aria-pressed="confirmingBulkDelete"
                            class="hover:border-danger! hover:text-danger!"
                            @click="
                                confirmingBulkDelete = !confirmingBulkDelete
                            "
                            >{{ $t('Löschen') }}</Button
                        >
                    </span>
                    <button
                        type="button"
                        class="ml-auto text-[11.5px] text-ash-light underline hover:text-white"
                        @click="clearSelection"
                    >
                        {{ $t('Auswahl aufheben') }}
                    </button>
                </div>
                <p v-if="!can_publish" class="text-ash-dark mt-1 text-[11px]">
                    {{
                        $t(
                            'Veröffentlichen ist Ihrer Rolle nicht erlaubt — Einreichen schickt die Seiten in die Freigabe.',
                        )
                    }}
                </p>
                <p
                    v-if="confirmingBulkDelete"
                    class="mt-1.5 flex flex-wrap items-center gap-x-2.5 text-[12px]"
                    role="alertdialog"
                >
                    <span>{{
                        selectionHasChildren
                            ? $t(
                                  ':pages in den Papierkorb — mit allen Unterseiten?',
                                  { pages: pagesCount(selection.length) },
                              )
                            : $t(':pages in den Papierkorb?', {
                                  pages: pagesCount(selection.length),
                              })
                    }}</span>
                    <button
                        type="button"
                        class="text-lava-light font-semibold underline hover:text-white"
                        :disabled="bulkBusy"
                        @click="bulk('delete')"
                    >
                        {{ $t('Ja, löschen') }}
                    </button>
                    <button
                        type="button"
                        class="text-ash-light underline hover:text-white"
                        @click="confirmingBulkDelete = false"
                    >
                        {{ $t('Nein') }}
                    </button>
                </p>
            </div>
            <Table
                v-if="
                    tree.length > 0 && !(filtered && visibleRows.length === 0)
                "
                class="[--tree-indent:0.75rem] md:[--tree-indent:1.25rem]"
            >
                <template #head>
                    <tr>
                        <th class="w-full px-3 py-2">
                            <span class="flex items-center gap-2">
                                <input
                                    v-if="canBulk && selectableRows.length > 0"
                                    type="checkbox"
                                    class="h-4 w-4 accent-lava-dark"
                                    :checked="allSelected"
                                    :indeterminate="
                                        !allSelected && selection.length > 0
                                    "
                                    :aria-label="
                                        $t('Alle Seiten mit Inhalt auswählen')
                                    "
                                    @change="toggleAllRows"
                                />
                                {{ $t('Titel · Pfad · Typ') }}
                            </span>
                        </th>
                        <th class="px-3 py-2 md:whitespace-nowrap">
                            {{ $t('Status je Sprache') }}
                        </th>
                        <th class="px-3 py-2">{{ $t('Geändert') }}</th>
                        <th class="px-3 py-2">{{ $t('Operationen') }}</th>
                    </tr>
                </template>
                <template v-for="row in visibleRows" :key="row.id">
                    <tr
                        class="align-top transition-shadow"
                        :class="[
                            dropClass(row),
                            dragging?.id === row.id ? 'opacity-40' : '',
                        ]"
                        :draggable="can_edit ? 'true' : undefined"
                        @dragstart="onDragStart(row, $event)"
                        @dragover="onDragOver(row, $event)"
                        @dragleave="onDragLeave(row)"
                        @drop.prevent="onDrop(row)"
                        @dragend="onDragEnd"
                    >
                        <td class="px-3 py-2">
                            <div class="flex items-start gap-3">
                                <span
                                    v-if="canBulk"
                                    class="mt-[2px] flex w-4 shrink-0 justify-center"
                                >
                                    <input
                                        v-if="isSelectable(row)"
                                        type="checkbox"
                                        class="h-4 w-4 accent-lava-dark"
                                        :checked="selection.includes(row.id)"
                                        :aria-label="
                                            $t(':title auswählen', {
                                                title: row.title,
                                            })
                                        "
                                        @change="toggleRow(row)"
                                    />
                                </span>
                                <div
                                    class="flex min-w-0 flex-1 items-start gap-2"
                                    :style="{
                                        paddingLeft: `calc(${row.depth} * var(--tree-indent, 1.25rem))`,
                                    }"
                                >
                                    <span
                                        v-if="row.depth > 0"
                                        class="text-ash-dark mt-[2px] shrink-0 text-[11px] leading-none"
                                        aria-hidden="true"
                                        >└</span
                                    >
                                    <button
                                        v-if="row.has_children"
                                        type="button"
                                        class="-mx-1.5 -mt-0.5 -mb-1.5 flex h-6 w-6 shrink-0 items-center justify-center rounded text-[10px] text-basalt-light hover:bg-ash-light"
                                        :aria-expanded="!collapsed.has(row.id)"
                                        :aria-label="
                                            collapsed.has(row.id)
                                                ? $t(
                                                      'Unterseiten von «:title» zeigen',
                                                      { title: row.title },
                                                  )
                                                : $t(
                                                      'Unterseiten von «:title» verbergen',
                                                      { title: row.title },
                                                  )
                                        "
                                        :title="
                                            collapsed.has(row.id)
                                                ? $t('Unterseiten zeigen')
                                                : $t('Unterseiten verbergen')
                                        "
                                        @click="toggle(row.id)"
                                    >
                                        {{ collapsed.has(row.id) ? '▸' : '▾' }}
                                    </button>
                                    <span
                                        v-else
                                        class="w-3 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <Hex
                                        :icon="
                                            row.is_home
                                                ? 'home'
                                                : pageTypeIcon(row.type)
                                        "
                                        :tone="
                                            row.is_home || row.type === 'page'
                                                ? 'basalt'
                                                : 'ash'
                                        "
                                        :title="row.type_label"
                                        :class="
                                            isDimmed(row) ? 'grayscale' : ''
                                        "
                                    />
                                    <div
                                        class="min-w-0"
                                        :class="
                                            isDimmed(row) ? 'text-ash-dark' : ''
                                        "
                                    >
                                        <div
                                            class="flex flex-wrap items-center gap-x-1.5 gap-y-0.5"
                                        >
                                            <Link
                                                :href="
                                                    row.urls.content ??
                                                    row.urls.settings
                                                "
                                                class="text-[13.5px] font-semibold break-words hover:underline"
                                                >{{ row.title }}</Link
                                            >
                                            <span
                                                v-if="row.hint"
                                                class="text-[11px]"
                                                :class="
                                                    row.hint ===
                                                        'nicht veröffentlicht' ||
                                                    row.hint ===
                                                        $t(
                                                            'nicht veröffentlicht',
                                                        )
                                                        ? 'text-warning'
                                                        : 'text-ash-dark'
                                                "
                                                >{{ row.hint }}</span
                                            >
                                        </div>
                                        <p
                                            class="flex flex-wrap items-center gap-x-1.5 text-[11.5px]"
                                            :class="
                                                isDimmed(row)
                                                    ? 'text-ash-dark'
                                                    : 'text-basalt-light'
                                            "
                                        >
                                            <span
                                                v-if="row.path"
                                                class="font-mono break-all"
                                                >{{ row.path }}</span
                                            >
                                            <span
                                                v-if="row.target"
                                                class="font-mono break-all"
                                                >→ {{ row.target }}</span
                                            >
                                            <span class="text-ash-dark"
                                                >· {{ row.type_label }}</span
                                            >
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td
                            :data-label="$t('Status je Sprache')"
                            class="px-3 py-2"
                        >
                            <span
                                v-if="row.type !== 'folder'"
                                class="inline-flex items-center gap-1.5 md:whitespace-nowrap"
                                :class="isDimmed(row) ? 'grayscale' : ''"
                            >
                                <span
                                    v-for="l in locales"
                                    :key="l.locale"
                                    class="inline-flex items-center gap-1"
                                >
                                    <StatusHex
                                        :status="
                                            versionStatus(row.status[l.locale])
                                        "
                                        :label="l.locale.toUpperCase()"
                                        :title="
                                            statusTitle(
                                                l.locale,
                                                row.status[l.locale] ?? null,
                                            )
                                        "
                                    />
                                    <TranslationMark
                                        v-if="
                                            row.translation?.[l.locale] ===
                                            'outdated'
                                        "
                                        :state="row.translation[l.locale]"
                                        :locale="l.locale"
                                        quiet
                                    />
                                </span>
                            </span>
                            <span v-else class="text-ash-dark text-xs">—</span>
                        </td>
                        <td
                            :data-label="$t('Geändert')"
                            class="px-3 py-2 text-[12px] md:whitespace-nowrap"
                        >
                            <template v-if="row.changed">
                                <span
                                    class="block"
                                    :class="
                                        isDimmed(row)
                                            ? 'text-ash-dark'
                                            : 'text-basalt-light'
                                    "
                                    >{{ row.changed.by ?? '—' }}</span
                                >
                                <span
                                    v-if="row.changed.at"
                                    class="text-ash-dark block text-[11px]"
                                    >{{ row.changed.at }}</span
                                >
                            </template>
                            <span v-else class="text-ash-dark text-xs">—</span>
                        </td>
                        <td
                            :data-label="$t('Operationen')"
                            class="px-3 py-2 whitespace-normal"
                        >
                            <span
                                class="inline-flex flex-wrap items-center gap-1 md:flex-nowrap"
                            >
                                <Link
                                    v-if="row.urls.content"
                                    :href="row.urls.content"
                                    :class="opButton"
                                    :title="$t('Inhalt bearbeiten')"
                                    :aria-label="$t('Inhalt bearbeiten')"
                                    ><OpIcon name="content"
                                /></Link>
                                <Link
                                    :href="row.urls.settings"
                                    :class="opButton"
                                    :title="$t('Einstellungen')"
                                    :aria-label="$t('Einstellungen')"
                                    ><OpIcon name="settings"
                                /></Link>
                                <a
                                    v-if="row.urls.preview"
                                    :href="row.urls.preview"
                                    target="_blank"
                                    rel="noopener"
                                    :class="opButton"
                                    :title="$t('Vorschau')"
                                    :aria-label="$t('Vorschau')"
                                    ><OpIcon name="preview"
                                /></a>
                                <Link
                                    v-if="row.urls.review"
                                    :href="row.urls.review"
                                    :class="opButton"
                                    :title="$t('Freigabe')"
                                    :aria-label="$t('Freigabe')"
                                    ><OpIcon name="review"
                                /></Link>
                                <template v-if="can_edit">
                                    <Link
                                        v-if="row.urls.new_child"
                                        :href="row.urls.new_child"
                                        :class="opButton"
                                        :title="$t('Unterseite')"
                                        :aria-label="$t('Unterseite')"
                                        ><OpIcon name="child"
                                    /></Link>
                                    <button
                                        v-if="row.urls.copy"
                                        type="button"
                                        :class="[
                                            opButton,
                                            copying === row.id
                                                ? 'border-lava-dark bg-lava/10 text-lava-dark'
                                                : '',
                                        ]"
                                        :aria-pressed="copying === row.id"
                                        :title="$t('Kopieren')"
                                        :aria-label="$t('Kopieren')"
                                        @click="
                                            copying =
                                                copying === row.id
                                                    ? null
                                                    : row.id
                                        "
                                    >
                                        <OpIcon name="copy" />
                                    </button>
                                    <button
                                        type="button"
                                        :class="[
                                            opButton,
                                            moving === row.id
                                                ? 'border-lava-dark bg-lava/10 text-lava-dark'
                                                : '',
                                        ]"
                                        :aria-pressed="moving === row.id"
                                        :title="$t('Verschieben')"
                                        :aria-label="$t('Verschieben')"
                                        @click="
                                            moving === row.id
                                                ? closeMove()
                                                : openMove(row)
                                        "
                                    >
                                        <OpIcon name="move" />
                                    </button>
                                    <button
                                        type="button"
                                        :class="[
                                            opButtonDanger,
                                            deleting === row.id
                                                ? 'border-danger text-danger'
                                                : '',
                                        ]"
                                        :aria-pressed="deleting === row.id"
                                        :title="$t('Löschen')"
                                        :aria-label="$t('Löschen')"
                                        @click="
                                            deleting =
                                                deleting === row.id
                                                    ? null
                                                    : row.id
                                        "
                                    >
                                        <OpIcon name="delete" />
                                    </button>
                                </template>
                            </span>
                            <span
                                v-if="can_edit && copying === row.id"
                                class="mt-1 flex flex-wrap items-center gap-x-2 text-[11px] text-basalt"
                                role="alertdialog"
                            >
                                <span>{{ $t('Kopie anlegen?') }}</span>
                                <button
                                    type="button"
                                    class="font-semibold text-lava-dark underline"
                                    @click="copy(row)"
                                >
                                    {{ $t('Ja') }}
                                </button>
                                <button
                                    type="button"
                                    class="text-basalt-light underline"
                                    @click="copying = null"
                                >
                                    {{ $t('Nein') }}
                                </button>
                            </span>
                            <span
                                v-if="can_edit && deleting === row.id"
                                class="text-danger mt-1 flex flex-wrap items-center gap-x-2 text-[11px]"
                                role="alertdialog"
                            >
                                <span>{{
                                    row.has_children
                                        ? $t(
                                              'Wirklich in den Papierkorb — mit allen Unterseiten?',
                                          )
                                        : $t('Wirklich in den Papierkorb?')
                                }}</span>
                                <button
                                    type="button"
                                    class="font-semibold underline"
                                    @click="destroy(row)"
                                >
                                    {{ $t('Ja') }}
                                </button>
                                <button
                                    type="button"
                                    class="text-basalt-light underline"
                                    @click="deleting = null"
                                >
                                    {{ $t('Nein') }}
                                </button>
                            </span>
                        </td>
                    </tr>
                    <tr v-if="moving === row.id && movingRow" class="bg-paper">
                        <td colspan="4" class="px-3 py-2.5">
                            <form
                                v-dialog="{ close: closeMove, modal: false }"
                                class="flex flex-wrap items-end gap-x-3 gap-y-2 text-[12.5px]"
                                :aria-label="
                                    $t('«:title» verschieben', {
                                        title: movingRow.title,
                                    })
                                "
                                @submit.prevent="submitMove"
                            >
                                <span class="font-semibold">{{
                                    $t('«:title» verschieben nach', {
                                        title: movingRow.title,
                                    })
                                }}</span>
                                <label class="flex flex-col gap-1">
                                    <span
                                        class="text-[11px] text-basalt-light"
                                        >{{ $t('Elternseite') }}</span
                                    >
                                    <select
                                        :value="targetParent ?? ''"
                                        :class="[small, 'w-56']"
                                        @change="setTargetParent"
                                    >
                                        <option value="">
                                            {{ $t('— oberste Ebene —') }}
                                        </option>
                                        <option
                                            v-for="p in parentChoices"
                                            :key="p.id"
                                            :value="p.id"
                                        >
                                            {{ '\u00a0'.repeat(p.depth * 3)
                                            }}{{ p.title }}
                                        </option>
                                    </select>
                                </label>
                                <label class="flex flex-col gap-1">
                                    <span
                                        class="text-[11px] text-basalt-light"
                                        >{{ $t('Position') }}</span
                                    >
                                    <select
                                        :value="targetAfter ?? ''"
                                        :class="[small, 'w-56']"
                                        @change="setTargetAfter"
                                    >
                                        <option value="">
                                            {{ $t('am Anfang') }}
                                        </option>
                                        <option
                                            v-for="s in positionChoices"
                                            :key="s.id"
                                            :value="s.id"
                                        >
                                            {{
                                                $t('nach «:title»', {
                                                    title: s.title,
                                                })
                                            }}
                                        </option>
                                    </select>
                                </label>
                                <span class="flex gap-2">
                                    <Button type="submit" size="sm">{{
                                        $t('Verschieben')
                                    }}</Button>
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        :disabled="
                                            indexAmongSiblings(movingRow) === 0
                                        "
                                        :title="$t('einen Platz nach oben')"
                                        @click="moveBy(movingRow, -1)"
                                        >↑ {{ $t('nach oben') }}</Button
                                    >
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        :disabled="isLastSibling(movingRow)"
                                        :title="$t('einen Platz nach unten')"
                                        @click="moveBy(movingRow, 1)"
                                        >↓ {{ $t('nach unten') }}</Button
                                    >
                                    <Button
                                        variant="link"
                                        size="sm"
                                        @click="closeMove"
                                        >{{ $t('Abbrechen') }}</Button
                                    >
                                </span>
                            </form>
                        </td>
                    </tr>
                </template>
                <template #foot>
                    <p>
                        {{
                            filtered
                                ? $t(':count Treffer', { count: counts.tree })
                                : $tc(
                                      ':count Eintrag|:count Einträge',
                                      counts.tree,
                                  )
                        }}
                        ·
                        {{
                            $t(
                                'Reihenfolge und Verschachtelung durch Ziehen (↕) — ein Slug-Wechsel legt automatisch eine Umleitung an (Reiter «Umleitungen»).',
                            )
                        }}
                    </p>
                    <p class="mt-1.5 inline-flex flex-wrap items-center gap-3">
                        <StatusHex
                            status="live"
                            :label="$t('Veröffentlicht')"
                        />
                        <StatusHex
                            status="scheduled"
                            :label="$t('Freigegeben / Geplant')"
                        />
                        <StatusHex status="review" :label="$t('In Prüfung')" />
                        <StatusHex status="draft" :label="$t('Entwurf')" />
                        <StatusHex status="missing" :label="$t('fehlt')" />
                        <span class="inline-flex items-center gap-1"
                            ><TranslationMark state="outdated" />
                            {{
                                $t(
                                    'Übersetzung beruht auf einer älteren Fassung der Standardsprache',
                                )
                            }}</span
                        >
                    </p>
                </template>
            </Table>
            <div
                v-if="canBulk && selection.length > 0"
                class="h-36 md:hidden"
                aria-hidden="true"
            />
        </template>

        <template #redirects>
            <section
                v-if="can_edit && urls.redirect_store"
                class="mb-2 rounded-lg border border-ash-light bg-white px-3 py-2.5"
                :aria-label="$t('Neue Umleitung')"
            >
                <form
                    class="grid gap-x-2 gap-y-2 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto_auto] md:items-start"
                    novalidate
                    @submit.prevent="createRedirect"
                >
                    <div class="min-w-0">
                        <label for="redirect-new-from" :class="labelClasses">{{
                            $t('Alte Adresse')
                        }}</label>
                        <input
                            id="redirect-new-from"
                            v-model="newRedirect.from"
                            type="text"
                            :placeholder="$t('/alte-seite')"
                            autocomplete="off"
                            spellcheck="false"
                            :class="smallMono(newRedirect.errors.from)"
                            :aria-invalid="
                                newRedirect.errors.from ? 'true' : undefined
                            "
                        />
                        <p
                            v-if="newRedirect.errors.from"
                            class="text-danger mt-1 text-[11.5px] font-semibold"
                            role="alert"
                        >
                            {{ newRedirect.errors.from }}
                        </p>
                    </div>
                    <div class="min-w-0">
                        <label for="redirect-new-to" :class="labelClasses">{{
                            $t('Ziel')
                        }}</label>
                        <input
                            id="redirect-new-to"
                            v-model="newRedirect.to"
                            type="text"
                            :placeholder="
                                needsTarget(newRedirect.status)
                                    ? $t('/neue-seite oder https://…')
                                    : ''
                            "
                            autocomplete="off"
                            spellcheck="false"
                            :disabled="!needsTarget(newRedirect.status)"
                            :class="smallMono(newRedirect.errors.to)"
                            :aria-invalid="
                                newRedirect.errors.to ? 'true' : undefined
                            "
                        />
                        <p
                            v-if="!needsTarget(newRedirect.status)"
                            class="mt-1 text-[11.5px] text-basalt-light"
                        >
                            {{
                                $t(
                                    'Die Adresse antwortet «gibt es nicht mehr».',
                                )
                            }}
                        </p>
                        <p
                            v-if="newRedirect.errors.to"
                            class="text-danger mt-1 text-[11.5px] font-semibold"
                            role="alert"
                        >
                            {{ newRedirect.errors.to }}
                        </p>
                    </div>
                    <div class="min-w-0">
                        <label
                            for="redirect-new-status"
                            :class="labelClasses"
                            >{{ $t('Status') }}</label
                        >
                        <select
                            id="redirect-new-status"
                            v-model="newRedirect.status"
                            :class="[
                                controlClasses({
                                    small: true,
                                    error: Boolean(newRedirect.errors.status),
                                }),
                                'md:w-auto',
                            ]"
                        >
                            <option
                                v-for="o in statusChoices"
                                :key="o.value"
                                :value="o.value"
                            >
                                {{ o.label }}
                            </option>
                        </select>
                        <p
                            v-if="newRedirect.errors.status"
                            class="text-danger mt-1 text-[11.5px] font-semibold"
                            role="alert"
                        >
                            {{ newRedirect.errors.status }}
                        </p>
                    </div>
                    <div>
                        <span
                            :class="labelClasses"
                            class="hidden md:block"
                            aria-hidden="true"
                            >&nbsp;</span
                        >
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="newRedirect.processing"
                            >{{ $t('Anlegen') }}</Button
                        >
                    </div>
                </form>
                <div
                    v-if="urls.redirect_import"
                    class="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-1.5 border-t border-ash-light pt-2.5 text-[11.5px] text-basalt-light"
                >
                    <input
                        ref="importInput"
                        type="file"
                        class="hidden"
                        accept=".csv,.txt,text/csv,text/plain"
                        :aria-label="$t('CSV-Datei mit Umleitungen')"
                        @change="importCsv"
                    />
                    <Button
                        variant="secondary"
                        size="sm"
                        :disabled="importForm.processing"
                        @click="importInput?.click()"
                        >{{
                            importForm.processing
                                ? $t('Importiert …')
                                : $t('CSV importieren …')
                        }}</Button
                    >
                    <span class="min-w-0 flex-1 basis-60"
                        ><template
                            v-for="(part, i) in $t(
                                'Je Zeile :format — Semikolon, Komma oder Tabulator, Kopfzeile erlaubt, Status leer = 301. Gleiche alte Adresse überschreibt.',
                            ).split(':format')"
                            :key="i"
                            ><span v-if="i > 0" class="font-mono">{{
                                $t('alte Adresse; Ziel; Status')
                            }}</span
                            >{{ part }}</template
                        ></span
                    >
                </div>
                <p
                    v-if="importForm.errors.file"
                    class="text-danger mt-1.5 text-[11.5px] font-semibold"
                    role="alert"
                >
                    {{ importForm.errors.file }}
                </p>
                <Notice
                    v-if="importErrors.length > 0"
                    tone="warning"
                    :title="$t('Nicht übernommen')"
                    class="mt-2"
                >
                    <ul class="list-disc pl-4 [overflow-wrap:anywhere]">
                        <li v-for="(e, i) in importErrors" :key="i">{{ e }}</li>
                    </ul>
                </Notice>
            </section>

            <div
                v-if="redirects.length === 0"
                class="rounded-lg border border-ash-light bg-white"
            >
                <EmptyState
                    :title="$t('Noch keine Umleitung')"
                    :text="
                        $t(
                            'Ein Slug-Wechsel legt sie automatisch an; von Hand oder per CSV oben.',
                        )
                    "
                    icon="redirect"
                    tone="ash"
                />
            </div>
            <Table v-else>
                <template #head>
                    <tr>
                        <th class="w-full px-3 py-2">{{ $t('Alt → Ziel') }}</th>
                        <th class="px-3 py-2">{{ $t('Status') }}</th>
                        <th class="px-3 py-2">{{ $t('Herkunft') }}</th>
                        <th class="px-3 py-2 md:text-right">
                            {{ $t('Treffer') }}
                        </th>
                        <th v-if="can_edit" class="px-3 py-2">
                            <span class="sr-only">{{ $t('Aktionen') }}</span>
                        </th>
                    </tr>
                </template>
                <tr
                    v-for="r in redirects"
                    :key="r.id"
                    class="align-top"
                    :class="editing === r.id ? 'bg-paper' : ''"
                    @focusout="onEditFocusOut(r, $event)"
                    @keydown.esc="editing === r.id ? cancelEdit(r) : undefined"
                >
                    <td :data-label="$t('Alt → Ziel')" class="px-3 py-2.5">
                        <div
                            v-if="editing === r.id"
                            class="grid gap-1.5 md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] md:items-start"
                        >
                            <div class="min-w-0">
                                <label
                                    :for="`redirect-from-${r.id}`"
                                    class="sr-only"
                                    >{{ $t('Alte Adresse') }}</label
                                >
                                <input
                                    :id="`redirect-from-${r.id}`"
                                    v-model="editDraft.from"
                                    type="text"
                                    autocomplete="off"
                                    spellcheck="false"
                                    :class="smallMono(editErrors.from)"
                                    :aria-invalid="
                                        editErrors.from ? 'true' : undefined
                                    "
                                    @keydown.enter.prevent="saveEdit(r)"
                                />
                                <p
                                    v-if="editErrors.from"
                                    class="text-danger mt-1 text-[11.5px] font-semibold"
                                    role="alert"
                                >
                                    {{ editErrors.from }}
                                </p>
                            </div>
                            <span
                                class="text-ash-dark hidden pt-1 md:block"
                                aria-hidden="true"
                                >→</span
                            >
                            <div class="min-w-0">
                                <label
                                    :for="`redirect-to-${r.id}`"
                                    class="sr-only"
                                    >{{ $t('Ziel') }}</label
                                >
                                <input
                                    :id="`redirect-to-${r.id}`"
                                    v-model="editDraft.to"
                                    type="text"
                                    autocomplete="off"
                                    spellcheck="false"
                                    :placeholder="
                                        needsTarget(editDraft.status)
                                            ? $t('/neue-seite oder https://…')
                                            : $t('gibt es nicht mehr')
                                    "
                                    :disabled="!needsTarget(editDraft.status)"
                                    :class="smallMono(editErrors.to)"
                                    :aria-invalid="
                                        editErrors.to ? 'true' : undefined
                                    "
                                    @keydown.enter.prevent="saveEdit(r)"
                                />
                                <p
                                    v-if="editErrors.to"
                                    class="text-danger mt-1 text-[11.5px] font-semibold"
                                    role="alert"
                                >
                                    {{ editErrors.to }}
                                </p>
                            </div>
                        </div>
                        <template v-else>
                            <span class="font-mono text-xs break-all">{{
                                r.from
                            }}</span>
                            <span
                                class="text-ash-dark"
                                :aria-label="$t('nach')"
                            >
                                →
                            </span>
                            <span
                                v-if="needsTarget(r.status)"
                                class="font-mono text-xs break-all"
                                >{{ r.to }}</span
                            >
                            <span v-else class="text-xs text-basalt-light">{{
                                $t('gibt es nicht mehr')
                            }}</span>
                            <span
                                class="text-ash-dark ml-1.5 font-mono text-[10px]"
                                :title="
                                    $t('Sprache :locale', {
                                        locale: r.locale.toUpperCase(),
                                    })
                                "
                                >{{ r.locale.toUpperCase() }}</span
                            >
                            <span
                                v-if="savedRedirect === r.id"
                                class="text-success ml-1.5 text-[11.5px] font-semibold"
                                >✓ {{ $t('gespeichert') }}</span
                            >
                        </template>
                    </td>
                    <td :data-label="$t('Status')" class="px-3 py-2.5">
                        <template v-if="editing === r.id">
                            <label
                                :for="`redirect-status-${r.id}`"
                                class="sr-only"
                                >{{ $t('Status') }}</label
                            >
                            <select
                                :id="`redirect-status-${r.id}`"
                                v-model="editDraft.status"
                                :class="[
                                    controlClasses({
                                        small: true,
                                        error: Boolean(editErrors.status),
                                    }),
                                    'md:w-auto',
                                ]"
                                @keydown.enter.prevent="saveEdit(r)"
                            >
                                <option
                                    v-for="o in statusChoices"
                                    :key="o.value"
                                    :value="o.value"
                                >
                                    {{ o.label }}
                                </option>
                            </select>
                            <p
                                v-if="editErrors.status"
                                class="text-danger mt-1 text-[11.5px] font-semibold"
                                role="alert"
                            >
                                {{ editErrors.status }}
                            </p>
                        </template>
                        <StateTag v-else :tone="redirectStatusTone(r.status)"
                            >{{ r.status }}
                            {{ redirectStatusWord(r.status) }}</StateTag
                        >
                    </td>
                    <td
                        :data-label="$t('Herkunft')"
                        class="px-3 py-2.5 text-[12.5px] md:whitespace-nowrap"
                    >
                        {{ redirectSourceLabel(r.source) }}
                        <span
                            v-if="r.created_at"
                            class="text-ash-dark block text-[11px]"
                            >{{ r.created_at }}</span
                        >
                    </td>
                    <td
                        :data-label="$t('Treffer')"
                        class="px-3 py-2.5 text-[12.5px] md:text-right md:whitespace-nowrap"
                    >
                        {{ r.hits }}
                        <span class="text-ash-dark block text-[11px]">{{
                            r.last_hit_at
                                ? $t('zuletzt :date', { date: r.last_hit_at })
                                : r.hits === 0
                                  ? $t('noch keiner')
                                  : ''
                        }}</span>
                    </td>
                    <td
                        v-if="can_edit"
                        class="px-3 py-2.5 text-[11px] whitespace-normal md:text-right"
                    >
                        <span
                            v-if="editing === r.id"
                            class="inline-flex flex-wrap items-center gap-x-2 gap-y-0.5 text-basalt-light md:justify-end"
                        >
                            <span v-if="editSaving">{{
                                $t('speichert …')
                            }}</span>
                            <span v-else>{{
                                $t(
                                    'Enter oder Verlassen speichert · Esc bricht ab',
                                )
                            }}</span>
                            <button
                                type="button"
                                class="underline hover:text-basalt"
                                @mousedown.prevent
                                @click="cancelEdit(r)"
                            >
                                {{ $t('Abbrechen') }}
                            </button>
                        </span>
                        <span
                            v-else-if="removingRedirect !== r.id"
                            class="inline-flex flex-wrap items-center gap-x-2.5 gap-y-0.5 md:flex-nowrap md:justify-end"
                        >
                            <button
                                :id="`redirect-edit-${r.id}`"
                                type="button"
                                class="font-semibold text-lava-dark underline hover:text-basalt"
                                @click="startEdit(r)"
                            >
                                {{ $t('Bearbeiten') }}
                            </button>
                            <button
                                type="button"
                                class="text-danger hover:text-danger/80 underline"
                                @click="removingRedirect = r.id"
                            >
                                {{ $t('Entfernen') }}
                            </button>
                        </span>
                        <span
                            v-else
                            class="text-danger inline-flex flex-wrap items-center gap-x-2"
                            role="alertdialog"
                        >
                            <span>{{
                                $t(
                                    'Wirklich entfernen? Die alte Adresse antwortet dann 404.',
                                )
                            }}</span>
                            <button
                                type="button"
                                class="font-semibold underline"
                                @click="removeRedirect(r)"
                            >
                                {{ $t('Ja') }}
                            </button>
                            <button
                                type="button"
                                class="text-basalt-light underline"
                                @click="removingRedirect = null"
                            >
                                {{ $t('Nein') }}
                            </button>
                        </span>
                    </td>
                </tr>
                <template #foot>
                    {{
                        $tc(
                            ':count Umleitung|:count Umleitungen',
                            redirects.length,
                        )
                    }}
                    ·
                    {{
                        $t(
                            '«automatisch» entsteht beim Slug-Wechsel, «von Hand» oben, «Import» aus einer CSV-Datei. 410 meldet «gibt es nicht mehr» ohne Ziel.',
                        )
                    }}
                </template>
            </Table>
        </template>

        <template #not_found>
            <div
                v-if="notFound.length === 0"
                class="rounded-lg border border-ash-light bg-white"
            >
                <EmptyState
                    :title="$t('Keine 404-Treffer')"
                    :text="
                        $t(
                            'Ruft jemand eine Adresse ohne Seite auf, steht sie hier — mit Zähler und Verweis.',
                        )
                    "
                />
            </div>
            <Table v-else>
                <template #head>
                    <tr>
                        <th class="w-full px-3 py-2">{{ $t('Pfad') }}</th>
                        <th class="px-3 py-2 md:text-right">
                            {{ $t('Treffer') }}
                        </th>
                        <th class="px-3 py-2">{{ $t('Zuletzt') }}</th>
                        <th class="px-3 py-2 md:whitespace-nowrap">
                            {{ $t('Erster Treffer') }}
                        </th>
                        <th class="px-3 py-2">{{ $t('Verweis') }}</th>
                        <th v-if="can_edit" class="px-3 py-2">
                            <span class="sr-only">{{ $t('Aktionen') }}</span>
                        </th>
                    </tr>
                </template>
                <template v-for="hit in notFound" :key="hit.id">
                    <tr class="align-top">
                        <td
                            :data-label="$t('Pfad')"
                            class="px-3 py-2.5 font-mono text-xs break-all"
                        >
                            {{ hit.path }}
                        </td>
                        <td
                            :data-label="$t('Treffer')"
                            class="px-3 py-2.5 text-[12.5px] md:text-right"
                        >
                            {{ hit.hits }}
                        </td>
                        <td
                            :data-label="$t('Zuletzt')"
                            class="px-3 py-2.5 text-[12.5px] text-basalt-light md:whitespace-nowrap"
                        >
                            {{ hit.last_seen_at ?? '—' }}
                        </td>
                        <td
                            :data-label="$t('Erster Treffer')"
                            class="px-3 py-2.5 text-[12.5px] text-basalt-light md:whitespace-nowrap"
                        >
                            {{ hit.first_seen_at ?? '—' }}
                        </td>
                        <td
                            :data-label="$t('Verweis')"
                            class="px-3 py-2.5 font-mono text-[11px] [overflow-wrap:anywhere] text-basalt-light md:min-w-40"
                            :title="hit.referrer ?? undefined"
                        >
                            {{ shortReferrer(hit.referrer) || '—' }}
                        </td>
                        <td
                            v-if="can_edit"
                            class="px-3 py-2.5 text-[11px] whitespace-normal md:text-right"
                        >
                            <span
                                class="inline-flex flex-wrap items-center gap-x-2.5 gap-y-0.5 md:flex-nowrap md:justify-end"
                            >
                                <button
                                    type="button"
                                    class="font-semibold whitespace-nowrap text-lava-dark underline hover:text-basalt"
                                    :aria-expanded="hitOpen === hit.id"
                                    @click="
                                        hitOpen === hit.id
                                            ? closeHit()
                                            : openHit(hit)
                                    "
                                >
                                    {{ $t('Umleitung anlegen') }}
                                </button>
                                <button
                                    type="button"
                                    class="text-basalt-light underline hover:text-basalt disabled:text-ash"
                                    :disabled="dismissing === hit.id"
                                    :title="
                                        $t(
                                            'Aus dem Protokoll entfernen — kommt der Pfad wieder, zählt er neu',
                                        )
                                    "
                                    @click="dismissHit(hit)"
                                >
                                    {{ $t('Verwerfen') }}
                                </button>
                            </span>
                        </td>
                    </tr>
                    <tr v-if="can_edit && hitOpen === hit.id" class="bg-paper">
                        <td colspan="6" class="px-3 py-2.5">
                            <form
                                class="flex flex-wrap items-start gap-x-2 gap-y-2 text-[12.5px]"
                                novalidate
                                @submit.prevent="createFromHit"
                                @keydown.esc="closeHit"
                            >
                                <span
                                    class="w-full font-semibold md:w-auto md:pt-1"
                                    ><template
                                        v-for="(part, i) in $t(
                                            'Umleitung von :path nach',
                                        ).split(':path')"
                                        :key="i"
                                        ><span
                                            v-if="i > 0"
                                            class="font-mono break-all"
                                            >{{ hit.path }}</span
                                        >{{ part }}</template
                                    ></span
                                >
                                <div class="min-w-0 flex-1 basis-48">
                                    <label
                                        :for="`hit-to-${hit.id}`"
                                        class="sr-only"
                                        >{{ $t('Ziel') }}</label
                                    >
                                    <input
                                        :id="`hit-to-${hit.id}`"
                                        v-model="hitForm.to"
                                        type="text"
                                        autocomplete="off"
                                        spellcheck="false"
                                        :placeholder="
                                            needsTarget(hitForm.status)
                                                ? $t(
                                                      '/neue-seite oder https://…',
                                                  )
                                                : ''
                                        "
                                        :disabled="!needsTarget(hitForm.status)"
                                        :class="smallMono(hitForm.errors.to)"
                                        :aria-invalid="
                                            hitForm.errors.to
                                                ? 'true'
                                                : undefined
                                        "
                                    />
                                    <p
                                        v-if="!needsTarget(hitForm.status)"
                                        class="mt-1 text-[11.5px] text-basalt-light"
                                    >
                                        {{
                                            $t(
                                                'Die Adresse antwortet «gibt es nicht mehr».',
                                            )
                                        }}
                                    </p>
                                    <p
                                        v-for="e in hitErrors"
                                        :key="e"
                                        class="text-danger mt-1 text-[11.5px] font-semibold"
                                        role="alert"
                                    >
                                        {{ e }}
                                    </p>
                                </div>
                                <div>
                                    <label
                                        :for="`hit-status-${hit.id}`"
                                        class="sr-only"
                                        >{{ $t('Status') }}</label
                                    >
                                    <select
                                        :id="`hit-status-${hit.id}`"
                                        v-model="hitForm.status"
                                        :class="[
                                            controlClasses({ small: true }),
                                            'w-auto!',
                                        ]"
                                    >
                                        <option
                                            v-for="o in statusChoices"
                                            :key="o.value"
                                            :value="o.value"
                                        >
                                            {{ o.label }}
                                        </option>
                                    </select>
                                </div>
                                <span class="flex gap-2">
                                    <Button
                                        type="submit"
                                        size="sm"
                                        :disabled="hitForm.processing"
                                        >{{ $t('Anlegen') }}</Button
                                    >
                                    <Button
                                        variant="link"
                                        size="sm"
                                        @click="closeHit"
                                        >{{ $t('Abbrechen') }}</Button
                                    >
                                </span>
                            </form>
                        </td>
                    </tr>
                </template>
                <template #foot>
                    {{
                        $tc(
                            ':count Adresse ohne Ziel, die häufigsten zuerst|:count Adressen ohne Ziel, die häufigsten zuerst',
                            notFound.length,
                        )
                    }}
                    ·
                    {{
                        $t(
                            'Eine Umleitung erledigt die Zeile; «Verwerfen» entfernt sie (kommt der Pfad wieder, zählt er neu). Alte Zeilen räumt der nächtliche Löschlauf ab (Einstellungen › Aufbewahrung).',
                        )
                    }}
                </template>
            </Table>
        </template>

        <template #trash>
            <div
                v-if="trash.length === 0"
                class="rounded-lg border border-ash-light bg-white"
            >
                <EmptyState
                    :title="$t('Der Papierkorb ist leer')"
                    :text="
                        $t(
                            'Gelöschte Seiten landen hier und lassen sich wiederherstellen.',
                        )
                    "
                />
            </div>
            <Table v-else>
                <template #head>
                    <tr>
                        <th class="px-3 py-2">{{ $t('Titel') }}</th>
                        <th class="px-3 py-2">{{ $t('Typ') }}</th>
                        <th class="px-3 py-2">{{ $t('Gelöscht') }}</th>
                        <th class="px-3 py-2">
                            <span class="sr-only">{{ $t('Aktionen') }}</span>
                        </th>
                    </tr>
                </template>
                <tr v-for="t in trash" :key="t.id" class="align-top">
                    <td class="px-3 py-2.5 text-[13.5px] font-semibold">
                        {{ t.title }}
                        <span
                            v-if="t.children > 0"
                            class="block text-[11.5px] font-normal text-basalt-light"
                            >{{
                                $tc(
                                    'mit :count Unterseite|mit :count Unterseiten',
                                    t.children,
                                )
                            }}</span
                        >
                    </td>
                    <td
                        :data-label="$t('Typ')"
                        class="px-3 py-2.5 text-[12.5px]"
                    >
                        {{ t.type_label }}
                    </td>
                    <td
                        :data-label="$t('Gelöscht')"
                        class="px-3 py-2.5 text-[12.5px] text-basalt-light"
                    >
                        {{ t.deleted_at ?? '—' }}
                    </td>
                    <td class="px-3 py-2.5 text-right">
                        <Button
                            v-if="can_edit"
                            variant="link"
                            size="sm"
                            :href="t.restore_url"
                            method="post"
                            >{{ $t('Wiederherstellen') }}</Button
                        >
                    </td>
                </tr>
                <template #foot>
                    {{ $tc(':count Eintrag|:count Einträge', trash.length) }} ·
                    {{
                        $t(
                            'Wiederherstellen legt die Seite samt ihren Unterseiten an den alten Platz im Baum zurück.',
                        )
                    }}
                </template>
            </Table>
        </template>
    </Tabs>
</template>
