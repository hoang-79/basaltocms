# Condizioni per gli autori del Marketplace

> **Bozza — verifica legale in sospeso.** Versione 0.3 · Stato 25.10.2026 · non ancora in vigore.
> Fa fede la versione tedesca; le traduzioni servono alla comprensione.

Le presenti condizioni si applicano a tutti coloro che offrono Theme e Plugin nel Marketplace su basaltocms.com («autrice» o «autore»). Si applicano inoltre le [CG](agb.md) per il conto cliente e l’[Informativa sulla protezione dei dati](datenschutz.md).

## Le regole in breve

| Regola | Che cosa vale |
| --- | --- |
| Voci gratuite | senza commissione |
| Voci a pagamento | commissione del 10 per cento sul prezzo IVA esclusa, pagamento tramite Stripe Connect (cifra 7) |
| Verifica | ogni versione viene verificata automaticamente ed esaminata manualmente prima della pubblicazione |
| Versamento | tramite Stripe Connect, direttamente sul conto dell’autrice o dell’autore |
| Licenza | a scelta per ciascuna voce |

## 1. Ruoli

Basalto — un prodotto di nguyen-development.ch, Hoang Nguyen, ditta individuale, Winterthur («noi»), gestisce il Marketplace. Mettiamo a disposizione il catalogo, la verifica e il repository Composer. Non siamo autori delle voci né parte della licenza con cui offrite la vostra voce; questa vale tra voi e le utenti e gli utenti.

## 2. Conto autore

Vi occorre un conto cliente, nel quale attivate il profilo d’autore. Indicate in modo veritiero il vostro nome o quello della vostra azienda; esso compare pubblicamente accanto alle vostre voci. Avete almeno 18 anni oppure agite per un’impresa per la quale avete diritto di firma. Indicate un indirizzo di contatto per le utenti e gli utenti e lo mantenete aggiornato.

## 3. Invio, verifica, pubblicazione

1. Inviate un Theme o un Plugin come pacchetto con manifest. Ogni nuova versione è trattata come la prima.
2. **Verifica automatica:** manifest e dipendenze, verifica Lava per i Theme, test del pacchetto, firma e somma di controllo. Un errore blocca la versione; potete vedere l’esito.
3. **Esame manuale:** esaminiamo ogni versione prima della pubblicazione — in particolare per individuare codice malevolo, connessioni nascoste verso l’esterno, elusione del modulo di licenza e violazioni manifeste della legge.
4. Pubblichiamo una versione solo quando ha superato entrambe le verifiche. Non sussiste alcun diritto alla pubblicazione; se la rifiutiamo, ne indichiamo il motivo.
5. La verifica protegge le utenti e gli utenti, ma non vi esonera: rimanete responsabili del vostro codice (cifra 6). Il fatto che pubblichiamo una versione non costituisce una garanzia che essa sia sicura, priva di errori o lecita.

## 4. Licenza per ciascuna voce

Per ogni voce scegliete la licenza con cui le utenti e gli utenti possono utilizzarla:

- una licenza open source riconosciuta, indicata come identificativo SPDX nel manifest (ad esempio `MIT`), oppure
- la licenza Marketplace che mettiamo a disposizione come modello [[Platzhalter: modello della licenza Marketplace — decisione E-6 nel mandato di verifica]], oppure
- una licenza propria, il cui testo completo è allegato al pacchetto.

La licenza vale per la versione che una persona ottiene; se la modificate, la nuova licenza vale per le nuove versioni. La vostra voce non può contenere codice di Basalto che vada oltre le interfacce documentate (Contratto di licenza § 7) e vi assicurate che la licenza scelta sia compatibile con le licenze dei pacchetti di terzi incorporati.

## 5. Diritti che ci concedete

Ci concedete, per la durata della pubblicazione, il diritto non esclusivo, gratuito e valido in tutto il mondo di memorizzare, verificare e riprodurre la vostra voce, di metterla a disposizione tramite il repository Composer e di mostrarla con nome, logo, descrizione e schermate nel catalogo e nella nostra comunicazione relativa al Marketplace. Le versioni già installate possiamo tenerle a disposizione delle installazioni esistenti anche dopo la rimozione della voce, senza offrirle nuovamente — salvo in caso di lacuna di sicurezza o di violazione del diritto.

## 6. I vostri obblighi e garanzie

Garantite e provvedete affinché

