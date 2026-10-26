/* ── Formulare: Liste, Baukasten, Eingänge (Bereiche 16 und 29, TB 07) ── */

/** Texte je Sprache: «*» ist die Grundsprache der Site, sonst der Sprachcode («en», «fr»). */
export type FormTexts = Record<string, string>;

/**
 * Ein Feld im Baukasten — so, wie `FormBuilder::inputFor` es liefert und `lavaFor` es liest. Gruppen tragen eine
 * Ebene Unterfelder; die Tiefe steht auch im Typ (ein rekursiver Typ sprengt `useForm`).
 */
type FormFieldCore = {
    name: string;
    type: string;
    required: boolean;
    labels: FormTexts;
    placeholder: FormTexts;
    help: FormTexts;
    text: FormTexts;
    /** Optionen je Sprache, eine Liste je Sprache (Auswahl, Radio) */
    options: Record<string, string[]>;
    min: string | number | null;
    max: string | number | null;
    step: string | number | null;
    default: string | null;
    /** Dateiendungen mit Komma («pdf, docx») */
    types: string | null;
    /** Grösse in MB */
    size: string | number | null;
};

/** Ein Unterfeld in einer Gruppe — selbst ohne Unterfelder. */
export type FormSubField = FormFieldCore & { fields: never[] };

export type FormFieldInput = FormFieldCore & {
    /** Unterfelder einer Gruppe (eine Ebene) */
    fields: FormSubField[];
};

/** Die Eingabe des Baukastens als Ganzes. */
export type FormInput = {
    handle: string;
    labels: FormTexts;
    fields: FormFieldInput[];
    to: string | null;
    subject: string | null;
    thanks: FormTexts;
    submit: FormTexts;
    consent: FormTexts;
    store: boolean;
    redirect: string | null;
    webhook: string | null;
    retain: string | number | null;
};

/**
 * Die Daten im Formular-Objekt des Baukastens: wie `FormInput`, nur heisst die Knopfbeschriftung `submit_label` —
 * `submit` ist bei `useForm` die Methode zum Senden. Beim Senden wird sie wieder zu `submit`.
 */
export type FormBuilderData = Omit<FormInput, 'submit'> & {
    submit_label: FormTexts;
};

export type FormSource = 'theme' | 'site';

export type FormEmbedding = { label: string; url: string | null };

/** Was der Baukasten über ein bestehendes Formular weiss (null beim Anlegen). */
export type FormMeta = {
    source: FormSource;
    /** Lava-Zeile, z. B. «theme.lava:171» */
    location: string;
    /** Code-Ansicht — wie der Drucker das Formular schreibt */
    lava: string;
    submissions: number;
    spam: number;
    secret: string | null;
    embedded: FormEmbedding[];
};

export type FormLocale = { code: string; default: boolean };

export type FormFieldType = { value: string; label: string };

export type FormPageProps = {
    form: FormInput;
    meta: FormMeta | null;
    field_types: FormFieldType[];
    locales: FormLocale[];
    retention_default: number;
    urls: {
        save: string;
        index: string;
        delete: string | null;
        secret: string | null;
        inbox: string | null;
        /** TB 31: Live-Vorschau des Baukastens */
        preview?: string;
    };
};

/** Eine Zeile der Formularliste. */
export type FormRow = {
    handle: string;
    label: string;
    source: FormSource;
    location: string;
    fields: number;
    actions: string[];
    submissions: number;
    new: number;
    mail_failed: number;
    spam: number;
    last: string | null;
    embedded: string[];
    urls: { edit: string; inbox: string };
};

export type SubmissionStatus = 'new' | 'read' | 'answered';

/** Eine Zeile im Eingangskorb. */
export type SubmissionRow = {
    id: number;
    created_at: string | null;
    sender: string;
    email: string | null;
    excerpt: string;
    status: SubmissionStatus;
    mail_failed: boolean;
    mail_error: string | null;
    files: number;
    locale: string;
    url: string;
};

export type InboxPageProps = {
    form: {
        handle: string;
        label: string;
        source: FormSource;
        spam: number;
        retention: number;
    };
    rows: SubmissionRow[];
    pagination: {
        current: number;
        last: number;
        total: number;
        prev: string | null;
        next: string | null;
    };
    counts: { new: number; read: number; answered: number; all: number };
    filters: { status: '' | SubmissionStatus; q: string };
    urls: { export: string; bulk: string; edit: string; index: string };
};

/** Ein Wert eines Eingangs; Dateien als Download. */
export type SubmissionValue = {
    name: string;
    label: string;
    type: string;
    value: string;
    file: { name: string; size: number; url: string } | null;
};

export type SubmissionPageProps = {
    form: { handle: string; label: string };
    submission: {
        id: number;
        created_at: string | null;
        locale: string;
        page: string | null;
        consent_at: string | null;
        status: SubmissionStatus;
        mail_status: string | null;
        mail_error: string | null;
        webhook_status: string | null;
        webhook_error: string | null;
        values: SubmissionValue[];
        reply_to: string | null;
    };
    urls: {
        update: string;
        destroy: string;
        resend: string | null;
        inbox: string;
    };
};
