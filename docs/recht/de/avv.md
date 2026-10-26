# Vereinbarung zur Auftragsbearbeitung

> **Entwurf — anwaltliche Prüfung ausstehend.** Fassung 0.3 · Stand 25.10.2026 · noch nicht in Kraft.
> Massgeblich ist die deutsche Fassung; Übersetzungen dienen dem Verständnis.

## Wann diese Vereinbarung gilt

Diese Vereinbarung gilt, soweit wir Personendaten in Ihrem Auftrag bearbeiten:

1. **Basalto als SaaS** — wir betreiben Ihre Sites auf unserer Infrastruktur. Die Vereinbarung gilt für alle Inhalte,
   Benutzer, Medien, Formulareingänge, Kommentare, Kundenkonten Ihrer Besucher und Protokolle Ihrer Sites. Sie ist
   Teil des Abos und gilt mit der Registrierung.
2. **Support mit Zugriff auf Ihre Daten** — Sie geben uns Zugang zu Ihrer eigenen Installation, schicken uns eine
   Sicherung, einen Export oder Auszüge aus Protokollen, damit wir einen Fehler finden.
3. **Unterlizenzen der Agentur** — erfasst eine Agentur im Kundenkonto Namen ihrer Kunden, die natürliche Personen
   sind, bearbeiten wir sie nur, um deren Schlüssel auszustellen (AGB Ziffer 8). [[Platzhalter: Einordnung als
   Auftragsbearbeitung oder eigene Verantwortung — Frage im Prüfauftrag]]

Ihre eigene Basalto-Installation sendet uns keine Inhalte; für deren Daten sind allein Sie verantwortlich.
Kundenkonto, Abo, Rechnungen, Lizenzkauf und Marktplatz bearbeiten wir als eigene Verantwortliche nach der
[Datenschutzerklärung](datenschutz.md).

## 1. Gegenstand und Geltung

Diese Vereinbarung gilt zwischen Ihnen als Verantwortlichem («Kunde») und Basalto — ein Produkt von nguyen-development.ch, Hoang Nguyen, Einzelunternehmen, Hohfurristrasse 24, 8408 Winterthur, Schweiz
(«wir»). Sie ist Teil der [AGB](agb.md) und erfüllt Art. 9 des schweizerischen Datenschutzgesetzes
(DSG) und Art. 28 der Datenschutz-Grundverordnung der EU (DSGVO). Wo sie den AGB widerspricht, geht sie vor.

## 2. Was bearbeitet wird

**Zweck:** Betrieb Ihrer Sites in der SaaS — Speichern, Ausliefern, Sichern, Aktualisieren, Exportieren und Löschen —;
Fehlersuche und Behebung im Support; die Ausstellung von Unterlizenz-Schlüsseln.

**Art der Bearbeitung:** Erheben über Ihre Sites, Speichern, Ausliefern, Sichern und Wiederherstellen, Lesen im
Support, Exportieren, Löschen.

**Betroffene Personen:** Benutzer Ihres Backends, Besucherinnen und Besucher Ihrer Sites, Personen, die Formulare,
Kommentare oder Beiträge absenden oder ein Kundenkonto auf Ihrer Site eröffnen, Personen in Ihren Inhalten und Medien,
Kunden einer Agentur.

**Datenarten:** Namen, E-Mail-Adressen, Rollen, Anmeldedaten (Passkeys als öffentlicher Schlüssel), Protokolleinträge
mit Prüfsummen von IP-Adressen, Formulareingänge, Kommentare, Beiträge, Inhalte und Medien, anonyme oder — mit
Einwilligung Ihrer Besucher — pseudonyme Statistik. Besonders schützenswerte Personendaten bearbeiten wir nur, wenn Sie
sie selbst erfassen; Sie entscheiden, ob Ihre Sites solche Daten enthalten dürfen.

**Dauer:** in der SaaS die Laufzeit des Abos und die Frist nach Ziffer 10; im Support bis zum Abschluss des Falls,
höchstens 30 Tage; bei Unterlizenzen so lange wie die Agentur-Lizenz.

## 3. Weisungen

Wir bearbeiten die Daten nur nach Ihren Weisungen. Weisungen sind diese Vereinbarung, die AGB, Ihre Einstellungen und
Handlungen im Backend und Ihr Auftrag im Supportfall; weitere erteilen Sie schriftlich oder per E-Mail. Halten wir eine
Weisung für rechtswidrig, sagen wir es Ihnen. Ausgenommen ist, was wir von Gesetzes wegen tun müssen; dann informieren
wir Sie vorher, soweit das Gesetz es erlaubt.

## 4. Vertraulichkeit und Zugriff

Wer bei uns auf die Daten zugreift, ist zur Vertraulichkeit verpflichtet. Heute ist das der Inhaber; kommen weitere
Personen hinzu, verpflichten wir sie schriftlich. Auf Ihre Sites greifen wir nur zu, wenn es für den Betrieb oder den
Support nötig ist. Ein Support-Zugriff auf eine Site ist **nur lesend**, braucht einen Grund, endet nach höchstens
vier Stunden und steht für alle Benutzer sichtbar in einem Band im Backend und im Protokoll der Site.

## 5. Sicherheit

Wir treffen die technischen und organisatorischen Massnahmen nach Art. 8 DSG und Art. 32 DSGVO, insbesondere:

