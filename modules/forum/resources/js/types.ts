/** CR-006 (TB 36): Typen der Extranet-Seiten des Forums. */
export type LocaleOption = { locale: string; is_default: boolean };

export type ForumOverviewProps = {
    counts: {
        nodes: number;
        threads: number;
        posts: number;
        members: number;
        today: number;
    };
    page: { title: string; url: string | null } | null;
    team: {
        linked: {
            name: string;
            avatar: string | null;
            profile_url: string | null;
        } | null;
        email: string;
        members: { name: string; user: string }[];
    };
    settings: {
        edit_minutes: number;
        max_mb: number;
        quota_mb: number;
        types: string;
        max_files: number;
        reactions: string[];
        redirect_days: number;
        flood_seconds: number;
        auto_hide: number;
        warning_days: number;
        mute_points: number;
        mute_days: number;
        ban_points: number;
        ban_days: number;
        censor: string;
        privacy_posts: 'anonymisieren' | 'loeschen';
        terms_required: boolean;
        terms_version: string;
    };
    reaction_options: { key: string; symbol: string; label: string }[];
    moderation: { open: number; pending: number };
    urls: {
        settings: string;
        nodes: string;
        groups: string;
        link: string;
        unlink: string;
        create_page: string;
        moderation: string;
        members: string;
        notices: string;
        recognition: string;
    };
};

export type NodeRow = {
    id: number;
    parent_id: number | null;
    depth: number;
    type: 'category' | 'board' | 'link';
    type_label: string;
    slug: string;
    title: Record<string, string>;
    description: Record<string, string>;
    link_url: string | null;
    closed: boolean;
    icon: string;
    prefixes: number[];
    prefix_required: boolean;
    fields: ThreadField[];
    threads: number;
    posts: number;
    url: string | null;
    update_url: string;
    destroy_url: string;
    move_url: string;
    permissions_url: string;
};

export type ThreadField = {
    key?: string;
    label: Record<string, string>;
    type: 'text' | 'url' | 'select';
    options: string[];
    required: boolean;
};

export type PrefixRow = {
    id: number;
    label: Record<string, string>;
    color: string;
    update_url: string;
    destroy_url: string;
};

export type ForumNodesProps = {
    nodes: NodeRow[];
    locales: LocaleOption[];
    types: { value: string; label: string }[];
    icons: { value: string; label: string; svg: string }[];
    prefixes: PrefixRow[];
    colors: string[];
    field_types: string[];
    base: string | null;
    urls: { store: string; overview: string; prefix_store: string };
};

export type GroupRow = {
    id: number;
    key: string | null;
    name: string;
    badge: string | null;
    system: boolean;
    assignable: boolean;
    members: number | null;
    member_list: {
        id: number;
        email: string;
        name: string;
        remove_url: string;
    }[];
    update_url: string;
    destroy_url: string | null;
    add_member_url: string | null;
};

export type PermissionCell = {
    group: number;
    value: number | null;
    effective: number | null;
    from: string;
};

export type PermissionRow = {
    key: string;
    label: string;
    group: string;
    help: string;
    cells: PermissionCell[];
};

export type ForumGroupsProps = {
    groups: GroupRow[];
    permissions: PermissionRow[];
    forum: { id: number; title: string } | null;
    forums: { id: number; title: string; depth: number }[];
    urls: {
        store: string;
        permission: string;
        self: string;
        customers: string;
        overview: string;
    };
};

/** TB 39: Extranet › Community › Moderation */
export type ModerationTab =
    | 'meldungen'
    | 'freischaltung'
    | 'papierkorb'
    | 'protokoll';

export type ReportCase = {
    id: number;
    type: string;
    count: number;
    title: string;
    excerpt: string;
    url: string | null;
    place: string;
    author: string;
    author_id: number | null;
    hidden: boolean;
    trashed: boolean;
    reports: {
        reason: string;
        note: string | null;
        by: string;
        date: string | null;
    }[];
};