1. deteniate tutti i diritti sulla vostra voce — su codice, immagini, caratteri e testi — e questa non violi diritti di terzi;
2. la vostra voce non contenga codice malevolo, non trasmetta dati all’insaputa della gestrice o del gestore di un sito e indichi ogni connessione verso l’esterno nel manifest e nella descrizione; la telemetria è ammessa solo con consenso esplicito nell’installazione;
3. la vostra voce non eluda il modulo di licenza di Basalto, non ne rimuova gli avvisi e non generi chiavi di licenza;
4. la descrizione sia corretta — funzioni, requisiti, versione di Basalto, licenza e, per le voci a pagamento, supporto e aggiornamenti;
5. ci segnaliate senza indugio una lacuna di sicurezza nota e la eliminiate entro un termine adeguato;
6. la vostra voce rispetti il diritto in materia di protezione dei dati, nella misura in cui tratta dati personali, e ne informi la gestrice o il gestore di un sito.

Ci tenete indenni da pretese di terzi fondate sulla vostra voce o su una violazione delle presenti condizioni, comprese le spese adeguate di difesa.

## 7. Voci a pagamento, commissione e versamento

Il Marketplace accetta voci gratuite e a pagamento. Per le voci a pagamento vale quanto segue:

1. **Le voci gratuite** restano senza commissione e senza tasse.
2. **Prezzo:** Stabilite voi il prezzo di una voce a pagamento; basaltocms.com lo indica in CHF, EUR e USD.
3. **Commissione:** Riceviamo il 10 per cento del prezzo pagato, IVA esclusa. Le spese di Stripe sono a carico del Marketplace. Annunciamo una commissione diversa a tutte le autrici e a tutti gli autori per e-mail con almeno 30 giorni di anticipo; essa si applica agli acquisti a partire dalla data annunciata. Se non siete d’accordo, potete ritirare le vostre voci a pagamento fino a tale data oppure offrirle gratuitamente.
4. **Versamento:** tramite Stripe Connect. Aprite un conto collegato presso Stripe e ne accettate le condizioni; Stripe verifica la vostra identità. Stripe vi versa la vostra quota secondo il proprio piano di versamento; noi non deteniamo denaro per vostro conto.
5. **Venditore:** La licenza la vendete voi stessi, in nome vostro e per vostro conto; noi fungiamo da intermediari nella vendita, riceviamo il pagamento tramite Stripe e tratteniamo la commissione. La ricevuta indica voi come venditore. [[Platzhalter: chiarire con la consulenza fiscale l’IVA nel Marketplace — oggi la ricevuta indica l’IVA del Marketplace]]
6. **Restituzione e storno:** Le utenti e gli utenti possono restituire una voce a pagamento entro 14 giorni, come una licenza (CG cifra 11). In caso di restituzione o di storno la vostra quota viene riaddebitata; la commissione la rimborsiamo pro rata.
7. **Imposte:** Dei vostri redditi e delle vostre imposte siete responsabili voi stessi.
8. **Supporto:** Per le voci a pagamento prestate il supporto promesso nella descrizione. Noi non prestiamo supporto per voci di terzi.

## 8. Segnalazioni, rimozione e blocco

Chiunque può segnalarci contenuti illeciti o lacune di sicurezza in una voce, a email@basaltocms.com. Esaminiamo ogni segnalazione, vi informiamo e prendiamo una decisione motivata.

Possiamo nascondere, bloccare o rimuovere una versione o una voce se viola le presenti condizioni o la legge, se una lacuna di sicurezza non viene eliminata o se sussiste una pretesa fondata di terzi. Salvo in caso di pericolo nel ritardo, ve lo comunichiamo preventivamente e vi diamo la possibilità di prendere posizione. Contro una decisione potete presentare opposizione entro 30 giorni; una persona diversa da quella che ha deciso la riesamina [[Platzhalter: chi effettua il riesame finché Nguyen Development è un’impresa composta da una sola persona?]].

## 9. Responsabilità

Della vostra voce rispondete voi. Per il Marketplace si applica la cifra 12 delle CG: rispondiamo per dolo e colpa grave; per colpa lieve solo dei danni diretti fino all’importo della commissione che abbiamo ricevuto da voi nei dodici mesi precedenti l’evento. Non rispondiamo delle mancate vendite dovute a un’interruzione o a una verifica.

## 10. Durata e cessazione

Potete ritirare una voce e chiudere il profilo d’autore in qualsiasi momento. Chi ha ottenuto una voce conserva i diritti derivanti dalla relativa licenza. Possiamo porre fine alle presenti condizioni con un preavviso di 30 giorni e, per motivi gravi, con effetto immediato.

## 11. Modifiche

Annunciamo le modifiche per e-mail con almeno 30 giorni di anticipo. Se non siete d’accordo, potete ritirare le vostre voci fino all’entrata in vigore; lo indichiamo nell’annuncio.

## 12. Disposizioni finali

Si applica il diritto svizzero, con esclusione delle norme sui conflitti di leggi e della Convenzione delle Nazioni Unite sui contratti di compravendita internazionale di merci. Il foro competente è Winterthur. Sono fatte salve le disposizioni imperative a tutela dei consumatori. Fa fede la versione tedesca; le altre sono traduzioni per vostra informazione.
