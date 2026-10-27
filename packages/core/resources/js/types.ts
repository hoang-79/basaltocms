import type { PushConfig } from './live/push';
import type { FormDataConvertible } from '@inertiajs/core';
import type {
    LayoutForm,
    PartForm,
    ResolvedRegion,
    TemplateForm,
} from './components/layoutEditor';

export type BackendMode = 'content' | 'setup' | 'admin';

export type MenuItem = {
    label: string;
    href: string;
    route: string;
    mode: string;
    module: string | null;
    icon: string | null;
    badge?: number | null;
};

export type ModuleInfo = { handle: string; label: string; switchable: boolean };

export type SiteOption = {
    id: number;
    handle: string;
    name: string;
    switch_url: string;
};

/** TB 23: eine Station des Fahrplans der Einrichtung */
export type JourneyStation = {
    key: string;
    question: string;
    term: string;
    explain: string;
    action: string;
    letters: string[];
    status: 'success' | 'warning' | 'danger' | 'waiting';
    findings: LineRow[];
    href: string | null;
    links: { label: string; href: string }[];
};

export type Journey = {
    stations: JourneyStation[];
    active: string | null;
    branch: {
        label: string;
        question: string;
        href: string | null;
        active: boolean;
    };
    interchange: { label: string; href: string | null };
};

/** Eine Station der roten Linie A–Z: Buchstabe, Schlüsselwort, Stand («✓», «✗», «–») und Zusammenfassung. */
export type LineRow = {
    letter: string;
    keyword: string;
    status: string;
    summary: string;
    /** Die Seite der Station (C, D, E, G) — ohne Adresse bleibt die Station ein reiner Stand. */
    href?: string | null;
};

export type ThemeInfo = { name: string; source: 'files' | 'database' };

/** Ein Statuspunkt: Form und Farbe nach Statusmodell einer Fassung oder als reiner Zustand. */
export type StatusKind =
    | 'live'
    | 'review'
    | 'draft'
    | 'stale'
    | 'scheduled'
    | 'missing'
    | 'success'
    | 'warning'
    | 'danger'
    | 'lava'
    | 'none';

/**
 * Ein Reiter eines Formulars oder einer Liste; `errors` zählt die Validierungsfehler auf diesem Reiter,
 * `count` die Einträge dahinter (kleine Marke in Mono auf Papier, wie der Zähler in der Modus-Leiste).
 */
export type TabDef = {
    key: string;
    label: string;
    errors?: number;
    count?: number;
};

/** TB 27: eine Benachrichtigung in Glocke und Center — Texte fertig übersetzt, Zeit nach dem Profil */
export type NotificationItem = {
    id: string;
    kind: string;
    title: string;
    body: string | null;
    icon: string;
    group: string | null;
    read: boolean;
    at: string | null;
    day: string;
    /** Name der Site, wenn sie nicht die gerade gewählte ist */
    site: string | null;
    open_url: string;
    read_url: string;
    destroy_url: string;
};

export type NotificationsShared = {
    unread: number;
    bell_url: string;
    index_url: string;
    read_all_url: string;
    settings_url: string;
    /** CR-008: Schlüssel der Live-Aktualisierung und Push, wenn eingerichtet */
    live?: {
        site: number;
        key: string;
        token: string;
        version: number;
        interval: number;
        push: PushConfig | null;
    };
};

/** TB 27: Einstellungen im Profil — Gruppen mit ihren Arten und die Wahl der Person */
export type NotificationTypeRow = {
    key: string;
    label: string;
    description: string | null;
    icon: string;
    bell: boolean;
    mail: boolean;
    mail_required: boolean;
    mail_available: boolean;
};

export type NotificationGroup = {
    scope: 'backend' | 'extranet';
    group: string;
    types: NotificationTypeRow[];
};

export type NotificationPreferences = {
    enabled: boolean;
    bell: boolean;
    mail: boolean;
    types: Record<string, { bell: boolean; mail: boolean }>;
};

