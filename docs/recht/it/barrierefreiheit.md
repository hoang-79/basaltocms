# Dichiarazione di accessibilità

> **Bozza — verifica legale in sospeso.** Versione 0.2 · Stato 25.10.2026 · non ancora in vigore.
> Fa fede la versione tedesca; le traduzioni servono alla comprensione.

Basalto deve poter essere utilizzato da tutti — su basaltocms.com e nel software con cui altri realizzano i propri siti
web. La presente dichiarazione indica a che punto siamo, che cosa manca ancora e come può contattarci se qualcosa non
funziona.

## 1. Il nostro obiettivo

Ci basiamo sulle Web Content Accessibility Guidelines (WCAG) 2.2 al livello AA e sulla norma europea EN 301 549.
Sviluppiamo le nuove funzioni fin dall’inizio in modo che funzionino con tastiera, lettore di schermo, ingrandimento e
impostazioni dei colori personalizzate.

## 2. A che cosa si applica la presente dichiarazione

- **basaltocms.com** con documentazione, demo, community, Marketplace, conto cliente e acquisto di licenze;
- **il software Basalto**: il backend, l’editor, le impostazioni dei cookie, i conti cliente nel frontend e il Theme
  «starter» fornito con il software.

Dei siti web gestiti con Basalto è responsabile chi li gestisce. Basalto aiuta in questo — ad esempio con l’obbligo del
testo alternativo per le immagini, che può essere attivato —, ma non può garantire i contenuti e i Theme propri di
altri.

## 3. Stato

basaltocms.com e Basalto sono **parzialmente conformi** alle WCAG 2.2 livello AA. Le non conformità sono indicate alla
cifra 5. La base è un’autovalutazione del 25.10.2026 (cifra 8).

## 4. Che cosa facciamo a tal fine

- Pagine con una struttura chiara: titoli nell’ordine corretto, punti di riferimento (landmark), lingua per pagina e per
  sezione, un link «Vai al contenuto».
- Tutto utilizzabile con la tastiera, con focus visibile; le finestre di dialogo come le impostazioni dei cookie
  mantengono il focus e si chiudono con Esc.
- Contrasti secondo il livello AA in modalità chiara e scura; nessuna informazione trasmessa solo tramite il colore.
- Movimento solo dove spiega qualcosa; con «riduci movimento» attivo nel sistema operativo, tutto resta fermo.
- Moduli con etichette visibili e messaggi di errore accanto al campo.
- Accesso senza password e senza enigmi visivi: codice via e-mail o passkey; la protezione antispam esegue un calcolo in
  background anziché ricorrere a un CAPTCHA.
- Leggibile con un ingrandimento fino al 200 %, senza scorrimento orizzontale sul telefono.

## 5. Limitazioni note

- **I grafici nelle statistiche** riassumono i loro valori in una didascalia; manca ancora una tabella con tutti i
  valori giornalieri.
- **I documenti PDF** — fatture e ricevute — non sono ancora contrassegnati come PDF accessibili. Su richiesta Le
  inviamo le indicazioni come testo.
- **Il trascinamento** nell’albero delle pagine e nell’editor a blocchi: dove non esiste un comando equivalente tramite
  menu o tastiera, ci stiamo lavorando.
- **I contenuti delle utenti e degli utenti** — messaggi, immagini, voci nel Marketplace — possono contenere immagini
  senza descrizione o strutture poco chiare. Lo segnaliamo negli editor.
- **I contenuti di terzi incorporati** (video) dipendono dai rispettivi fornitori; vengono caricati solo dopo il Suo
  consenso.

## 6. Riscontro e contatto

Ha incontrato una barriera o ha bisogno di contenuti in un’altra forma? Scriva a email@basaltocms.com indicando
l’indirizzo della pagina e una breve descrizione. Rispondiamo entro dieci giorni lavorativi e Le comunichiamo entro
quando risolveremo il problema o come può ottenere l’informazione in altro modo.

## 7. Applicazione

In Svizzera la legge sui disabili (LDis) vieta ai fornitori privati di discriminare le persone con disabilità. Per i
servizi nell’UE si applica l’European Accessibility Act (direttiva (UE) 2019/882); in quanto microimpresa siamo esentati
dai suoi requisiti relativi ai servizi e li rispettiamo volontariamente. Se la nostra risposta non La soddisfa, può
rivolgersi all’autorità di vigilanza del mercato del Suo Paese o a un’organizzazione per le persone con disabilità.

## 8. Come verifichiamo

- Verifiche automatiche con axe-core a ogni modifica del software e con Lighthouse sulle pagine principali di
  basaltocms.com.
- Verifiche manuali con tastiera, ingrandimento, modalità scura e schermo stretto per ogni nuova funzione.
- Controlli a campione con lettore di schermo: [[Platzhalter: eseguire la verifica con VoiceOver e NVDA e inserire la
  data]]

Tecnica: HTML, CSS e JavaScript con WAI-ARIA, dove l’HTML da solo non basta. Le pagine di contenuto funzionano anche
senza JavaScript; le impostazioni dei cookie, le funzioni in tempo reale e il backend ne hanno bisogno. Sono supportate
le versioni attuali di Chrome, Edge, Firefox e Safari.

Verifichiamo la presente dichiarazione almeno una volta all’anno e in caso di modifiche importanti.
