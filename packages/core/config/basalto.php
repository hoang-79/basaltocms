<?php

declare(strict_types=1);

use Basalto\Core\Core;

return [
    /*
     * Das Backend liegt unter diesem Pfad (z. B. https://site.ch/admin). Änderbar je Installation.
     */
    'backend' => [
        'path' => env('BASALTO_BACKEND_PATH', 'admin'),
    ],

    /*
     * Site für Hosts ohne Eintrag in site_domains (lokale Entwicklung, Einzel-Installationen).
     */
    'default_site' => env('BASALTO_DEFAULT_SITE'),

    /*
     * Theme-Verzeichnis unter themes/, wenn eine Site keines nennt und nichts in lava_sources liegt.
     */
    'default_theme' => env('BASALTO_DEFAULT_THEME', 'nguyen-development'),

    /*
     * Verzeichnis der Datei-Themes (Standard: themes/ im Projekt).
     */
    'themes_path' => env('BASALTO_THEMES_PATH'),

    /*
     * Medien liegen auf der Standard-Disk (auf Laravel Cloud der private Bucket); Varianten erzeugt die Warteschlange.
     */
    // Seiten-Cache (M-16): fertiges HTML je Seite; in der lokalen Entwicklung aus, damit der Vite-Watcher jede Änderung zeigt
    'cache' => [
        'pages' => (bool) env('BASALTO_PAGE_CACHE', env('APP_ENV') !== 'local'),
        // leer = Standard-Store (Cloud: Valkey; sonst Datenbank)
        'store' => env('BASALTO_PAGE_CACHE_STORE'),
    ],

    // CR-008: Live-Aktualisierung — Takt der Abfrage im Browser in Sekunden (Push kommt ohne Takt, wo eingerichtet)
    // TB 50: Spamschutz — Aufgabe im Browser (Proof of Work) und Abgleich mit Stop Forum Spam (aus, bis eingeschaltet)
    'spam' => [
        'pow' => (bool) env('BASALTO_SPAM_POW', true),
        'pow_max' => (int) env('BASALTO_SPAM_POW_MAX', 50000),
        'stopforumspam_url' => env('BASALTO_STOPFORUMSPAM_URL', 'https://api.stopforumspam.org/api'),
    ],
    'live' => [
        'interval' => (int) env('BASALTO_LIVE_INTERVAL', 15),
        // Abfragen je Minute und Adresse (600 = 150 offene Tabs hinter einer Adresse)
        'rate' => (int) env('BASALTO_LIVE_RATE', 600),
        // Push über das Pusher-Protokoll (Reverb in Laravel Cloud, eigener Reverb, Soketi) — eingerichtet, sobald App,
        // Schlüssel, Geheimnis und Host gesetzt sind; `BASALTO_LIVE_PUSH=false` schaltet ab. `host`/`port`/`scheme`
        // erreicht der Browser, `server_…` (leer = gleich) der Server, etwa intern ohne TLS.
        'push' => [
            'enabled' => env('BASALTO_LIVE_PUSH'),
            'app_id' => env('REVERB_APP_ID'),
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'host' => env('REVERB_HOST'),
            'port' => (int) env('REVERB_PORT', 443),
            'scheme' => env('REVERB_SCHEME', 'https'),
            'server_host' => env('BASALTO_LIVE_PUSH_SERVER_HOST'),
            'server_port' => (int) env('BASALTO_LIVE_PUSH_SERVER_PORT', 0),
            'server_scheme' => env('BASALTO_LIVE_PUSH_SERVER_SCHEME'),
        ],
    ],

    'media' => [
        'max_kilobytes' => 10240,
        // Kontingent je Site in MB (leer = unbegrenzt); das Backend zeigt ein Band ab 90 % und sperrt den Upload bei 100 %
        'quota_megabytes' => env('BASALTO_MEDIA_QUOTA_MB') !== null ? (int) env('BASALTO_MEDIA_QUOTA_MB') : null,
        // Papierkorb: nach so vielen Tagen löscht «basalto:media-purge» endgültig
        'trash_days' => 30,
    ],

    /*
     * Passwortlose Anmeldung (K-04): Mail-Code zehn Minuten, Einmal-Links eine Stunde; Passwort nur als Option für Installationen ohne Mail.
     */
    'auth' => [
        'code_minutes' => 10,
        'link_minutes' => 60,
        'password_login' => (bool) env('BASALTO_PASSWORD_LOGIN', false),
    ],

    /*
     * SaaS-Domains (G-11): Alias je Site unter der Alias-Zone; reservierte Namen gehören der Produkt-Site.
     */
    'saas' => [
        'alias_domain' => env('BASALTO_ALIAS_DOMAIN', 'basaltocms.com'),
        'reserved_aliases' => ['testing', 'demo', 'status', 'www', 'admin', 'api', 'mail', 'app', 'basalto', 'extranet', 'docs', 'start', 'pro'],
        // TB 12 Scheibe 1 (M-21): SaaS-Modus der Installation — Registrierung, Konto-Bereich, Testphase; aus = Download-Installation
        'enabled' => (bool) env('BASALTO_SAAS', false),
        // Rolle dieser Instanz (ADR-008): «start» trägt Testphase und Start, «pro» Standard und Pro
        'instance' => env('BASALTO_INSTANCE', 'start'),
        'trial_days' => 14,
        // Erinnerung so viele Tage vor dem Ende der Testphase; Fussleiste im Frontend so viele Tage nach dem Ende ohne Plan
        'trial_reminder_days' => 3,
        'trial_banner_days' => 14,
        // Themes zur Wahl bei der Registrierung (Komma-Liste); leer = alle installierten ohne Fehler
        'themes' => array_values(array_filter(array_map('trim', explode(',', (string) env('BASALTO_SAAS_THEMES', ''))))),
        'default_theme' => env('BASALTO_SAAS_DEFAULT_THEME', 'starter'),
        // Kontingente je Plan (Mockup 23; Benutzer und Testphase = Annahme): Hinweis bei Überschreitung, nie Sperre der Inhalte
        'plans' => [
            'trial' => ['instance' => 'start', 'price' => 0, 'sites' => 1, 'domains' => 0, 'storage_mb' => 2048, 'users' => 3],
            'start' => ['instance' => 'start', 'price' => 29, 'sites' => 1, 'domains' => 0, 'storage_mb' => 2048, 'users' => 3],
            'standard' => ['instance' => 'pro', 'price' => 59, 'sites' => 1, 'domains' => 1, 'storage_mb' => 5120, 'users' => 10],
            'pro' => ['instance' => 'pro', 'price' => 99, 'sites' => 3, 'domains' => 5, 'storage_mb' => 20480, 'users' => 30],
        ],
        'extra_site_price' => 15,
        'extra_domain_price' => 5,
        // TB 12 Scheibe 2 (M-21): Abo und Rechnungen — Rechnungssteller und Konto für den QR-Zahlteil, MwSt. (0 = nicht
        // pflichtig, bis CHF 100'000 Umsatz), Zahlungsfrist (danach genau eine Erinnerung, keine Sperre), Präfix der
        // Rechnungsnummern (leer = S auf «start», P auf «pro»), Preis-IDs der Stripe-Abos (monatlich, CHF) und Webhook-Pfad
        'billing' => [
            'creditor' => ['name' => env('BASALTO_SAAS_CREDITOR', 'Nguyen Development'), 'street' => env('BASALTO_SAAS_CREDITOR_STREET', ''), 'building' => env('BASALTO_SAAS_CREDITOR_BUILDING', ''),
                'postal_code' => env('BASALTO_SAAS_CREDITOR_POSTAL_CODE', ''), 'city' => env('BASALTO_SAAS_CREDITOR_CITY', ''), 'country' => 'CH',
                'iban' => env('BASALTO_SAAS_IBAN', ''), 'besr_id' => env('BASALTO_SAAS_BESR_ID', '')],
            'vat' => ['rate' => (float) env('BASALTO_SAAS_VAT_RATE', 0), 'number' => env('BASALTO_SAAS_VAT_NUMBER', '')],
            'payment_days' => 14,
            'invoice_prefix' => env('BASALTO_SAAS_INVOICE_PREFIX'),
            'stripe_prices' => ['start' => env('BASALTO_SAAS_STRIPE_PRICE_START'), 'standard' => env('BASALTO_SAAS_STRIPE_PRICE_STANDARD'), 'pro' => env('BASALTO_SAAS_STRIPE_PRICE_PRO'),
                'extra_site' => env('BASALTO_SAAS_STRIPE_PRICE_EXTRA_SITE'), 'extra_domain' => env('BASALTO_SAAS_STRIPE_PRICE_EXTRA_DOMAIN')],
            'webhook_path' => 'stripe/saas-webhook',
        ],
        // TB 12 Scheibe 4 (M-21, ADR-008): Austritt — Sperre nach der Laufzeit (dann Löschung), Exporte abrufbar, privater
        // Speicher (leer = Vorgabe-Disk); Umzug zwischen Instanzen mit gemeinsamem Geheimnis (mind. 32 Zeichen) und der
        // Adresse jeder Instanz; Wildcard der Alias-Domain zeigt auf `alias_instance`
        'lock_days' => 30,
        'export_days' => 7,
        'export_disk' => env('BASALTO_EXPORT_DISK'),
        'instance_secret' => env('BASALTO_INSTANCE_SECRET'),
        'instances' => ['start' => env('BASALTO_INSTANCE_START_URL'), 'pro' => env('BASALTO_INSTANCE_PRO_URL')],
        'alias_instance' => env('BASALTO_ALIAS_INSTANCE', 'start'),
        // TB 18 (P-08): SaaS lokal — Ordner, in dem jede Registrierung einen Verweis `<alias>` auf diese Installation
        // anlegt (Herd parkt ihn als `<alias>.test`); nur bei APP_ENV=local, leer = aus. Dazu BASALTO_ALIAS_DOMAIN=test
        'local_path' => env('BASALTO_SAAS_LOCAL_PATH'),
        'move_attach_delay' => 120,
    ],

    /*
     * Lizenz (K-18, Option 2): öffentlicher Ed25519-Schlüssel des Produkts (im Paket), Lizenzschlüssel der Installation (.env);
     * der private Schlüssel existiert nur auf basaltocms.com.
     */
    // Zeitpunkt der letzten Auslieferung (Cloud setzt die Variable im Deploy-Schritt); leer = unbekannt
    'deployed_at' => env('BASALTO_DEPLOYED_AT'),

    // TB 10 Scheibe 6 (N-09): Ordner der Redaktions-Hilfe (Markdown) für /admin/hilfe; leer = docs/redaktion im Projekt
    'help_path' => env('BASALTO_HELP_PATH'),
    // TB 11 Scheibe 3 (G-07): Ordner der Rechtstexte (<sprache>/<text>.md); leer = docs/recht im Projekt
    'legal_path' => env('BASALTO_LEGAL_PATH'),

    // TB 11 Scheibe 4 (G-10): öffentliche Demo — Schnellzugang, Mails abgefangen, nächtlich basalto:demo-reset; nie sonst
    'demo' => ['enabled' => (bool) env('BASALTO_DEMO', false), 'site' => env('BASALTO_DEMO_SITE', 'demo'), 'theme' => env('BASALTO_DEMO_THEME', 'starter'), 'mailer' => env('BASALTO_DEMO_MAILER', 'log'),
        // Testdaten nach dem Neuaufsetzen: klein, mittel, gross oder aus
        'testdata' => env('BASALTO_DEMO_TESTDATA', 'klein')],

    // Installer im Browser (K-05): mit Wert braucht /admin/installieren den Parameter ?token=… — sonst offen, bis eingerichtet
    'install_token' => env('BASALTO_INSTALL_TOKEN'),

    /*
     * Betrieb (K-17, B-05): Auf einfachem Hosting arbeitet der Scheduler (Cron jede Minute) die Datenbank-Warteschlange
     * ab — kein Worker nötig. Wo ein Worker läuft (Laravel Cloud, managed queues), ausschalten.
     */
    'operations' => [
        'queue_via_scheduler' => (bool) env('BASALTO_QUEUE_VIA_SCHEDULER', true),
        // TB 64: die ausgelieferte Fassung (Commit, Zeitpunkt), von tools/ausliefern.sh geschrieben und in /up genannt
        'release_file' => env('BASALTO_RELEASE_FILE', base_path('RELEASE')),
    ],

    /*
     * Sicherung (K-17, B-10): Pfad zum Dump-Werkzeug, wenn es nicht im Suchpfad liegt oder nicht zur Version des
     * Servers passt (pg_dump bricht bei einem neueren Server ab).
     */
    'backup' => [
        'binaries' => [
            'mysql' => env('BASALTO_BACKUP_MYSQL_DUMP'),
            'mariadb' => env('BASALTO_BACKUP_MYSQL_DUMP'),
            'pgsql' => env('BASALTO_BACKUP_PG_DUMP'),
        ],
    ],

    'license' => [
        // Vorgabe ist der Produktschlüssel im Kern; die Variable nur für Tests und eigene Ausstellungen
        // Leer gesetzt (wie in .env.example) gilt ebenfalls der Produktschlüssel — sonst liesse sich kein Schlüssel prüfen
        'public_key' => env('BASALTO_LICENSE_PUBLIC_KEY') ?: Core::LICENSE_PUBLIC_KEY,
        'key' => env('BASALTO_LICENSE_KEY'),
        'secret_key' => env('BASALTO_LICENSE_SECRET_KEY'),
        'banner_days' => (int) env('BASALTO_LICENSE_BANNER_DAYS', 30),
    ],

    // CR-004 (P-01): der Hersteller — Adresse und Kontakt an einer Stelle (Lizenzkauf, Marktplatz, Hinweise); lokal https://basalto.test
    // TB 64: Testumgebung — jede Antwort mit X-Robots-Tag noindex, robots.txt sperrt alles
    'noindex' => (bool) env('BASALTO_NOINDEX', false),

    'vendor' => ['url' => env('BASALTO_VENDOR_URL', 'https://basaltocms.com'), 'email' => env('BASALTO_VENDOR_EMAIL', 'email@basaltocms.com')],

    /*
     * TB 19 (CR-004, P-09): Hersteller-Extranet — eigener Host für das Team des Herstellers. An, sobald die Module des
     * Herstellers (Lizenzverkauf) installiert sind oder BASALTO_EXTRANET=true; Host leer = lokal `extranet-<ordner>.test`,
     * sonst `extranet.<Host des Herstellers>`. `site` ist die Site, deren Lizenzverkauf und Marktplatz das Extranet führt.
     */
    'extranet' => [
        'enabled' => env('BASALTO_EXTRANET'),
        'host' => env('BASALTO_EXTRANET_HOST'),
        'site' => env('BASALTO_EXTRANET_SITE', 'basaltocms'),
        // Support-Zugriff in eine Kunden-Site: Dauer in Minuten (Vorgabe) und höchstens
        'support_minutes' => 60,
        'support_max_minutes' => 240,
    ],

    // CR-004 (P-05): Release-Archive für den Web-Installer — gebaut mit basalto:release, ausgeliefert von der Hersteller-Site.
    // Schlüssel wie beim Marktplatz: privat nur beim Hersteller (Base64 oder Pfad), öffentlich im Kern.
    'release' => [
        'path' => env('BASALTO_RELEASE_PATH') ?: storage_path('app/releases'),
        'secret_key' => env('BASALTO_RELEASE_SECRET_KEY') ?: env('BASALTO_MARKETPLACE_SECRET_KEY'),
        'public_key' => env('BASALTO_RELEASE_PUBLIC_KEY') ?: (env('BASALTO_MARKETPLACE_PUBLIC_KEY') ?: Core::MARKETPLACE_PUBLIC_KEY),
    ],

    // CR-007: Updates im Backend — Quelle der Releases und des Marktplatzes (sonst die Adresse des Herstellers):
    // lokal https://basalto.test, im Test https://testing.basaltocms.com, Demo und Produktion https://basaltocms.com
    'updates' => [
        'url' => env('BASALTO_UPDATES_URL'),
        // täglicher Check mit Benachrichtigung an die Verwaltung
        'check_daily' => (bool) env('BASALTO_UPDATES_CHECK', true),
        // CR-011 TB 62: PHP für die Schritte nach dem Tausch — leer: das PHP dieses Prozesses. Unter LiteSpeed (cyon)
        // ist das im Browser lsphp; dann die CLI angeben, etwa /opt/alt/php85/usr/bin/php
        'php' => env('BASALTO_PHP_BINARY'),
    ],

    // TB 11 Scheibe 2 (M-20): Marktplatz des Herstellers — Katalog unter Verwaltung › Module, Pakete mit dem Marktplatz-Schlüssel geprüft
    'marketplace' => ['url' => env('BASALTO_MARKETPLACE_URL') ?: env('BASALTO_VENDOR_URL', 'https://basaltocms.com'), 'public_key' => env('BASALTO_MARKETPLACE_PUBLIC_KEY') ?: Core::MARKETPLACE_PUBLIC_KEY, 'cache_minutes' => 10,
        // TB 13 Scheibe 1: Zugang zu gekauften Themes (E-Mail und Token des Kundenkontos auf basaltocms.com) — sonst aus auth.json (http-basic)
        'email' => env('BASALTO_MARKETPLACE_EMAIL'), 'token' => env('BASALTO_MARKETPLACE_TOKEN')],

    /*
     * Laravel-Cloud-CLI aus der Anwendung (Kundendomains nur automatisch, D-06). Token nie ins Repo.
     */
    'cloud' => [
        'on_cloud' => (bool) env('LARAVEL_CLOUD', false),
        'binary' => env('BASALTO_CLOUD_BINARY', 'vendor/bin/cloud'),
        'environment' => env('BASALTO_CLOUD_ENVIRONMENT'),
        'token' => env('LARAVEL_CLOUD_TOKEN'),
        // TB 12 Scheibe 3: Kundendomains je Cloud-Organisation (Growth 50, Business 250) — Zähler mit Warnung ab 80 % unter «Betrieb»
        'domain_limit' => (int) env('BASALTO_CLOUD_DOMAIN_LIMIT', 50),
    ],
];
