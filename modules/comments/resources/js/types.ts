/** Verträge der Moderation (M-27) — der Controller liefert fertig formatierte Werte. */

export type CommentStatus = 'pending' | 'approved' | 'spam' | 'deleted';

export type CommentFilter = CommentStatus | 'all';

export type CommentOrigin = {
    id: number;
    title: string;
    type: string;
    url: string | null;
    edit: string | null;
};

export type CommentRow = {
    id: number;
    author: string;
    initials: string;
    email: string | null;
    website: string | null;
    body: string;
    excerpt: string;
    created_at: string | null;
    status: CommentStatus;
    status_label: string;
    staff: boolean;
    reply_to: string | null;
    locale: string;
    origin: CommentOrigin;
    urls: { status: string; reply: string; destroy: string };
};

export type ModerationPageProps = {
    rows: CommentRow[];
    counts: Record<CommentFilter, number>;
    spam: { recent: number; total: number; window: number };
    retention: number;
    origins: { id: number; label: string }[];
    filters: { status: CommentFilter; q: string; ursprung: number | null };
    pagination: {
        current: number;
        last: number;
        total: number;
        prev: string | null;
        next: string | null;
    };
    urls: { index: string; bulk: string };
};