export type BackendProps = {
    /** TB 19: nur im Hersteller-Extranet gesetzt — dann gilt der Rahmen ExtranetLayout */
    extranet?: import('./types-extranet').ExtranetShared | null;
    auth: {
        user: {
            id: number;
            name: string;
            email: string;
            /** TB 21: Profilbild, sonst Initialen */
            avatar_url?: string | null;
        } | null;
    };
    backend: {
        name: string;
        locale: string;
        mode: BackendMode;
        /** CR-011 TB 59: «Verträge hier kündigen» — nur für Gäste der SaaS, sonst null */
        cancellation?: string | null;
        menus: { content: MenuItem[]; setup: MenuItem[]; admin: MenuItem[] };
        modules: ModuleInfo[];
        site: {
            id: number;
            handle: string;
            name: string;
            default_locale: string;
        } | null;
        sites: SiteOption[];
        logout_url: string;
        /** TB 21: Benutzermenü — Profil und Einstellungen (Reiter derselben Seite) */
        profile_url?: string;
        settings_url?: string;
        /** TB 27: Glocke — ungelesene und Adressen von Glocke, Center und Einstellungen */
        notifications?: NotificationsShared | null;
        /** TB 21: Darstellung nach dem Profil (nicht zu verwechseln mit `theme`, dem Theme der Site) */
        appearance?: 'light' | 'dark' | 'system';
        /** CR-005 (R-06): Stufe je Bereich (0 keine … 4 alles) und sichtbare Modi */
        access: Record<string, number>;
        modes: { setup: boolean; admin: boolean };
        /** TB 19 (P-16): Band «Support des Herstellers hat lesenden Zugriff» */
        support?: { text: string; until: string } | null;
        /** «Zum Extranet» für Superadmin und das Team des Extranets — sonst null */
        extranet_url?: string | null;
        /** CR-004 (P-01): Adresse des Herstellers */
        vendor: { url: string; host: string; license_url: string };
        content_url: string;
        setup_url: string;
        /** TB 23: erste Seite der Verwaltung, null ohne Zugang */
        admin_url?: string | null;
        /** TB 23: Fahrplan der Einrichtung, null ohne Zugang */
        journey?: Journey | null;
        search_url: string | null;
        /** TB 10 Scheibe 6 (N-09): Hilfeseite zur aktuellen Route, sonst null */
        help_url?: string | null;
        /** TB 20 (Webhilfe): Seite zur Route fürs Panel, Grundadresse der Hilfe, Adressen von Panel und Suche */
        help?: {
            page: string | null;
            url: string;
            panel_url: string;
            search_url: string;
            /** TB 21: Tastenkürzel ? und F1 nach dem Profil */
            shortcuts?: boolean;
            /** TB 29: Breite der Leiste aus dem Profil (px) und wohin eine gezogene Breite gespeichert wird */
            width?: number;
            width_url?: string;
        };
        /** TB 30: geführtes Blockformular — Linkziele der Site und Live-Vorschau beliebiger Blöcke */
        editor?: {
            links_url: string;
            preview_url: string;
            theme_images_url?: string;
        } | null;
        /** White-Label (G-02): Name der Agentur statt der Marke Basalto */
        brand: string | null;
        line: LineRow[];
        theme: ThemeInfo | null;
        version: string;
        flash: { status: string | null };
        /** TB 11 Scheibe 4 (G-10): Hinweisband der Demo, sonst null */
        demo?: string | null;
        /** TB 12 Scheibe 1 (M-21): Hinweisband «Testphase abgelaufen» der SaaS, sonst null */
        trial?: {
            text: string;
            href: string | null;
            label: string | null;
        } | null;
        /** TB 13 Scheibe 3: geteilte Props der Module für ihre Teile in Seiten des Kerns (ModuleSlot), null = nicht da */
        extensions?: Record<string, unknown>;
        /** TB 12 Scheibe 4 (M-21): Hinweisband nach Kündigung, Sperre oder während des Umzugs, sonst null */
        account_exit?: {
            text: string;
            href: string | null;
            label: string | null;
            tone: 'warn' | 'danger';
        } | null;
    };
};

