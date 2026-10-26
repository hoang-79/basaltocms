# Informativa sulla protezione dei dati

> **Bozza — verifica legale in sospeso.** Versione 0.3 · Stato 25.10.2026 · non ancora in vigore.
> Fa fede la versione tedesca; le traduzioni servono alla comprensione.

La presente informativa descrive che cosa facciamo con i dati personali: su basaltocms.com e i suoi sottodomini (sito
web, documentazione, demo, community, Marketplace), nel conto cliente, nell’acquisto di licenze, nel SaaS e nella
verifica degli aggiornamenti del software. Si basa sulla legge federale sulla protezione dei dati (LPD) e, per le
persone nell’UE, sul regolamento generale sulla protezione dei dati (RGPD).

Dei siti web gestiti con Basalto è responsabile chi li gestisce. Se gestiamo un sito per Lei nel SaaS, ne trattiamo i
dati su Suo incarico secondo l’[Accordo sul trattamento dei dati su incarico](avv.md).

## 1. Chi è responsabile

Basalto — un prodotto di nguyen-development.ch, Hoang Nguyen, ditta individuale, Hohfurristrasse 24, 8408 Winterthur, Svizzera.
Contatto per la protezione dei dati: email@basaltocms.com.

Rappresentante nell’UE (art. 27 RGPD): [[Platzhalter: designare un rappresentante nell’UE o motivare perché non è
necessario]]

## 2. Che cosa ci invia il software

Un’installazione di Basalto verifica la propria chiave di licenza offline e non trasmette nulla di ciò che accade su un
sito — nessun contenuto, nessun visitatore, nessun utente. Si connette a basaltocms.com solo nei seguenti casi:

- **Verifica degli aggiornamenti** — una volta al giorno e quando qualcuno seleziona «Verifica ora»
  nell’amministrazione. In tal modo vediamo l’indirizzo IP del server e la versione di Basalto. Se l’impostazione
  **«Dati di utilizzo per il produttore»** è attiva (attiva di fabbrica), l’installazione invia inoltre un
  identificativo casuale, la versione di PHP e il tipo di database. L’identificativo viene generato alla prima verifica
  e non è collegato a nulla; ne memorizziamo solo una somma di controllo, al massimo una volta al giorno, con versione,
  PHP e database, e contiamo così le installazioni attive. In questo contesto non memorizziamo l’indirizzo IP. Si
  disattiva in Amministrazione › Impostazioni › Esercizio.
- **Aggiornamenti e download** — quando si scarica l’installer web, l’archivio della release o un aggiornamento.
  Contiamo il download con tipo, versione e canale, senza indirizzo IP.
- **Marketplace** — se qualcuno installa un Theme o un Plugin, l’installazione effettua la richiesta con la chiave di
  accesso personale del conto cliente (cifra 9).
- **Collegamento con il conto cliente** — chi collega l’installazione al proprio conto cliente per vedere licenze e
  fatture nel proprio `/admin` attiva una richiesta con una chiave di accesso (cifra 5).

Se scarica Basalto tramite GitHub o Packagist, si applicano le rispettive informative sulla protezione dei dati.

## 3. Visita del sito web

A ogni richiesta, il nostro fornitore di hosting cyon tratta il Suo indirizzo IP, l’indirizzo richiamato, l’ora,
l’identificativo del browser e la risposta, per fornire la pagina, individuare errori e respingere attacchi. Limitiamo i
tentativi di accesso, i moduli e le richieste per indirizzo IP; questi contatori scadono al più tardi dopo un’ora.
Verifichiamo moduli e accessi con un piccolo calcolo nel Suo browser anziché con un enigma visivo; in tal modo nulla
viene trasmesso a terzi.

## 4. Statistiche e impostazioni dei cookie

Contiamo noi stessi l’utilizzo di basaltocms.com, senza servizi di analisi o pubblicitari di terzi, senza profili e
senza trasmissione a terzi.

- **Senza consenso** contiamo ogni visualizzazione di pagina in forma anonima: pagina richiamata (senza parametri),
  provenienza (solo il nome del sito web di provenienza e le indicazioni della campagna contenute nell’indirizzo),
  browser, sistema operativo e tipo di dispositivo, lingua, tempo di risposta e se Lei ha effettuato l’accesso. Nessun
  indirizzo IP, nessun cookie, nessun identificativo — una visualizzazione non può essere attribuita a una persona.
