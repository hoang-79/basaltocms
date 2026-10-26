/** Konfiguration der Insel aus der Seite (`<script type="application/json" id="basalto-frontend-editor">`). */
export type VersionInfo = {
    id: number;
    number: number;
    status: string;
    status_label: string;
    stamp: string;
};

export type EditableField = {
    kind: 'text' | 'textarea' | 'richtext';
    label: string;
};

export type IslandConfig = {
    entry: number;
    locale: string;
    ui_locale?: string;
    editing: boolean;
    live: { number: number };
    version: VersionInfo;
    fields: Record<string, EditableField> | [];
    urls: { mode: string; save: string; backend: string };
};

/** Was die Leiste zeigt. */
export type IslandState = {
    locale: string;
    editing: boolean;
    live: { number: number };
    version: VersionInfo;
    fieldCount: number;
    message: string;
    tone: 'info' | 'saved' | 'error';
    busy: boolean;
    backendUrl: string;
};

/** Liest die Konfiguration — null, wenn sie fehlt oder kaputt ist (dann bleibt die Seite, wie sie ist). */
export function readConfig(doc: Document): IslandConfig | null {
    const el = doc.getElementById('basalto-frontend-editor');
    if (!el || el.getAttribute('type') !== 'application/json') {
        return null;
    }
    try {
        const config = JSON.parse(el.textContent ?? '') as IslandConfig;

        return typeof config.entry === 'number' && config.urls ? config : null;
    } catch {
        return null;
    }
}

/** Die bearbeitbaren Felder als Liste (PHP schickt eine leere Liste als `[]`). */
export function fieldMap(config: IslandConfig): Record<string, EditableField> {
    return Array.isArray(config.fields) ? {} : config.fields;
}
