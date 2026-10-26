# Rollen

Eine **Rolle** legt fest, was jemand im Backend darf — je **Bereich** eine **Stufe**, dazu die **Sprachen**, in denen jemand Inhalte bearbeiten darf. Eine Person hat je Site genau eine Rolle; die Rollen selbst gelten für alle Sites der Installation.

## Wo

Modus «Verwaltung» › **Rollen** (oder unter «Benutzer» der Knopf «Rollen»).

![Die Rollen mit der Matrix der Rolle «Administrator»](bilder/rollen.webp)

Links stehen die Rollen — je Rolle, wie viele Personen sie haben, welche Modi sie sieht («sieht: Inhalte · Einrichtung · Verwaltung») und welche Sprachen. Marken zeigen **«unveränderlich»** (Betreiber), **«angepasst»** (eine Vorlage mit eigenen Änderungen) oder **«eigene»** (selbst angelegt). Rechts die gewählte Rolle:

1. **Name** und **Beschreibung** (freiwillig) — die Beschreibung erscheint beim Einladen unter der Rolle.
2. **Sprachen**: **alle**, **alle ausser der Quellsprache** oder eine **Auswahl**. Darunter steht, in welchen Sprachen die Rolle Inhalte bearbeiten darf. Seiten anlegen, löschen, verschieben und ihre Einstellungen ändern verlangt die Quellsprache.
3. **Die Matrix** — je Bereich eine Stufe, nach den Gruppen Inhalte, Einrichtung und Verwaltung. **«alle auf»** setzt eine ganze Gruppe auf «keine», «ansehen» oder «bearbeiten». Bereiche aus Modulen tragen «Modul».
4. **Vorschau** unten — was die Rolle sieht und wie viele Personen sie haben. **«Speichern»** übernimmt die Änderung; sie gilt ab der nächsten Anfrage. Rechte ändern sich nie nebenbei.

| Stufe | Bedeutet |
| --- | --- |
| **keine** | Der Bereich fehlt im Menü. |
| **ansehen** | lesen, ohne etwas zu ändern |
| **bearbeiten** | anlegen und ändern |
| **löschen** | zusätzlich löschen |
| **alles** | zusätzlich die heiklen Einstellungen des Bereichs — etwa den Webhook eines Formulars oder den Schlüssel der KI-Vorschläge |

Sieht eine Rolle in einer Gruppe keinen Bereich, fehlt auch der Modus dazu (zum Beispiel «Einrichtung»).

## Die Vorlagen

| Vorlage | Darf |
| --- | --- |
| **Betreiber** | alles; unveränderlich, damit sich keine Site aussperrt. Jede Site behält mindestens einen Betreiber. |
| **Administrator** | alles, ausser die Rolle Betreiber vergeben |
| **Redaktion** | Seiten, Medien und Formulare bis «löschen», Übersetzung, Kommentare, Mitglieder und KI-Vorschläge «bearbeiten»; keine Einrichtung, keine Verwaltung, kein Freigeben |
| **Freigabe** | Freigabe und Veröffentlichen «alles»; Seiten, Medien, Übersetzung, Formulare und Kommentare «ansehen» |
| **Übersetzung** | Seiten und Übersetzung «bearbeiten», Medien «ansehen», KI-Vorschläge «bearbeiten» — in allen Sprachen ausser der Quellsprache |

Eine angepasste Vorlage holt **«Zurücksetzen»** auf den Stand ab Werk zurück (ausser Betreiber).

## Eigene Rollen

- **«Neue Rolle»** legt eine leere Rolle an (sie darf nichts) — oder eine Kopie einer bestehenden. **«Kopieren»** oben rechts kopiert die gewählte Rolle.
- **«Löschen»** entfernt eine eigene Rolle, solange niemand sie hat und keine offene Einladung auf sie lautet.

## Regeln, die niemand umgeht

- Rollen ändern verlangt «Benutzer und Rollen · alles»; ansehen genügt «ansehen».
- Wer eine Rolle vergibt, braucht in jedem Bereich mindestens dieselbe Stufe und jede ihrer Sprachen.
- API-Tokens handeln mit den Rechten ihrer Person.
- Alles steht im [Protokoll](protokoll.md): angelegt, geändert (vorher und nachher), zurückgesetzt, gelöscht und jede Zuweisung.

Mehr Hintergrund für die Technik: [Rollen und Rechte](../betrieb/rollen.md).

## Weiter

- [Benutzer](benutzer.md)
- [Rollen und Rechte](../betrieb/rollen.md)
