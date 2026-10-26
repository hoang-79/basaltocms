# Datenschutzerklärung

> **Entwurf — anwaltliche Prüfung ausstehend.** Fassung 0.3 · Stand 25.10.2026 · noch nicht in Kraft.
> Massgeblich ist die deutsche Fassung; Übersetzungen dienen dem Verständnis.

Diese Erklärung beschreibt, was wir mit Personendaten tun: auf basaltocms.com und seinen Subdomains (Website,
Dokumentation, Demo, Community, Marktplatz), im Kundenkonto, beim Lizenzkauf, in der SaaS und bei der Update-Prüfung
der Software. Sie richtet sich nach dem schweizerischen Datenschutzgesetz (DSG) und, für Personen in der EU, nach
der Datenschutz-Grundverordnung (DSGVO).

Für Websites, die mit Basalto betrieben werden, ist verantwortlich, wer sie betreibt. Betreiben wir eine Site für Sie
in der SaaS, bearbeiten wir deren Daten in Ihrem Auftrag nach der [Vereinbarung zur Auftragsbearbeitung](avv.md).

## 1. Wer verantwortlich ist

Basalto — ein Produkt von nguyen-development.ch, Hoang Nguyen, Einzelunternehmen, Hohfurristrasse 24, 8408 Winterthur, Schweiz.
Datenschutz-Kontakt: email@basaltocms.com.

Vertretung in der EU (Art. 27 DSGVO): [[Platzhalter: Vertretung in der EU benennen oder begründen, warum keine nötig
ist]]

## 2. Was die Software an uns sendet

Eine Basalto-Installation prüft ihren Lizenzschlüssel offline und überträgt nichts darüber, was auf einer Site
geschieht — keine Inhalte, keine Besucher, keine Benutzer. Mit basaltocms.com verbindet sie sich nur in diesen
Fällen:

- **Prüfung auf Updates** — einmal am Tag und wenn jemand in der Verwaltung «Jetzt prüfen» wählt. Dabei sehen wir die
  IP-Adresse des Servers und die Version von Basalto. Ist die Einstellung **«Nutzungsangaben an den Hersteller»** an
  (ab Werk an), schickt die Installation zusätzlich eine zufällige Kennung, die PHP-Version und die Art der Datenbank
  mit. Die Kennung entsteht bei der ersten Prüfung und hängt an nichts; wir speichern nur eine Prüfsumme davon,
  höchstens einmal am Tag, mit Version, PHP und Datenbank, und zählen damit aktive Installationen. Die IP-Adresse
  speichern wir dabei nicht. Ausschalten unter Verwaltung › Einstellungen › Betrieb.
- **Updates und Downloads** — beim Herunterladen von Web-Installer, Release-Archiv oder einem Update. Wir zählen den
  Download mit Art, Version und Weg, ohne IP-Adresse.
- **Marktplatz** — installiert jemand ein Theme oder Plugin, fragt die Installation mit dem persönlichen
  Zugangsschlüssel des Kundenkontos an (Ziffer 9).
- **Verbindung mit dem Kundenkonto** — wer die Installation mit seinem Kundenkonto verbindet, um Lizenzen und
  Rechnungen im eigenen `/admin` zu sehen, löst eine Abfrage mit einem Zugangsschlüssel aus (Ziffer 5).

Laden Sie Basalto über GitHub oder Packagist, gelten deren Datenschutzerklärungen.

## 3. Besuch der Website

Bei jedem Aufruf verarbeitet unser Hostinganbieter cyon Ihre IP-Adresse, die aufgerufene Adresse,
Zeitpunkt, Browserkennung und die Antwort, um die Seite auszuliefern, Fehler zu finden und Angriffe abzuwehren.
Anmeldeversuche, Formulare und Abfragen begrenzen wir je IP-Adresse; diese Zähler verfallen nach höchstens einer
Stunde. Formulare und Anmeldungen prüfen wir mit einer kleinen Rechenaufgabe in Ihrem Browser statt mit einem
Bilderrätsel; dabei geht nichts an Dritte.