- **Con il Suo consenso** alla categoria «Statistiche», il Suo browser imposta inoltre due cookie con identificativi
  casuali (`basalto_besucher` per un anno, `basalto_sitzung` per 30 minuti). Ci consentono di riconoscere le visite
  ricorrenti e le sessioni correlate — ad esempio quante pagine comprende una visita. Gli identificativi non rivelano
  chi è Lei.
- Se il Suo browser invia il segnale «Global Privacy Control», lo trattiamo come un no.
- Eliminiamo le visualizzazioni di pagina dopo 400 giorni.

Memorizziamo la Sua scelta nel cookie `basalto_consent` per un anno. Può modificarla in qualsiasi momento tramite il
link «Impostazioni dei cookie» in fondo a ogni pagina; una revoca vale per il futuro.

### Cookie e memoria nel browser

| Nome | Scopo | Durata | Tipo |
| --- | --- | --- | --- |
| `basalto-session` | sessione, accesso | 2 ore dopo l’ultima richiesta | necessario |
| `XSRF-TOKEN` | protezione dei moduli contro gli abusi | come la sessione | necessario |
| `basalto_consent` | la Sua scelta nelle impostazioni dei cookie | 1 anno | necessario |
| `basalto_community_terms` | il Suo consenso alle condizioni della community come ospite (sito e versione) | 1 anno | necessario |
| `basalto_besucher` | identificativo casuale per le visite ricorrenti | 1 anno | statistiche, solo con consenso |
| `basalto_sitzung` | identificativo casuale di una sessione | 30 minuti | statistiche, solo con consenso |

Nella memoria del Suo browser (non come cookie, mai trasmessi a noi) memorizziamo, se Lei lo desidera: modalità chiara o
scura, avvisi nascosti, bozze di messaggi nel forum, messaggi memorizzati per la citazione e se desidera ricevere
notifiche sul desktop. Può eliminare queste indicazioni insieme ai dati dei siti web del Suo browser.

I contenuti di terzi incorporati (ad esempio video) vengono caricati solo dopo il Suo consenso alla categoria «Contenuti
esterni»; in tal caso si applicano le informative sulla protezione dei dati dei rispettivi fornitori.

## 5. Conto cliente e accesso

**Conto cliente:** indirizzo e-mail, nome, lingua e — facoltativamente — azienda e indirizzo. Registriamo chi ha
accettato quale versione del Contratto di licenza, delle CG e delle Condizioni d’uso e quando.

**Accesso con codice:** Inviamo un codice al Suo indirizzo e-mail. Memorizziamo il codice solo come somma di controllo e
l’indirizzo IP della richiesta come somma di controllo con la nostra chiave; eliminiamo i codici scaduti dopo un giorno.

**Passkey:** Il Suo dispositivo genera una coppia di chiavi. Memorizziamo solo la chiave pubblica, un identificativo, il
nome che Lei dà alla passkey e quando è stata utilizzata l’ultima volta. L’impronta digitale o l’immagine del volto non
lasciano mai il Suo dispositivo.

