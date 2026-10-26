/* ── Workflow, Fassungen, Cockpit (Bereiche 9 und 13, TB 05) ── */

import type {
    DashboardHint,
    DashboardTask,
    MenuItem,
    PageTreeRow,
} from './types';

/** Die Stufen einer Sprachfassung im Statusmodell (§ 4.2). */
export type Stage =
    | 'draft'
    | 'in_review'
    | 'approved'
    | 'scheduled'
    | 'published'
    | 'archived';

/** Eine Feldänderung zwischen zwei Fassungen; `old`/`new` sind schon als Text aufbereitet. */
export type Change = {
    path: string;
    label: string;
    kind: 'added' | 'removed' | 'changed';
    old: string | null;
    new: string | null;
};

export type ReviewEntry = {
    id: number;
    type: string;
    type_label: string;
    title: string;
    is_page: boolean;
};

export type ReviewSite = { name: string; timezone: string; four_eyes: boolean };

/** Die geprüfte Fassung; `scheduled_date`/`scheduled_time` in der Zeitzone der Site («YYYY-MM-DD», «HH:MM»). */
export type ReviewVersion = {
    id: number;
    number: number;
    status: Stage;
    status_label: string;
    author: string | null;
    submitted_at: string | null;
    approved_by: string | null;
    scheduled_for: string | null;
    scheduled_date: string | null;
    scheduled_time: string | null;
    comment: string | null;
    /** Wer zurückgewiesen hat (liefert die PHP-Seite zusätzlich zum Vertrag). */
    commented_by?: string | null;
};

export type ReviewLive = {
    id: number;
    number: number;
    published_at: string | null;
    author: string | null;
};

export type ReviewActions = {
    submit: boolean;
    withdraw: boolean;
    approve: boolean;
    reject: boolean;
    schedule: boolean;
    unschedule: boolean;
    publish: boolean;
    unpublish: boolean;
};

/** Der Grund, warum ein Knopf gesperrt ist (Vier-Augen, fehlende Pflichtsprache) — null heisst nicht gesperrt. */
export type ReviewBlocked = { approve: string | null; publish: string | null };

export type RequiredLocale = {
    locale: string;
    is_default: boolean;
    is_required: boolean;
    status: string | null;
    status_label: string | null;
    ok: boolean;
};

export type ReviewChanges = {
    against: 'live' | 'previous' | null;
    fields: Change[];
    unchanged: number;
};

/** Eine Fassung derselben Sprache in der Liste «Fassungen». */
export type ReviewVersionRow = {
    id: number;
    number: number;
    status: Stage;
    status_label: string;
    author: string | null;
    approved_by: string | null;
    created_at: string | null;
    published_at: string | null;
    is_live: boolean;
    is_current: boolean;
    preview_url: string;
    restore_url: string;
    compare_url: string;
};

/** Der Vergleich zweier Fassungen (`?a=&b=`). */
export type ReviewCompare = {
    a: { id: number; number: number };
    b: { id: number; number: number };
    fields: Change[];
};

export type ReviewHeartbeat = { age_seconds: number | null; ok: boolean };

export type ReviewUrls = {
    submit: string;
    withdraw: string;
    approve: string;
    reject: string;
    schedule: string;
    unschedule: string;
    publish: string;
    unpublish: string;
    preview: string | null;
    editor: string;
    back: string;
    review_list: string;
};

/* ── Liste «Freigabe» ── */

export type ReviewRow = {
    entry_id: number;
    version_id: number;
    title: string;
    type_label: string;
    locale: string;
    number: number;
    status: Stage;
    status_label: string;
    author: string | null;
    at: string | null;
    scheduled_for: string | null;
    url: string;
    own: boolean;
};

export type ReviewCounts = {
    in_review: number;
    approved: number;
    scheduled: number;
};

export type ReviewFilter = '' | 'in_review' | 'approved' | 'scheduled';

export type ReviewFilters = { status: ReviewFilter };

/* ── Editor, Seitenbaum, Cockpit, Modus-Leiste ── */

/** Die Freigabe im Editor: Adresse, Stufe und die letzte Rückweisung. */
export type EntryReview = {
    url: string;
    stage: Stage;
    rejection: null | {
        comment: string;
        by: string | null;
        at: string | null;
    };
};

/** Eine Zeile des Seitenbaums mit Freigabe (Standardsprache) und Kopieren. */
export type WorkflowPageRow = PageTreeRow & {
    urls: PageTreeRow['urls'] & {
        review?: string | null;
        copy?: string | null;
    };
};

export type BulkAction = 'submit' | 'publish' | 'delete';

export type TaskKind =
    | 'review'
    | 'approved'
    | 'scheduled'
    | 'rejected'
    | 'translation'
    | 'not_found';

/** Eine Aufgabe im Cockpit — `kind` bleibt offen für Module. */
export type WorkflowTask = Omit<DashboardTask, 'kind'> & {
    kind: TaskKind | (string & {});
};

export type HintAction = {
    label: string;
    url: string;
    method: 'post' | 'delete';
};

/** Ein Hinweis im Cockpit, z. B. ein fehlgeschlagener Job mit «Noch einmal versuchen» und «Verwerfen». */
export type WorkflowHint = Omit<DashboardHint, 'kind'> & {
    kind: DashboardHint['kind'] | (string & {});
    actions?: HintAction[];
};

/** Ein Menüeintrag mit Zahl (Freigabe, Übersetzung). */
export type MenuItemWithCount = MenuItem & { count?: number | null };