export type LicenseInfo = {
    plan: string;
    plan_label: string;
    licensee: string | null;
    registered: boolean;
    compliant: boolean;
    stand: string;
    users: number;
    sites: number;
    updates_until: string | null;
    hints: string[];
    banner_days: number;
    banner_from: string | null;
    banner_active: boolean;
};

export type OperationsInfo = {
    heartbeat_age_seconds: number | null;
    queue_waiting: number;
    queue_failed: number;
    version: string;
    last_deploy: string | null;
};

export type DashboardHint = {
    kind: 'passkey' | 'license' | 'jobs';
    text: string;
    href: string | null;
    action: string | null;
};

export type DashboardTask = {
    kind: string;
    title: string;
    meta: string;
    href: string;
    action: string;
};

export type DashboardSite = {
    id: number;
    handle: string;
    name: string;
    locales: string[];
    domains: { host: string; kind: string; verified: boolean }[];
    /** null, wenn die Rolle die Einstellungen der Site nicht sieht (CR-005) */
    edit_url: string | null;
};

export type SiteLocale = {
    locale: string;
    is_default: boolean;
    is_required: boolean;
    fallback_locale: string | null;
};

export type SiteDomain = {
    host: string;
    kind: string;
    is_primary: boolean;
    verified_at: string | null;
};

export type SiteListRow = {
    id: number;
    handle: string;
    name: string;
    default_locale: string;
    locales: SiteLocale[];
    domains: SiteDomain[];
    users_count: number;
    edit_url: string;
    switch_url: string;
};

export type SiteFormDomain = {
    id: number | null;
    host: string;
    kind: string;
    is_primary: boolean;
};

export type SiteFormData = {
    id: number | null;
    handle: string;
    name: string;
    default_locale: string;
    timezone: string;
    theme: string | null;
    locales: SiteLocale[];
    domains: SiteFormDomain[];
    modules: Record<string, boolean>;
    media?: { alt_required: boolean };
};

export type UserRow = {
    id: number;
    name: string;
    email: string;
    role: string;
    role_label: string;
    passkeys: number;
    last_login: string | null;
};

export type RoleOption = { value: string; label: string };

export type ActivityEntry = {
    id: number;
    action: string;
    user: string | null;
    subject: string | null;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    created_at: string;
};

export type SettingRow = {
    id: number;
    key: string;
    value: unknown;
    scope: string;
    updated_by: string | null;
    updated_at: string | null;
    revisions: number;
};

/* ── Mediathek (Bereich 14, TB 03) ── */

/** Ein Ordner der Mediathek in Baumreihenfolge; `depth` ist die Einrückung, `count` die Dateien darin. */
export type MediaFolder = {
    id: number;
    name: string;
    parent_id: number | null;
    depth: number;
    count: number;
    urls?: { update: string; delete: string };
};

export type MediaCounts = { all: number; trash: number };

export type MediaView = 'library' | 'trash';

export type MediaTypeFilter = '' | 'image' | 'document';

export type MediaFilters = { q: string; type: MediaTypeFilter };

/** Stand der Bildvarianten: fertig, in der Warteschlange oder keine (Dokument, SVG). */
export type MediaVariantsState = 'ready' | 'pending' | 'none';

/** Eine Kachel der Mediathek; `alt_missing` nennt die Sprachen ohne Alt-Text, `usages` zählt die Inhalte. */
export type MediaRow = {
    id: number;
    name: string;
    mime: string;
    size: number;
    width: number | null;
    height: number | null;
    is_image: boolean;
    url: string | null;
    full: string | null;
    variants_state: MediaVariantsState;
    created_at: string | null;
    uploaded_by: string | null;
    folder_id: number | null;
    alt_missing: string[];
    usages: number;
    detail_url: string;
};

