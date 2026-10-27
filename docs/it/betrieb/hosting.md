# Hosting

Basalto funziona presso un **semplice provider di hosting** con PHP, database e una voce cron — oppure su **Laravel
Cloud** con servizi gestiti. In entrambi i casi senza Redis e senza un worker sempre in esecuzione.

## Hosting semplice

![Una voce cron avvia lo scheduler ogni minuto — si occupa di battito, pubblicazioni, coda e backup](bilder/scheduler.svg)

1. Portare Basalto sul server e configurarlo come descritto in [Installazione](installation.md).
2. Impostare i driver nel `.env` sul database — è già il valore predefinito di `.env.example`:

   ```dotenv
   DB_CONNECTION=mariadb
   CACHE_STORE=database
   SESSION_DRIVER=database
   QUEUE_CONNECTION=database
   ```

3. Creare **una** voce cron (adattare il percorso):

   ```cron
   * * * * * cd /pfad/zu/basalto && php artisan schedule:run >> /dev/null 2>&1
   ```

4. Verificare nel backend in **Amministrazione › Esercizio**: `/up` segnala «bereit» (pronto), il battito è più
   recente della soglia del battito (predefinito 5 minuti, Amministrazione › Impostazioni › Esercizio), la coda viene
   elaborata tramite il cron.

### Coda senza worker

E-mail, webhook, varianti delle immagini e il preriscaldamento della cache delle pagine passano dalla coda. Senza
worker la elabora lo scheduler: ogni minuto avvia

```sh
php artisan queue:work --stop-when-empty --max-time=50 --tries=3
```

senza sovrapposizioni e su un solo server. Ciò avviene finché valgono entrambe le condizioni:

- `QUEUE_CONNECTION=database` e
- `BASALTO_QUEUE_VIA_SCHEDULER` non è impostata oppure è `true`.

