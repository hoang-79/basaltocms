/** TB 54: die Auswertung der Statistik (wie Fristo) — Form der Antwort von `Analytics\Aggregator`. */
export type Bucket = { label: string; value: string; count: number };
export type PageRow = Bucket & { visitors: number; avg_ms?: number };
export type FilterKey =
    | 'path'
    | 'referrer'
    | 'browser'
    | 'os'
    | 'device'
    | 'country'
    | 'source'
    | 'locale';

export type StatisticsPayload = {
    range: string;
    include_bots: boolean;
    filters: Partial<Record<FilterKey, string>>;
    period: { from: string; to: string; days: number };
    kpis: {
        pageviews: number;
        visitors: number;
        sessions: number;
        accounts: number;
        anonymous: number;
        pages_per_session: number;
    };
    engagement: {
        avg_session_seconds: number;
        avg_time_on_page_seconds: number;
        bounce_rate: number;
    };
    new_vs_returning: { new: number; returning: number };
    timeseries: {
        date: string;
        pageviews: number;
        visitors: number;
        sessions: number;
    }[];
    hourly: { hour: number; pageviews: number; visitors: number }[];
    channels: Bucket[];
    top_referrers: Bucket[];
    campaigns: {
        source: string;
        medium: string | null;
        campaign: string | null;
        views: number;
        visitors: number;
        sessions: number;
    }[];
    top_pages: PageRow[];
    landing_pages: PageRow[];
    exit_pages: PageRow[];
    browsers: Bucket[];
    operating_systems: Bucket[];
    devices: Bucket[];
    languages: Bucket[];
    countries: Bucket[];
    performance: {
        measured: number;
        avg_ms: number;
        p50: number;
        p90: number;
        p99: number;
        slowest: {
            label: string;
            avg_ms: number;
            max_ms: number;
            count: number;
        }[];
    };
    bot_traffic: {
        total: number;
        humans: number;
        bots: number;
        bot_share: number;
        top_bots: Bucket[];
    };
    recent: {
        path: string;
        referrer_host: string | null;
        browser: string | null;
        os: string | null;
        device_type: string | null;
        country: string | null;
        created_at: string;
    }[];
};

/** Sprache der Oberfläche als Format der Schweiz (2’488 statt 2.488). */
export function uiLocale(lang: string = document.documentElement.lang): string {
    const base = (lang || 'de').slice(0, 2);

    return base === 'en' ? 'en-GB' : `${base}-CH`;
}

/** Zahl und Dauer im Format der Oberfläche. */
export function formatNumber(value: number, locale = 'de-CH'): string {
    return new Intl.NumberFormat(locale).format(value);
}

export function formatDuration(seconds: number): string {
    if (seconds < 60) {
        return `${seconds} s`;
    }
    const minutes = Math.floor(seconds / 60);
    const rest = seconds % 60;

    return rest > 0 ? `${minutes} min ${rest} s` : `${minutes} min`;
}

export function formatMs(ms: number, locale = 'de-CH'): string {
    return ms < 1000
        ? `${formatNumber(ms, locale)} ms`
        : `${(ms / 1000).toFixed(2)} s`;
}

/** Anteil an der grössten Zeile (für die Balken in Listen). */
export function share(count: number, rows: { count: number }[]): number {
    const max = Math.max(1, ...rows.map((row) => row.count));

    return Math.round((count / max) * 100);
}

/** Punkte der Zeitreihe in einem Feld 1000 × Höhe. */
export function chartPoints(
    values: number[],
    height: number,
): { x: number; y: number }[] {
    const max = Math.max(1, ...values);
    const n = values.length;

    return values.map((value, i) => ({
        x: n > 1 ? (i / (n - 1)) * 1000 : 500,
        y: height - (value / max) * (height - 12),
    }));
}

/** TB 55: Betrag in Rappen (bzw. Cent) im Format der Oberfläche, ohne Rappen ab 1000. */
export function formatMoney(
    cents: number,
    currency = 'CHF',
    locale = 'de-CH',
): string {
    const whole = Math.abs(cents) >= 100_000;

    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency,
        minimumFractionDigits: whole ? 0 : 2,
        maximumFractionDigits: whole ? 0 : 2,
    }).format(cents / 100);
}

/**
 * TB 55: Veränderung zum Zeitraum davor in ganzen Prozent — `null` ohne Vergleich, `'neu'` wenn vorher nichts war.
 */
export function change(
    value: number,
    previous: number | null,
): number | 'neu' | null {
    if (previous === null) {
        return null;
    }
    if (previous === 0) {
        return value > 0 ? 'neu' : null;
    }

    return Math.round(((value - previous) / previous) * 100);
}

/** TB 55: Kennzahl, Verlauf und Rangliste eines Abschnitts der Statistik im Extranet. */
export type MetricFormat = 'number' | 'money' | 'percent';

export type MetricKpi = {
    label: string;
    value: number;
    previous: number | null;
    format: MetricFormat;
    currency: string | null;
    hint: string | null;
    headline: boolean;
};

export type MetricSeries = {
    label: string;
    points: { date: string; value: number }[];
    summary: 'total' | 'max';
};

export type MetricRows = {
    title: string;
    rows: {
        label: string;
        value: number;
        hint: string | null;
        currency: string | null;
    }[];
    format: MetricFormat;
    currency: string | null;
    empty: string | null;
};

export type MetricSectionData = {
    key: string;
    label: string;
    lead: string | null;
    kpis: MetricKpi[];
    series: MetricSeries[];
    lists: MetricRows[];
    link: { label: string; href: string } | null;
};

/** Wert einer Kennzahl oder Zeile im passenden Format. */
export function formatMetric(
    value: number,
    format: MetricFormat,
    currency: string | null,
    locale = 'de-CH',
): string {
    if (format === 'money') {
        return formatMoney(value, currency ?? 'CHF', locale);
    }

    return format === 'percent'
        ? `${formatNumber(value, locale)} %`
        : formatNumber(value, locale);
}

/** TB 55: Pfeil mit Prozent und Farbe für die Veränderung einer Kennzahl — `null` ohne Vergleich. */
export function trend(
    kpi: Pick<MetricKpi, 'value' | 'previous'>,
    newLabel: string,
): { text: string; tone: string } | null {
    const delta = change(kpi.value, kpi.previous);
    if (delta === null) {
        return null;
    }
    if (delta === 'neu') {
        return { text: newLabel, tone: 'text-success' };
    }
    if (delta === 0) {
        return { text: '± 0 %', tone: 'text-basalt-light' };
    }

    return delta > 0
        ? { text: `▲ +${delta} %`, tone: 'text-success' }
        : { text: `▼ ${delta} %`, tone: 'text-danger' };
}