export type MediaPagination = {
    next: string | null;
    prev: string | null;
    page: number;
    total: number;
};

export type MediaVariant = {
    name: string;
    width: number;
    height: number;
    url: string;
    formats: string[];
    densities?: number[];
    spec?: string | null;
};

/** Eine Verwendung: der Inhalt, die Sprache und das Feld, in dem die Datei eingebunden ist. */
export type MediaUsage = {
    entry_id: number;
    title: string;
    locale: string;
    field: string;
    url: string;
};

export type MediaDetail = {
    id: number;
    name: string;
    mime: string;
    size: number;
    width: number | null;
    height: number | null;
    is_image: boolean;
    title: string;
    credit: string;
    alt: Record<string, string>;
    focus_x: number;
    focus_y: number;
    url: string | null;
    original_url: string;
    folder_id: number | null;
    variants: MediaVariant[];
    variants_state: MediaVariantsState;
    usages: MediaUsage[];
    uploaded: { by: string | null; at: string | null };
    deleted_at: string | null;
    urls: {
        update: string;
        delete: string;
        restore: string;
        replace: string;
        download: string;
    };
};

/** Das Kontingent der Site in Bytes — null ohne Grenze. */
export type MediaQuota = { used: number; limit: number };

export type MediaUrls = { index: string; upload: string; folders: string };

/** Die Antwort des Mehrfach-Uploads: je Datei ok oder abgewiesen mit Grund. */
export type MediaUploadResult = {
    files: {
        name: string;
        ok: boolean;
        id?: number;
        error?: string;
        media?: MediaRow;
    }[];
};

/**
 * Ein Feld aus dem Theme-Schema; `allow` nennt bei Typ `blocks` die erlaubten Blöcke (verschachtelt),
 * `relation` bei Typ `relation` den Zieltyp, `fields` bei `repeater` die Spalten.
 */
export type SchemaField = {
    name: string;
    label: string;
    /** TB 30: Erklärung unter dem Feld, Beispiel als Platzhalter, Feld, dessen Wert gilt, solange dieses leer ist
     * («cta.label»), und «unter Weitere Möglichkeiten» — alles aus Lava (`help`, `placeholder`, `from`, Flag `advanced`) */
    help?: string | null;
    placeholder?: string | null;
    from?: string | null;
    /** TB 30: «image» an einem Text-Feld — Bild aus der Mediathek wählen (gespeichert als «media:<id>») */
    pick?: string | null;
    advanced?: boolean;
    type: string;
    required: boolean;
    translatable: boolean;
    options: string[];
    default: unknown;
    allow: string[];
    relation: string | null;
    fields: SchemaField[];
};

/** Ein Block des Themes, der für diesen Typ nicht erlaubt ist — bleibt in der Palette sichtbar, mit Grund. */
export type BlockedBlock = { name: string; label: string; reason: string };

/** Der Auszug der Seiteneinstellungen im Editor (E-25) — nur bei Seiten. */
export type SettingsExcerpt = {
    type_label: string;
    template: string;
    slug: string;
    meta_title: string;
    robots: string;
    visible: string;
    window: string;
};

export type SchemaBlock = {
    name: string;
    label: string;
    /** TB 28: Gruppe und ein Satz für die Palette (aus Lava `group`, `hint`) — sonst ordnet der Editor nach den Feldern */
    group?: string | null;
    hint?: string | null;
    fields: SchemaField[];
};

export type Schema = {
    blocks: SchemaBlock[];
    types: {
        name: string;
        label: string;
        template: string;
        allowed: string[];
        fields: SchemaField[];
    }[];
};

export type FieldValue = FormDataConvertible;

export type BlockValue = { type: string; data: Record<string, FieldValue> };