- verschlüsselte Übertragung (TLS) auf allen Wegen; Daten und Sicherungen in Rechenzentren in der Schweiz;
- Trennung der Kunden: jede Abfrage ist auf Ihr Konto und Ihre Sites beschränkt;
- Anmeldung ohne Passwort, Rollen und Rechte je Bereich, Protokoll von Änderungen und Zugriffen;
- tägliche Sicherungen, Updates mit Sicherung und Rückweg;
- Prüfsummen statt IP-Adressen in Protokollen, Geheimnisse verschlüsselt gespeichert;
- im Support: Daten nur auf verschlüsselten Datenträgern, nicht in E-Mail-Postfächern, Testumgebungen nur lokal und
  ohne Zugang aus dem Internet, Löschung nach Abschluss des Falls.

Wir passen die Massnahmen dem Stand der Technik an, ohne das Schutzniveau zu senken.

## 6. Unterauftragsbearbeiter

Sie genehmigen diese Unterauftragsbearbeiter:

| Unterauftragsbearbeiter | Leistung | Ort |
| --- | --- | --- |
| cyon AG, Basel (Schweiz) | Betrieb von Anwendung, Datenbank, Dateien und Sicherungen; Versand der E-Mails Ihrer Sites | Rechenzentren in der Schweiz |

Im Support ziehen wir keine weiteren Unterauftragsbearbeiter bei; Daten, die Sie uns schicken, laufen nur über unser
E-Mail-Postfach bei cyon und unsere Geräte. Live-Funktionen laufen ohne weiteren Dienst über Abfragen beim
Server Ihrer Site.

Einen neuen oder ersetzten Unterauftragsbearbeiter kündigen wir mindestens 30 Tage vorher per E-Mail an. Sie können
innert dieser Frist aus einem wichtigen datenschutzrechtlichen Grund widersprechen. Findet sich keine Lösung, können
Sie auf den Zeitpunkt des Wechsels kündigen; im Voraus Bezahltes erstatten wir anteilig. Wir verpflichten jeden
Unterauftragsbearbeiter auf ein gleichwertiges Schutzniveau und stehen für ihn ein wie für eigenes Handeln, im Rahmen
von AGB Ziffer 12.

## 7. Bekanntgabe ins Ausland

Wir geben Daten nur in Staaten mit angemessenem Datenschutzniveau bekannt oder mit Garantien nach Art. 16 Abs. 2 DSG
und Art. 46 DSGVO — namentlich der Zertifizierung nach dem EU-US Data Privacy Framework und dessen Swiss-US-Teil oder
den Standardvertragsklauseln der EU-Kommission. Für den Betrieb Ihrer Sites geben wir keine Daten ins Ausland bekannt:
Sie liegen bei cyon in der Schweiz.

## 8. Unterstützung

Wir unterstützen Sie, damit Sie Anfragen betroffener Personen beantworten, Verletzungen der Datensicherheit melden
und Datenschutz-Folgenabschätzungen erstellen können, soweit es um unsere Bearbeitung geht. Basalto stellt Ihnen dafür
im Backend Auskunft, Export und Löschung je Person zur Verfügung. Erreicht uns eine Anfrage einer betroffenen Person
zu Ihren Daten, leiten wir sie an Sie weiter und beantworten sie nicht selbst.

## 9. Verletzung der Datensicherheit

Stellen wir eine Verletzung der Datensicherheit fest, die Ihre Daten betrifft, melden wir sie Ihnen so rasch als
möglich, spätestens innert 48 Stunden, mit dem, was bekannt ist: Art der Verletzung, betroffene Daten und Personen,
voraussichtliche Folgen, ergriffene Massnahmen. Was später bekannt wird, reichen wir nach. Ob Aufsichtsbehörde und
betroffene Personen informiert werden, entscheiden Sie; wir helfen Ihnen dabei.

## 10. Löschung und Rückgabe

**SaaS:** Sie können jederzeit einen vollständigen Export Ihrer Sites und Ihres Kontos erstellen. Nach dem Ende des
Abos ist das Konto 30 Tage gesperrt; Sie können in dieser Zeit noch exportieren. Danach löschen wir Sites, Inhalte,
Medien und Benutzer. Aus den Sicherungen verschwinden die Daten, wenn diese überschrieben werden
([[Platzhalter: Aufbewahrung der Sicherungen bei cyon]]). Rechnungen bewahren wir nach dem Gesetz auf.

**Support:** Nach Abschluss eines Falls löschen wir die Daten, die Sie uns dafür gegeben haben, spätestens nach 30
Tagen, und bestätigen es Ihnen auf Wunsch. **Unterlizenzen:** Die Angaben löschen wir mit dem Ende der Agentur-Lizenz,
soweit wir sie nicht als Nachweis zum Schlüssel aufbewahren müssen.

## 11. Nachweise und Überprüfungen

Wir stellen Ihnen auf Anfrage die Angaben zur Verfügung, die Sie brauchen, um die Einhaltung dieser Vereinbarung zu
belegen — vorrangig schriftlich, für die Rechenzentren über die Berichte und Zertifikate der Unterauftragsbearbeiter.
Eine Überprüfung vor Ort ist nur bei begründetem Anlass möglich, mit 30 Tagen Vorankündigung, während der
Geschäftszeiten, ohne Zugriff auf Daten anderer Kunden und durch eine zur Vertraulichkeit verpflichtete Person. Die
Kosten tragen Sie, ausser die Prüfung deckt einen wesentlichen Verstoss auf.

## 12. Haftung und Schluss

Für die Haftung gilt AGB Ziffer 12. Im Übrigen gelten die AGB, auch zu anwendbarem Recht und Gerichtsstand.
