# Esercizio

**Esercizio** mostra a colpo d’occhio se l’installazione è in salute: la pianificazione funziona, la coda lavora, il database risponde, ci sono backup, gli errori si accumulano. Rosso significa: qui qualcosa non va — di solito serve allora la tecnica.

## Dove

Modalità «Amministrazione» › **Esercizio**.

![La pagina «Esercizio»](bilder/betrieb.webp)

In alto è indicato ciò che segnala l’indirizzo di stato `/up`: **«pronto»** significa che il sito funziona e tutti i servizi rispondono; **«con problemi»** significa che manca qualcosa (per esempio il battito). Il monitoraggio esterno interroga proprio questo indirizzo. Inoltre la versione di Basalto.

## Le schede

| Scheda | Che cosa mostra | Rosso significa | Che cosa fare |
| --- | --- | --- | --- |
| **Battito** | se la pianificazione (scheduler) funziona, la soglia e quanti compiti ha | nessun battito da oltre la soglia — pubblicazioni pianificate, ciclo di eliminazione e backup non vengono eseguiti | Tecnica: verificare lo scheduler (cron, ogni minuto) |
| **Coda** | driver, job in attesa e falliti, come vengono elaborati | almeno un job è fallito | vedi sotto |
| **Database** | tipo, versione, tempo di risposta, dimensione e se tutte le migrazioni sono state eseguite | nessuna connessione o migrazioni in sospeso | Tecnica: dopo un aggiornamento `php artisan migrate` |
| **Cache** | driver e tempo di risposta | nessuna risposta | Tecnica |
| **Spazio di archiviazione** | archivio dei media, numero e spazio occupato | archivio non raggiungibile | Tecnica |
| **Backup** | ultimo backup, quando è stato provato l’ultima volta un ripristino | nessun backup | «Backup ora» o tecnica |

In abbonamento si aggiunge la scheda **Domini dei clienti**: lo stato dei domini propri.

### Job falliti

I job sono lavori in background — ritagliare varianti d’immagine, inviare e-mail, preriscaldare pagine. Se uno fallisce, compare nella scheda «Coda» con nome e motivo, e anche la «Panoramica» lo segnala. Con **«Riprovare»** torna nella coda; **«Scarta»** lo rimuove. Entrambe le azioni compaiono nel registro. Se un job fallisce sempre di nuovo, la causa è più profonda — trasmetta il motivo alla tecnica.

### Backup

**«Backup ora»** avvia in background un backup dell’intera installazione (database e media); di fabbrica viene eseguito ogni notte, e restano gli ultimi 7 (impostazione «Conservare i backup»). Il **ripristino** può farlo solo la tecnica (vedi [Backup](../betrieb/backup.md)).

Un backup salva l’intera installazione per le emergenze. L’**esportazione** di un sito (sotto «Siti») è un’altra cosa: una copia di un singolo sito per il trasferimento.

## Metriche e allarme errori

Chi gestisce l’installazione vede sotto le **metriche dell’ultima ora** su tutti i siti — eccezioni segnalate, richieste e query lente, job — e **«Apri la dashboard di Pulse»** per i dettagli.

La scheda **Allarme errori** mostra a partire da quanti errori in quanti minuti parte un’e-mail a titolare e amministrazione dell’account dell’installazione, quando c’è stato l’ultimo allarme e chi lo riceve. **«Modifica soglia»** porta alle [Impostazioni](einstellungen.md), gruppo «Esercizio».

## Compiti dello scheduler

In basso l’elenco di tutti i compiti pianificati con la loro cadenza e quando sono stati eseguiti l’ultima volta — per esempio battito (ogni minuto), pubblicazioni pianificate, backup (di notte), ciclo di eliminazione, verifica della licenza. «non ancora eseguita» per tutti significa di solito: lo scheduler non funziona.

## Domande frequenti

**«nessun battito» — il sito web è fuori uso?**
No, il sito web funziona. Ma tutto ciò che è a tempo è fermo. La tecnica deve configurare lo scheduler (vedi [Installazione](../betrieb/installation.md)).

**Non vedo le metriche di Pulse.**
Sono riservate ai gestori dell’installazione.

## Per continuare

- [Monitoraggio](../betrieb/monitoring.md)
- [Aggiornamenti](updates.md)