export type PendingCase = {
    id: number;
    thread_id: number | null;
    kind: 'thread' | 'post';
    title: string;
    excerpt: string;
    author: string;
    author_id: number | null;
    date: string | null;
    place: string;
    reported: boolean;
    url: string | null;
};

export type TrashCase = {
    kind: 'thread' | 'post';
    id: number;
    title: string;
    excerpt: string;
    author: string;
    reason: string | null;
    date: string | null;
};

export type LogRow = {
    id: number;
    date: string | null;
    action: string;
    subject: string | null;
    reason: string | null;
    by: string;
    target: string | null;
};

export type ModerationProps = {
    tab: ModerationTab;
    counts: { meldungen: number; freischaltung: number; papierkorb: number };
    reports: ReportCase[];
    pending: PendingCase[];
    trash: TrashCase[];
    log: LogRow[];
    filter: { aktion: string; konto: string };
    actions: { value: string; label: string }[];
    urls: {
        index: string;
        threads: string;
        posts: string;
        report: string;
        overview: string;
        members: string;
    };
};

/** TB 39: Extranet › Community › Mitglieder */
export type MemberWarning = {
    id: number;
    reason: string;
    points: number;
    active: boolean;
    date: string | null;
    expires: string | null;
    revoked: boolean;
    by: string;
    revoke_url: string;
};

export type MemberRow = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
    posts: number;
    team: boolean;
    last: string | null;
    points: number;
    muted: { until: string | null } | null;
    banned: { until: string | null; reason: string | null } | null;
    warnings: MemberWarning[];
    urls: { warn: string; sanction: string; spammer: string };
};

export type MembersProps = {
    q: string;
    filter: 'alle' | 'gesperrt' | 'schreibsperre' | 'verwarnt';
    members: MemberRow[];
    defaults: { warning_days: number; mute_days: number; ban_days: number };
    urls: { index: string; overview: string; moderation: string };
};

/** TB 39: Extranet › Community › Hinweise */
export type NoticeRow = {
    id: number;
    text: Record<string, string>;
    tone: 'info' | 'warnung' | 'erfolg';
    area: string;
    audience: 'all' | 'guests' | 'members';
    groups: number[];
    dismissible: boolean;
    is_active: boolean;
    position: number;
    starts_at: string | null;
    ends_at: string | null;
    update_url: string;
    destroy_url: string;
};

export type NoticesProps = {
    notices: NoticeRow[];
    locales: string[];
    areas: { value: string; label: string }[];
    groups: { id: number; name: string }[];
    urls: { store: string; overview: string };
};

/** TB 40: Extranet › Community › Profile und Auszeichnungen */
export type RankRow = {
    id: number;
    title: string;
    visitor_group_id: number | null;
    min_posts: number;
    color: string;
    icon: string | null;
    update_url: string;
    destroy_url: string;
};

export type TrophyRow = {
    id: number;
    title: string;
    description: string | null;
    icon: string;
    rule: string | null;
    threshold: number | null;
    is_active: boolean;
    awarded: number;
    update_url: string;
    destroy_url: string;
    award_url: string;
};

export type GroupRuleRow = {
    id: number;
    visitor_group_id: number;
    rule: string;
    threshold: number;
    is_active: boolean;
    members: number;
    last_run: string | null;
    update_url: string;
    destroy_url: string;
};

export type ProfileFieldRow = {
    key: string;
    label: string;
    type: 'text' | 'textarea' | 'url' | 'select';
    visibility: 'all' | 'members' | 'team';
    required: boolean;
    options: string[];
};

export type RecognitionProps = {
    ranks: RankRow[];
    trophies: TrophyRow[];
    group_rules: GroupRuleRow[];
    rules: { value: string; label: string; help: string; threshold: boolean }[];
    groups: { id: number; name: string }[];
    all_groups: { id: number; name: string }[];
    fields: ProfileFieldRow[];
    colors: string[];
    urls: {
        ranks: string;
        trophies: string;
        group_rules: string;
        preview: string;
        run: string;
        fields: string;
        overview: string;
        customers: string;
    };
};
