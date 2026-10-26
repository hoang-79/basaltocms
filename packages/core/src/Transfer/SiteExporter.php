<?php

declare(strict_types=1);

namespace Basalto\Core\Transfer;

use Basalto\Core\Content\ThemeSchema;
use Basalto\Core\Core;
use Basalto\Core\Models\Site;
use Basalto\Core\Theme\ThemeException;
use Basalto\Core\Theme\ThemeLoader;
use Basalto\Lava\Theme\LavaSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Vollexport einer Site (M-18): ein ZIP mit `site.json`, den Dateien der Mediathek (Original und Varianten) und —
 * nur auf Wunsch — den Formulareingängen samt Dateien (Personendaten, N-05). Interne Kennungen bleiben draussen:
 * Inhalte tragen ihre UUID, Medien, Ordner und Menüeinträge einen Schlüssel im Export, Benutzer ihre E-Mail; Verweise
 * in JSON-Spalten werden zu Marken (`References`). Dasselbe Format liest `SiteImporter`.
 *
 * Varianten gehen mit: Der Import ergibt sofort dieselbe Site — ohne Warteschlange, ohne Bildbibliothek, mit
 * denselben Bytes. Das Archiv wird dafür grösser (Varianten sind WebP/AVIF und klein gegenüber dem Original).
 *
 * @phpstan-type Result array{path: string, size: int, files: int, counts: array<string, int>, warnings: list<string>}
 */
final class SiteExporter
{
    public const FORMAT = 'basalto-site';

    public const FORMAT_VERSION = 1;

    public const DATA_FILE = 'site.json';

    /**
     * Tabellen mit `site_id`, die der Export trägt (abhängige Tabellen ohne `site_id`: `entry_versions` an den Inhalten,
     * `media_usages` an Medien und Inhalten). `invitations`: offene Einladungen als Mitglied «ausstehend». `theme_drafts` und
     * `theme_versions` (Theme-Editor, TB 10) nur, wenn die Tabellen schon angelegt sind.
     *
     * @var list<string>
     */
    public const EXPORTED = ['site_locales', 'site_domains', 'site_user', 'invitations', 'settings', 'site_themes', 'lava_sources', 'theme_drafts', 'theme_versions', 'media_folders', 'media', 'entries',
        'redirects', 'navigation_items', 'forms', 'form_submissions', 'snippets'];

