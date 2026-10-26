# Befehle

Alle Befehle von Basalto liegen im Namensraum `basalto`. Die aktuelle Liste zeigt `php artisan list basalto`, die
Optionen eines Befehls `php artisan <befehl> --help`. Die meisten Befehle stammen aus dem Kern (`packages/core`);
`basalto:check`, `basalto:lava-import` und `basalto:lsp` aus Lava (`packages/lava`), `basalto:tests-sync` aus dem Modul
Testmanagement, `basalto:licensing-revocations` aus dem Modul Lizenzverkauf.

![Der Zeitplan des Schedulers: was jede Minute, jede Stunde und jede Nacht läuft](bilder/scheduler.svg)

## Einrichten und Anmelden

| Befehl | Zweck | Wichtigste Argumente und Optionen |
| --- | --- | --- |
| `basalto:install` | Datenbank migrieren, erste Site und erstes Konto anlegen, Einmal-Link ausgeben; läuft mehrfach gefahrlos | `--site`, `--name`, `--locale` (Vorgabe `de`), `--timezone` (Vorgabe `Europe/Zurich`), `--user`, `--email`, `--minutes` (Vorgabe 60) |
| `basalto:login-link` | Einmal-Link zur Anmeldung für ein Konto | `<email>`, `--minutes` (Vorgabe 15) |

## Sites und Inhalte

| Befehl | Zweck | Wichtigste Argumente und Optionen |
| --- | --- | --- |
| `basalto:publish` | Site prüfen (Links, Bilder, Alt-Texte, Pflichtsprachen, Rechtsseiten, Formulare, Umleitungen), vorwärmen und live schalten | `<site>`, `--dry-run` (nur prüfen), `--ohne-seed` (Seed nicht einspielen) |
| `basalto:seed` | Startinhalte aus dem `seed` des Themes einspielen (idempotent); `--auffrischen` spielt Unverändertes neu ein | `<site>`, `--auffrischen` |
| `basalto:legal` | Impressum oder Datenschutzerklärung aus Vorlage als Entwurf anlegen | `<site>`, `<art>` (`impressum` oder `datenschutz`) |
| `basalto:publish-scheduled` | geplante Fassungen veröffentlichen, deren Zeitpunkt erreicht ist | — (läuft im Scheduler) |
| `basalto:page-cache` | Seiten-Cache vorwärmen oder leeren | `<aktion>` (`warm` oder `clear`), `--site` (sonst alle) |
| `basalto:search-index` | Suchindex neu aufbauen, etwa nach einem Import oder Treiberwechsel | `--site` (sonst alle) |
| `basalto:docs-import` | Markdown-Doku (`docs/**/*.md`, Übersetzungen aus `docs/<sprache>/`) als Seiten eines Typs anlegen oder auffrischen — für basaltocms.com; nur Geändertes bekommt eine neue Fassung, Rechtstexte bleiben draussen | `<site>`, `--path` (Vorgabe `docs`), `--type` (Vorgabe `doc`) |

## Themes und Lava

| Befehl | Zweck | Wichtigste Argumente und Optionen |
| --- | --- | --- |
| `basalto:check` | Lava-Theme prüfen und die rote Linie A–Z zeigen | `--theme` (Ordner, Vorgabe `themes/nguyen-development`), `--site` (Theme aus der Datenbank), `--json` |
| `basalto:lava-import` | Lava-Theme aus Dateien in die Tabelle `lava_sources` übernehmen | `<site>`, `--theme`, `--scope` (`theme` oder `site`) |
| `basalto:tokens` | Design-Tokens als CSS-Variablen ausgeben | `[<site>]` (sonst Standard-Site), `--tailwind` (als `@theme` für Tailwind 4) |
| `basalto:theme-pack` | Theme als Paket mit Manifest und optionaler Signatur schnüren | `<name>` (Ordner unter `themes/`), `--output` (sonst `storage/app/themes`), `--key` |
| `basalto:theme-verify` | Theme-Paket gegen Manifest und Signatur prüfen | `<path>`, `--public-key` |
| `basalto:lsp` | Sprachdienst für Lava (Language Server Protocol) für VS Code und PhpStorm | `--stdio` (Vorgabe) |

