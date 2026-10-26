/** Verträge der Mitglieder-Liste (M-23) — der Controller liefert fertig formatierte Werte. */

export type MemberStatus = 'pending' | 'active' | 'blocked';

export type MemberFilter = MemberStatus | 'all';

export type MemberRow = {
    id: number;
    name: string | null;
    email: string;
    initials: string;
    locale: string;
    status: MemberStatus;
    status_label: string;
    since: string | null;
    last_login: string | null;
    invited: boolean;
    urls: { status: string; destroy: string };
};

export type MembersPageProps = {
    rows: MemberRow[];
    counts: Record<MemberFilter, number>;
    filters: { status: MemberFilter; q: string };
    pagination: {
        current: number;
        last: number;
        total: number;
        prev: string | null;
        next: string | null;
    };
    settings: {
        registration: 'open' | 'approval' | 'invite';
        login_path: string;
        login_default: string;
    };
    registrations: { value: string; label: string }[];
    protected: {
        id: number;
        title: string;
        path: string | null;
        edit: string;
    }[];
    locales: string[];
    default_locale: string;
    can: { manage: boolean };
    urls: {
        index: string;
        invite: string;
        settings: string;
        bulk: string;
        export: string;
        pages: string;
    };
};

/** Zustandsfarbe: wartet Lava, aktiv grün, gesperrt karmin. */
export function memberTone(
    status: MemberStatus,
): 'lava' | 'success' | 'danger' {
    return status === 'pending'
        ? 'lava'
        : status === 'active'
          ? 'success'
          : 'danger';
}
