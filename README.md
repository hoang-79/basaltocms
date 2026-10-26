# Basalto

**Aus einem Guss.** Basalto ist ein Content-Management-System auf Laravel mit einer eigenen Theme-Sprache: **Lava**.
Basalto ist ein Produkt von [Nguyen Development](https://nguyen-development.ch), Winterthur — Website, Doku,
Download und Community unter [basaltocms.com](https://basaltocms.com).

> **Dieses Repository** enthält den aktuellen Stand des Produkts und wird bei jeder Änderung nachgeführt. Es ist aus
> der Entwicklungsumgebung von Nguyen Development erzeugt; Pull Requests werden dort übernommen, nicht hier
> zusammengeführt (siehe [CONTRIBUTING.md](CONTRIBUTING.md)). Für eine Website ohne Terminal gibt es den
> [Web-Installer](https://basaltocms.com/download).

## Inhalt

| Ordner           | Was                                                                                                   |
| ---------------- | ----------------------------------------------------------------------------------------------------- |
| `packages/core`  | Kern: Sites, Seitenbaum, Block-Editor, Medien, Workflow, Formulare, Suche, Kundenkonten, Updates, API |
| `packages/lava`  | Lava: Grammatik, Prüfer, Compiler und Renderer der Theme-Sprache                                      |
| `modules/`       | Module `forum` (Community), `members` (Mitglieder), `comments` (Kommentare), `ai` (KI-Assistenz)      |
| `themes/starter` | Starter-Theme, vollständig kommentiert von A bis Z (MIT)                                              |
| `docs/`          | Produkt-Doku in Deutsch, Englisch, Französisch und Italienisch — dieselbe wie im Backend unter «?»    |
| Wurzelordner     | Anwendungsgerüst (Laravel), Konfiguration, Bauwerkzeuge                                               |

## Voraussetzungen

| Was       | Version                                                                 |
| --------- | ----------------------------------------------------------------------- |
| PHP       | 8.4 oder neuer, mit `pdo` für die gewählte Datenbank, `gd` und `sodium` |
| Composer  | 2                                                                       |
| Node.js   | 24, nur zum Bauen des Frontends                                         |
| Datenbank | SQLite, MariaDB, MySQL oder PostgreSQL                                  |

Redis und ein dauerhafter Queue-Worker sind nicht nötig: Cache, Sitzungen und Warteschlange liegen ohne weitere
Einstellung in der Datenbank; auf einfachem Hosting genügt ein Cron-Eintrag (siehe `docs/betrieb/hosting.md`).

## Installation

```sh
git clone https://github.com/hoang-79/basaltocms.git
cd basaltocms
composer setup
php artisan basalto:install
```

`composer setup` installiert die Abhängigkeiten, legt `.env` aus `.env.example` an, erzeugt den Anwendungsschlüssel,
migriert die Datenbank (Vorgabe SQLite), baut Frontend und Backend und veröffentlicht die Backend-Assets.
`basalto:install` fragt nach Handle und Name der Site und nach der E-Mail des ersten Kontos und gibt einen
**Einmal-Link** zur ersten Anmeldung aus — ohne Passwort und ohne Mail-Server. Einen neuen Link gibt
`php artisan basalto:login-link <e-mail>`.

Mit dem Starter-Theme ausprobieren — in `.env`:

```dotenv
BASALTO_DEFAULT_THEME=starter
```

```sh
php artisan basalto:publish <site>   # Startinhalte einspielen, Site prüfen und live schalten
php artisan serve                    # http://localhost:8000, Backend unter /admin
```

`composer run dev` startet Server, Warteschlange, Protokoll und Vite zusammen; `php artisan list basalto` zeigt alle
Befehle.

## Themes entwickeln

- **Ausgangspunkt:** `themes/starter` kopieren. Ein Theme besteht aus `theme.lava`, einem Ordner `assets/` und
  optional `theme.json`.
- **Prüfen:** `php artisan basalto:check --theme=themes/<name>` zeigt Fehler und Warnungen mit Zeile und Vorschlag
  sowie die rote Linie A–Z.
- **Paketieren:** `php artisan basalto:theme-pack <name>`, prüfen mit `php artisan basalto:theme-verify <zip>`.
- **Editor:** Sprachdienst für Lava mit `php artisan basalto:lsp` (Language Server Protocol).
- **Doku:** [Lava](docs/entwickler/lava.md), [Themes](docs/entwickler/themes.md),
  [Erweiterungen](docs/entwickler/erweiterungen.md), [API](docs/entwickler/api.md).

## Qualität

```sh
vendor/bin/pint --test        # Code-Stil PHP
vendor/bin/phpstan analyse    # statische Analyse (Larastan)
npm run check                 # Linter und Format
npm run types:check           # Typen (vue-tsc)
```

Die Testsuite gehört noch zur Entwicklungsumgebung und folgt in diesem Repository.

## Lizenz

Basalto ist **source-available**, nicht Open Source: Der Code ist öffentlich, die Nutzung regelt die
[Basalto-Lizenz](LICENSE.md). Kurz: kostenlos für eine Site mit allen Modulen sowie für Entwicklung, Tests und
Privates; für den kommerziellen Einsatz mit mehreren Benutzern oder Sites gibt es Site- und Agentur-Lizenzen
([Preise](https://basaltocms.com/preise)). Der Lizenzschlüssel wird offline geprüft; ohne Schlüssel zeigt Basalto
Hinweise, sperrt aber nie eine Funktion. Das Anwendungsgerüst im Wurzelordner und das Starter-Theme stehen unter MIT;
Einzelheiten im Abschnitt «Geltungsbereich» der [Lizenz](LICENSE.md).

## Kontakt

- Fragen und Ideen: im [Forum](https://basaltocms.com/community).
- Fehler: als Issue in diesem Repository.
- Sicherheitslücken: vertraulich nach [SECURITY.md](SECURITY.md), nie als öffentliches Issue.
- Alles andere: [email@basaltocms.com](mailto:email@basaltocms.com).

## In English

Basalto is a content management system built on Laravel with its own theme language, Lava. This repository holds the
current state of the product and is updated with every change. It is **source-available**: free for one site (all
modules included), for development and private use; commercial use with several users or sites requires a Site or
Agency licence ([LICENSE.en.md](LICENSE.en.md)). Install with `composer setup` and `php artisan basalto:install`
(see above). Documentation in English: [`docs/en/`](docs/en/README.md). Questions are welcome in English in the
[forum](https://basaltocms.com/en/community).