    /** @var array<string, string> Tabellen mit `site_id`, die bewusst nicht mitgehen — mit Grund */
    public const EXCLUDED = [
        'activity_log' => 'Protokoll der Quellinstallation: Kennungen von Benutzern und IP-Prüfsummen, die in der Zielinstallation nichts bedeuten (K-15, N-05); der Import schreibt einen eigenen Eintrag',
        'not_found_hits' => '404-Protokoll der Quellinstallation: Zugriffsdaten mit Referrern, die Personendaten tragen können; nach dem Umzug zählen neue Treffer',
        'search_documents' => 'abgeleitet — der Import baut den Suchindex neu auf',
        'support_sessions' => 'Support-Zugriffe des Herstellers (TB 19, P-16) — gehören zur Installation des Herstellers, nicht zur Site; im Protokoll der Site steht jeder Zugriff',
        'personal_access_tokens' => 'API-Tokens sind Geheimnisse und hängen an Benutzerkonten der Quellinstallation — in der Zielinstallation neu ausstellen',
        // Modul Kommentare (M-27, TB 10): Modul-Tabellen gehen erst mit einer Export-Schnittstelle für Module mit (offen)
        'comments' => 'Tabelle des Moduls Kommentare — Export und Import über eine Schnittstelle für Module (offen); Kommentare tragen Personendaten wie Formulareingänge',
        'comment_spam_counts' => 'Spam-Zähler des Moduls Kommentare je Tag — Betriebszahl der Quellinstallation, zählt nach dem Umzug neu',
        // TB 11 Scheibe 1: Modul Lizenzverkauf — Geschäftsdaten und Personendaten, keine Inhalte der Site
        // TB 13 Scheibe 2: `customers` ist seit den Mitgliedern das Frontend-Konto des Kerns (Lizenzverkauf, Marktplatz, Mitglieder)
        'customers' => 'Frontend-Konten (Kunden des Lizenzverkaufs, Autoren des Marktplatzes, Mitglieder) — Personendaten mit Stripe-Kennung; sie gehören zu den Menschen und zum Geschäft, nicht zur Site-Vorlage',
        'customer_login_codes' => 'Anmeldecodes des Frontend-Kontos — kurzlebige Geheimnisse, nach einem Tag gelöscht',
        // TB 53: Anmeldung über Drittanbieter
        'customer_login_providers' => 'Anbieter für die Anmeldung im Frontend-Konto — App mit verschlüsseltem Client-Secret (APP_KEY der Quellinstallation) und Redirect-URLs auf deren Hosts; in der Zielinstallation neu einrichten',
        'customer_identities' => 'Verknüpfungen von Kundenkonten mit Konten bei Google, Microsoft, GitHub oder LinkedIn — Personendaten, Konten gehen nicht mit',
        // TB 54: Statistik
        'analytics_page_views' => 'Seitenaufrufe der Statistik — Messwerte der Quellinstallation, teils mit pseudonymen Kennungen; gehören nicht zur Site-Vorlage',
        'customer_passkeys' => 'Passkeys von Kundenkonten — an Konten und an die Domain der Quellinstallation gebunden, Konten gehen nicht mit',
        'contract_cancellations' => 'Kündigungen über «Verträge hier kündigen» (CR-011 TB 59) — Nachweise der Quellinstallation mit Personendaten; Verträge gehen nicht mit',
        'community_terms_consents' => 'Zustimmungen zu den Bedingungen der Community (CR-011 TB 60) — hängen an Kundenkonten, die nicht mitgehen',
        'privacy_requests' => 'Anliegen zum Datenschutz (CR-011 TB 61) — Personendaten der Quellinstallation; Kundenkonten gehen nicht mit',
        'members' => 'Mitgliedschaften des Moduls Mitglieder (M-23) — Personendaten wie die Frontend-Konten, an denen sie hängen (nicht exportiert); geschützte Seiten gehen mit, ihre Mitglieder nicht',
        'licenses' => 'Verkaufte Lizenzen des Moduls Lizenzverkauf — Schlüssel sind an Kunden und Zahlungen gebunden, nicht an die Site',
        'license_orders' => 'Bestellungen des Moduls Lizenzverkauf — Buchhaltung der Quellinstallation (Aufbewahrungspflicht), nicht übertragbar',
        'license_invoices' => 'Rechnungen des Moduls Lizenzverkauf — Nummern fortlaufend je Installation, Aufbewahrungspflicht, nicht übertragbar',
        // TB 11 Scheibe 2: Modul Marktplatz — Geschäfts- und Personendaten von basaltocms.com, keine Inhalte der Site
        'marketplace_authors' => 'Autorenprofile des Moduls Marktplatz — hängen an Kundenkonten (nicht exportiert), Personendaten',
        'marketplace_tokens' => 'Composer-Tokens des Moduls Marktplatz — Geheimnisse je Kundenkonto, in der Zielinstallation neu erzeugen',
        'marketplace_packages' => 'Einträge des Moduls Marktplatz — gehören Autoren (Kundenkonten) und verweisen auf Pakete im Speicher, nicht auf Inhalte der Site',
        'marketplace_versions' => 'Einreichungen des Moduls Marktplatz — Prüfberichte, Pakete und Signaturen der Quellinstallation, nicht übertragbar',
        'marketplace_ratings' => 'Bewertungen des Marktplatzes (TB 25) — hängen an Kundenkonten (nicht exportiert) und an Einträgen, die selbst nicht mitgehen',
        'notifications' => 'Benachrichtigungen (TB 27) — gehören Personen, nicht der Site; Benutzer gehen nicht mit',
        'marketplace_usages' => 'Belegte Nutzung von Marktplatz-Einträgen (TB 25) — Download-Spuren je Kundenkonto, Personendaten der Quellinstallation',
        'marketplace_sandboxes' => 'Wegwerf-Installationen des Marktplatzes für den Test einer Einreichung (TB 24) — leben höchstens einen Tag, lokal an Ordner gebunden',
        // TB 13 Scheibe 1: bezahlter Marktplatz
        'marketplace_purchases' => 'Käufe des Moduls Marktplatz — Buchhaltung mit Quittungsnummern und Stripe-Kennungen der Quellinstallation (Aufbewahrungspflicht), nicht übertragbar',
        'marketplace_payouts' => 'Auszahlungen von Stripe an Autoren (Modul Marktplatz) — Spiegel der verbundenen Stripe-Konten der Quellinstallation, nicht übertragbar',
        // TB 12 Scheibe 4: Exporte aus dem Konto-Bereich der SaaS
        'account_exports' => 'Exporte aus dem Konto-Bereich der SaaS — Archive der Quellinstallation mit Ablaufdatum (7 Tage), gehören zum Konto, nicht zur Site',
        // TB 13 Scheibe 4 (M-25): SSO je Site
        'sso_connections' => 'SSO-Einrichtung der Site — App-Registrierung mit verschlüsseltem Client-Secret (APP_KEY der Quellinstallation) und Redirect-URLs auf deren Hosts; in der Zielinstallation neu einrichten',
        // TB 13 Scheibe 3: Modul KI-Vorschläge (M-24) — Geheimnis und Protokoll, keine Inhalte der Site
        'ai_settings' => 'KI-Einstellung des Moduls KI-Vorschläge — trägt den Schlüssel des Kunden (Geheimnis, mit dem App-Schlüssel der Quellinstallation verschlüsselt); in der Zielinstallation neu eintragen',
        'ai_usages' => 'Nutzungsprotokoll des Moduls KI-Vorschläge — Kennungen von Benutzern der Quellinstallation, zählt nach dem Umzug neu',
        // TB 15 Scheibe 4 (CR-004 P-06): Installationen, die mit einem Kundenkonto verbunden sind
        'licensing_installations' => 'Verbundene Installationen des Moduls Lizenzverkauf — Token-Prüfsummen je Kundenkonto (Geheimnisse, Konten werden nicht exportiert); Installationen verbinden sich in der Zielinstallation neu',
        // TB 55: Zahlen des Herstellers — Downloads und Update-Prüfungen
        'licensing_downloads' => 'Downloads von Web-Installer und Archiv beim Hersteller — Messwerte der Quellinstallation, zählen nach dem Umzug neu',
        'licensing_pings' => 'Update-Prüfungen von Installationen beim Hersteller (Prüfsumme der Kennung, Version) — Messwerte der Quellinstallation, zählen nach dem Umzug neu',
        // CR-006 (TB 36): Community im Kern und Modul Forum — hängen an Kundenkonten (nicht exportiert)
        'community_profiles' => 'Community-Profile (Anzeigename, Profilbild, Signatur) — gehören zu Kundenkonten, die nicht mitgehen; Personendaten',
        'visitor_groups' => 'Besuchergruppen der Community — ihre Mitglieder sind Kundenkonten (nicht exportiert); Gruppen und Rechte gehen mit einer Export-Schnittstelle für Module mit (offen, CR-006 FO-30)',
        'visitor_permissions' => 'Besucherrechte je Gruppe und Forum — hängen an Besuchergruppen und Foren, die selbst nicht mitgehen',
        'forum_nodes' => 'Forenstruktur des Moduls Forum — geht mit Themen und Beiträgen über eine Export-Schnittstelle für Module mit (offen, CR-006 FO-30)',
        'forum_threads' => 'Themen des Moduls Forum — Beiträge von Kundenkonten (nicht exportiert), Personendaten wie Kommentare',
        'forum_posts' => 'Beiträge des Moduls Forum — Personendaten (Text, IP-Prüfsumme) von Kundenkonten, die nicht mitgehen',
        'community_attachments' => 'Anhänge von Besuchern (TB 37) — Dateien von Kundenkonten (nicht exportiert), gebunden an Beiträge, die nicht mitgehen',
        'forum_prefixes' => 'Präfixe des Moduls Forum — gehören zur Forenstruktur, die mit der Export-Schnittstelle für Module mitgeht (offen, CR-006 FO-30)',
        'forum_post_revisions' => 'Frühere Fassungen von Forenbeiträgen — Personendaten wie die Beiträge selbst',
        'community_subscriptions' => 'Abos und Ignorieren von Kundenkonten (TB 38) — Personendaten, Konten gehen nicht mit',
        'community_reactions' => 'Reaktionen von Kundenkonten (TB 38) — hängen an Beiträgen und Konten, die nicht mitgehen',
        // TB 39: Moderation
        'community_reports' => 'Meldungen zu Inhalten (TB 39, früher Bewertungen TB 26) — hängen an Beiträgen, Bewertungen und Konten, die nicht mitgehen',
        'community_moderation_log' => 'Moderationsprotokoll (TB 39) — nennt Benutzer und Kundenkonten der Quellinstallation, Personendaten',
        'community_warnings' => 'Verwarnungen von Kundenkonten (TB 39) — Personendaten, Konten gehen nicht mit',
        'community_notices' => 'Hinweise der Community (TB 39) — hängen am Forum mit seinen Gruppen, das nicht mitgeht',
        // TB 40: Profile und Konversationen
        'community_follows' => 'Folgen zwischen Kundenkonten (TB 40) — Personendaten, Konten gehen nicht mit',
        'community_blocks' => 'Blockieren zwischen Kundenkonten (TB 40) — Personendaten, Konten gehen nicht mit',
        'community_conversations' => 'Konversationen (TB 40) — private Nachrichten von Kundenkonten, nie Teil der Site-Vorlage',
        'community_conversation_messages' => 'Nachrichten in Konversationen (TB 40) — privat, Konten gehen nicht mit',
        'community_ranks' => 'Ränge der Community (TB 40) — hängen an Besuchergruppen und Beitragszahlen, die nicht mitgehen',
        'community_trophies' => 'Trophäen der Community (TB 40) — gehören zu den Mitgliedern der Quellinstallation',
        'community_group_rules' => 'Regeln der automatischen Gruppenzuordnung (TB 40) — hängen an Besuchergruppen, die nicht mitgehen',
        'community_wall_posts' => 'Pinnwand-Einträge in Profilen (TB 52) — Personendaten von Kundenkonten, die nicht mitgehen',
        'forum_tags' => 'Schlagwörter des Forums (TB 41) — hängen an Themen, die nicht mitgehen',
        'forum_polls' => 'Umfragen in Forenthemen (TB 38) — gehen mit den Themen (offen, CR-006 FO-30)',
    ];

