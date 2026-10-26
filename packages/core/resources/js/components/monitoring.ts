/**
 * Kennzahlen aus Laravel Pulse und Fehleralarm auf der Seite «Betrieb» (B-09) — nur für Betreiber der Installation.
 */
export type MonitoringInfo = {
    installed: boolean;
    enabled: boolean;
    url: string | null;
    period: string;
    error: string | null;
    exceptions: {
        total: number;
        top: {
            class: string;
            location: string;
            count: number;
            last: string | null;
        }[];
    };
    slow_requests: {
        total: number;
        threshold_ms: number | null;
        top: { method: string; path: string; count: number; max_ms: number }[];
    };
    slow_queries: number;
    slow_jobs: number;
    jobs: { processed: number; failed: number; released: number };
};

export type AlarmInfo = {
    threshold: number;
    minutes: number;
    pause: number;
    current: number;
    last: string | null;
    last_count: number | null;
    recipients: number;
    settings_url: string;
};

/** Klassenname ohne Namensraum: `Illuminate\Database\QueryException` → `QueryException`. */
export function shortClass(name: string): string {
    const parts = name.split('\\');

    return parts[parts.length - 1] || name;
}

/** Zustand der Alarmkarte: aus, unter der Schwelle, erreicht. */
export function alarmState(alarm: AlarmInfo): 'off' | 'quiet' | 'reached' {
    if (alarm.threshold === 0) {
        return 'off';
    }

    return alarm.current >= alarm.threshold ? 'reached' : 'quiet';
}