## 4. Statistik und Cookie-Einstellungen

Wir zählen die Nutzung von basaltocms.com selbst, ohne Analyse- oder Werbedienste Dritter, ohne Profile und ohne
Weitergabe.

- **Ohne Einwilligung** zählen wir jeden Seitenaufruf anonym: aufgerufene Seite (ohne Parameter), Herkunft (nur der
  Name der verweisenden Website und Kampagnenangaben aus der Adresse), Browser, Betriebssystem und Gerätetyp, Sprache,
  Antwortzeit und ob Sie angemeldet sind. Keine IP-Adresse, keine Cookies,
  keine Kennung — ein Aufruf lässt sich keiner Person zuordnen.
- **Mit Ihrer Einwilligung** in die Kategorie «Statistik» setzt Ihr Browser zusätzlich zwei Cookies mit zufälligen
  Kennungen (`basalto_besucher` für ein Jahr, `basalto_sitzung` für 30 Minuten). Damit erkennen wir wiederkehrende
  Besuche und zusammenhängende Sitzungen — etwa wie viele Seiten ein Besuch umfasst. Die Kennungen verraten nicht, wer
  Sie sind.
- Sendet Ihr Browser das Signal «Global Privacy Control», behandeln wir das wie ein Nein.
- Seitenaufrufe löschen wir nach 400 Tagen.

Ihre Wahl speichern wir im Cookie `basalto_consent` für ein Jahr. Sie ändern sie jederzeit über den Link
«Cookie-Einstellungen» unten auf jeder Seite; ein Widerruf gilt für die Zukunft.

### Cookies und Speicher im Browser

| Name | Zweck | Dauer | Art |
| --- | --- | --- | --- |
| `basalto-session` | Sitzung, Anmeldung | 2 Stunden nach dem letzten Aufruf | notwendig |
| `XSRF-TOKEN` | Schutz von Formularen vor Missbrauch | wie die Sitzung | notwendig |
| `basalto_consent` | Ihre Wahl in den Cookie-Einstellungen | 1 Jahr | notwendig |
| `basalto_community_terms` | Ihre Zustimmung zu den Bedingungen der Community als Gast (Site und Fassung) | 1 Jahr | notwendig |
| `basalto_besucher` | zufällige Kennung für wiederkehrende Besuche | 1 Jahr | Statistik, nur mit Einwilligung |
| `basalto_sitzung` | zufällige Kennung einer Sitzung | 30 Minuten | Statistik, nur mit Einwilligung |

Im Speicher Ihres Browsers (nicht als Cookie, nie an uns übertragen) merken wir uns auf Ihren Wunsch: hellen oder
dunklen Modus, ausgeblendete Hinweise, Entwürfe von Beiträgen im Forum, zum Zitieren gemerkte Beiträge und ob Sie
Desktop-Benachrichtigungen wünschen. Sie löschen diese Angaben mit den Website-Daten Ihres Browsers.

Eingebettete Inhalte Dritter (etwa Videos) laden erst nach Ihrer Einwilligung in die Kategorie «Externe Inhalte»;
dann gelten die Datenschutzerklärungen ihrer Anbieter.

## 5. Kundenkonto und Anmeldung

**Kundenkonto:** E-Mail-Adresse, Name, Sprache und — freiwillig — Firma und Adresse. Wir halten fest, wer welche
Fassung von Lizenzvertrag, AGB und Nutzungsbedingungen wann angenommen hat.

**Anmeldung mit Code:** Wir schicken einen Code an Ihre E-Mail-Adresse. Den Code speichern wir nur als Prüfsumme,
die IP-Adresse der Anfrage als Prüfsumme mit unserem Schlüssel; abgelaufene Codes löschen wir nach einem Tag.

**Passkey:** Ihr Gerät erzeugt ein Schlüsselpaar. Wir speichern nur den öffentlichen Schlüssel, eine Kennung, den
Namen, den Sie dem Passkey geben, und wann er zuletzt verwendet wurde. Fingerabdruck oder Gesichtsbild verlassen Ihr
Gerät nie.