    public function __construct(private readonly ThemeLoader $themes) {}

    /**
     * @return Result
     *
     * @throws TransferException
     */
    public function export(Site $site, string $path, bool $withSubmissions = false): array
    {
        $writer = new ArchiveWriter($path);
        try {
            [$payload, $warnings] = $this->payload($site, $writer, $withSubmissions);
            $writer->addString(self::DATA_FILE, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
            $result = $writer->close(['format' => self::FORMAT, 'format_version' => self::FORMAT_VERSION, 'generated_at' => $payload['generated_at'], 'basalto_version' => Core::VERSION, 'site' => $site->handle]);
        } catch (Throwable $e) {
            $writer->discard();

            throw $e;
        }
        $counts = [];
        foreach (['locales', 'domains', 'members', 'settings', 'site_themes', 'lava_sources', 'theme_drafts', 'theme_versions', 'media_folders', 'media', 'entries', 'media_usages', 'redirects', 'navigation', 'forms', 'submissions'] as $key) {
            $counts[$key] = is_array($payload[$key] ?? null) ? count($payload[$key]) : 0;
        }
        $counts['versions'] = array_sum(array_map(static fn (array $e): int => count($e['versions']), $payload['entries']));

        return [...$result, 'counts' => $counts, 'warnings' => $warnings];
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function payload(Site $site, ArchiveWriter $writer, bool $withSubmissions): array
    {
        $warnings = [];
        $siteId = $site->id;
        $users = [];
        foreach (DB::table('users')->get(['id', 'email']) as $user) {
            $users[(int) $user->id] = strtolower((string) $user->email);
        }
        $email = static fn (mixed $id): ?string => is_numeric($id) ? ($users[(int) $id] ?? null) : null;

        $mediaRows = DB::table('media')->where('site_id', $siteId)->orderBy('id')->get();
        $mediaKeys = [];
        foreach ($mediaRows as $i => $row) {
            $mediaKeys[(int) $row->id] = 'm'.($i + 1);
        }
        $entryRows = DB::table('entries')->where('site_id', $siteId)->orderBy('id')->get();
        $uuids = [];
        foreach ($entryRows as $row) {
            $uuids[(int) $row->id] = (string) $row->uuid;
        }
        $refs = $this->references($site, $mediaKeys, $uuids, $users);
        $prefix = 'sites/'.$siteId.'/';

        $payload = [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'basalto_version' => Core::VERSION,
            'with_submissions' => $withSubmissions,
            'site' => [
                'handle' => $site->handle, 'name' => $site->name, 'default_locale' => $site->default_locale, 'timezone' => $site->timezone, 'theme' => $site->theme,
                'settings' => $refs->siteSettings(self::json(DB::table('sites')->where('id', $siteId)->value('settings'))),
                'live_at' => self::time(DB::table('sites')->where('id', $siteId)->value('live_at')),
            ],
        ];

        $payload['locales'] = DB::table('site_locales')->where('site_id', $siteId)->orderBy('sort_order')->orderBy('id')->get()
            ->map(static fn (object $r): array => ['locale' => (string) $r->locale, 'is_default' => (bool) $r->is_default, 'is_required' => (bool) $r->is_required,
                'fallback_locale' => $r->fallback_locale, 'sort_order' => (int) $r->sort_order,
                'created_at' => self::time($r->created_at), 'updated_at' => self::time($r->updated_at)])->values()->all();
        // Ohne Cloud-Felder: Stand und Prüfung einer Domain gehören zur Quellinstallation, die Zielinstallation prüft neu
        $payload['domains'] = DB::table('site_domains')->where('site_id', $siteId)->orderBy('id')->get()
            ->map(static fn (object $r): array => ['host' => (string) $r->host, 'kind' => (string) $r->kind, 'is_primary' => (bool) $r->is_primary,
                'created_at' => self::time($r->created_at), 'updated_at' => self::time($r->updated_at)])->values()->all();
        $members = [];
        foreach (DB::table('site_user')->where('site_id', $siteId)->orderBy('id')->get(['user_id', 'role']) as $row) {
            $address = $email($row->user_id);
            if ($address !== null) {
                $members[] = ['email' => $address, 'role' => (string) $row->role, 'pending' => false];
            }
        }
        foreach (DB::table('invitations')->where('site_id', $siteId)->whereNull('accepted_at')->orderBy('id')->get(['email', 'role']) as $row) {
            $members[] = ['email' => strtolower((string) $row->email), 'role' => (string) $row->role, 'pending' => true];
        }
        $payload['members'] = $members;
        $payload['settings'] = DB::table('settings')->where('site_id', $siteId)->orderBy('key')->get()
            ->map(static fn (object $r): array => ['key' => (string) $r->key, 'value' => self::json($r->value), 'updated_by' => $email($r->updated_by),
                'created_at' => self::time($r->created_at), 'updated_at' => self::time($r->updated_at)])->values()->all();
        // TB 22: die Theme-Bibliothek — Kennungen werden zu Schlüsseln (t1, t2 …), Bilder und Schriften gehen als Dateien mit
        $themeKeys = [];
        $payload['site_themes'] = [];
        foreach (! Schema::hasTable('site_themes') ? [] : DB::table('site_themes')->where('site_id', $siteId)->orderBy('id')->get() as $i => $r) {
            $key = 't'.($i + 1);
            $themeKeys[(int) $r->id] = $key;
            $assets = self::json($r->assets);
            $assets = is_array($assets) ? $assets : [];
            foreach (array_keys($assets) as $file) {
                if (! $writer->addDiskFile('theme-assets/'.$key.'/'.$file, Storage::disk(), 'theme-assets/'.$siteId.'/'.$r->id.'/'.$file)) {
                    $warnings[] = sprintf('Datei «%s» des Themes «%s» fehlt im Speicher — sie geht nicht mit.', $file, $r->name);
                    unset($assets[$file]);
                }
            }
            $payload['site_themes'][] = ['key' => $key, 'name' => (string) $r->name, 'label' => $r->label, 'description' => $r->description, 'version' => $r->version,
                'origin' => (string) $r->origin, 'origin_name' => $r->origin_name, 'signed' => (bool) $r->signed, 'assets' => $assets === [] ? null : $assets,
                'created_by' => $email($r->created_by), 'created_at' => self::time($r->created_at), 'updated_at' => self::time($r->updated_at)];
        }
        $payload['lava_sources'] = DB::table('lava_sources')->where('site_id', $siteId)->orderBy('scope')->orderBy('path')->get()
            ->map(static function (object $r) use ($themeKeys): array {
                $theme = str_starts_with((string) $r->scope, LavaSource::THEME_PREFIX) ? ($themeKeys[(int) substr((string) $r->scope, strlen(LavaSource::THEME_PREFIX))] ?? null) : null;

                return ['scope' => $theme !== null ? LavaSource::SCOPE_THEME : (string) $r->scope, 'theme' => $theme, 'path' => (string) $r->path, 'source' => (string) $r->source,
                    'created_at' => self::time($r->created_at), 'updated_at' => self::time($r->updated_at)];
            })->values()->all();
        // Theme-Editor (TB 10): Entwürfe je Datei und Fassungen als Schnappschuss — nur, wenn die Tabellen schon da sind
        $payload['theme_drafts'] = ! Schema::hasTable('theme_drafts') ? [] : DB::table('theme_drafts')->where('site_id', $siteId)->orderBy('site_theme_id')->orderBy('path')->get()
            ->map(static fn (object $r): array => ['theme' => $themeKeys[(int) ($r->site_theme_id ?? 0)] ?? null, 'path' => (string) $r->path, 'source' => $r->source, 'base_hash' => $r->base_hash, 'updated_by' => $email($r->updated_by),
                'submitted_at' => self::time($r->submitted_at), 'submitted_by' => $email($r->submitted_by), 'created_at' => self::time($r->created_at), 'updated_at' => self::time($r->updated_at)])->values()->all();
        $payload['theme_versions'] = ! Schema::hasTable('theme_versions') ? [] : DB::table('theme_versions')->where('site_id', $siteId)->orderBy('site_theme_id')->orderBy('number')->get()
            ->map(static fn (object $r): array => ['theme' => $themeKeys[(int) ($r->site_theme_id ?? 0)] ?? null, 'number' => (int) $r->number, 'files' => (string) $r->files, 'note' => $r->note, 'submitted_by' => $email($r->submitted_by),
                'approved_by' => $email($r->approved_by), 'published_at' => self::time($r->published_at), 'created_at' => self::time($r->created_at), 'updated_at' => self::time($r->updated_at)])->values()->all();

        $folderKeys = [];
        $folderRows = DB::table('media_folders')->where('site_id', $siteId)->orderBy('id')->get();
        foreach ($folderRows as $i => $row) {
            $folderKeys[(int) $row->id] = 'f'.($i + 1);
        }
        $payload['media_folders'] = $folderRows->map(static fn (object $r): array => ['key' => $folderKeys[(int) $r->id], 'parent' => $folderKeys[(int) $r->parent_id] ?? null,
            'name' => (string) $r->name, 'position' => (int) $r->position, 'created_at' => self::time($r->created_at), 'updated_at' => self::time($r->updated_at)])->values()->all();

        $payload['media'] = [];
        foreach ($mediaRows as $row) {
            $disk = Storage::disk((string) $row->disk);
            $relative = self::relative((string) $row->path, $prefix);
            $hasFile = $relative !== null && $writer->addDiskFile('media/'.$relative, $disk, (string) $row->path);
            if (! $hasFile) {
                $warnings[] = sprintf('Datei von «%s» fehlt im Speicher (%s) — nur die Angaben gehen mit.', $row->name, $row->path);
            }
            $variants = self::json($row->variants);
            if (is_array($variants)) {
                $variants = self::mapVariantPaths($variants, function (string $file) use ($disk, $prefix, $writer, $row, &$warnings): ?string {
                    $relative = self::relative($file, $prefix);
                    if ($relative === null) {
                        return null;
                    }
                    if (! $writer->has('media/'.$relative) && ! $writer->addDiskFile('media/'.$relative, $disk, $file)) {
                        $warnings[] = sprintf('Variante %s von «%s» fehlt im Speicher.', $file, $row->name);
                    }

                    return $relative;
                });
            }
            $payload['media'][] = ['key' => $mediaKeys[(int) $row->id], 'folder' => $folderKeys[(int) $row->folder_id] ?? null, 'path' => $relative ?? 'extern/'.$mediaKeys[(int) $row->id], 'file' => $hasFile,
                'name' => (string) $row->name, 'title' => $row->title, 'credit' => $row->credit, 'alt' => self::json($row->alt), 'mime' => (string) $row->mime, 'size' => (int) $row->size,
                'width' => $row->width === null ? null : (int) $row->width, 'height' => $row->height === null ? null : (int) $row->height,
                'focus_x' => (float) $row->focus_x, 'focus_y' => (float) $row->focus_y, 'variants' => $variants, 'variants_generated_at' => self::time($row->variants_generated_at),
                'created_by' => $email($row->created_by), 'created_at' => self::time($row->created_at), 'updated_at' => self::time($row->updated_at), 'deleted_at' => self::time($row->deleted_at)];
        }

        $versions = DB::table('entry_versions')->whereIn('entry_id', array_keys($uuids))->orderBy('entry_id')->orderBy('locale')->orderBy('number')->get()->groupBy('entry_id');
        $versionRefs = [];
        foreach ($versions->flatten(1) as $v) {
            $versionRefs[(int) $v->id] = ['entry_id' => (int) $v->entry_id, 'locale' => (string) $v->locale, 'number' => (int) $v->number];
        }
        $payload['entries'] = [];
        foreach ($entryRows as $row) {
            $out = [];
            foreach ($versions->get((int) $row->id, collect()) as $v) {
                $source = $versionRefs[(int) $v->source_version_id] ?? null;
                $data = self::json($v->data);
                $out[] = ['locale' => (string) $v->locale, 'number' => (int) $v->number, 'status' => (string) $v->status, 'title' => (string) $v->title, 'slug' => (string) $v->slug,
                    'data' => is_array($data) ? $refs->versionData((string) $row->type, $data) : $data,
                    'source' => $source !== null && $source['entry_id'] === (int) $row->id ? ['locale' => $source['locale'], 'number' => $source['number']] : null,
                    'author' => $email($v->author_id), 'submitted_at' => self::time($v->submitted_at), 'submitted_by' => $email($v->submitted_by), 'approved_by' => $email($v->approved_by),
                    'comment' => $v->comment, 'reviewed_by' => $email($v->reviewed_by), 'scheduled_for' => self::time($v->scheduled_for), 'published_at' => self::time($v->published_at),
                    'created_at' => self::time($v->created_at), 'updated_at' => self::time($v->updated_at)];
            }
            $payload['entries'][] = ['uuid' => (string) $row->uuid, 'type' => (string) $row->type, 'parent' => $uuids[(int) $row->parent_id] ?? null, 'position' => (int) $row->position,
                'settings' => $refs->entrySettings(self::json($row->settings)), 'redirect_to' => $refs->redirectTo(self::json($row->redirect_to)), 'created_by' => $email($row->created_by),
                'created_at' => self::time($row->created_at), 'updated_at' => self::time($row->updated_at), 'deleted_at' => self::time($row->deleted_at), 'versions' => $out];
        }

        $payload['media_usages'] = DB::table('media_usages')->whereIn('media_id', array_keys($mediaKeys))->whereIn('entry_id', array_keys($uuids))->orderBy('id')->get()
            ->map(static fn (object $r): array => ['media' => $mediaKeys[(int) $r->media_id], 'entry' => $uuids[(int) $r->entry_id], 'locale' => (string) $r->locale, 'field' => (string) $r->field,
                'created_at' => self::time($r->created_at), 'updated_at' => self::time($r->updated_at)])->values()->all();
        $payload['redirects'] = DB::table('redirects')->where('site_id', $siteId)->orderBy('id')->get()
            ->map(static fn (object $r): array => ['locale' => (string) $r->locale, 'from_path' => (string) $r->from_path, 'to_path' => (string) $r->to_path, 'status' => (int) $r->status,
                'source' => (string) $r->source, 'hits' => (int) $r->hits, 'last_hit_at' => self::time($r->last_hit_at), 'created_at' => self::time($r->created_at), 'updated_at' => self::time($r->updated_at)])->values()->all();

        $navRows = DB::table('navigation_items')->where('site_id', $siteId)->orderBy('id')->get();
        $navKeys = [];
        foreach ($navRows as $i => $row) {
            $navKeys[(int) $row->id] = 'n'.($i + 1);
        }
        $payload['navigation'] = $navRows->map(static fn (object $r): array => ['key' => $navKeys[(int) $r->id], 'nav' => (string) $r->nav, 'parent' => $navKeys[(int) $r->parent_id] ?? null,
            'position' => (int) $r->position, 'entry' => $uuids[(int) $r->entry_id] ?? null, 'url' => $r->url, 'label' => self::json($r->label), 'visible' => (bool) $r->visible,
            'new_window' => (bool) $r->new_window, 'created_at' => self::time($r->created_at), 'updated_at' => self::time($r->updated_at)])->values()->all();

        // Bausteine (TB 10 Scheibe 3, M-28): Blöcke mit Verweisen auf Medien und Inhalte wie die Blöcke einer Seite
        $payload['snippets'] = DB::table('snippets')->where('site_id', $siteId)->orderBy('id')->get()
            ->map(static fn (object $r): array => ['handle' => (string) $r->handle, 'name' => (string) $r->name, 'blocks' => $refs->snippetBlocks((array) (self::json($r->blocks) ?? [])),
                'updated_by' => $email($r->updated_by), 'created_at' => self::time($r->created_at), 'updated_at' => self::time($r->updated_at)])->values()->all();

        // Das Webhook-Geheimnis geht nicht mit: im Klartext läge es offen im Archiv, verschlüsselt hängt es am APP_KEY der
        // Quelle. Der Import meldet die betroffenen Formulare; das Backend erzeugt ein neues (Formular → Webhook).
        $forms = DB::table('forms')->where('site_id', $siteId)->orderBy('id')->get();
        $payload['forms'] = $forms->map(static fn (object $r): array => ['handle' => (string) $r->handle, 'webhook_secret_set' => $r->webhook_secret !== null && $r->webhook_secret !== '',
            'spam_count' => (int) $r->spam_count, 'spam_last_at' => self::time($r->spam_last_at), 'last_submission_at' => self::time($r->last_submission_at),
            'created_at' => self::time($r->created_at), 'updated_at' => self::time($r->updated_at)])->values()->all();
        $payload['submissions'] = $withSubmissions ? $this->submissions($siteId, $forms->pluck('handle', 'id')->all(), $writer, $warnings) : [];

        return [$payload, $warnings];
    }

    /**
     * Formulareingänge (nur auf Wunsch): Werte, Dateien vom privaten Speicher, Zustimmung, Status. Die IP-Prüfsumme
     * bleibt zurück — sie ist mit dem App-Schlüssel der Quelle gebildet und in der Zielinstallation nicht vergleichbar.
     *
     * @param  array<int, string>  $forms
     * @param  list<string>  $warnings
     * @return list<array<string, mixed>>
     */
    private function submissions(int $siteId, array $forms, ArchiveWriter $writer, array &$warnings): array
    {
        $out = [];
        $disk = Storage::disk('local');
        $prefix = 'forms/'.$siteId.'/';
        foreach (DB::table('form_submissions')->where('site_id', $siteId)->orderBy('id')->get() as $row) {
            $files = [];
            foreach ((array) (self::json($row->files) ?? []) as $field => $file) {
                if (! is_array($file) || ! is_string($file['path'] ?? null)) {
                    continue;
                }
                $relative = self::relative($file['path'], $prefix);
                $name = $relative === null ? null : 'submissions/'.$relative;
                if ($name === null || ! $writer->addDiskFile($name, $disk, $file['path'])) {
                    $warnings[] = sprintf('Datei «%s» eines Eingangs fehlt im Speicher.', (string) ($file['name'] ?? $file['path']));
                    $name = null;
                }
                $files[(string) $field] = ['file' => $name, 'name' => (string) ($file['name'] ?? ''), 'size' => (int) ($file['size'] ?? 0), 'mime' => (string) ($file['mime'] ?? '')];
            }
            $out[] = ['form' => $forms[(int) $row->form_id] ?? null, 'locale' => (string) $row->locale, 'page_path' => $row->page_path, 'data' => self::json($row->data),
                'files' => $files === [] ? null : $files, 'consent_at' => self::time($row->consent_at), 'status' => (string) $row->status,
                'mail_status' => $row->mail_status, 'mail_error' => $row->mail_error, 'webhook_status' => $row->webhook_status, 'webhook_error' => $row->webhook_error,
                'created_at' => self::time($row->created_at), 'updated_at' => self::time($row->updated_at)];
        }

        return $out;
    }

    /**
     * Felder je Typ, Global und Block aus dem Theme der Site; bei einem kaputten Theme ohne Schema (Suche nach Bildwerten).
     *
     * @param  array<int, string>  $media
     * @param  array<int, string>  $entries
     * @param  array<int, string>  $users
     */
    private function references(Site $site, array $media, array $entries, array $users): References
    {
        try {
            $theme = $this->themes->forSite($site);
        } catch (ThemeException) {
            return new References(null, [], $media, $entries, $users, $site->id);
        }
        $schema = ThemeSchema::fromTheme($theme);
        $types = [];
        foreach ($schema->types as $name => $type) {
            $types[$name] = $type['fields'];
        }
        foreach ($theme->document->all('global') as $global) {
            $types[(string) $global->name] ??= ThemeSchema::fieldsOf($global);
        }
        $blocks = [];
        foreach ($schema->blocks as $name => $block) {
            $blocks[$name] = $block['fields'];
        }

        return new References($types, $blocks, $media, $entries, $users, $site->id);
    }

    /** Pfad relativ zum Ordner der Site — Fremdes landet unter `extern/`, Unsicheres (`..`) gar nicht. */
    private static function relative(string $path, string $prefix): ?string
    {
        $relative = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : 'extern/'.ltrim($path, '/');

        return ArchiveReader::isSafeName($relative) ? $relative : null;
    }

    /**
     * @param  array<mixed>  $variants
     * @param  callable(string): ?string  $map
     * @return array<mixed>
     */
    public static function mapVariantPaths(array $variants, callable $map): array
    {
        foreach ($variants as $name => $variant) {
            if (! is_array($variant)) {
                continue;
            }
            foreach (['path', 'avif'] as $key) {
                if (is_string($variant[$key] ?? null)) {
                    $variant[$key] = $map($variant[$key]);
                }
            }
            foreach (is_array($variant['densities'] ?? null) ? $variant['densities'] : [] as $density => $file) {
                foreach (['path', 'avif'] as $key) {
                    if (is_array($file) && is_string($file[$key] ?? null)) {
                        $variant['densities'][$density][$key] = $map($file[$key]);
                    }
                }
            }
            $variants[$name] = $variant;
        }

        return $variants;
    }

    private static function json(mixed $value): mixed
    {
        return is_string($value) ? json_decode($value, true) : $value;
    }

    /** Zeitpunkte einheitlich als «Y-m-d H:i:s» (UTC, wie gespeichert) — gleich auf SQLite, MariaDB und PostgreSQL. */
    public static function time(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->format('Y-m-d H:i:s');
    }
}
