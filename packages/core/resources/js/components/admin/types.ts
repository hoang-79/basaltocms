/**
 * Verwaltungsseiten aus Beschreibung (CR-018, TB 99): die Form, die `Basalto\Core\Backend\Schema\AdminPage` schickt.
 * Texte kommen schon übersetzt aus dem Plugin.
 */
export type AdminAction = {
    label: string;
    url: string;
    method: 'get' | 'post' | 'put' | 'delete';
    key: string | null;
    confirm: string | null;
    variant: 'primary' | 'secondary' | 'danger';
    data: Record<string, unknown>;
};

export type AdminCell = { text: string; tone?: string; url?: string };

export type AdminTableSection = {
    kind: 'table';
    key: string;
    title: string | null;
    columns: {
        key: string;
        label: string;
        type: 'text' | 'mono' | 'number' | 'money' | 'date' | 'badge' | 'link';
    }[];
    rows: {
        id: string | number;
        cells: Record<string, AdminCell>;
        url: string | null;
        actions: AdminAction[];
    }[];
    search: { param: string; value: string; placeholder: string } | null;
    filters: {
        param: string;
        label: string;
        value: string;
        options: { value: string; label: string; count: number | null }[];
    }[];
    pagination: {
        page: number;
        last: number;
        total: number;
        per_page: number;
    } | null;
    bulk: AdminAction[];
    empty: { title: string; text: string | null };
};

export type AdminField = {
    name: string;
    label: string;
    type:
        | 'text'
        | 'email'
        | 'url'
        | 'number'
        | 'password'
        | 'textarea'
        | 'select'
        | 'checkbox'
        | 'date'
        | 'time'
        | 'datetime'
        | 'color'
        | 'richtext'
        | 'file'
        | 'image';
    help?: string;
    placeholder?: string;
    required?: boolean;
    min?: number;
    max?: number;
    step?: number;
    rows?: number;
    mono?: boolean;
    on_leave?: boolean;
    half?: boolean;
    accept?: string;
    choices?: { value: string; label: string }[];
    images?: { id: number; name: string; url: string }[];
    none?: string;
};

export type AdminFormSection = {
    kind: 'form';
    title: string | null;
    url: string;
    method: 'post' | 'put';
    autosave: boolean;
    submit: string;
    fields: AdminField[];
    values: Record<string, unknown>;
};

export type AdminSection =
    | {
          kind: 'stats';
          items: {
              label: string;
              value: string;
              hint: string | null;
              tone: 'neutral' | 'success' | 'warning' | 'danger';
          }[];
      }
    | {
          kind: 'notice';
          tone: 'grey' | 'success' | 'warning' | 'danger';
          title: string | null;
          text: string;
      }
    | { kind: 'text'; title: string | null; text: string }
    | AdminTableSection
    | AdminFormSection;

export type AdminPageData = {
    title: string;
    crumb: string;
    lead: string;
    actions: AdminAction[];
    sections: AdminSection[];
};

/** Eine Aktion ausführen — der Aufrufer hat schon nachgefragt, falls nötig. */
export type RunAction = (
    action: AdminAction,
    data?: Record<string, unknown>,
    onSuccess?: () => void,
) => void;

/** Ton einer Zelle → Ton des Zustandsschilds des Kerns. */
export function tagTone(
    tone: string | undefined,
): 'grey' | 'lava' | 'success' | 'warning' | 'danger' {
    return tone === 'success' ||
        tone === 'warning' ||
        tone === 'danger' ||
        tone === 'lava'
        ? tone
        : 'grey';
}

/** Die Adresse mit geänderten Query-Parametern (leer entfernt) — für Suche, Filter und Seiten. */
export function withQuery(
    search: string,
    changes: Record<string, string | null>,
): Record<string, string> {
    const query = Object.fromEntries(new URLSearchParams(search).entries());
    for (const [key, value] of Object.entries(changes)) {
        if (value === null || value === '') {
            delete query[key];
        } else {
            query[key] = value;
        }
    }

    return query;
}
