# Basalto-Lizenz

> **Entwurf v0.5 vom 25.10.2026 — juristische Prüfung ausstehend (R-45).**
> Dieser Text ist noch nicht in Kraft und nicht massgeblich. Der Vertrag (ab «In Kürze») ist derselbe wie
> [docs/recht/de/lizenzvertrag.md](docs/recht/de/lizenzvertrag.md) — dort auch auf Englisch, Italienisch und
> Französisch, im Produkt unter `/_recht/<sprache>/lizenzvertrag` — und geht mit dem
> [Prüfauftrag TB 11](dokumentationen/02-feasibility/recht-pruefauftrag-tb11.md) an eine Anwältin oder einen Anwalt;
> ein Test hält beide gleich. Stellen in `[[Offen: …]]` sind bewusst nicht entschieden, die offenen Fragen zum
> Vertrag stehen im Prüfauftrag. Eine englische Übersetzung liegt in [LICENSE.en.md](LICENSE.en.md); massgeblich ist
> die deutsche Fassung.

## Geltungsbereich in diesem Repository

Das Repository enthält Teile unter verschiedenen Lizenzen (ADR-002):

| Teil                                                                                                  | Lizenz                                                                                                  |
| ----------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| Kern `packages/core` (`basalto/core`), Lava `packages/lava` (`basalto/lava`), Module unter `modules/` | **diese Basalto-Lizenz** (SPDX `LicenseRef-Basalto`)                                                    |
| Anwendungsgerüst (Skeleton) im Wurzelordner, künftig `basalto/basalto`                                | MIT, damit `composer create-project` ohne Lizenzfrage startet                                           |
| Themes und Werkzeuge mit eigener Lizenzangabe                                                         | die Lizenz in ihrer `theme.json` bzw. `package.json` (z. B. `themes/starter`, `tools/vscode-lava`: MIT) |
| Pakete Dritter (`vendor/`, `node_modules/`)                                                           | ihre eigenen Lizenzen                                                                                   |

[[Offen: Die `composer.json` der Pakete stehen noch auf `proprietary`, die Wurzel noch auf dem Namen des Starter-Kits — Umstellung auf `LicenseRef-Basalto` bzw. `basalto/basalto` vor der Beta (Recht § 2). Jedes Paket bekommt eine Kopie dieser Datei.]]

[[Offen: Lizenz des Themes `themes/nguyen-development` (Website von Nguyen Development, ohne `theme.json`) — eigene Rechte vorbehalten oder diese Lizenz?]]

[[Offen: § 1 zählt «mitgelieferte Themes» zur Software, das Starter-Theme trägt in `theme.json` aber MIT — eine der beiden Angaben anpassen.]]

## In Kürze (nicht Teil des Vertrags)

Sie dürfen den Quellcode von Basalto lesen, ausführen und für sich anpassen. Kostenlos ist Basalto für eine Site mit
einem Backend-Benutzer — mit allen Modulen —, für Entwicklung, Tests und Privates. Setzen Sie Basalto kommerziell mit
mehreren Benutzern oder mehreren Sites ein, brauchen Sie eine Site- oder Agentur-Lizenz. Basalto prüft den Schlüssel
offline und sendet nichts an uns. Ohne gültigen Schlüssel läuft Basalto weiter, zeigt aber Hinweise: im Backend
sofort, im Frontend nach 30 Tagen. Basalto sperrt nie eine Funktion.

## § 1 Begriffe

- **Lizenzgeberin:** Basalto — ein Produkt von nguyen-development.ch, Hoang Nguyen, Einzelunternehmen, Hohfurristrasse 24, 8408 Winterthur, Schweiz;
  Kontakt für Lizenzfragen: email@basaltocms.com.
- **Software:** Basalto in Quell- oder Buildform, soweit dieser Vertrag nach dem Geltungsbereich in `LICENSE.md` gilt:
  Kern, Module, Lava-Compiler und mitgelieferte Themes ohne eigene Lizenzangabe.
- **Installation:** eine Kopie der Software mit eigener Datenbank.
- **Site:** ein Webauftritt innerhalb einer Installation (ein Eintrag der Tabelle `sites`).
- **Produktions-Domain:** der Domainname, unter dem eine Site öffentlich erreichbar ist, mit und ohne `www`.
  Subdomains derselben Domain zählen zu ihr.
- **Backend-Benutzer:** ein Konto mit Zugang zum Backend einer Site.
- **Kommerzieller Einsatz:** Betrieb einer Site durch oder für ein Unternehmen, eine Behörde oder eine Organisation
  oder mit Erwerbsabsicht.
- **Entwicklungs- und Testinstallation:** eine Installation, die nicht öffentlich erreichbar ist oder nur der
  Erprobung dient.