**Anmeldung über ein anderes Konto:** Wählen Sie Google, Microsoft, GitHub oder LinkedIn, leitet Ihr Browser Sie zum
Anbieter. Wir erhalten von dort eine Kennung, Ihren Namen und — nur wenn der Anbieter sie bestätigt hat — Ihre
E-Mail-Adresse, bei Microsoft zusätzlich die Kennung der Organisation. Wir speichern Anbieter, Kennung, E-Mail-Adresse
und den Zeitpunkt der letzten Anmeldung, nicht aber Ihr Passwort beim Anbieter oder weitere Daten aus Ihrem Konto
dort. Die Anbieter sind für ihren Teil selbst verantwortlich:
[Google](https://policies.google.com/privacy), [Microsoft](https://privacy.microsoft.com/),
[GitHub](https://docs.github.com/site-policy/privacy-policies/github-general-privacy-statement),
[LinkedIn](https://www.linkedin.com/legal/privacy-policy). Verknüpfungen trennen Sie im Kundenkonto unter «Anmeldung».

**Sitzung:** Ihre Sitzung enthält IP-Adresse und Browserkennung und endet zwei Stunden nach dem letzten Aufruf.

**Verbundene Installationen:** Verbinden Sie eine Installation mit Ihrem Kundenkonto, speichern wir deren Adresse
(Host), einen frei wählbaren Namen, eine Prüfsumme des Zugangsschlüssels und wann sie zuletzt angefragt hat. Sie
trennen die Verbindung im Kundenkonto oder in der Installation.

## 6. Lizenzkauf und Zahlung

**Lizenzen:** Rechnungsadresse, Lizenznehmer, Produktions-Domains, Stufe, ausgestellte Schlüssel, Rechnungen und
Zahlungen. Lizenznehmer und Domains stehen im Schlüssel selbst und sind für jede Person lesbar, die den Schlüssel
sieht. Bei einer Agentur-Lizenz erfassen Sie Name und Domain Ihrer Kunden für deren Unterlizenz.

**Zahlung mit Karte und anderen Mitteln** läuft über Stripe. Kartendaten gehen direkt an Stripe; wir sehen und
speichern sie nie. Wir erhalten den Zahlungsstand, den Betrag, die Art und die letzten Ziffern des Zahlungsmittels.
**Bei der QR-Rechnung** erhalten wir von unserer Bank Name, Konto und Betrag der Zahlung.

## 7. SaaS

Für ein Konto in der SaaS bearbeiten wir als Verantwortliche: Name und E-Mail-Adressen der Personen im Konto mit ihren
Rollen, Firma, Rechnungsadresse, Plan, Abo, Rechnungen und Zahlungen, Domains, den Zeitpunkt der Zustimmung zu AGB
und Datenschutzerklärung bei der Registrierung sowie Kündigung, Export und Löschung.

Die Inhalte Ihrer Sites — Seiten, Medien, Benutzer, Formulareingänge, Kommentare und Kundenkonten Ihrer Besucher —
bearbeiten wir nur in Ihrem Auftrag nach der [Vereinbarung zur Auftragsbearbeitung](avv.md). Für Hilfe kann unser Team
eine Site für höchstens vier Stunden **nur lesend** öffnen; der Grund steht in einem Band in Ihrem Backend und im
Protokoll Ihrer Site.

## 8. Community

**Profil:** Anzeigename, auf Wunsch Profilbild, Titelbild, Signatur und weitere Angaben, die Sie selbst eintragen.
Das Profil, Ihre Beiträge, Reaktionen und Ihr Rang sind öffentlich sichtbar und können von Suchmaschinen gefunden
werden. Wie viele Beiträge Sie geschrieben haben und wann Sie zuletzt aktiv waren, zeigt das Profil ebenfalls.

**Beiträge:** Text, Anhänge, Bearbeitungen mit ihrem Verlauf, Umfragestimmen, Reaktionen und als hilfreich markierte
Antworten. Zu jedem Beitrag speichern wir eine Prüfsumme Ihrer IP-Adresse, um Spam und Missbrauch abzuwehren.

**Was nur Sie sehen:** gelesene Themen, Abos, gemerkte Beiträge, wem Sie folgen und wen Sie ignorieren oder
blockieren. **Private Konversationen** sehen nur die Beteiligten; Moderatorinnen und Moderatoren sehen eine Nachricht
nur, wenn jemand sie meldet.

**Live-Funktionen:** Während Sie einen Beitrag schreiben, sehen andere im selben Thema für einige Sekunden «… schreibt
gerade» mit Ihrem Anzeigenamen. Neue Beiträge und Benachrichtigungen erscheinen ohne Neuladen.

**Bedingungen:** Bevor Sie die Community nutzen, stimmen Sie den Nutzungsbedingungen und den Community-Richtlinien zu.
Mit Konto speichern wir Fassung, Datum und Uhrzeit Ihrer Zustimmung und eines Widerrufs; Sie sehen sie im
Kundenkonto und widerrufen dort. Als Gast merkt sich Ihr Browser die Zustimmung im Cookie `basalto_community_terms`.

**Moderation:** Meldungen, Verwarnungen, Sperren und jede Massnahme der Moderation halten wir mit Begründung fest.
Neue Konten können wir mit der öffentlichen Liste von Stop Forum Spam abgleichen; dabei gehen IP-Adresse und eine
Prüfsumme der E-Mail-Adresse an den Betreiber dieser Liste. Auf basaltocms.com ist dieser Abgleich ausgeschaltet;
schalten wir ihn ein, ergänzen wir diese Erklärung vorher.

**Konto schliessen:** Wir löschen Profil, Bilder und Anhänge; Beiträge bleiben ohne Namen und ohne Verbindung zu
Ihnen stehen, die Prüfsumme der IP-Adresse fällt weg (siehe [Nutzungsbedingungen](nutzungsbedingungen.md)).

## 9. Marktplatz

**Autorinnen und Autoren:** das öffentliche Autorenprofil (Name oder Firma, auf Wunsch Ort, Website und Logo), die
eingereichten Pakete mit den Ergebnissen der automatischen Prüfung und der Prüfung in einer abgeschotteten
Testinstallation, die Kommunikation zur Durchsicht sowie Verkäufe und Auszahlungen. Für bezahlte Einträge eröffnen
Sie ein Konto bei Stripe (Stripe Connect); die Prüfung Ihrer Identität und Ihres Bankkontos nimmt Stripe in eigener
Verantwortung vor.

**Käuferinnen und Käufer:** Käufe, Rechnungen, der persönliche Zugangsschlüssel zum Composer-Repository (verschlüsselt,
damit Sie ihn im Kundenkonto wieder sehen) und je Eintrag, dass und in welcher Version Sie ihn heruntergeladen haben — Grundlage des Vermerks
«installiert» bei einer Bewertung.

**Bewertungen:** Sterne, Titel, Text und Ihr Anzeigename sind öffentlich; dazu der Vermerk «gekauft» oder
«installiert». Meldungen von Bewertungen speichern wir mit Grund; bei Meldungen ohne Konto nur eine Prüfsumme, die
Mehrfachmeldungen verhindert.

## 10. E-Mails, Benachrichtigungen und Kontakt

Wir schicken E-Mails, die zum Konto gehören: Anmeldecodes, Bestätigungen, Rechnungen, Erinnerungen und Hinweise zur
Sicherheit. Benachrichtigungen aus der Community — Antworten, Erwähnungen, Zusammenfassungen — erhalten Sie in der
Glocke und, wie Sie es im Profil einstellen, per E-Mail. Einen Newsletter gibt es nicht.

Was Sie uns per E-Mail oder über das Kontaktformular schreiben, bearbeiten wir mit Name und E-Mail-Adresse, um zu
antworten. Eingänge des Kontaktformulars löschen wir nach 90 Tagen.

Kündigen Sie über «Verträge hier kündigen», bearbeiten wir Name, E-Mail-Adresse, die Angaben zum Vertrag und den
Zeitpunkt des Eingangs, um die Kündigung auszuführen, zu bestätigen und zu belegen (Vertrag und gesetzliche Pflicht).

## 11. Protokoll und Sicherheit

Änderungen im Kundenkonto, in der Moderation und in der Verwaltung von basaltocms.com halten wir mit Zeitpunkt, Konto
und einer Prüfsumme der IP-Adresse fest, zur Nachvollziehbarkeit und Sicherheit; Einträge löschen wir nach 365 Tagen.

Wir übertragen alles verschlüsselt (TLS), melden ohne Passwort an, speichern Codes und IP-Adressen nur als
Prüfsummen und Zugangsschlüssel verschlüsselt oder als Prüfsumme, sichern täglich und protokollieren Zugriffe. Vollständige Sicherheit kann niemand zusichern.
Stellen wir eine Verletzung der Datensicherheit fest, die Sie betrifft, informieren wir Sie und — wo das Gesetz es
verlangt — den EDÖB oder die zuständige Aufsichtsbehörde.

## 12. Rechtsgrundlagen

- **Vertrag** (Art. 31 Abs. 2 lit. a DSG, Art. 6 Abs. 1 lit. b DSGVO): Kundenkonto, Anmeldung, Kauf, Schlüssel,
  Rechnungen, SaaS, Marktplatz, Community, Support.
- **Gesetzliche Pflicht** (Art. 6 Abs. 1 lit. c DSGVO): Aufbewahrung von Rechnungen und Buchungsbelegen.
- **Berechtigtes Interesse** (Art. 31 Abs. 1 DSG, Art. 6 Abs. 1 lit. f DSGVO): Betrieb und Sicherheit der Website,
  Abwehr von Missbrauch und Spam, anonyme Statistik, Zählen aktiver Installationen, Prüfung eingereichter
  Erweiterungen, Moderation, Nachweis angenommener Bedingungen, Antworten auf Ihre Anfragen.
- **Einwilligung** (Art. 6 Abs. 1 lit. a DSGVO): Cookies der Statistik, eingebettete Inhalte Dritter.

Wir verkaufen keine Daten, nutzen sie nicht für Werbung und treffen keine automatisierten Einzelentscheidungen mit
rechtlicher Wirkung. Automatische Schutzmassnahmen der Community (Pause zwischen Beiträgen, Wortfilter, Prüfung im
Browser) und die automatische Prüfung im Marktplatz entscheiden nicht endgültig; darüber entscheidet ein Mensch.

## 13. Empfänger und Dienstleister

Wir setzen Dienstleister ein, die Daten in unserem Auftrag bearbeiten:

- **cyon AG, Basel (Schweiz):** Betrieb von Website, SaaS, Datenbank, Dateien und Sicherungen sowie der Versand unserer
  E-Mails, in Rechenzentren in der Schweiz. Live-Funktionen der Community und der Benachrichtigungen laufen ohne
  weiteren Dienst über Abfragen bei unserem Server.

In eigener Verantwortung bearbeiten Daten:

- **Stripe Payments Europe Ltd. (Irland)** für Zahlungen und Stripe Connect; Stripe übermittelt Daten für
  Betrugsabwehr und gesetzliche Pflichten an Stripe, Inc. (USA).
- **[[Platzhalter: Bank]]** für Zahlungseingänge auf QR-Rechnungen.
- **Google, Microsoft, GitHub und LinkedIn**, nur wenn Sie sich über sie anmelden (Ziffer 5).

Darüber hinaus geben wir Daten nur weiter, wenn das Gesetz oder eine Behörde es verlangt oder es zur Durchsetzung
unserer Rechte nötig ist.

## 14. Bekanntgabe ins Ausland

Unsere Daten liegen in der Schweiz. Ins Ausland gehen Daten nur über Stripe und — wenn Sie sie nutzen — die Anmeldung
über Google, Microsoft, GitHub oder LinkedIn; diese Empfänger haben ihren Sitz oder Konzern in den USA. Das stützen
wir auf die Zertifizierung der Empfänger nach dem EU-US Data Privacy Framework und dessen Swiss-US-Teil; fällt
sie weg, auf die Standardvertragsklauseln der EU-Kommission in der vom EDÖB anerkannten Fassung.
[[Platzhalter: Zertifizierung jedes Empfängers im DPF-Register prüfen]] Für Personen im EWR: Die EU anerkennt für die
Schweiz ein angemessenes Datenschutzniveau.

## 15. Wie lange

| Daten | Aufbewahrung |
| --- | --- |
| Kundenkonto, Profil, Verknüpfungen, Passkeys | bis Sie das Konto schliessen |
| Zustimmungen zu den Bedingungen der Community | bis Sie das Konto schliessen |
| Anmeldecodes | einen Tag nach Ablauf |
| Rechnungen und Buchungsbelege | zehn Jahre (Art. 958f OR) |
| Ausgestellte Schlüssel und angenommene Bedingungen | bis zehn Jahre nach Ende des Vertrags (Art. 127 OR) |
| SaaS-Konto nach der Kündigung | 30 Tage gesperrt, dann gelöscht; Rechnungen wie oben |
| Seitenaufrufe der Statistik, Update-Prüfungen | 400 Tage |
| Downloads | ohne Personenbezug, ohne Frist |
| Beiträge im Forum | solange sie bestehen; nach dem Schliessen des Kontos ohne Personenbezug |
| Eingänge des Kontaktformulars | 90 Tage |
| Kündigungen über «Verträge hier kündigen» | fünf Jahre nach dem Eingang (Art. 128 OR) |
| Anliegen zum Datenschutz | drei Jahre nach der Antwort |
| E-Mails an uns | zwei Jahre nach der letzten Nachricht, sofern Sie nicht Kundin oder Kunde sind |
| Protokoll | 365 Tage |
| Sicherungen | unsere eigenen täglich, die letzten sieben bleiben; dazu die Sicherungen von cyon ([[Platzhalter: Aufbewahrung der Sicherungen bei cyon]]) |

## 16. Ihre Rechte

Sie können Auskunft über Ihre Daten verlangen, sie berichtigen, löschen oder einschränken lassen und sie in einem
gängigen elektronischen Format erhalten (Art. 25, 28 und 32 DSG, Art. 15 bis 20 DSGVO). Wo wir uns auf ein
berechtigtes Interesse stützen, können Sie widersprechen (Art. 21 DSGVO); eine Einwilligung können Sie jederzeit für
die Zukunft widerrufen. Eine Nachricht an email@basaltocms.com genügt; wir antworten innert
30 Tagen. Was wir aufbewahren müssen (Ziffer 15), bleibt bis zum Ende der Frist.

Mit Kundenkonto erledigen Sie das meiste selbst unter **Kundenkonto › Datenschutz**: Ihre Daten als Datei
herunterladen, Name und Adresse berichtigen, Einwilligungen ansehen und widerrufen, Einschränkung oder Widerspruch
verlangen (mit Frist von einem Monat) und das Konto löschen.

Wenn Sie mit uns nicht weiterkommen, können Sie sich an den Eidgenössischen Datenschutz- und
Öffentlichkeitsbeauftragten wenden (edoeb.admin.ch), im EWR an die Aufsichtsbehörde Ihres Landes.

## 17. Kinder und Jugendliche

basaltocms.com richtet sich an Erwachsene und Unternehmen. Die Community steht Personen ab 16 Jahren offen. Erfahren
wir, dass ein Konto einer jüngeren Person gehört, löschen wir es.

## 18. Änderungen

Wir passen diese Erklärung an, wenn sich basaltocms.com, die Software oder die Rechtslage ändern. Oben steht der
Stand. Wesentliche Änderungen teilen wir Kundinnen und Kunden per E-Mail mit.