export type MediaOption = {
    id: number;
    name: string;
    url: string | null;
    mime?: string | null;
    width?: number | null;
    height?: number | null;
    folder_id?: number | null;
    alt_missing?: string[];
};

/** Ein Bild unter assets/ des Themes — `path` ohne «assets/», so wie ein Bildfeld es speichert. */
export type ThemeImage = {
    path: string;
    url: string;
};

export type VersionChange = {
    path: string;
    kind: string;
    old: unknown;
    new: unknown;
};

export type HistoryItem = {
    id: number;
    number: number;
    status: string;
    status_label: string;
    created_at: string | null;
    preview_url: string;
    restore_url?: string;
    author?: string | null;
    changes: VersionChange[];
};

/* ── Seitenbaum und Seiteneinstellungen (Bereich 28, TB 01) ── */

export type PageTypeValue =
    | 'page'
    | 'redirect'
    | 'error_401'
    | 'error_403'
    | 'error_404'
    | 'error_503'
    | 'feed'
    | 'folder';

export type PageTypeOption = {
    value: string;
    label: string;
    description: string;
};

export type PageLocaleOption = {
    locale: string;
    is_default: boolean;
    is_required: boolean;
};

/**
 * Eine Zeile des Seitenbaums in Baumreihenfolge; `depth` ist die Einrückung.
 * Bei aktivem Filter: `match` = Treffer, `visible` = Treffer oder Vorfahre eines Treffers (gedimmt); ohne Filter beides wahr.
 * `changed` ist die letzte Fassung über alle Sprachen, `at` relativ («heute 09:40», «gestern 16:12», «12.10.», «01.09.2025»).
 */
export type PageTreeRow = {
    id: number;
    title: string;
    type: string;
    type_label: string;
    depth: number;
    parent_id: number | null;
    position: number;
    has_children: boolean;
    path: string | null;
    status: Record<string, string | null>;
    hint: string | null;
    target: string | null;
    is_home: boolean;
    translation?: Record<string, TranslationState>;
    match: boolean;
    visible: boolean;
    changed: { by: string | null; at: string | null } | null;
    urls: {
        settings: string;
        content: string | null;
        preview: string | null;
        move: string;
        delete: string;
        new_child: string | null;
    };
};

/** Der Filter des Seitenbaums: Status, Sprache, Suchtext — leer heisst «alle». */
export type PageFilters = { status: string; locale: string; q: string };

/** Die Zahlen auf den Reitern: `tree` zählt bei aktivem Filter die Treffer, sonst alle Seiten. */
export type PageCounts = {
    tree: number;
    redirects: number;
    not_found?: number;
    trash: number;
};

/** Ein Status des Statusmodells als Auswahl: `draft` → «Entwurf». */
export type StatusOption = { value: string; label: string };

/** Eine Umleitung (M-10): 301/302 mit Ziel, 410 ohne; Herkunft Slug-Wechsel, von Hand oder Import. */
export type PageRedirectRow = {
    id: number;
    locale: string;
    from: string;
    to: string;
    status: number;
    source: 'slug_change' | 'manual' | 'import' | (string & {});
    hits: number;
    created_at: string | null;
    /** relativ, z. B. «vor 2 Stunden» */
    last_hit_at: string | null;
    update_url: string;
    delete_url: string;
};

/** Eine Zeile des 404-Protokolls: Pfad ohne Ziel mit Zähler, Verweis und erstem/letztem Treffer. */
export type PageNotFoundRow = {
    id: number;
    path: string;
    locale: string | null;
    hits: number;
    referrer: string | null;
    first_seen_at: string | null;
    /** relativ, z. B. «vor 2 Stunden» */
    last_seen_at: string | null;
    dismiss_url: string;
};

export type PageTrashRow = {
    id: number;
    title: string;
    type_label: string;
    /** TB 29: Unterseiten, die mit ihr gelöscht wurden und mit ihr zurückkommen */
    children: number;
    deleted_at: string | null;
    restore_url: string;
};

