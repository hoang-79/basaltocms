# Monitoring

Basalto shows operational figures where you look anyway: under **Administration › Operations** in the backend. On top
of that there is the dashboard of [Laravel Pulse](https://laravel.com/docs/pulse) and an alarm by email when errors pile
up — both without a third-party service.

![The “Operations” page in the backend: heartbeat, queue, database, storage, backups and tasks](../verwaltung/bilder/betrieb.webp)

| What | Where | Who sees it |
| --- | --- | --- |
| Heartbeat, queue, database, cache, storage, backups, scheduler | Administration › Operations, `/up`, `/up/live` | whoever may administer the site |
| Metrics from Pulse (last hour, all sites) and status of the error alarm | Administration › Operations, section “Metrics from Pulse” | installation operators |
| Pulse dashboard with all cards | `/admin/pulse` | installation operators |
| Error alarm | email | installation operators |

**Installation operators** are the owners and administration of the installation account — the first account that
`php artisan basalto:install` creates. Owners or administrators of a single site do not see the Pulse figures,
because they cover all sites of the installation (on the SaaS also those of other customers). Deactivated accounts
do not count.

## Pulse

Pulse is part of the application (`laravel/pulse` 1.8) and records in the application database: tables
`pulse_values`, `pulse_entries` and `pulse_aggregates`, created by `php artisan migrate`. The configuration is in
`config/pulse.php`.

- **Dashboard:** `/<BASALTO_BACKEND_PATH>/pulse`, so usually `/admin/pulse` (own path with `PULSE_PATH`). Guests
  end up on the login, everyone else except the installation operators gets 403. The link is also on the
  “Operations” page.
- **On “Operations”:** reported exceptions (the most frequent with location), slow requests above the threshold
  (`PULSE_SLOW_REQUESTS_THRESHOLD`, default 1,000 ms), slow queries, processed, failed and re-queued jobs, slow
  jobs — each over the last hour.
- **Switching off:** `PULSE_ENABLED=false`. Pulse then records nothing more; “Operations” says so and shows the
  last recorded figures. In `phpunit.xml` Pulse is off.
- **Retention:** Pulse deletes entries after seven days itself (`PULSE_STORAGE_KEEP`).
- **Heavy traffic:** sampling per recorder (`PULSE_USER_REQUESTS_SAMPLE_RATE=0.1` etc.) or Redis ingestion
  (`PULSE_INGEST_DRIVER=redis`, plus a permanently running `php artisan pulse:work`). On simple hosting neither is
  needed.
- **Card cache:** The cards put their queries into the cache as objects; Laravel 13 does not unserialise objects from
  the cache (`cache.serializable_classes`). Basalto therefore puts them into the array store per request
  (`PULSE_CACHE_DRIVER=array`, default in `config/pulse.php`). Consequence: `php artisan pulse:restart` does not reach
  running `pulse:check`/`pulse:work` processes — restart these processes after the deploy (Laravel Cloud does that anyway).
- **Server card:** Memory and CPU only appear if `php artisan pulse:check` runs permanently (on Laravel
  Cloud as a background process). On simple hosting the card stays empty — that is not an error.
- **Databases:** Pulse supports SQLite, MariaDB, MySQL and PostgreSQL. On SQL Server the migration skips
  the tables; “Operations” then reports “Pulse is not set up”, and `PULSE_ENABLED=false` belongs in the `.env`.
- **Privacy:** Pulse stores user IDs (card “Application Usage”), paths, queries and locations of
  exceptions, no content. Basalto shows people without Gravatar (no call to a third-party service). The
  Pulse dashboard itself loads third-party resources: the font from `fonts.bunny.net` and, in the card “Slow Outgoing
  Requests”, logos of the target hosts from `unavatar.io`. This only affects operators, not visitors to the site.
- **Livewire:** Pulse brings Livewire along. Basalto switches off its automatic injection
  (`livewire.inject_assets`), so that `livewire.js` never ends up in pages of the site or the backend.

## Error alarm

Basalto counts every reported exception per minute in the cache — regardless of whether Pulse is recording. The
scheduler calls `php artisan basalto:error-alarm` every minute. If the window contains at least as many exceptions as
the threshold, an email goes to the installation operators; after that there is quiet for the duration of the pause,
even if the errors continue. Unreported exceptions (404, validation) do not count.

| Setting (Administration › Settings › Operations) | Key | Default |
| --- | --- | --- |
| Error alarm from | `operations.error_alarm_threshold` | 20 exceptions (0 = no alarm) |
| Error alarm window | `operations.error_alarm_minutes` | 5 minutes |
| Pause after an error alarm | `operations.error_alarm_pause_minutes` | 60 minutes |

Requirements: the scheduler runs (cron every minute or Laravel Cloud) and mail is set up
(`MAIL_*` in the `.env`). With `MAIL_MAILER=log` the email only ends up in the log. The card “Error alarm” on
“Operations” shows the threshold, current count, last alarm and the number of recipients.

Checking manually:

```sh
php artisan basalto:error-alarm     # reports “unter der Schwelle” (below threshold), “gedrosselt” (throttled) or “Alarm an n Betreiber” (alarm to n owners)
```

## Watching several instances

If you run many instances, you can also connect [Laravel Nightwatch](https://nightwatch.laravel.com): requests, queries,
jobs and exceptions across all instances. The placeholders are commented out in `.env.example` (`NIGHTWATCH_ENABLED`,
`NIGHTWATCH_TOKEN` and the sample rates); you install the `laravel/nightwatch` package yourself. Nightwatch transfers
operational data to Laravel — check data processing and the privacy policy before you switch it on.

## Next

- [Operations](../verwaltung/betrieb.md)
- [Hosting](hosting.md)
- [Commands](befehle.md)