## Betrieb

| Befehl | Zweck | Wichtigste Argumente und Optionen |
| --- | --- | --- |
| `basalto:heartbeat` | Zeitstempel in den Cache schreiben — Nachweis, dass der Scheduler läuft (`/up`) | — (läuft im Scheduler) |
| `basalto:cleanup` | Protokoll und archivierte Fassungen nach den Aufbewahrungsfristen löschen | — (läuft im Scheduler) |
| `basalto:media-purge` | Medien endgültig löschen, die länger als die Frist im Papierkorb liegen | `--days` (Vorgabe 30) |
| `basalto:error-alarm` | Betreiber per Mail benachrichtigen, wenn sich gemeldete Ausnahmen häufen (gedrosselt) | — (läuft im Scheduler; Schwellen unter Verwaltung › Einstellungen › Betrieb) |

## Sicherung und Umzug

| Befehl | Zweck | Wichtigste Argumente und Optionen |
| --- | --- | --- |
| `basalto:backup` | Datenbank und Medien sichern, mit Manifest und Prüfsummen | `--path` (sonst `storage/app/backups`) |
| `basalto:backup-verify` | Sicherung prüfen (Prüfsummen, Rückspielprobe bei SQLite) | `[<archiv>]` (sonst die neueste), `--path` |
| `basalto:export` | Site vollständig exportieren (JSON und Medien) | `<site>`, `--path` (Datei oder Ordner, sonst `storage/app/exports`), `--with-submissions` |
| `basalto:import` | Site aus einem Export anlegen | `<zip>`, `--handle` (sonst der Handle aus dem Export) |

Einzelheiten unter [Backup](backup.md).

## Lizenz und Datenschutz

| Befehl | Zweck | Wichtigste Argumente und Optionen |
| --- | --- | --- |
| `basalto:license` | Lizenzstand anzeigen und festhalten, Schlüssel prüfen; für basaltocms.com auch ausstellen und sperren | `--check=<schlüssel>`; zum Ausstellen `--keypair`, `--issue`, `--revoke` u. a., siehe [Lizenz](lizenz.md) |
| `basalto:privacy` | Auskunft über eine Person geben oder ihre Daten löschen (DSG/DSGVO) | `<email>`, `--export=<datei>` (Auskunft als JSON), `--delete` (Konto und Eingänge löschen), `--force` (ohne Rückfrage) |

## Entwicklung und Tests

Nicht für den laufenden Betrieb gedacht.