- **Private Installation:** persönlicher oder familiärer Gebrauch ohne Erwerbsabsicht.
- **Lizenzschlüssel:** der von der Lizenzgeberin signierte Schlüssel mit Stufe, Lizenznehmer, Domains und Grenzen.
- **Stufe:** Free, Site oder Agentur.
- **Update-Anspruch:** der Zeitraum, in dem Sie neue Versionen beziehen dürfen.
- **Hauptversion:** alle Versionen mit derselben ersten Versionsnummer.
- **Erweiterung:** ein Theme oder Plugin, das über die dokumentierten Schnittstellen der Software arbeitet.

## § 2 Einräumung und Zustandekommen

1. Die Lizenzgeberin räumt Ihnen ein nicht ausschliessliches, nicht übertragbares Recht ein, die Software zu
   installieren, auszuführen, ihren Quellcode einzusehen und für den eigenen Gebrauch zu ändern — im Umfang Ihrer
   Stufe (§ 3 und § 4). Alle übrigen Rechte bleiben bei der Lizenzgeberin.
2. Dieser Vertrag kommt zustande, wenn Sie die Software installieren oder nutzen. Der Installer — im Browser und auf
   der Konsole — weist vor dem Abschluss der Installation auf diesen Vertrag hin und verlinkt ihn. Wer die Software
   für eine andere Person oder ein Unternehmen installiert, sichert zu, dafür berechtigt zu sein.

## § 3 Free

Ohne Lizenzschlüssel dürfen Sie die Software mit **einer Site** und **einem Backend-Benutzer** betreiben, mit allen
Modulen und ohne zeitliche Grenze: für Entwicklung und Tests, privat und für eine Ein-Personen-Site auch
kommerziell. Vereine, Bildungseinrichtungen und gemeinnützige Organisationen erhalten auf Anfrage eine kostenlose
Site-Lizenz.

## § 4 Lizenzpflicht

1. Setzen Sie die Software kommerziell mit mehr als einem Backend-Benutzer oder mit mehr als einer Site ein,
   brauchen Sie eine Site- oder Agentur-Lizenz.
2. **Site-Lizenz:** gilt für eine Produktions-Domain und bis zu drei Sites in einer Installation, mit unbegrenzt
   vielen Backend-Benutzern und Sprachen. Entwicklungs- und Testinstallationen derselben Domain sind eingeschlossen.
3. **Agentur-Lizenz:** gilt für alle Sites und Installationen, die Sie für sich oder Ihre Kunden betreiben. Sie
   dürfen je Kunde Unterlizenz-Schlüssel ausstellen; diese bleiben mit Ihrer Agentur-Lizenz verbunden. Sie dürfen
   die Software unter eigenem Auftritt anbieten (White-Label).
4. Den Lizenzschlüssel tragen Sie innert 30 Tagen ein, nachdem die Site erstmals öffentlich erreichbar ist. Ohne
   gültigen Schlüssel oder über den Grenzen Ihrer Stufe zeigt die Software Hinweise im Backend und nach 30 Tagen
   einen Hinweis in der Fussleiste des Frontends. Sie schränkt keine Funktion ein. Sie dürfen diese Hinweise nicht
   entfernen und das Lizenzmodul nicht umgehen.
5. Fällt die bisherige Site weg, dürfen Sie den Schlüssel über Ihr Kundenkonto einer anderen Domain zuordnen.
6. Site- und Agentur-Lizenzen kaufen Sie auf basaltocms.com; dafür gelten die [AGB](docs/recht/de/agb.md).

## § 5 Updates und Support

Die Site-Lizenz enthält neue Versionen während eines Jahres ab Kauf; der Update-Anspruch lässt sich verlängern. Die
Agentur-Lizenz enthält neue Versionen während ihrer Laufzeit. Sicherheitskorrekturen für die jeweils letzte
Hauptversion liefert die Lizenzgeberin während zwei Jahren nach deren Erscheinen. Nach Ablauf des Update-Anspruchs
dürfen Sie die bezogenen Versionen weiter nutzen. Support richtet sich nach dem Angebot auf basaltocms.com; zugesicherte
Antwortzeiten gibt es nicht.

## § 6 Beschränkungen

1. Sie dürfen die Software nicht weitergeben, verkaufen, vermieten, verleihen oder Dritten als gehosteten Dienst
   anbieten. Ausgenommen sind Agenturen, die Installationen für ihre Kunden betreiben und abrechnen, und Hoster, die
   Installationen ihrer Kunden betreiben, wenn der Kunde Lizenznehmer ist.
2. Änderungen für den eigenen Gebrauch sind erlaubt. Untersagt sind Änderungen am Lizenzmodul, das Entfernen von
   Urheber- und Lizenzhinweisen und die Verbreitung geänderter Fassungen.
3. Ein Backend-Konto darf nicht von mehreren Personen geteilt werden, um die Grenze der Stufe zu umgehen.
4. Die Software darf nicht für rechtswidrige Inhalte oder Handlungen eingesetzt werden.

## § 7 Erweiterungen und Beiträge

1. Erweiterungen, die Sie über die dokumentierten Schnittstellen erstellen, gehören Ihnen. Sie dürfen sie unter einer
   Lizenz Ihrer Wahl verbreiten, sofern sie keinen Code der Software enthalten, der über diese Schnittstellen
   hinausgeht, und das Lizenzmodul nicht umgehen.
