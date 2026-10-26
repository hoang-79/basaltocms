# Erklärung zur Barrierefreiheit

> **Entwurf — anwaltliche Prüfung ausstehend.** Fassung 0.2 · Stand 25.10.2026 · noch nicht in Kraft.
> Massgeblich ist die deutsche Fassung; Übersetzungen dienen dem Verständnis.

Basalto soll für alle nutzbar sein — auf basaltocms.com und in der Software, mit der andere ihre Websites bauen.
Diese Erklärung sagt, wie weit wir sind, was noch fehlt und wie Sie uns erreichen, wenn etwas nicht geht.

## 1. Unser Ziel

Wir richten uns nach den Web Content Accessibility Guidelines (WCAG) 2.2 auf Stufe AA und nach der europäischen Norm
EN 301 549. Neue Funktionen bauen wir von Anfang an so, dass sie mit Tastatur, Bildschirmleser, Vergrösserung und
eigenen Farbeinstellungen funktionieren.

## 2. Wofür diese Erklärung gilt

- **basaltocms.com** mit Dokumentation, Demo, Community, Marktplatz, Kundenkonto und Lizenzkauf;
- **die Software Basalto**: das Backend, den Editor, die Cookie-Einstellungen, die Kundenkonten im Frontend und das
  mitgelieferte Theme «starter».

Für Websites, die mit Basalto betrieben werden, ist verantwortlich, wer sie betreibt. Basalto hilft dabei — etwa mit
der Pflicht zum Alt-Text für Bilder, die sich einschalten lässt —, kann aber Inhalte und eigene Themes anderer nicht
garantieren.

## 3. Stand

basaltocms.com und Basalto sind mit den WCAG 2.2 Stufe AA **teilweise vereinbar**. Die Abweichungen stehen in
Ziffer 5. Grundlage ist eine Selbstbewertung vom 25.10.2026 (Ziffer 8).

## 4. Was wir dafür tun

- Seiten mit klarer Struktur: Überschriften in der richtigen Reihenfolge, Landmarken, Sprache je Seite und Abschnitt,
  ein Link «Zum Inhalt springen».
- Alles mit der Tastatur bedienbar, mit sichtbarem Fokus; Dialoge wie die Cookie-Einstellungen halten den Fokus und
  lassen sich mit Escape schliessen.
- Kontraste nach AA in hellem und dunklem Modus; keine Information nur über Farbe.
- Bewegung nur, wo sie etwas erklärt; mit «weniger Bewegung» im Betriebssystem ruhig.
- Formulare mit sichtbaren Beschriftungen und Fehlermeldungen beim Feld.
- Anmeldung ohne Passwort und ohne Bilderrätsel: Code per E-Mail oder Passkey; Spamschutz rechnet im Hintergrund statt
  mit einem CAPTCHA.
- Bis 200 % vergrössert lesbar, ohne waagrechtes Scrollen auf dem Telefon.

## 5. Bekannte Einschränkungen

- **Diagramme in der Statistik** fassen ihre Werte in einer Beschriftung zusammen; eine Tabelle mit allen Tageswerten
  fehlt noch.
- **PDF-Dokumente** — Rechnungen und Belege — sind noch nicht als barrierefreie PDF ausgezeichnet. Auf Wunsch senden
  wir Ihnen die Angaben als Text.
- **Ziehen und Ablegen** im Seitenbaum und im Block-Editor: Wo es keine gleichwertige Bedienung über Menüs oder
  Tastatur gibt, arbeiten wir daran.
- **Inhalte von Nutzerinnen und Nutzern** — Beiträge, Bilder, Einträge im Marktplatz — können Bilder ohne
  Beschreibung oder unklare Strukturen enthalten. Wir weisen in den Editoren darauf hin.
- **Eingebettete Inhalte Dritter** (Videos) richten sich nach deren Anbietern; sie laden erst nach Ihrer Zustimmung.

## 6. Rückmeldung und Kontakt

Stossen Sie auf eine Barriere oder brauchen Sie Inhalte in einer anderen Form? Schreiben Sie an
email@basaltocms.com mit der Adresse der Seite und einer kurzen Beschreibung. Wir antworten innert zehn
Arbeitstagen und sagen Ihnen, bis wann wir das Problem beheben oder wie Sie die Information anders erhalten.

## 7. Durchsetzung

In der Schweiz verbietet das Behindertengleichstellungsgesetz (BehiG) privaten Anbietern, Menschen mit Behinderungen
zu diskriminieren. Für Dienstleistungen in der EU gilt der European Accessibility Act (Richtlinie (EU) 2019/882); als
Kleinstunternehmen sind wir von dessen Anforderungen an Dienstleistungen ausgenommen und richten uns freiwillig danach.
Sind Sie mit unserer Antwort nicht zufrieden, können Sie sich an die Marktüberwachungsbehörde Ihres Landes oder an
eine Organisation für Menschen mit Behinderungen wenden.

## 8. Wie wir prüfen

- Automatische Prüfungen mit axe-core bei jeder Änderung an der Software und mit Lighthouse auf den wichtigsten
  Seiten von basaltocms.com.
- Prüfungen von Hand mit Tastatur, Vergrösserung, dunklem Modus und schmalem Bildschirm bei jeder neuen Funktion.
- Stichproben mit Bildschirmleser: [[Platzhalter: Prüfung mit VoiceOver und NVDA durchführen und Datum eintragen]]

Technik: HTML, CSS und JavaScript mit WAI-ARIA, wo HTML allein nicht reicht. Inhaltsseiten funktionieren auch ohne
JavaScript; die Cookie-Einstellungen, Live-Funktionen und das Backend brauchen es. Unterstützt werden die aktuellen
Fassungen von Chrome, Edge, Firefox und Safari.

Diese Erklärung prüfen wir mindestens einmal im Jahr und bei grösseren Änderungen.
