# Commands

All Basalto commands live in the `basalto` namespace. `php artisan list basalto` shows the current list,
`php artisan <command> --help` the options of a command. Most commands come from the core (`packages/core`);
`basalto:check`, `basalto:lava-import` and `basalto:lsp` from Lava (`packages/lava`), `basalto:tests-sync` from the
test management module, `basalto:licensing-revocations` from the licence sales module.

![The scheduler timetable: what runs every minute, every hour and every night](bilder/scheduler.svg)

## Setting up and logging in

| Command | Purpose | Main arguments and options |
| --- | --- | --- |
| `basalto:install` | migrate the database, create the first site and first account, print a one-time link; can safely be run several times | `--site`, `--name`, `--locale` (default `de`), `--timezone` (default `Europe/Zurich`), `--user`, `--email`, `--minutes` (default 60) |
| `basalto:login-link` | one-time login link for an account | `<email>`, `--minutes` (default 15) |

## Sites and content

| Command | Purpose | Main arguments and options |
| --- | --- | --- |
| `basalto:publish` | check the site (links, images, alt texts, required languages, legal pages, forms, redirects), warm it up and take it live | `<site>`, `--dry-run` (check only), `--ohne-seed` (do not load the seed) |
| `basalto:seed` | load the starter content from the theme's `seed` (idempotent); `--auffrischen` reloads untouched entries | `<site>`, `--auffrischen` |
| `basalto:legal` | create the legal notice or privacy policy from a template as a draft | `<site>`, `<art>` (`impressum` or `datenschutz`) |
| `basalto:publish-scheduled` | publish scheduled versions whose time has come | — (runs in the scheduler) |
| `basalto:page-cache` | warm up or clear the page cache | `<aktion>` (`warm` or `clear`), `--site` (otherwise all) |
| `basalto:search-index` | rebuild the search index, for example after an import or a driver change | `--site` (otherwise all) |
| `basalto:docs-import` | create or refresh the Markdown docs (`docs/**/*.md`, translations from `docs/<language>/`) as pages of a type — for basaltocms.com; only changed files get a new version, legal texts stay out | `<site>`, `--path` (default `docs`), `--type` (default `doc`) |

## Themes and Lava

| Command | Purpose | Main arguments and options |
| --- | --- | --- |
| `basalto:check` | check a Lava theme and show the red line A–Z | `--theme` (folder, default `themes/nguyen-development`), `--site` (theme from the database), `--json` |
| `basalto:lava-import` | copy a Lava theme from files into the `lava_sources` table | `<site>`, `--theme`, `--scope` (`theme` or `site`) |
| `basalto:tokens` | output design tokens as CSS variables | `[<site>]` (otherwise the default site), `--tailwind` (as `@theme` for Tailwind 4) |
| `basalto:theme-pack` | bundle a theme as a package with manifest and optional signature | `<name>` (folder under `themes/`), `--output` (otherwise `storage/app/themes`), `--key` |
| `basalto:theme-verify` | check a theme package against manifest and signature | `<path>`, `--public-key` |
| `basalto:lsp` | language server for Lava (Language Server Protocol) for VS Code and PhpStorm | `--stdio` (default) |

## Operations

