# Monitoring

Basalto zeigt Betriebszahlen dort, wo Sie ohnehin nachsehen: unter **Verwaltung › Betrieb** im Backend. Dazu kommen
das Dashboard von [Laravel Pulse](https://laravel.com/docs/pulse) und ein Alarm per Mail, wenn sich Fehler häufen —
beides ohne Fremddienst.

![Die Seite «Betrieb» im Backend: Herzschlag, Warteschlange, Datenbank, Speicher, Sicherungen und Aufgaben](../verwaltung/bilder/betrieb.webp)

| Was | Wo | Wer sieht es |
| --- | --- | --- |
| Herzschlag, Warteschlange, Datenbank, Cache, Speicher, Backups, Scheduler | Verwaltung › Betrieb, `/up`, `/up/live` | wer die Site verwalten darf |
| Kennzahlen aus Pulse (letzte Stunde, alle Sites) und Stand des Fehleralarms | Verwaltung › Betrieb, Abschnitt «Kennzahlen aus Pulse» | Betreiber der Installation |
| Pulse-Dashboard mit allen Karten | `/admin/pulse` | Betreiber der Installation |
| Fehleralarm | Mail | Betreiber der Installation |

**Betreiber der Installation** sind Besitzer und Verwaltung des Installationskontos — des ersten Kontos, das
`php artisan basalto:install` anlegt. Betreiber oder Administratoren einer einzelnen Site sehen die Pulse-Zahlen
nicht, denn sie umfassen alle Sites der Installation (auf der SaaS auch die anderer Kunden). Deaktivierte Konten
zählen nicht.

## Pulse

Pulse ist Teil der Anwendung (`laravel/pulse` 1.8) und zeichnet in der Anwendungsdatenbank auf: Tabellen
`pulse_values`, `pulse_entries` und `pulse_aggregates`, angelegt von `php artisan migrate`. Die Konfiguration liegt
in `config/pulse.php`.

- **Dashboard:** `/<BASALTO_BACKEND_PATH>/pulse`, also meist `/admin/pulse` (eigener Pfad mit `PULSE_PATH`). Gäste
  landen auf der Anmeldung, alle anderen ausser den Betreibern der Installation bekommen 403. Der Link steht auch
  auf der Seite «Betrieb».
- **Auf «Betrieb»:** gemeldete Ausnahmen (die häufigsten mit Ort), langsame Anfragen über der Schwelle
  (`PULSE_SLOW_REQUESTS_THRESHOLD`, Vorgabe 1000 ms), langsame Abfragen, verarbeitete, fehlgeschlagene und erneut
  eingereihte Jobs, langsame Jobs — jeweils über die letzte Stunde.
- **Abschalten:** `PULSE_ENABLED=false`. Dann zeichnet Pulse nichts mehr auf; «Betrieb» sagt das und zeigt die
  zuletzt erfassten Zahlen. In `phpunit.xml` ist Pulse aus.
- **Aufbewahrung:** Pulse löscht Einträge nach sieben Tagen selbst (`PULSE_STORAGE_KEEP`).
- **Viel Verkehr:** Stichproben je Aufzeichner (`PULSE_USER_REQUESTS_SAMPLE_RATE=0.1` usw.) oder die Redis-Aufnahme
  (`PULSE_INGEST_DRIVER=redis`, dazu dauerhaft `php artisan pulse:work`). Auf einfachem Hosting ist beides nicht
  nötig.
- **Cache der Karten:** Die Karten legen ihre Abfragen als Objekte in den Cache; Laravel 13 entpackt aus dem Cache
  keine Objekte (`cache.serializable_classes`). Basalto legt sie deshalb je Anfrage in den Array-Store
  (`PULSE_CACHE_DRIVER=array`, Vorgabe in `config/pulse.php`). Folge: `php artisan pulse:restart` erreicht laufende
  `pulse:check`/`pulse:work` nicht — diese Prozesse nach dem Deploy neu starten (Laravel Cloud tut das ohnehin).
- **Server-Karte:** Speicher und CPU erscheinen nur, wenn `php artisan pulse:check` dauerhaft läuft (auf Laravel
  Cloud als Hintergrundprozess). Auf einfachem Hosting bleibt die Karte leer — das ist kein Fehler.
- **Datenbanken:** Pulse unterstützt SQLite, MariaDB, MySQL und PostgreSQL. Auf SQL Server überspringt die Migration
  die Tabellen; «Betrieb» meldet dann «Pulse ist nicht eingerichtet», und `PULSE_ENABLED=false` gehört in die `.env`.
- **Datenschutz:** Pulse speichert Benutzer-IDs (Karte «Application Usage»), Pfade, Abfragen und Orte von
  Ausnahmen, keine Inhalte. Basalto zeigt Personen ohne Gravatar (kein Aufruf an einen Fremddienst). Das
  Dashboard von Pulse selbst lädt Fremdressourcen: die Schrift von `fonts.bunny.net` und in der Karte «Slow Outgoing
  Requests» Logos der Zielhosts von `unavatar.io`. Das betrifft nur Betreiber, nicht Besucher der Site.
- **Livewire:** Pulse bringt Livewire mit. Basalto schaltet dessen automatische Einbindung ab
  (`livewire.inject_assets`), damit `livewire.js` nie in Seiten der Site oder des Backends gerät.

## Fehleralarm

Basalto zählt jede gemeldete Ausnahme je Minute im Cache — unabhängig davon, ob Pulse aufzeichnet. Der Scheduler
ruft jede Minute `php artisan basalto:error-alarm` auf. Liegen im Fenster mindestens so viele Ausnahmen wie die
Schwelle, geht eine Mail an die Betreiber der Installation; danach ist für die Dauer der Pause Ruhe, auch wenn die
Fehler anhalten. Nicht gemeldete Ausnahmen (404, Validierung) zählen nicht.

| Einstellung (Verwaltung › Einstellungen › Betrieb) | Schlüssel | Vorgabe |
| --- | --- | --- |
| Fehleralarm ab | `operations.error_alarm_threshold` | 20 Ausnahmen (0 = kein Alarm) |
| Fenster des Fehleralarms | `operations.error_alarm_minutes` | 5 Minuten |
| Pause nach einem Fehleralarm | `operations.error_alarm_pause_minutes` | 60 Minuten |

Voraussetzungen: der Scheduler läuft (Cron jede Minute bzw. Laravel Cloud) und die Mail ist eingerichtet
(`MAIL_*` in der `.env`). Mit `MAIL_MAILER=log` steht die Mail nur im Protokoll. Die Karte «Fehleralarm» auf
«Betrieb» zeigt Schwelle, aktuelle Zahl, letzten Alarm und die Zahl der Empfänger.

Von Hand prüfen:

```sh
php artisan basalto:error-alarm     # meldet «unter der Schwelle», «gedrosselt» oder «Alarm an n Betreiber»
```

## Mehrere Instanzen beobachten

Wer viele Instanzen betreibt, kann zusätzlich [Laravel Nightwatch](https://nightwatch.laravel.com) anschliessen:
Anfragen, Abfragen, Jobs und Ausnahmen über alle Instanzen. Die Platzhalter stehen auskommentiert in `.env.example`
(`NIGHTWATCH_ENABLED`, `NIGHTWATCH_TOKEN` und die Stichproben); das Paket `laravel/nightwatch` installieren Sie selbst.
Nightwatch überträgt Betriebsdaten an Laravel — prüfen Sie Auftragsverarbeitung und Datenschutzerklärung, bevor Sie es
einschalten.

## Weiter

- [Betrieb](../verwaltung/betrieb.md)
- [Hosting](hosting.md)
- [Befehle](befehle.md)
