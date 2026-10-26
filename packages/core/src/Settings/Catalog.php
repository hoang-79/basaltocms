<?php

declare(strict_types=1);

namespace Basalto\Core\Settings;

/**
 * Katalog der Einstellungen (K-07): jede Frist, jeder Takt, jeder Schalter mit Gruppe, Typ, Grenzen und dem Startwert
 * aus dem Code. Gespeichert wird nur, was jemand ändert (mit Verlauf in `setting_revisions`); gelesen über `Settings::get()`.
 *
 * @phpstan-type Item array{key: string, group: string, label: string, help: string|null, type: 'bool'|'int'|'link', default: bool|int|null, unit: string|null, min: int|null, max: int|null, scope: 'site'|'installation', route: string|null}
 */
final class Catalog
{
    /** @var array<string, string> Gruppe → Beschriftung, in dieser Reihenfolge (Deutsch; übersetzt über groups()) */
    public const GROUPS = ['workflow' => 'Freigabe', 'media' => 'Medien', 'cache' => 'Cache', 'auth' => 'Anmeldung', 'spam' => 'Spamschutz', 'statistics' => 'Statistik', 'retention' => 'Aufbewahrung', 'languages' => 'Sprachen', 'operations' => 'Betrieb'];

    /** @return array<string, string> Gruppe → Beschriftung in der Sprache der Oberfläche (N-01) */
    public static function groups(): array
    {
        return ['workflow' => __('Freigabe'), 'media' => __('Medien'), 'cache' => __('Cache'), 'auth' => __('Anmeldung'), 'spam' => __('Spamschutz'), 'statistics' => __('Statistik'), 'retention' => __('Aufbewahrung'), 'languages' => __('Sprachen'), 'operations' => __('Betrieb')];
    }

