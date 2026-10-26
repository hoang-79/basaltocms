# Autoren-Bedingungen für den Marktplatz

> **Entwurf — anwaltliche Prüfung ausstehend.** Fassung 0.3 · Stand 25.10.2026 · noch nicht in Kraft.
> Massgeblich ist die deutsche Fassung; Übersetzungen dienen dem Verständnis.

Diese Bedingungen gelten für alle, die Themes und Plugins im Marktplatz auf basaltocms.com anbieten («Autorin» oder
«Autor»). Daneben gelten die [AGB](agb.md) für das Kundenkonto und die [Datenschutzerklärung](datenschutz.md).

## Die Regeln in Kürze

| Regel | Was gilt |
| --- | --- |
| Kostenlose Einträge | ohne Provision |
| Bezahlte Einträge | 10 Prozent Provision vom Preis ohne Mehrwertsteuer, Auszahlung über Stripe Connect (Ziffer 7) |
| Prüfung | jede Version wird vor der Veröffentlichung automatisch geprüft und von Hand durchgesehen |
| Auszahlung | über Stripe Connect, direkt auf das Konto der Autorin oder des Autors |
| Lizenz | je Eintrag wählbar |

## 1. Rollen

Basalto — ein Produkt von nguyen-development.ch, Hoang Nguyen, Einzelunternehmen, Winterthur («wir»), betreibt den
Marktplatz. Wir stellen Katalog,
Prüfung und Composer-Repository bereit. Wir sind nicht Urheberin der Einträge und nicht Partei der Lizenz, unter der
Sie Ihren Eintrag anbieten; diese gilt zwischen Ihnen und den Nutzerinnen und Nutzern.

## 2. Autorenkonto

Sie brauchen ein Kundenkonto und schalten darin das Autorenprofil frei. Sie geben Ihren Namen oder den Ihrer Firma
wahrheitsgemäss an; er erscheint öffentlich bei Ihren Einträgen. Sie sind mindestens 18 Jahre alt oder handeln für
ein Unternehmen, für das Sie zeichnen dürfen. Eine Kontaktadresse für Nutzerinnen und Nutzer geben Sie an und halten
sie aktuell.

## 3. Einreichen, Prüfen, Veröffentlichen

1. Sie reichen ein Theme oder Plugin als Paket mit Manifest ein. Jede neue Version wird wie die erste behandelt.
2. **Automatische Prüfung:** Manifest und Abhängigkeiten, Lava-Prüfung bei Themes, Tests des Pakets, Signatur und
   Prüfsumme. Ein Fehler hält die Version an; Sie sehen den Befund.
3. **Durchsicht von Hand:** Wir sehen jede Version vor der Veröffentlichung durch — namentlich auf Schadcode,
   versteckte Verbindungen nach aussen, Umgehung des Lizenzmoduls und offensichtliche Rechtsverstösse.
4. Wir veröffentlichen eine Version erst, wenn beide Prüfungen bestanden sind. Einen Anspruch auf Veröffentlichung
   gibt es nicht; lehnen wir ab, nennen wir den Grund.
5. Die Prüfung schützt die Nutzerinnen und Nutzer, sie entlastet Sie nicht: Für Ihren Code bleiben Sie verantwortlich
   (Ziffer 6). Dass wir eine Version veröffentlichen, ist keine Zusicherung, dass sie sicher, fehlerfrei oder
   rechtmässig ist.

## 4. Lizenz je Eintrag

Sie wählen für jeden Eintrag die Lizenz, unter der Nutzerinnen und Nutzer ihn verwenden dürfen:

- eine anerkannte Open-Source-Lizenz, angegeben als SPDX-Kennung im Manifest (etwa `MIT`), oder
- die Marktplatz-Lizenz, die wir als Vorlage bereitstellen [[Platzhalter: Vorlage der Marktplatz-Lizenz — Entscheid
  E-6 im Prüfauftrag]], oder
- eine eigene Lizenz, deren vollständiger Text dem Paket beiliegt.

Die Lizenz gilt für die Version, die jemand bezieht; ändern Sie sie, gilt die neue für neue Versionen. Ihr Eintrag
darf keinen Code von Basalto enthalten, der über die dokumentierten Schnittstellen hinausgeht (Lizenzvertrag § 7),
und Sie stellen sicher, dass die gewählte Lizenz mit den Lizenzen eingebundener Fremdpakete vereinbar ist.

## 5. Rechte, die Sie uns einräumen

Sie räumen uns für die Dauer der Veröffentlichung das nicht ausschliessliche, unentgeltliche, weltweite Recht ein,
Ihren Eintrag zu speichern, zu prüfen, zu vervielfältigen, über das Composer-Repository bereitzustellen und mit
Namen, Logo, Beschreibung und Bildschirmfotos im Katalog und in unserer Kommunikation über den Marktplatz zu zeigen.
Versionen, die bereits installiert sind, dürfen wir auch nach dem Entfernen des Eintrags für bestehende
Installationen bereithalten, ohne sie neu anzubieten — ausser bei einer Sicherheitslücke oder einer Rechtsverletzung.

## 6. Ihre Pflichten und Zusicherungen

Sie sichern zu und sorgen dafür, dass

1. Sie alle Rechte an Ihrem Eintrag haben — an Code, Bildern, Schriften und Texten — und er keine Rechte Dritter
   verletzt;
2. Ihr Eintrag keinen Schadcode enthält, keine Daten ohne Wissen der Betreiberin oder des Betreibers einer Site
   überträgt und jede Verbindung nach aussen im Manifest und in der Beschreibung nennt; Telemetrie gibt es nur mit
   ausdrücklicher Einwilligung in der Installation;