| Command | Purpose | Main arguments and options |
| --- | --- | --- |
| `basalto:heartbeat` | write a timestamp to the cache — proof that the scheduler is running (`/up`) | — (runs in the scheduler) |
| `basalto:cleanup` | delete log entries and archived versions according to the retention periods | — (runs in the scheduler) |
| `basalto:media-purge` | permanently delete media that have been in the trash longer than the period | `--days` (default 30) |
| `basalto:error-alarm` | notify operators by email when reported exceptions pile up (throttled) | — (runs in the scheduler; thresholds under Administration › Settings › Operations) |
| `basalto:db-zeitzone` | check the database time zone and convert points in time written before the UTC connection once (MariaDB/MySQL only, [Database time zone](installation.md#database-time-zone)) | `--umrechnen`, `--von=<zone>` (otherwise the server's), `--force` |

## Backup and moving

| Command | Purpose | Main arguments and options |
| --- | --- | --- |
| `basalto:backup` | back up the database and media, with manifest and checksums | `--path` (otherwise `storage/app/backups`) |
| `basalto:backup-verify` | verify a backup (checksums, restore test with SQLite) | `[<archiv>]` (otherwise the newest), `--path` |
| `basalto:export` | export a site completely (JSON and media) | `<site>`, `--path` (file or folder, otherwise `storage/app/exports`), `--with-submissions` |
| `basalto:import` | create a site from an export | `<zip>`, `--handle` (otherwise the handle from the export) |

Details under [Backup](backup.md).

## Licence and privacy

| Command | Purpose | Main arguments and options |
| --- | --- | --- |
| `basalto:license` | show and record the licence status, check keys; for basaltocms.com also issue and revoke | `--check=<key>`; for issuing `--keypair`, `--issue`, `--revoke` and others, see [Licence](lizenz.md) |
| `basalto:privacy` | provide information about a person or delete their data (Swiss FADP/GDPR) | `<email>`, `--export=<file>` (information as JSON), `--delete` (delete account and submissions), `--force` (without confirmation) |

## Development and tests

Not intended for live operation.

| Command | Purpose | Main arguments and options |
| --- | --- | --- |
| `basalto:benchmark` | load test: creates many sites, entries and media and measures the key queries — **writes test data to the database** | `--sites` (100), `--entries` (1000), `--media` (3334), `--locales` (2), `--runs` (30), `--keep`, `--clean` (remove bench data), `--json` |
| `basalto:load-test` | HTTP load test against your own addresses: requests per second, p50/p95/p99, errors, cache hits — foreign hosts only with `--remote` ([Load test](lasttest.md)) | `<url>…`, `--concurrency` (10), `--duration` (30), `--requests`, `--max-p95`, `--max-errors` (1), `--remote`, `--json` |
| `basalto:tests-sync` | synchronise the test cases of test management with the acceptance criteria | `--source` (`auto`, `docs` or `bundled`), `--write` |
| `basalto:demo-reset` | **Deletes the whole database** and sets up the public demo again: demo site with the seed of its theme, three accounts for quick access (owner, editor, translator); then test data in the size `BASALTO_DEMO_TESTDATA` (default `klein`, `aus` switches it off); ends with a checksum of the data. Runs only with `BASALTO_DEMO=true` and only after confirmation | `--force` (no prompt, as in the scheduler) |
| `basalto:update` | Check for updates (core, plugins, themes against the vendor of the environment) or install the core or a plugin from the signed archive — with backup, maintenance mode and way back ([Updates](updates.md)) | `--kern`, `--plugin=<vendor/package>`, `--benachrichtigen` |
| `basalto:testdaten` | Plenty of varied test data for development, test environments and the demo: team of the site and in the extranet, customer accounts (also with tricky names), community, forum, comments, members, marketplace and licence sales — depending on the enabled modules. Addresses end in `@testdaten.example`, mails go nowhere; same run, same data. In production only in demo mode ([Local](../hersteller/lokal.md#test-data)) | `<site>`, `--umfang` (`klein`, `mittel`, `gross`), `--nur=<area>`, `--neu` (remove first), `--entfernen` |
| `basalto:local` | Local landscape of the vendor under Laravel Herd: site “basaltocms” on `<folder>.test` (primary), load its content, rewrite production addresses in the content to the local ones; refuses in production | `--herd-config`, `--no-publish` |
| `basalto:release` | Build the release archive for the web installer: tracked files without development ballast, `vendor` without dev packages, manifest with Ed25519 signature (vendor) | `--release-version`, `--key`, `--out`, `--source`, `--vendor-from`, `--store`, `--notes` (text or file: “What’s new?” under Administration › Updates) |

## Scheduler timetable

Basalto schedules these tasks. They run as long as the cron calls `php artisan schedule:run` every minute
([Hosting](hosting.md)); all only on one server. The times apply in the application's time zone, which is UTC (`config/app.php`): 03:15 is 04:15 in Switzerland
(winter time) or 05:15 (summer time). Check with `php artisan schedule:list`.

| Task | Frequency | Cron | Purpose |
| --- | --- | --- | --- |
| `basalto:heartbeat` | every minute | `* * * * *` | sign of life for `/up` and Administration › Operations |
| `basalto:publish-scheduled` | every minute, without overlapping | `* * * * *` | publish scheduled versions |
| `basalto:error-alarm` | every minute | `* * * * *` | check for piled-up errors, email throttled |
| `queue:work --stop-when-empty --max-time=50 --tries=3` | every minute, without overlapping | `* * * * *` | work through the queue — only with `QUEUE_CONNECTION=database` and `BASALTO_QUEUE_VIA_SCHEDULER` not `false` |
| `basalto:license` | hourly | `0 * * * *` | update the licence status (offline) |
| `basalto:cleanup` | daily 03:00 | `0 3 * * *` | deletion run according to the retention periods |
| `basalto:backup` | daily 03:15 | `15 3 * * *` | backup, then retention according to `backup.keep` |
| `basalto:demo-reset --force` | daily 02:30, only with `BASALTO_DEMO=true` | `30 2 * * *` | set up the demo again |
| `basalto:media-purge` | daily 03:30 | `30 3 * * *` | empty the media library's trash |
| `basalto:update --benachrichtigen` | daily 04:10, not in SaaS mode, switch off with `BASALTO_UPDATES_CHECK=false` | `10 4 * * *` | check for updates; administration hears about every new state once |

Administration › Operations shows the last run of every task with its result.

## Next

- [Installation](installation.md)
- [Hosting](hosting.md)