/** Die bearbeitete Seite — null beim Anlegen. */
export type PageInfo = {
    id: number;
    type: string;
    uuid: string;
    title: string;
    paths: Record<string, string | null>;
    status: Record<string, string | null>;
    versions: Record<string, number | null>;
    created_at: string | null;
    content_url: string | null;
    preview_url: string | null;
    published_url: string | null;
};

export type PageMetaLocale = { title: string; description: string };

/** Metadaten: je Sprache Titel und Beschreibung, daneben `robots` — der Vertrag der PHP-Seite mischt beides in einem Objekt. */
export type PageMetaData = { robots: string } & Record<
    string,
    PageMetaLocale | string
>;

export type PageFormData = {
    type: string;
    name: Record<string, string>;
    parent_id: number | null;
    routing: {
        slugs: Record<string, string>;
        priority: number;
        redirect_on_slug_change: boolean;
        home: Record<string, boolean>;
    };
    meta: PageMetaData;
    image: {
        media_id: number | null;
        focus_x: number;
        focus_y: number;
        alt: string;
    };
    canonical: { mode: 'auto' | 'custom'; url: string; keep_query: string };
    access: { protected: boolean; children: boolean };
    layout: { mode: 'inherit' | 'own'; template: string; children: string };
    cache: {
        /** Seiten-Cache (TB 06): fertiges HTML aus dem Cache, Standard ein */
        page_cache: boolean;
        shared_max_age: number;
        private_max_age: number;
        always_shared: boolean;
    };
    rights: { owner_id: number | null; roles: string[] };
    expert: {
        css_class: string;
        in_sitemap: boolean;
        in_search: boolean;
        hide_in_menu: boolean;
        /** CR-006 (FO-K1): eingehängtes Modul (Forum …) oder leer. */
        mount?: string | null;
    };
    keyboard: { shortcut: string };
    publication: { visible_from: string; visible_until: string };
    feed: { type: string; limit: number; format?: 'rss' | 'atom' };
    redirect: {
        kind: 'internal' | 'external';
        entry_id: number | null;
        url: string;
        /** Externe Ziele der weiteren Sprachen — fehlt eines, gilt `url` */
        urls: Record<string, string>;
        status: number;
    };
};

/**
 * Eine mögliche Elternseite oder ein Ziel: Pfade je Sprache (mit Sprachpräfix), Tiefe im Baum und `base_paths` —
 * der Pfad ohne Präfix, unter dem Kinder landen («» für die oberste Ebene oder die Startseite, `null` = unbekannt).
 */
export type PageParentOption = {
    id: number;
    parent_id: number | null;
    title: string;
    path: string | null;
    paths: Record<string, string | null>;
    base_paths: Record<string, string | null>;
    type: string;
    depth: number;
};

export type PageMediaOption = {
    id: number;
    name: string;
    url: string | null;
    width: number | null;
    height: number | null;
};

export type PageOptions = {
    types: PageTypeOption[];
    parents: PageParentOption[];
    targets: PageParentOption[];
    templates: string[];
    content_types: string[];
    media: PageMediaOption[];
    roles: RoleOption[];
    users: { id: number; name: string }[];
    robots: string[];
    locales: PageLocaleOption[];
    site_url: string;
    title_suffix: string;
    /** CR-006 (FO-K1): Module, die sich an einer Seite einhängen lassen. */
    mounts?: { value: string; label: string }[];
    timezone: string;
    /** TB 13 Scheibe 2 (M-23): Reiter «Zugriff» — Modul der Mitglieder und geerbter Schutz. */
    access: PageAccessOptions;
};

export type PageAccessOptions = {
    installed: boolean;
    enabled: boolean;
    admin_url: string | null;
    modules_url: string;
    inherited: { title: string; url: string } | null;
};