2. Beiträge zum Quellcode der Software, etwa über GitHub, bringen Sie unter diesem Vertrag ein. Sie räumen der
   Lizenzgeberin das unentgeltliche, unbeschränkte Recht ein, Ihren Beitrag zu nutzen, zu ändern und unter diesem
   oder einem anderen Vertrag zu verbreiten. Ihr Urheberrecht bleibt bestehen.
3. Erweiterungen Dritter, die Sie über den Marktplatz auf basaltocms.com beziehen, unterstehen der Lizenz, die ihr Autor
   für den Eintrag gewählt hat. Diese Lizenz gilt zwischen Ihnen und dem Autor; die Lizenzgeberin ist nicht Partei
   und steht für Erweiterungen Dritter nicht ein. Für Autorinnen und Autoren gelten die
   [Autoren-Bedingungen](docs/recht/de/autoren-bedingungen.md).

## § 8 Vertrauensklausel

Veröffentlicht die Lizenzgeberin während zwölf Monaten keine stabile Version der Software, dürfen Sie die zuletzt
veröffentlichte Version unter den Bedingungen der MIT-Lizenz nutzen.

## § 9 Laufzeit, Beendigung, Rückgabe

Dieser Vertrag gilt unbefristet. Sie können ihn jederzeit beenden, indem Sie die Software nicht mehr nutzen. Die
Lizenzgeberin kann ihn bei einem Verstoss beenden, nachdem sie Ihnen den Verstoss mitgeteilt und eine Frist von
mindestens 14 Tagen zur Behebung gesetzt hat. Innert 14 Tagen nach dem Kauf können Sie eine Lizenz gegen
Rückerstattung zurückgeben (AGB § 10). Vor dem Kauf dienen die Free-Stufe und die Demo der Erprobung.

## § 10 Gewährleistung, Haftung, Rechtstexte

Die Software wird bereitgestellt, wie sie ist. Jede Gewährleistung ist ausgeschlossen, soweit das Gesetz es
zulässt. Die Lizenzgeberin haftet nur für Vorsatz und grobe Fahrlässigkeit. Die Haftung für Hilfspersonen ist
ausgeschlossen (Art. 101 Abs. 2 OR). Für mittelbare Schäden und entgangenen Gewinn wird nicht gehaftet. Die Haftung
ist auf die Lizenzgebühren beschränkt, die Sie in den zwölf Monaten vor dem Ereignis bezahlt haben. Mitgelieferte
Vorlagen für Rechtsseiten (Impressum, Datenschutz) sind Hilfen und keine Rechtsberatung; für ihren Inhalt auf Ihrer
Site sind Sie verantwortlich. Zwingende Haftung nach dem Gesetz, namentlich für Personenschäden, bleibt vorbehalten.

## § 11 Datenschutz

Der Lizenzschlüssel wird lokal geprüft. Die Software überträgt keine Inhalte und keine Angaben über Besucher oder
Benutzer Ihrer Site an die Lizenzgeberin; für die Daten auf Ihrer Site sind Sie allein verantwortlich. Einmal am Tag
fragt die Software bei basaltocms.com nach Updates; dabei sieht die Lizenzgeberin die IP-Adresse des Servers und die
Version und erhält, solange die Einstellung «Nutzungsangaben an den Hersteller» an ist, eine zufällige Kennung der
Installation, die PHP-Version und die Art der Datenbank. Die Nutzungsangaben können Sie jederzeit abschalten, die
tägliche Prüfung mit `BASALTO_UPDATES_CHECK=false`. Beziehen Sie Erweiterungen aus dem Marktplatz oder verbinden Sie
die Installation mit Ihrem Kundenkonto, erreicht die Anfrage mit Ihrem Zugangsschlüssel den Server von
basaltocms.com. Für Update-Prüfung, Kauf, Kundenkonto und Marktplatz gilt die [Datenschutzerklärung](docs/recht/de/datenschutz.md).

## § 12 Änderungen dieses Vertrags

Dieser Vertrag gilt in der Fassung, die der bezogenen Version der Software beiliegt. Die Lizenzgeberin kann ihn für
künftige Versionen ändern; Ihre Rechte an bereits bezogenen Versionen bleiben bestehen.

## § 13 Schlussbestimmungen

Es gilt Schweizer Recht unter Ausschluss des Kollisionsrechts und des UN-Kaufrechts. Gerichtsstand ist Winterthur.
Zwingende Verbraucherschutzvorschriften am Wohnsitz des Lizenznehmers bleiben vorbehalten. Massgeblich ist die
deutsche Fassung; Übersetzungen dienen dem Verständnis. Ist eine Bestimmung unwirksam, bleiben die übrigen in Kraft;
an die Stelle der unwirksamen tritt eine wirksame, die ihrem Zweck am nächsten kommt.

---

Copyright © 2026 Hoang Nguyen (nguyen-development.ch), Winterthur. Alle Rechte vorbehalten, soweit dieser Vertrag sie nicht einräumt.