| Befehl | Zweck | Wichtigste Argumente und Optionen |
| --- | --- | --- |
| `basalto:benchmark` | Lasttest: legt viele Sites, Inhalte und Medien an und misst die tragenden Abfragen — **schreibt Testdaten in die Datenbank** | `--sites` (100), `--entries` (1000), `--media` (3334), `--locales` (2), `--runs` (30), `--keep`, `--clean` (Bench-Daten entfernen), `--json` |
| `basalto:load-test` | HTTP-Lasttest gegen eigene Adressen: Anfragen je Sekunde, p50/p95/p99, Fehler, Cache-Treffer — fremde Hosts nur mit `--remote` ([Lasttest](lasttest.md)) | `<url>…`, `--concurrency` (10), `--duration` (30), `--requests`, `--max-p95`, `--max-errors` (1), `--remote`, `--json` |
| `basalto:tests-sync` | Testfälle des Testmanagements aus den Abnahmekriterien abgleichen | `--source` (`auto`, `docs` oder `bundled`), `--write` |
| `basalto:demo-reset` | **Löscht die ganze Datenbank** und setzt die öffentliche Demo neu auf: Demo-Site mit dem Seed ihres Themes, drei Konten für den Schnellzugang (Betreiber, Redaktion, Übersetzung); danach Testdaten im Umfang `BASALTO_DEMO_TESTDATA` (Vorgabe `klein`, `aus` schaltet ab); endet mit einer Prüfsumme des Bestands. Läuft nur mit `BASALTO_DEMO=true` und nur mit Bestätigung | `--force` (ohne Rückfrage, so im Scheduler) |
| `basalto:update` | Updates prüfen (Kern, Plugins, Themes gegen den Hersteller der Umgebung) oder Kern bzw. Plugin aus dem signierten Archiv einspielen — mit Sicherung, Wartungsmodus und Rückweg ([Updates](updates.md)) | `--kern`, `--plugin=<hersteller/paket>`, `--benachrichtigen` |
| `basalto:testdaten` | Viele, verschiedene Testdaten für Entwicklung, Test-Umgebungen und Demo: Team der Site und im Extranet, Kundenkonten (auch mit heiklen Namen), Community, Forum, Kommentare, Mitglieder, Marktplatz und Lizenzverkauf — je nach eingeschalteten Modulen. Adressen enden auf `@testdaten.example`, Mails gehen nirgends hin; gleicher Lauf, gleicher Bestand. In Produktion nur im Demo-Modus ([Lokal](../hersteller/lokal.md#testdaten)) | `<site>`, `--umfang` (`klein`, `mittel`, `gross`), `--nur=<bereich>`, `--neu` (vorher entfernen), `--entfernen` |
| `basalto:local` | Lokale Landschaft des Herstellers unter Laravel Herd: Site «basaltocms» auf `<ordner>.test` (primär), Inhalte einspielen, Adressen der Produktion in den Inhalten auf die lokalen umschreiben; verweigert in Produktion | `--herd-config`, `--no-publish` |
| `basalto:release` | Release-Archiv für den Web-Installer bauen: versionierte Dateien ohne Entwicklungsballast, `vendor` ohne Dev-Pakete, Manifest mit Ed25519-Signatur (Hersteller) | `--release-version`, `--key`, `--out`, `--source`, `--vendor-from`, `--store`, `--notes` (Text oder Datei: «Was ist neu?» unter Verwaltung › Updates) |

## Zeitplan des Schedulers

Basalto plant diese Aufgaben ein. Sie laufen, solange der Cron `php artisan schedule:run` jede Minute aufruft
([Hosting](hosting.md)); alle nur auf einem Server. Die Zeiten gelten in der Zeitzone der Anwendung, und die ist UTC (`config/app.php`): 03:15 ist in der Schweiz 04:15
(Winterzeit) bzw. 05:15 (Sommerzeit). Prüfen mit `php artisan schedule:list`.

| Aufgabe | Takt | Cron | Wozu |
| --- | --- | --- | --- |
| `basalto:heartbeat` | jede Minute | `* * * * *` | Lebenszeichen für `/up` und Verwaltung › Betrieb |
| `basalto:publish-scheduled` | jede Minute, ohne Überlappung | `* * * * *` | geplante Fassungen veröffentlichen |
| `basalto:error-alarm` | jede Minute | `* * * * *` | Fehlerhäufung prüfen, Mail gedrosselt |
| `queue:work --stop-when-empty --max-time=50 --tries=3` | jede Minute, ohne Überlappung | `* * * * *` | Warteschlange abarbeiten — nur mit `QUEUE_CONNECTION=database` und `BASALTO_QUEUE_VIA_SCHEDULER` nicht `false` |
| `basalto:license` | stündlich | `0 * * * *` | Lizenzstand nachführen (offline) |
| `basalto:cleanup` | täglich 03:00 | `0 3 * * *` | Löschlauf nach den Aufbewahrungsfristen |
| `basalto:backup` | täglich 03:15 | `15 3 * * *` | Sicherung, danach Aufbewahrung nach `backup.keep` |
| `basalto:demo-reset --force` | täglich 02:30, nur mit `BASALTO_DEMO=true` | `30 2 * * *` | Demo neu aufsetzen |
| `basalto:media-purge` | täglich 03:30 | `30 3 * * *` | Papierkorb der Mediathek leeren |
| `basalto:update --benachrichtigen` | täglich 04:10, nicht im SaaS-Modus, abschaltbar mit `BASALTO_UPDATES_CHECK=false` | `10 4 * * *` | prüfen, ob es Updates gibt; die Verwaltung erfährt jeden neuen Stand einmal |

Den letzten Lauf jeder Aufgabe mit Ergebnis zeigt Verwaltung › Betrieb.

## Weiter

- [Installation](installation.md)
- [Hosting](hosting.md)