export type PageRedirectHere = {
    from: string;
    to: string;
    status: number;
    source: string;
    hits: number;
};

/* ── Inhaltstypen: Station E und Typ-Listen (Bereiche 10 und 17, TB 01) ── */

/** Ein Befund des Lava-Compilers: Datei, Zeile, Spalte, Schritt der roten Linie und Vorschlag. */
export type ThemeFinding = {
    severity: string;
    message: string;
    file: string;
    line: number;
    column: number;
    step: string | null;
    suggestion: string | null;
};

export type ContentTypeSource = 'theme' | 'site' | 'missing';

/** Eine Zeile der Station E: Typ oder Einzelinhalt, Herkunft, Felder, Inhalte, Routen je Sprache, Template, Lava-Code. */
export type ContentTypeRow = {
    kind: 'type' | 'global';
    name: string;
    label: string;
    source: ContentTypeSource;
    is_page_type: boolean;
    fields: number;
    entries: number;
    routes: Record<string, string>;
    template: string | null;
    lava: string;
    edit_url: string;
    list_url: string | null;
};

/** Der bearbeitete Typ — null beim Anlegen; `file` und `line` zeigen auf die Lava-Quelle. */
export type ContentTypeInfo = {
    name: string;
    kind: string;
    source: ContentTypeSource;
    entries: number;
    lava: string;
    file: string;
    line: number;
};

export type ContentTypeFormField = {
    name: string;
    type: string;
    required: boolean;
    translatable: boolean;
    options: string;
    default: string;
    relation: string;
    allow: string[];
    /** TB 32: Beschriftung je Sprache (Sprachcode) und Hilfe der Grundsprache */
    labels: Record<string, string>;
    help: string;
};

export type ContentTypeFormData = {
    kind: 'type' | 'global';
    name: string;
    labels: Record<string, string>;
    fields: ContentTypeFormField[];
    route: Record<string, string>;
    template: string;
};

export type ContentTypeOptions = {
    field_types: { value: string; label: string }[];
    blocks: string[];
    templates: { name: string; for: string }[];
    types: string[];
    locales: string[];
    default_locale: string;
};

/** Ein Reiter der Typ-Listen: Typ mit Zahl; `in_theme: false` heisst, der Typ ist nicht mehr definiert. */
export type EntryTypeTab = {
    name: string;
    label: string;
    count: number;
    in_theme: boolean;
    /** TB 29: Einzelinhalt (global) — genau einer je Site, kein «Neuer Inhalt» */
    global?: boolean;
};

export type EntryRow = {
    id: number;
    type: string;
    title: string;
    status: Record<string, string | null>;
    translation?: Record<string, TranslationState>;
    date: string | null;
    edit_url: string;
    preview_url: string | null;
};

export type EntryFilters = { status: string; locale: string; q: string };

export type EntryPagination = {
    next: string | null;
    prev: string | null;
    page: number;
    /** TB 29: aus dem Profil */
    per_page: number;
    sort: string;
};

/* ── Übersetzung (Bereich 12, TB 04) ── */

/** Der Stand einer Sprachfassung: Standardsprache, aktuell, veraltet (ein übersetzbares Feld der Quelle hat sich geändert), fehlt. */
export type TranslationState = 'source' | 'current' | 'outdated' | 'missing';

/** Ein Feldpaar Quelle ↔ Ziel; `path` ist der Schlüssel beim Speichern (`values.<path>`), `previous` der Quelltext der übersetzten Fassung. */
export type TranslationField = {
    path: string;
    label: string;
    type: string;
    translatable: boolean;
    source: unknown;
    target: unknown;
    source_html: string | null;
    previous: unknown;
    previous_html: string | null;
    changed: boolean;
};

/** Ein Abschnitt der Übersetzung: Seite, Metadaten, ein Block («Block 1 · Hero», rechts `hero`). */
export type TranslationSection = {
    key: string;
    title: string;
    subtitle: string | null;
    fields: TranslationField[];
};

