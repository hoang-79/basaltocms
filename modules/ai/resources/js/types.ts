import type { AiFeature } from './ai';

/** Verwaltung › KI-Vorschläge (M-24) — der Schlüssel kommt nie mit, nur ob er gesetzt ist und seine letzten vier Zeichen. */
export type AiSettingsForm = {
    provider: string;
    model: string;
    has_key: boolean;
    key_hint: string | null;
    daily_limit: number;
    monthly_token_limit: number | null;
    features: Record<AiFeature, boolean>;
};

export type AiProviderOption = {
    value: string;
    label: string;
    vision: boolean;
    default_model: string | null;
    privacy_url: string;
};

export type AiFeatureOption = { value: AiFeature; label: string; help: string };

export type AiMonth = {
    month: string;
    label: string;
    count: number;
    applied: number;
    failed: number;
    tokens: number;
    features: Record<AiFeature, number>;
};

export type AiUsageRow = {
    id: number;
    at: string | null;
    user: string;
    feature: AiFeature;
    feature_label: string;
    subject: { label: string; url: string } | null;
    locale: string | null;
    provider: string;
    model: string | null;
    tokens: number | null;
    status: 'ok' | 'error';
    error: string | null;
    applied: boolean;
};

export type AiSettingsPageProps = {
    settings: AiSettingsForm;
    features: AiFeatureOption[];
    providers: AiProviderOption[];
    today: { count: number; limit: number; tokens_month: number };
    months: AiMonth[];
    recent: AiUsageRow[];
    urls: { update: string; key: string };
};
