/**
 * Verträge der Verwaltung (Bereich 18, TB 05 Scheibe 4b): Benutzer mit Einladung und Sitzungen, Einladung annehmen,
 * Profil, Einstellungen mit Verlauf, Protokoll mit Filtern. Zeiten kommen als fertige Zeichenketten (Dates).
 */

/** Eine angemeldete Sitzung: Gerät/Browser als Satz, IP, zuletzt aktiv; `current` ist die eigene Sitzung. */
export type AdminSession = {
    id: string;
    ip: string | null;
    agent: string;
    last_active: string | null;
    current: boolean;
};

export type AdminRoleOption = { value: string; label: string };

export type AdminUser = {
    id: number;
    name: string;
    email: string;
    /** TB 21: Profilbild, sonst Initialen */
    avatar_url?: string | null;
    role: string | null;
    role_label: string | null;
    passkeys: number;
    last_login: string | null;
    deactivated: boolean;
    is_self: boolean;
    sessions: AdminSession[];
    urls: {
        deactivate: string;
        activate: string;
        sessions_end: string;
        export: string;
        delete: string;
    };
};

export type AdminInvitation = {
    id: number;
    email: string;
    role_label: string;
    invited_by: string | null;
    expires_at: string | null;
    expired: boolean;
    urls: { resend: string; revoke: string };
};

export type UsersPageProps = {
    users: AdminUser[];
    invitations: AdminInvitation[];
    roles: AdminRoleOption[];
    can_manage: boolean;
    roles_url: string | null;
    update_url: string;
    invitation_hours: number;
    urls: { invite: string };
    status: string | null;
};

export type InvitationPageProps = {
    invitation: null | {
        email: string;
        site_name: string;
        role_label: string;
        expires_at: string | null;
        invited_by: string | null;
    };
    error: string | null;
    accept_url: string | null;
    login_url: string;
};

export type ProfileCountry = {
    code: string;
    name: string;
    dial: string;
    min: number;
    max: number;
    groups: number[];
    keep_zero: boolean;
    postal_example: string | null;
    postal_numeric: boolean;
    postal_max: number;
};

export type ProfileOption = { value: string; label: string; example?: string };

export type ProfilePageProps = {
    user: {
        first_name: string;
        last_name: string;
        name: string;
        email: string;
        phone: string;
        phone_country: string;
        street: string;
        address_extra: string;
        postal_code: string;
        city: string;
        country: string;
        locale: string;
        timezone: string;
        date_format: string;
        time_format: string;
        start_page: string;
        /** TB 21 */
        per_page: number;
        list_sort: string;
        help_shortcuts: boolean;
        theme: string;
        avatar_url: string | null;
    };
    countries: ProfileCountry[];
    locales: ProfileOption[];
    timezones: string[];
    date_formats: ProfileOption[];
    time_formats: ProfileOption[];
    start_pages: ProfileOption[];
    /** TB 21: Einträge pro Seite und Sortierung der Listen */
    per_page_options: ProfileOption[];
    list_sorts: ProfileOption[];
    /** TB 21: Darstellung — hell, dunkel, wie das System */
    themes: ProfileOption[];
    sessions: AdminSession[];
    api_tokens: ApiTokenRow[];
    /** Sites, deren Mitglied man ist — nur für sie gibt es Tokens */
    api_sites: ProfileOption[];
    api_abilities: string[];
    /** TB 27: Benachrichtigungen — Gruppen mit Arten und die Wahl der Person */
    notification_groups?: import('./types').NotificationGroup[];
    notification_settings?: import('./types').NotificationPreferences;
    urls: {
        update: string;
        notifications?: string;
        sessions_end_others: string;
        export: string;
        /** mit Platzhalter `__ID__` */
        session_end: string;
        passkeys: string;
        avatar_store: string;
        avatar_destroy: string;
        tokens_store: string;
        /** mit Platzhalter `__ID__` */
        token_destroy: string;
        api_base: string;
    };
    status: string | null;
};

/** Ein API-Token im Profil (M-17) — ohne Klartext; den gibt es nur einmal beim Anlegen. */
export type ApiTokenRow = {
    id: number;
    name: string;
    site: string | null;
    abilities: string[];
    last_used: string | null;
    expires: string | null;
    expired: boolean;
    created: string | null;
};

/** Ein früherer oder der aktuelle Wert einer Einstellung; `restore_url` stellt ihn wieder her. */
export type SettingHistoryRow = {
    id: number;
    old: unknown;
    new: unknown;
    by: string | null;
    at: string | null;
    current: boolean;
    restore_url: string;
};

export type SettingValue = boolean | number | null;

export type SettingItem = {
    key: string;
    label: string;
    help: string | null;
    type: 'bool' | 'int' | 'link';
    value: SettingValue;
    default: SettingValue;
    unit: string | null;
    min: number | null;
    max: number | null;
    scope: 'site' | 'installation';
    href: string | null;
    updated: null | { by: string | null; at: string | null };
    history: SettingHistoryRow[];
};

export type SettingGroup = { key: string; label: string; items: SettingItem[] };

export type SettingsPageProps = {
    groups: SettingGroup[];
    group: string;
    can_edit: boolean;
    urls: { index: string; update: string };
    status: string | null;
};

export type ActivityKind =
    | ''
    | 'content'
    | 'settings'
    | 'users'
    | 'logins'
    | 'system';

export type ActivityRow = {
    id: number;
    at: string | null;
    user: string | null;
    action: string;
    action_label: string;
    subject: string | null;
    subject_url: string | null;
    change: string | null;
};

export type ActivityFilters = {
    kind: ActivityKind;
    user: string;
    days: number;
    q: string;
    subject: string;
};

export type ActivityPageProps = {
    entries: ActivityRow[];
    filters: ActivityFilters;
    kinds: { value: string; label: string; count: number }[];
    people: { id: number; name: string }[];
    pagination: { next: string | null; prev: string | null };
    total: number;
    retention_days: number;
    urls: { index: string; export: string };
};

/** CR-005 (R-08): Rollen-Editor — Stufe je Bereich, Sprachen, Zahl der Personen. */
export type RoleLanguages = {
    mode: 'all' | 'except_default' | 'only';
    codes: string[];
};

export type RoleArea = {
    key: string;
    label: string;
    group: string;
    module: string | null;
};

export type AdminRole = {
    key: string;
    name: string;
    description: string | null;
    template: boolean;
    customized: boolean;
    locked: boolean;
    people: number;
    levels: Record<string, number>;
    languages: RoleLanguages;
    urls: { update: string; reset: string | null; destroy: string | null };
};

export type RolesPageProps = {
    roles: AdminRole[];
    areas: RoleArea[];
    groups: { key: string; label: string }[];
    levels: { value: number; label: string }[];
    locales: { code: string; is_default: boolean }[];
    can_manage: boolean;
    selected: string | null;
    urls: { store: string; users: string };
};
