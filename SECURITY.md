# Sicherheit

Danke, dass du hilfst, Basalto sicher zu halten. Sicherheitslücken meldest du bitte **vertraulich** und **nie** als
öffentliches Issue, in den Discussions oder in einem Pull Request.

## Wie du eine Lücke meldest

1. **Über GitHub (bevorzugt):** Im Repository unter **Security → Report a vulnerability** («Private vulnerability
   reporting»). Die Meldung sieht nur das Team von Basalto; wir können dort mit dir an einer Korrektur arbeiten und
   eine Sicherheitsmeldung (Advisory) mit CVE veröffentlichen.
2. **Per E-Mail:** Adresse folgt.

[[Offen: Adresse für Sicherheitsmeldungen — in der Doku noch nicht festgelegt]]

Hilfreich sind:

- betroffene Version oder Commit,
- Umgebung (PHP-Version, Datenbank, Betriebssystem, Browser),
- Schritte zum Nachstellen, möglichst mit einem kleinen Beispiel (etwa einem Lava-Ausschnitt),
- was ein Angreifer damit erreicht und unter welchen Voraussetzungen.

## Was danach geschieht

- Wir bestätigen den Eingang, prüfen die Meldung und halten dich über den Stand auf dem Laufenden.
- Wir beheben die Lücke, veröffentlichen eine korrigierte Version und danach eine Sicherheitsmeldung. Wenn du
  möchtest, nennen wir dich darin.
- Bitte veröffentliche nichts, bevor die Korrektur verfügbar ist.

[[Offen: zugesicherte Fristen für Eingangsbestätigung, Ersteinschätzung und Korrektur]]

[[Offen: Zusage für Meldende in gutem Glauben (Safe Harbor) — nur nach Prüfung durch die Anwältin]]

## Unterstützte Versionen

| Version                       | Sicherheitskorrekturen |
| ----------------------------- | ---------------------- |
| `main` (Beta in Vorbereitung) | ja                     |
| ältere Stände vor der Beta    | nein                   |

Nach der ersten stabilen Version gilt die Zusage aus dem Lizenzentwurf: Sicherheitskorrekturen für die jeweils
letzte Hauptversion während zwei Jahren nach deren Erscheinen ([LICENSE.md](LICENSE.md) § 5, Entwurf).

## Besonders wichtig

Diese Bereiche tragen die Sicherheitsversprechen von Basalto. Befunde dort interessieren uns besonders:

- **Lava-Sandbox:** Ein Theme darf keinen Code ausführen, keine Datei lesen und keine fremde Site erreichen (L-16).
  Nur registrierte Funktionen und Filter sind erlaubt, Ausgaben werden automatisch maskiert, Laufzeitgrenzen gelten.
- **Trennung der Sites:** Daten einer Site dürfen in einer anderen nie sichtbar werden (ADR-008, ADR-011).
- **Anmeldung ohne Passwort:** Einmal-Links, Mail-Code und Passkeys (ADR-006).
- **Formulare, signierte Adressen, Rate-Limits und Content Security Policy** (N-04).

## Nicht im Umfang

- Themes und Plugins Dritter: Bitte meldet sie ihren Autorinnen und Autoren.
- Umgehen der Lizenzprüfung: Das ist eine Frage der Lizenz, keine Sicherheitslücke.
- Angriffe auf fremde Installationen, auf Demo-, Test- oder Produktionsumgebungen von Nguyen Development und
  Lasttests gegen sie.