3. Ihr Eintrag das Lizenzmodul von Basalto nicht umgeht, seine Hinweise nicht entfernt und keine Lizenzschlüssel
   erzeugt;
4. die Beschreibung zutrifft — Funktionen, Voraussetzungen, Version von Basalto, Lizenz und, bei bezahlten Einträgen,
   Support und Updates;
5. Sie uns eine bekannte Sicherheitslücke unverzüglich melden und sie in angemessener Frist beheben;
6. Ihr Eintrag das Datenschutzrecht einhält, soweit er Personendaten bearbeitet, und die Betreiberin oder den Betreiber
   einer Site darüber informiert.

Sie stellen uns von Ansprüchen Dritter frei, die auf Ihrem Eintrag oder einem Verstoss gegen diese Bedingungen
beruhen, samt angemessener Kosten der Abwehr.

## 7. Bezahlte Einträge, Provision und Auszahlung

Der Marktplatz nimmt kostenlose und bezahlte Einträge an. Für bezahlte Einträge gilt:

1. **Kostenlose Einträge** bleiben ohne Provision und ohne Gebühr.
2. **Preis:** Sie bestimmen den Preis eines bezahlten Eintrags; basaltocms.com zeigt ihn in CHF, EUR und USD.
3. **Provision:** Wir erhalten 10 Prozent des bezahlten Preises ohne Mehrwertsteuer. Die Gebühren von Stripe trägt der
   Marktplatz. Eine andere Provision kündigen wir allen Autorinnen und Autoren mindestens 30 Tage vorher per E-Mail an;
   sie gilt für Käufe ab dem angekündigten Datum. Sind Sie nicht einverstanden, können Sie Ihre bezahlten Einträge bis
   dahin zurückziehen oder kostenlos anbieten.
4. **Auszahlung:** über Stripe Connect. Sie eröffnen ein verbundenes Konto bei Stripe und nehmen dessen Bedingungen
   an; Stripe prüft Ihre Identität. Stripe zahlt Ihren Anteil nach dem Zahlungsplan von Stripe aus; wir halten kein
   Geld für Sie.
5. **Verkäufer:** Die Lizenz verkaufen Sie selbst, in Ihrem Namen und auf Ihre Rechnung; wir vermitteln den Verkauf,
   nehmen die Zahlung über Stripe entgegen und behalten die Provision ein. Die Quittung nennt Sie als Verkäufer.
   [[Platzhalter: Mehrwertsteuer im Marktplatz mit der Steuerberatung klären — heute weist die Quittung die
   Mehrwertsteuer des Marktplatzes aus]]
6. **Rückgabe und Rückbuchung:** Nutzerinnen und Nutzer können einen bezahlten Eintrag innert 14 Tagen zurückgeben,
   wie eine Lizenz (AGB Ziffer 11). Bei einer Rückgabe oder Rückbuchung wird Ihr Anteil zurückbelastet; die
   Provision erstatten wir anteilig.
7. **Steuern:** Für Ihre Einkommen und Steuern sind Sie selbst verantwortlich.
8. **Support:** Für bezahlte Einträge leisten Sie den Support, den die Beschreibung verspricht. Wir leisten keinen
   Support für Einträge Dritter.

## 8. Meldungen, Entfernen und Sperren

Jede Person kann uns rechtswidrige Inhalte oder Sicherheitslücken in einem Eintrag melden, an
email@basaltocms.com. Wir prüfen jede Meldung, informieren Sie und entscheiden begründet.

Wir dürfen eine Version oder einen Eintrag ausblenden, sperren oder entfernen, wenn er gegen diese Bedingungen oder
das Gesetz verstösst, eine Sicherheitslücke nicht behoben wird oder ein begründeter Anspruch Dritter vorliegt. Ausser
bei Gefahr im Verzug sagen wir es Ihnen vorher und geben Ihnen Gelegenheit zur Stellungnahme. Gegen einen Entscheid
können Sie innert 30 Tagen Einspruch einlegen; eine andere Person als die, die entschieden hat, prüft ihn erneut
[[Platzhalter: Wer prüft, solange Nguyen Development eine Ein-Personen-Firma ist?]].

## 9. Haftung

Für Ihren Eintrag haften Sie. Für den Marktplatz gilt AGB Ziffer 12: Wir haften für Vorsatz und grobe Fahrlässigkeit;
für leichte Fahrlässigkeit nur für unmittelbare Schäden bis zur Höhe der Provision, die wir in den zwölf Monaten vor
dem Ereignis von Ihnen erhalten haben. Für entgangene Verkäufe wegen eines Ausfalls oder einer Prüfung haften wir
nicht.

## 10. Laufzeit und Beendigung

Sie können einen Eintrag jederzeit zurückziehen und das Autorenprofil schliessen. Wer einen Eintrag bezogen hat,
behält die Rechte aus dessen Lizenz. Wir können diese Bedingungen mit einer Frist von 30 Tagen beenden, aus wichtigem
Grund sofort.

## 11. Änderungen

Änderungen kündigen wir mindestens 30 Tage vorher per E-Mail an. Sind Sie nicht einverstanden, können Sie Ihre
Einträge bis zum Wirksamwerden zurückziehen; darauf weisen wir in der Ankündigung hin.

## 12. Schlussbestimmungen

Es gilt Schweizer Recht unter Ausschluss des Kollisionsrechts und des UN-Kaufrechts. Gerichtsstand ist Winterthur.
Zwingende Verbraucherschutzvorschriften bleiben vorbehalten. Massgeblich ist die deutsche Fassung; die anderen sind
Übersetzungen zu Ihrer Information.