    /** @return list<Item> */
    public static function items(): array
    {
        return [
            self::item('four_eyes', 'workflow', __('Vier-Augen-Prinzip'), __('Einreichende dürfen ihre eigene Fassung nicht freigeben.'), 'bool', false, scope: 'site'),
            self::item('workflow.versions_keep', 'workflow', __('Archivierte Fassungen behalten'), __('Je Inhalt und Sprache; ältere archivierte Fassungen löscht der nächtliche Löschlauf. 0 = alle behalten.'), 'int', 50, __('Fassungen'), 0, 1000, 'site'),
            self::item('media.alt_required', 'media', __('Alt-Text Pflicht'), __('Veröffentlichung ohne Alt-Text je Sprache wird abgewiesen.'), 'bool', false, scope: 'site'),
            self::item('media.trash_days', 'media', __('Papierkorb der Mediathek'), __('Danach löscht der Löschlauf endgültig — Original und Varianten.'), 'int', (int) config('basalto.media.trash_days', 30), __('Tage'), 1, 365),
            self::item('cache.pages', 'cache', __('Seiten-Cache'), __('Ausgelieferte Seiten kommen fertig aus dem Cache; jede Veröffentlichung macht ihn sofort ungültig und wärmt die Seite vor. Einzelne Seiten lassen sich im Reiter «Cache» ausnehmen.'), 'bool', true, scope: 'site'),
            self::item('cache.page_hours', 'cache', __('Seiten im Cache höchstens'), __('Danach rendert die nächste Anfrage neu — auch ohne Änderung.'), 'int', 24, __('Stunden'), 1, 168, 'site'),
            self::item('auth.code_minutes', 'auth', __('Gültigkeit des Anmeldecodes'), null, 'int', (int) config('basalto.auth.code_minutes', 10), __('Minuten'), 2, 60),
            // TB 72: «Code erneut senden» erst nach dieser Wartezeit (Backend, Extranet, Kundenkonten)
            self::item('auth.code_resend_seconds', 'auth', __('Neuer Code frühestens nach'), __('So lange wartet «Code erneut senden» nach dem letzten Code — schützt das Postfach und das Mailkontingent.'), 'int', (int) config('basalto.auth.code_resend_seconds', 30), __('Sekunden'), 0, 600),
            self::item('auth.link_minutes', 'auth', __('Gültigkeit von Einmal-Links'), __('Installer und Konsole (basalto:login-link).'), 'int', (int) config('basalto.auth.link_minutes', 60), __('Minuten'), 5, 1440),
            self::item('auth.invitation_hours', 'auth', __('Gültigkeit einer Einladung'), null, 'int', 72, __('Stunden'), 1, 720),
            // TB 50 (CR-009): Spamschutz — Aufgabe im Browser und Stop Forum Spam
            self::item('spam.pow_forms', 'spam', __('Prüfung im Browser für Formulare und Kommentare'), __('Der Browser löst beim Ausfüllen eine kleine Rechenaufgabe (Proof of Work) — kein Rätsel, keine Daten an Dritte. Ohne Skript stellt das Formular eine Rechenfrage.'), 'bool', (bool) config('basalto.spam.pow', true), scope: 'site'),
            self::item('spam.pow_accounts', 'spam', __('Prüfung im Browser bei der Anmeldung'), __('Gilt für das Anmelden und Registrieren mit Code im Frontend (Kundenkonten, Community).'), 'bool', (bool) config('basalto.spam.pow', true), scope: 'site'),
            self::item('spam.sfs', 'spam', __('Stop Forum Spam abfragen'), __('Neue Kundenkonten und Kommentare werden mit der öffentlichen Liste bekannter Spam-Absender abgeglichen. Dazu gehen die IP-Adresse und ein Hash der E-Mail-Adresse an stopforumspam.com; fällt der Dienst aus, geht der Eintrag durch.'), 'bool', false, scope: 'site'),
            self::item('spam.sfs_confidence', 'spam', __('Stop Forum Spam: Treffer ab'), __('Zuversicht der Liste, ab der ein Eintrag als Spam gilt.'), 'int', 80, __('Prozent'), 1, 100, 'site'),
            // TB 54: Statistik im Frontend (wie Fristo) und die Cookie-Einstellungen dazu
            self::item('statistics.enabled', 'statistics', __('Besuche zählen'), __('Seitenaufrufe der Website zählen (Verwaltung › Statistik) und die Cookie-Einstellungen zeigen. Besucher und Sitzungen nur mit Einwilligung; ohne Einwilligung zählt ein Aufruf anonym — ohne Cookie, ohne IP-Adresse.'), 'bool', true, scope: 'site'),
            self::item('statistics.retention_days', 'retention', __('Statistik aufbewahren'), __('Ältere Seitenaufrufe löscht der nächtliche Löschlauf endgültig.'), 'int', 400, __('Tage'), 30, 1095),
            self::item('forms.retention_days', 'retention', __('Formulareingänge aufbewahren'), __('Gilt für Formulare ohne eigene Frist («retain»); danach löscht der Löschlauf Eingang und Dateien endgültig.'), 'int', 90, __('Tage'), 1, 3650, 'site'),
            self::item('comments.retention_days', 'retention', __('Gelöschte Kommentare und Spam aufbewahren'), __('Modul Kommentare: gelöschte und als Spam markierte Kommentare löscht der nächtliche Löschlauf danach endgültig; freigegebene und wartende bleiben.'), 'int', 365, __('Tage'), 1, 3650, 'site'),
            self::item('seo.not_found_days', 'retention', __('404-Protokoll aufbewahren'), __('Pfade ohne Treffer in dieser Zeit löscht der nächtliche Löschlauf.'), 'int', 90, __('Tage'), 7, 730),
            self::item('activity.retention_days', 'retention', __('Protokoll aufbewahren'), __('Ältere Einträge löscht der nächtliche Löschlauf; der Löschlauf schreibt sich selbst ins Protokoll.'), 'int', 365, __('Tage'), 30, 3650),
            self::item('backup.keep', 'retention', __('Sicherungen behalten'), __('basalto:backup löscht nach jedem Lauf die älteren Sicherungen im selben Ordner; Cloud-Backups der Datenbank und die Versionierung des Buckets gelten daneben.'), 'int', 7, __('Sicherungen'), 1, 365),
            self::item('operations.heartbeat_minutes', 'operations', __('Herzschlag-Schwelle'), __('Ist der letzte Herzschlag des Schedulers älter, meldet /up «gestört» (Readiness-Probe).'), 'int', 5, __('Minuten'), 2, 60),
            // TB 10 Scheibe 6 (B-09): Alarm bei Fehlerhäufung an die Betreiber der Installation (basalto:error-alarm)
            self::item('operations.error_alarm_threshold', 'operations', __('Fehleralarm ab'), __('So viele gemeldete Ausnahmen im Fenster lösen eine Mail an Besitzer und Verwaltung des Installationskontos aus. 0 = kein Alarm.'), 'int', 20, __('Ausnahmen'), 0, 100000),
            self::item('operations.error_alarm_minutes', 'operations', __('Fenster des Fehleralarms'), __('Gezählt werden die Ausnahmen dieser letzten Minuten; der Scheduler prüft jede Minute.'), 'int', 5, __('Minuten'), 1, 60),
            self::item('operations.error_alarm_pause_minutes', 'operations', __('Pause nach einem Fehleralarm'), __('Solange kommt keine weitere Mail, auch wenn die Fehler anhalten.'), 'int', 60, __('Minuten'), 5, 1440),
            // TB 55: Nutzungsangaben bei der Update-Prüfung — der Hersteller zählt aktive Installationen
            self::item('operations.usage_report', 'operations', __('Nutzungsangaben an den Hersteller'), __('Mit der Prüfung auf Updates gehen eine zufällige Kennung dieser Installation, die Version von Basalto, die PHP-Version und die Art der Datenbank an den Hersteller — keine Adressen, keine Inhalte, keine Personendaten. Damit zählt er aktive Installationen und Versionen.'), 'bool', true),
            self::item('languages.required', 'languages', __('Pflichtsprachen vor der Freigabe'), __('Pflichtsprachen und Fallback stehen bei den Sprachen der Site.'), 'link', null, scope: 'site', route: 'basalto.sites.edit'),
        ];
    }

    /** @return Item|null */
    public static function find(string $key): ?array
    {
        foreach (self::items() as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  'bool'|'int'|'link'  $type
     * @param  'site'|'installation'  $scope
     * @return Item
     */
    private static function item(string $key, string $group, string $label, ?string $help, string $type, bool|int|null $default, ?string $unit = null, ?int $min = null, ?int $max = null, string $scope = 'installation', ?string $route = null): array
    {
        return ['key' => $key, 'group' => $group, 'label' => $label, 'help' => $help, 'type' => $type, 'default' => $default, 'unit' => $unit, 'min' => $min, 'max' => $max, 'scope' => $scope, 'route' => $route];
    }
}