**Accesso tramite un altro account:** Se sceglie Google, Microsoft, GitHub o LinkedIn, il Suo browser La reindirizza al
fornitore. Da quest’ultimo riceviamo un identificativo, il Suo nome e — solo se il fornitore lo ha confermato — il Suo
indirizzo e-mail, per Microsoft inoltre l’identificativo dell’organizzazione. Memorizziamo fornitore, identificativo,
indirizzo e-mail e il momento dell’ultimo accesso, ma non la Sua password presso il fornitore né altri dati del Suo
account presso di esso. I fornitori sono essi stessi responsabili per la loro parte:
[Google](https://policies.google.com/privacy), [Microsoft](https://privacy.microsoft.com/),
[GitHub](https://docs.github.com/site-policy/privacy-policies/github-general-privacy-statement),
[LinkedIn](https://www.linkedin.com/legal/privacy-policy). Può rimuovere i collegamenti nel conto cliente alla voce
«Accesso».

**Sessione:** La Sua sessione contiene l’indirizzo IP e l’identificativo del browser e termina due ore dopo l’ultima
richiesta.

**Installazioni collegate:** Se collega un’installazione al Suo conto cliente, memorizziamo il suo indirizzo (host), un
nome a libera scelta, una somma di controllo della chiave di accesso e il momento della sua ultima richiesta. Può
scollegarla nel conto cliente o nell’installazione.

## 6. Acquisto di licenze e pagamento

**Licenze:** indirizzo di fatturazione, licenziatario, domini di produzione, livello, chiavi emesse, fatture e
pagamenti. Il licenziatario e i domini figurano nella chiave stessa e sono leggibili da chiunque veda la chiave. Con una
licenza Agenzia Lei registra nome e dominio dei Suoi clienti per la loro sottolicenza.

**Il pagamento con carta e altri mezzi** avviene tramite Stripe. I dati della carta vanno direttamente a Stripe; noi non
li vediamo né li memorizziamo mai. Riceviamo lo stato del pagamento, l’importo, il tipo e le ultime cifre del mezzo di
pagamento. **Con la QR-fattura** riceviamo dalla nostra banca nome, conto e importo del pagamento.

## 7. SaaS

Per un conto nel SaaS trattiamo in qualità di titolari del trattamento: nome e indirizzi e-mail delle persone nel conto
con i loro ruoli, azienda, indirizzo di fatturazione, piano, abbonamento, fatture e pagamenti, domini, il momento del
consenso alle CG e all’informativa sulla protezione dei dati al momento della registrazione nonché disdetta,
esportazione e cancellazione.

I contenuti dei Suoi siti — pagine, media, utenti, invii di moduli, commenti e conti cliente dei Suoi visitatori — li
trattiamo solo su Suo incarico secondo l’[Accordo sul trattamento dei dati su incarico](avv.md). Per fornire assistenza,
il nostro team può aprire un sito per al massimo quattro ore **in sola lettura**; il motivo figura in un banner nel Suo
backend e nel registro del Suo sito.

## 8. Community

**Profilo:** nome visualizzato, su richiesta immagine del profilo, foto di copertina, firma e altre indicazioni che
inserisce Lei. Il profilo, i Suoi messaggi, le reazioni e il Suo rango sono visibili pubblicamente e possono essere
trovati dai motori di ricerca. Il profilo mostra inoltre quanti messaggi ha scritto e la data della Sua ultima attività.

**Messaggi:** testo, allegati, modifiche con la relativa cronologia, voti nei sondaggi, reazioni e risposte
contrassegnate come utili. Per ogni messaggio memorizziamo una somma di controllo del Suo indirizzo IP per respingere
spam e abusi.

**Ciò che vede solo Lei:** discussioni lette, discussioni seguite, messaggi memorizzati, chi segue e chi ignora o
blocca. **Le conversazioni private** sono visibili solo ai partecipanti; le moderatrici e i moderatori vedono un
messaggio solo se qualcuno lo segnala.

**Funzioni in tempo reale:** Mentre scrive un messaggio, gli altri nella stessa discussione vedono per alcuni secondi «…
sta scrivendo» con il Suo nome visualizzato. I nuovi messaggi e le notifiche compaiono senza ricaricare la pagina.

**Condizioni:** Prima di usare la community, Lei accetta le condizioni d’uso e le linee guida della community. Con un
conto memorizziamo versione, data e ora del Suo consenso e di un’eventuale revoca; li vede nel conto cliente e lì può
revocare il consenso. Come ospite, il Suo browser memorizza il consenso nel cookie `basalto_community_terms`.

**Moderazione:** Registriamo con la relativa motivazione le segnalazioni, gli avvertimenti, i blocchi e ogni misura
della moderazione. Possiamo confrontare i nuovi conti con l’elenco pubblico di Stop Forum Spam; in tal caso l’indirizzo
IP e una somma di controllo dell’indirizzo e-mail vengono trasmessi al gestore di tale elenco. Su basaltocms.com questo
confronto è disattivato; se lo attiviamo, integreremo preventivamente la presente informativa.

**Chiusura del conto:** Eliminiamo profilo, immagini e allegati; i messaggi restano senza nome e senza collegamento con
Lei, la somma di controllo dell’indirizzo IP viene eliminata (vedi [Condizioni d’uso](nutzungsbedingungen.md)).

## 9. Marketplace

**Autrici e autori:** il profilo d’autore pubblico (nome o ragione sociale, su richiesta località, sito web e logo), i
pacchetti inviati con i risultati della verifica automatica e della verifica in un’installazione di test isolata, la
corrispondenza relativa all’esame nonché vendite e versamenti. Per le voci a pagamento Lei apre un conto presso Stripe
(Stripe Connect); la verifica della Sua identità e del Suo conto bancario è effettuata da Stripe sotto la propria
responsabilità.

**Acquirenti:** acquisti, fatture, la chiave di accesso personale al repository Composer (cifrata, affinché possa
rivederla nel conto cliente) e, per ogni voce, il fatto che l’ha scaricata e in quale versione — base dell’indicazione
«installato» in una valutazione.

**Valutazioni:** stelle, titolo, testo e il Suo nome visualizzato sono pubblici; inoltre l’indicazione «acquistato» o
«installato». Memorizziamo le segnalazioni di valutazioni con il motivo; per le segnalazioni senza conto solo una somma
di controllo che impedisce le segnalazioni multiple.

## 10. E-mail, notifiche e contatto

Inviamo le e-mail che fanno parte del conto: codici di accesso, conferme, fatture, promemoria e avvisi di sicurezza. Le
notifiche della community — risposte, menzioni, riepiloghi — le riceve nella campanella e, secondo quanto impostato nel
profilo, per e-mail. Non esiste una newsletter.

Ciò che ci scrive per e-mail o tramite il modulo di contatto lo trattiamo con nome e indirizzo e-mail per poterLe
rispondere. Eliminiamo gli invii del modulo di contatto dopo 90 giorni.

Se disdice tramite «Disdire i contratti qui», trattiamo nome, indirizzo e-mail, i dati sul contratto e il momento della
ricezione per eseguire, confermare e documentare la disdetta (contratto e obbligo legale).

## 11. Registro e sicurezza

Registriamo le modifiche nel conto cliente, nella moderazione e nell’amministrazione di basaltocms.com con ora, conto e
una somma di controllo dell’indirizzo IP, ai fini della tracciabilità e della sicurezza; eliminiamo le voci dopo 365
giorni.

Trasmettiamo tutto in forma cifrata (TLS), l’accesso avviene senza password, memorizziamo codici e indirizzi IP solo
come somme di controllo e le chiavi di accesso in forma cifrata o come somma di controllo, eseguiamo backup giornalieri
e registriamo gli accessi. Nessuno può garantire una sicurezza assoluta. Se constatiamo una violazione della sicurezza
dei dati che La riguarda, informiamo Lei e — ove la legge lo richieda — l’IFPDT o l’autorità di vigilanza competente.

## 12. Basi giuridiche

- **Contratto** (art. 31 cpv. 2 lett. a LPD, art. 6 par. 1 lett. b RGPD): conto cliente, accesso, acquisto, chiave,
  fatture, SaaS, Marketplace, community, supporto.
- **Obbligo legale** (art. 6 par. 1 lett. c RGPD): conservazione di fatture e documenti contabili.
- **Interesse legittimo** (art. 31 cpv. 1 LPD, art. 6 par. 1 lett. f RGPD): gestione e sicurezza del sito web,
  prevenzione di abusi e spam, statistiche anonime, conteggio delle installazioni attive, verifica delle estensioni
  inviate, moderazione, prova delle condizioni accettate, risposte alle Sue richieste.
- **Consenso** (art. 6 par. 1 lett. a RGPD): cookie delle statistiche, contenuti di terzi incorporati.

Non vendiamo dati, non li utilizziamo a fini pubblicitari e non prendiamo decisioni individuali automatizzate con
effetti giuridici. Le misure di protezione automatiche della community (pausa tra un messaggio e l’altro, filtro di
parole, verifica nel browser) e la verifica automatica nel Marketplace non decidono in via definitiva; decide una
persona.

## 13. Destinatari e fornitori di servizi

Ricorriamo a fornitori di servizi che trattano dati su nostro incarico:

- **cyon AG, Basilea (Svizzera):** gestione di sito web, SaaS, banca dati, file e backup nonché invio delle nostre
  e-mail, in centri di calcolo in Svizzera. Le funzioni in tempo reale della community e delle notifiche si basano,
  senza alcun servizio aggiuntivo, su richieste al nostro server.

Trattano dati sotto la propria responsabilità:

- **Stripe Payments Europe Ltd. (Irlanda)** per i pagamenti e Stripe Connect; Stripe trasmette dati a Stripe, Inc. (USA)
  per la prevenzione delle frodi e per adempiere obblighi legali.
- **[[Platzhalter: banca]]** per i pagamenti in entrata su QR-fatture.
- **Google, Microsoft, GitHub e LinkedIn**, solo se Lei accede tramite questi servizi (cifra 5).

Al di là di ciò trasmettiamo dati solo se la legge o un’autorità lo richiede o se è necessario per far valere i nostri
diritti.

## 14. Comunicazione all’estero

I nostri dati si trovano in Svizzera. All’estero vengono trasferiti dati solo tramite Stripe e — se lo utilizza —
l’accesso tramite Google, Microsoft, GitHub o LinkedIn; questi destinatari hanno la propria sede o il proprio gruppo
negli USA. Per tali trasferimenti ci basiamo sulla certificazione dei destinatari secondo l’EU-US Data Privacy Framework
e la sua parte Swiss-US; se questa viene meno, sulle clausole contrattuali tipo della Commissione europea nella versione
riconosciuta dall’IFPDT. [[Platzhalter: verificare la certificazione di ciascun destinatario nel registro DPF]] Per le
persone nello SEE: l’UE riconosce alla Svizzera un livello adeguato di protezione dei dati.

## 15. Per quanto tempo

| Dati | Conservazione |
| --- | --- |
| Conto cliente, profilo, collegamenti, passkey | finché Lei non chiude il conto |
| Consensi alle condizioni della community | finché Lei non chiude il conto |
| Codici di accesso | un giorno dopo la scadenza |
| Fatture e documenti contabili | dieci anni (art. 958f CO) |
| Chiavi emesse e condizioni accettate | fino a dieci anni dopo la fine del contratto (art. 127 CO) |
| Conto SaaS dopo la disdetta | bloccato per 30 giorni, poi eliminato; fatture come sopra |
| Visualizzazioni di pagina delle statistiche, verifiche degli aggiornamenti | 400 giorni |
| Download | senza riferimento a persone, senza termine |
| Messaggi nel forum | finché esistono; dopo la chiusura del conto senza riferimento a persone |
| Invii del modulo di contatto | 90 giorni |
| Disdette tramite «Disdire i contratti qui» | cinque anni dalla ricezione (art. 128 CO) |
| Richieste sulla protezione dei dati | tre anni dopo la risposta |
| E-mail a noi | due anni dopo l’ultimo messaggio, salvo che Lei sia cliente |
| Registro | 365 giorni |
| Backup | i nostri, eseguiti ogni giorno, di cui restano gli ultimi sette; inoltre i backup di cyon ([[Platzhalter: conservazione dei backup presso cyon]]) |

## 16. I Suoi diritti

Può chiedere informazioni sui Suoi dati, farli rettificare, cancellare o limitarne il trattamento e riceverli in un
formato elettronico di uso comune (art. 25, 28 e 32 LPD, art. da 15 a 20 RGPD). Laddove ci basiamo su un interesse
legittimo, può opporsi (art. 21 RGPD); può revocare un consenso in qualsiasi momento con effetto per il futuro. È
sufficiente un messaggio a email@basaltocms.com; rispondiamo entro 30 giorni. Ciò che siamo tenuti a conservare
(cifra 15) rimane fino alla scadenza del termine.

Con un conto cliente fa la maggior parte da sé in **Conto cliente › Protezione dei dati**: scaricare i Suoi dati come
file, rettificare nome e indirizzo, vedere e revocare i consensi, chiedere la limitazione o opporsi (risposta entro un
mese) ed eliminare il conto.

Se con noi non trova una soluzione, può rivolgersi all’Incaricato federale della protezione dei dati e della trasparenza
(edoeb.admin.ch) e, nello SEE, all’autorità di vigilanza del Suo Paese.

## 17. Bambini e adolescenti

basaltocms.com si rivolge ad adulti e imprese. La community è aperta alle persone a partire dai 16 anni. Se veniamo a
sapere che un conto appartiene a una persona più giovane, lo eliminiamo.

## 18. Modifiche

Adeguiamo la presente informativa quando basaltocms.com, il software o la situazione giuridica cambiano. In alto è
indicato lo stato. Comunichiamo per e-mail alle clienti e ai clienti le modifiche essenziali.
