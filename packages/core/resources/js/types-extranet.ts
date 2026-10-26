import type {
    MetricKpi,
    MetricSectionData,
    StatisticsPayload,
} from './components/statistics/types';

/**
 * Props des Hersteller-Extranets (TB 19, CR-004 P-09–P-16). Das Layout wählt den Rahmen nach `extranet`.
 */
export type ExtranetMenuItem = {
    label: string;
    href: string;
    route: string;
    icon: string;
    count: number | null;
};

export type ExtranetShared = {
    home: string;
    host: string;
    menu: ExtranetMenuItem[];
    levels: Record<string, number>;
    role: string | null;
    cms_url: string;
    logout_url: string;
};

export type Option = { value: string; label: string };

export type Todo = { key: string; label: string; count: number; href: string };

export type OverviewProps = {
    todos: Todo[];
    last_url: string | null;
    name: string;
};

export type CustomerRow = {
    source: string;
    source_label: string;
    id: number;
    name: string;
    email: string;
    company: string | null;
    meta: string;
    created: string | null;
    tags: string[];
    url: string;
};

export type CustomersProps = {
    rows: CustomerRow[];
    filters: Option[];
    current: { q: string; filter: string | null };
    urls: { index: string };
};

export type CustomerSection = {
    title: string;
    source: string;
    columns: string[];
    rows: string[][];
    empty: string;
};

export type CustomerProps = {
    customer: {
        source: string;
        source_label: string | null;
        id: number;
        name: string;
        email: string;
        company: string | null;
        facts: [string, string][];
    };
    sections: CustomerSection[];
    notes: { id: number; body: string; author: string; date: string | null }[];
    can_note: boolean;
    urls: { index: string; note: string };
};

export type InvoiceRow = {
    source: string;
    source_label: string;
    id: number;
    number: string;
    customer: string;
    email: string;
    issued: string;
    due: string | null;
    currency: string;
    total: number;
    amount: string;
    status: string;
    status_label: string;
    overdue: boolean;
    payable: boolean;
    credited: string | null;
    credit_left: number;
    urls: {
        pdf: string;
        paid: string | null;
        remind: string | null;
        send: string | null;
        credit: string | null;
    };
};

export type InvoicesProps = {
    rows: InvoiceRow[];
    credits: {
        id: number;
        number: string;
        invoice: string;
        amount: string;
        reason: string;
        date: string;
        pdf: string;
    }[];
    filters: Option[];
    current: { filter: string | null };
    urls: { index: string };
};

export type InstanceSummary = {
    instance: string;
    reachable: boolean;
    error: string | null;
    version: string | null;
    ready: boolean;
    status: string;
    checks: Record<string, { ok: boolean; detail: string }>;
    accounts: Record<string, number>;
    domains: {
        used: number;
        limit: number;
        percent: number;
        warning: boolean;
        pending: number;
    };
    moves: number;
    deletions: number;
};

export type SaasProps = {
    tab: 'instances' | 'domains' | 'moves' | 'deletions';
    filter: 'pending' | null;
    saas: boolean;
    instances: InstanceSummary[];
    domains: {
        id: number;
        host: string;
        site: string;
        account: string;
        state: string;
        error: string | null;
        created: string | null;
        url: string | null;
    }[];
    moves: {
        id: number;
        account: string;
        direction: string;
        from: string;
        to: string;
        status: string;
        error: string | null;
        created: string;
    }[];
    deletions: {
        id: number;
        name: string;
        ends: string | null;
        delete_after: string | null;
        url: string;
    }[];
    urls: { index: string };
};

export type SupportProps = {
    sites: { id: number; name: string; handle: string; account: string }[];
    sessions: {
        id: number;
        site: string;
        person: string;
        reason: string;
        started: string | null;
        until: string;
        ended: string | null;
        active: boolean;
        own: boolean;
        urls: { open: string | null; end: string | null };
    }[];
    current: { q: string };
    minutes: { default: number; max: number };
    urls: { index: string; start: string };
};

export type TeamProps = {
    owners: { name: string; email: string }[];
    members: {
        id: number;
        name: string;
        email: string;
        role: string;
        role_label: string;
        urls: { update: string; destroy: string };
    }[];
    roles: (Option & { template: boolean })[];
    urls: { store: string; roles: string };
};

/** TB 55: Extranet › Statistik — Überblick, Website und Abschnitte aus Kern und Modulen. */
export type StatisticsProps = {
    tab: string;
    tabs: { key: string; label: string }[];
    range: string;
    period: { from: string; to: string; days: number };
    site: { name: string };
    overview: { key: string; label: string; kpis: MetricKpi[] }[] | null;
    section: MetricSectionData | null;
    website: StatisticsPayload | null;
    urls: { index: string; website: string };
};

export type CancellationsProps = {
    items: {
        id: number;
        number: string;
        status:
            | 'applied'
            | 'scheduled'
            | 'review'
            | 'done'
            | 'rejected'
            | 'revoked';
        received: string;
        kind: 'ordinary' | 'extraordinary';
        reason: string | null;
        name: string;
        email: string;
        contract: string;
        reference: string | null;
        wish: string | null;
        ends: string | null;
        note: string | null;
        site: string;
        resolve_url: string | null;
    }[];
    filter: 'review' | 'all';
    counts: { review: number; all: number };
    urls: { index: string };
    today: string;
};
