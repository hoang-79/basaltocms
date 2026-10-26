/** TB 12 Scheibe 1 (M-21): Typen der SaaS — Registrierung und Konto-Bereich. */

export type AliasState = 'free' | 'taken' | 'reserved' | 'invalid';

export type AliasResult = {
    state: AliasState;
    alias: string;
    host: string;
    message: string;
    suggestion: string | null;
};

export type SaasTheme = {
    name: string;
    label: string;
    description: string | null;
    has_preview: boolean;
    preview_url?: string | null;
};

export type SaasPlan = {
    key: string;
    label: string;
    instance: string;
    price: number;
    sites: number;
    domains: number;
    storage_mb: number;
    users: number;
    current?: boolean;
};

export type AccountTab = { key: string; label: string; href: string };

/** Der Kopf des Konto-Bereichs, den jede Seite mitbekommt (AccountPage::header). */
export type AccountHeader = {
    id: number;
    name: string;
    plan: SaasPlan | null;
    role: string | null;
    can_manage: boolean;
    can_bill: boolean;
    trial: {
        ends: string | null;
        days_left: number;
        expired: boolean;
        banner_from: string | null;
    } | null;
    tabs: AccountTab[];
};

export type UsageRow = {
    key: 'sites' | 'domains' | 'storage' | 'users';
    label: string;
    used: number;
    limit: number;
    unit: 'count' | 'bytes';
    over: boolean;
};

/** TB 12 Scheibe 3 (G-11): Domains je Site im Konto-Bereich (AccountDomainsController). */
export type DomainState = 'waiting' | 'certificate' | 'active' | 'error';

export type DnsRecord = {
    type: string;
    name: string;
    value: string;
    found: boolean | null;
};

export type CustomDomain = {
    id: number;
    host: string;
    hosts: {
        id: number;
        host: string;
        primary: boolean;
        primary_url: string;
    }[];
    state: DomainState;
    cloud_status: string | null;
    error: string | null;
    records: DnsRecord[];
    checked: string | null;
    verified: boolean;
    urls: { check: string; destroy: string };
};

export type DomainSite = {
    id: number;
    name: string;
    primary: string | null;
    primary_url: string;
    alias: {
        id: number;
        host: string;
        name: string;
        primary: boolean;
        primary_url: string;
    } | null;
    old_aliases: { host: string; until: string | null }[];
    domains: CustomDomain[];
    urls: { alias: string; store: string };
};

export type DomainQuota = {
    included: number;
    extra: number;
    ordered: number;
    limit: number;
    used: number;
};

/** TB 12 Scheibe 2: Abo und Rechnungen (AccountBillingController). */
export type BillingMethod = 'stripe' | 'invoice';

export type BillingPlan = SaasPlan & {
    action: 'current' | 'upgrade' | 'downgrade' | 'choose';
    pending: boolean;
};

export type BillingSubscription = {
    active: boolean;
    method: BillingMethod | null;
    method_label: string | null;
    card: string | null;
    next: { date: string; amount: string } | null;
    pending: { label: string; from: string | null } | null;
    extras: { sites: number; domains: number };
    moving_to: string | null;
};

export type BillingAddress = {
    company: string;
    name: string;
    street: string;
    postal_code: string;
    city: string;
    country: string;
    email: string;
    vat_id: string;
};

export type AccountInvoiceRow = {
    id: number;
    number: string;
    date: string;
    text: string;
    period: string | null;
    amount: string;
    status: 'open' | 'paid' | 'cancelled';
    overdue: boolean;
    due: string | null;
    method: BillingMethod;
    qr: boolean;
    url: string;
};