export type TranslationProgress = {
    translated: number;
    total: number;
    changed: number;
};

/** Ein Sprach-Reiter der Übersetzung mit Stand und Adresse. */
export type TranslationLocale = {
    locale: string;
    is_default: boolean;
    is_required: boolean;
    state: TranslationState;
    url: string;
};

/** Eine Zeile der Übersicht «Übersetzung»: Stand und Adresse je Sprache. */
export type TranslationRow = {
    entry_id: number;
    title: string;
    type_label: string;
    kind: 'page' | 'entry';
    states: Record<string, TranslationState>;
    urls: Record<string, string>;
};

export type TranslationFilters = {
    locale: string;
    state: '' | 'outdated' | 'missing';
};

/* ── Navigation — Station D (Bereich 15, TB 04) ── */

export type NavigationSource = 'pages' | 'type' | 'db' | 'theme';

/** Ein Menü aus dem Theme (`nav <handle> { … }`); `editable` nur bei `items: db`, `line` zeigt in theme.lava. */
export type NavigationMenu = {
    handle: string;
    label: string;
    source: NavigationSource;
    source_label: string;
    editable: boolean;
    depth: number;
    start: number;
    exclude: string[];
    only: string[];
    count: number;
    code: string;
    line: number | null;
};

export type NavigationTarget = {
    kind: 'entry' | 'url';
    entry_id: number | null;
    url: string | null;
    title: string | null;
    path: string | null;
};

/**
 * Ein Eintrag in Baumreihenfolge (`depth` 0 = oberste Ebene). Freie Einträge tragen `id` und `urls`; Einträge aus dem
 * Seitenbaum oder einer Typ-Liste nicht. `label_fallback`: die Beschriftung kommt aus einer anderen Sprache.
 */
export type NavigationItem = {
    id: number | null;
    parent_id: number | null;
    depth: number;
    label: Record<string, string>;
    label_effective: string;
    label_fallback: boolean;
    target: NavigationTarget;
    visible: boolean;
    new_window: boolean;
    live: boolean;
    hint: string | null;
    urls: { update: string; delete: string } | null;
};

/** Die Vorschau eines Menüs, wie das Theme es rendert. */
export type NavigationPreview = {
    label: string;
    href: string;
    active: boolean;
    children: NavigationPreview[];
};

export type NavigationPage = {
    id: number;
    title: string;
    path: string | null;
    depth: number;
    live: boolean;
};

/* ── Layouts, Teile, Templates — Station C/G (Bereich 27, TB 04, nur lesend) ── */

export type LayoutRegion = {
    name: string;
    fill: string[];
    inherited: boolean;
    placement: string | null;
};

/** Ein Layout im Layout-Editor (M-28): Lesesicht, Formular, Regionen samt geerbten, Code derselben Quelle. */
export type LayoutInfo = {
    name: string;
    extends: string | null;
    regions: LayoutRegion[];
    resolved: ResolvedRegion[];
    form: LayoutForm;
    markup_regions: string[];
    has_markup: boolean;
    code: string;
    file: string;
    line: number;
};

export type PartInfo = {
    name: string;
    kind: string;
    kind_label: string;
    origin: string;
    settings: Record<string, string>;
    used_in: string[];
    form: PartForm;
    code: string;
    file: string;
    line: number;
};

export type PartKind = {
    name: string;
    label: string;
    origin: string;
    settings: string[];
    enabled: boolean;
    instances: number;
};

export type TemplateInfo = {
    name: string;
    for: string | null;
    layout: string;
    noindex: boolean;
    regions: { name: string; fill: string[] }[];
    used_by: number;
    form: TemplateForm;
    layout_regions: string[];
    code: string;
    /** TB 35: eine Seite mit diesem Template (für die Vorschau) und die Beschriftung des Typs */
    example?: string | null;
    for_label?: string | null;
};
