# Ruoli

Un **ruolo** stabilisce che cosa può fare una persona nel backend — per ogni **area** un **livello**, più le **lingue** in cui può modificare contenuti. Una persona ha esattamente un ruolo per sito; i ruoli stessi valgono per tutti i siti dell’installazione.

## Dove

Modalità «Amministrazione» › **Ruoli** (oppure, sotto «Utenti», il pulsante «Ruoli»).

![I ruoli con la matrice del ruolo «Amministratore»](bilder/rollen.webp)

A sinistra si trovano i ruoli — per ogni ruolo quante persone lo hanno, quali modalità vede («vede: Contenuti · Configurazione · Amministrazione») e quali lingue. I contrassegni indicano **«non modificabile»** (Gestore), **«personalizzato»** (un modello con modifiche proprie) o **«propria»** (creato da sé). A destra il ruolo scelto:

1. **Nome** e **Descrizione** (facoltativa) — la descrizione compare sotto il ruolo al momento dell’invito.
2. **Lingue**: **tutte**, **tutte tranne la lingua di origine** o una **Selezione**. Sotto è indicato in quali lingue il ruolo può modificare contenuti. Creare, eliminare, spostare pagine e modificarne le impostazioni richiede la lingua di origine.
3. **La matrice** — per ogni area un livello, secondo i gruppi Contenuti, Configurazione e Amministrazione. **«tutti su»** imposta un intero gruppo su «nessuno», «vedere» o «modifica». Le aree provenienti dai moduli portano «modulo».
4. **Anteprima** in basso — che cosa vede il ruolo e quante persone lo hanno. Ogni modifica si salva da sola e vale dalla richiesta successiva; compare nel [registro](protokoll.md).

| Livello | Significa |
| --- | --- |
| **nessuno** | L’area manca nel menu. |
| **vedere** | leggere senza modificare nulla |
| **modifica** | creare e modificare |
| **eliminare** | in più eliminare |
| **tutto** | in più le impostazioni delicate dell’area — per esempio il webhook di un modulo o la chiave dei suggerimenti IA |

Se un ruolo non vede alcuna area in un gruppo, manca anche la relativa modalità (per esempio «Configurazione»).

## I modelli

| Modello | Può |
| --- | --- |
| **Gestore** | tutto; non modificabile, perché nessun sito si chiuda fuori da solo. Ogni sito mantiene almeno un gestore. |
| **Amministratore** | tutto, tranne assegnare il ruolo Gestore |
| **Redattore** | pagine, media e moduli fino a «eliminare», traduzione, commenti, membri e suggerimenti IA «modifica»; nessuna configurazione, nessuna amministrazione, nessuna approvazione |
| **Approvatore** | approvazione e pubblicazione «tutto»; pagine, media, traduzione, moduli e commenti «vedere» |
| **Traduttore** | pagine e traduzione «modifica», media «vedere», suggerimenti IA «modifica» — in tutte le lingue tranne la lingua di origine |

Un modello personalizzato lo riporta allo stato di fabbrica **«Ripristina»** (tranne Gestore).

## Ruoli propri

- **«Nuovo ruolo»** crea un ruolo vuoto (non può fare nulla) — oppure una copia di uno esistente. **«Copia»** in alto a destra copia il ruolo scelto.
- **«Elimina»** rimuove un ruolo proprio, finché nessuno lo ha e nessun invito aperto è intestato a esso.

## Regole che nessuno aggira

- Modificare i ruoli richiede «Utenti e ruoli · tutto»; per visualizzarli basta «vedere».
- Chi assegna un ruolo deve avere in ogni area almeno lo stesso livello e ciascuna delle sue lingue.
- I token API agiscono con i diritti della loro persona.
- Tutto compare nel [registro](protokoll.md): creato, modificato (prima e dopo), ripristinato, eliminato e ogni assegnazione.

Maggiori dettagli per la tecnica: [Ruoli e diritti](../betrieb/rollen.md).

## Per continuare

- [Utenti](benutzer.md)
- [Ruoli e diritti](../betrieb/rollen.md)