Se gira un worker proprio (Supervisor, coda gestita), imposti `BASALTO_QUEUE_VIA_SCHEDULER=false`. Con una coda diversa
da `database` Basalto non pianifica comunque `queue:work`. Quali altre attività esegua lo scheduler è indicato in
[Comandi](befehle.md#pianificazione-dello-scheduler).

### A che cosa prestare attenzione con il provider di hosting

- `schedule:run` avvia ogni attività come processo PHP separato; il provider deve consentire `proc_open` e un cron con
  cadenza al minuto.
- Per il backup giornaliero il server necessita dello strumento di dump del database, vedi [Backup](backup.md).
- Node.js non serve sul server: l’archivio di release contiene già il frontend compilato.

### Web hosting con LiteSpeed (p. es. cyon)

- **Cron con il percorso PHP completo:** senza percorso il cron avvia la versione predefinita del server, non quella del
  Suo sito:

  ```cron
  * * * * * cd /home/<login>/basalto && /opt/alt/php85/usr/bin/php artisan schedule:run >> /dev/null 2>&1
  ```

- **Versione PHP per cartella:** LiteSpeed eredita il `.htaccess` solo di un livello. Se `public/` si trova più in basso
  (`public_html/basalto/public`), `public/.htaccess` ha bisogno della versione PHP stessa. L’installer web la riprende dal
  `.htaccess` superiore; gli aggiornamenti lasciano `public/.htaccess` al suo posto.
- **`.htaccess` esistente:** l’installer web aggiunge il reindirizzamento a `public/` invece di ometterlo.
- **Aggiornamenti senza `proc_open`:** se `proc_open` è disattivato nel browser, Amministrazione › Aggiornamenti indica il
  comando per SSH: `/opt/alt/php85/usr/bin/php artisan basalto:update --kern`. Se nel browser PHP gira come `lsphp`,
  imposti `BASALTO_PHP_BINARY=/opt/alt/php85/usr/bin/php`.
- **SQLite:** Basalto imposta SQLite su WAL con 5 secondi di attesa (`DB_JOURNAL_MODE`, `DB_BUSY_TIMEOUT`); il file si
  trova in `database/`, fuori da `public/`, e viene salvato con `VACUUM INTO`.
- **E-mail:** il mittente è un indirizzo di un dominio sullo stesso hosting (SMTP tramite porta 465 o 587); un nuovo
  hosting invia al massimo 30 e-mail all’ora nei primi 30 giorni.

## Stato di salute: `/up` e `/up/live`

| Indirizzo | Verifica | Risposta |
| --- | --- | --- |
| `/up` | database e battito dello scheduler (readiness) | 200 «bereit» (pronto) o 503 «gestört» (con problemi), JSON senza segreti |
| `/up/live` | solo il processo PHP (liveness) | 200 |

Un’installazione nuova senza un primo battito è considerata con problemi solo dopo la soglia. Maggiori informazioni
sulla sorveglianza in [Monitoraggio](monitoring.md).

## Laravel Cloud

Per Laravel Cloud valgono queste impostazioni:

| Tema | Su Laravel Cloud |
| --- | --- |
| File | media nel bucket privato (`FILESYSTEM_DISK=s3`); nulla sul disco volatile |
| Cache, sessioni | Valkey (`CACHE_STORE=redis`, `SESSION_DRIVER=redis`), cache propria per ogni ambiente |
| Coda | coda gestita o `database`; con worker proprio `BASALTO_QUEUE_VIA_SCHEDULER=false` |
| Build | `composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader` · `php artisan vendor:publish --tag=basalto-assets --force` · `npm ci --audit false` · `npm run build` |
| Comando di deploy | `php artisan migrate --force` |
| Scheduler | attivarlo nel cluster dell’app; tutte le attività girano `onOneServer` |
| Domini propri dei clienti (solo SaaS) | `basalto:domains-verify` ogni dieci minuti tramite la CLI di Cloud (`BASALTO_CLOUD_ENVIRONMENT`, `LARAVEL_CLOUD_TOKEN` come secret) |

Laravel Cloud compila la nuova release ed esegue le migrazioni prima di passare alla nuova versione — per un certo
tempo il vecchio codice serve il nuovo schema. Le migrazioni proprie (ad esempio nei moduli) seguono perciò la regola
«prima estendere, poi riordinare»: aggiungere e popolare prima una nuova colonna, rimuovere la vecchia solo in una
release successiva.

I backup del database della piattaforma e il versioning del bucket si attivano in Laravel Cloud; il backup di Basalto
si aggiunge a questi, vedi [Backup](backup.md).

## Demo pubblica

Un’installazione può funzionare come demo pubblica: con `BASALTO_DEMO=true` — mai insieme a dati che devono restare.

- **Configurazione:** con l’installer web, in «Avanzate» «Configurare come demo pubblica» — l’installer scrive
  `BASALTO_DEMO=true` e porta direttamente al tour delle funzioni.
- **Accesso rapido:** la pagina di accesso mostra tre pulsanti «Visualizza come gestore», «Visualizza come redattore» e
  «Visualizza come traduttore», che fanno accedere senza password né codice. Senza l’interruttore non esistono né i
  pulsanti né l’indirizzo che vi sta dietro.
- **E-mail intercettate:** ogni e-mail viene trattenuta prima dell’invio e solo registrata (mailer `log`, modificabile
  con `BASALTO_DEMO_MAILER`); il backend mostra la fascia «Demo — i dati vengono ripristinati ogni notte, le e-mail non
  vengono inviate.».
- **Nuova ogni notte:** alle 02:30 lo scheduler richiama `basalto:demo-reset --force` — svuotare il database ed
  eseguire di nuovo le migrazioni, svuotare i media, caricare il sito `BASALTO_DEMO_SITE` (predefinito `demo`) con i
  contenuti iniziali di `BASALTO_DEMO_THEME` (predefinito `starter`) e metterlo online, creare tre account.
- **Spazio limitato:** ciò che i visitatori caricano — media, allegati dei moduli, immagini del profilo, immagini del
  tema, allegati del forum — occupa in tutto al massimo `BASALTO_DEMO_QUOTA_MB` fino al prossimo ripristino
  (predefinito 200, `0` senza limite). Quando lo spazio è pieno, ogni ulteriore caricamento viene rifiutato con un
  avviso nel campo del file. La demo non mostra l’invito «Configurare passkey» — i suoi account vengono creati di nuovo
  ogni notte.
- **Tour delle funzioni:** della demo fa parte la pagina `/funktionen` con una sottopagina per ogni area — che cosa sa
  fare, che cosa si può provare e un accesso diretto al punto giusto del backend tramite l’accesso rapido.
- Il dominio del sito demo è l’host di `APP_URL`; `BASALTO_DEFAULT_SITE=demo` vi indirizza anche altri host.

## Per continuare

- [Installazione](installation.md)
- [Monitoraggio](monitoring.md)
- [Comandi](befehle.md)
