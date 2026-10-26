# Sicherheit

Danke, dass Sie helfen, Basalto sicher zu halten. Sicherheitslücken melden Sie bitte **vertraulich** und **nie** als
öffentliches Issue, im Forum oder in einem Pull Request.

## Wie Sie eine Lücke melden

1. **Per E-Mail** an [email@basaltocms.com](mailto:email@basaltocms.com), Betreff «Sicherheit».
2. **Über GitHub:** im Repository unter **Security → Report a vulnerability** («Private vulnerability reporting»).
   Die Meldung sieht nur das Team von Basalto; dort können wir mit Ihnen an einer Korrektur arbeiten und eine
   Sicherheitsmeldung (Advisory) veröffentlichen.

Hilfreich sind:

- betroffene Version oder Commit,
- Umgebung (PHP-Version, Datenbank, Betriebssystem, Browser),
- Schritte zum Nachstellen, möglichst mit einem kleinen Beispiel (etwa einem Lava-Ausschnitt),
- was ein Angreifer damit erreicht und unter welchen Voraussetzungen.

## Was danach geschieht

- Wir bestätigen den Eingang, prüfen die Meldung und halten Sie über den Stand auf dem Laufenden.
- Wir beheben die Lücke, veröffentlichen eine korrigierte Version und danach eine Sicherheitsmeldung. Wenn Sie
  möchten, nennen wir Sie darin.
- Bitte veröffentlichen Sie nichts, bevor die Korrektur verfügbar ist.

## Unterstützte Versionen

| Version                                                         | Sicherheitskorrekturen |
| --------------------------------------------------------------- | ---------------------- |
| aktueller Stand (`main`) und die letzte veröffentlichte Version | ja                     |
| ältere Versionen                                                | nein                   |

Für veröffentlichte Hauptversionen gilt die Zusage in [LICENSE.md](LICENSE.md) § 5.

## Besonders wichtig

Diese Bereiche tragen die Sicherheitsversprechen von Basalto. Befunde dort interessieren uns besonders:

- **Lava-Sandbox:** Ein Theme darf keinen Code ausführen, keine Datei lesen und keine fremde Site erreichen. Nur
  registrierte Funktionen und Filter sind erlaubt, Ausgaben werden automatisch maskiert, Laufzeitgrenzen gelten.
- **Trennung der Sites:** Daten einer Site dürfen in einer anderen nie sichtbar werden.
- **Anmeldung ohne Passwort:** Einmal-Links, Code per E-Mail und Passkeys.
- **Formulare, signierte Adressen, Rate-Limits und Content Security Policy.**

## Nicht im Umfang

- Themes und Plugins Dritter: Bitte melden Sie diese ihren Autorinnen und Autoren.
- Umgehen der Lizenzprüfung: Das ist eine Frage der Lizenz, keine Sicherheitslücke.
- Angriffe auf fremde Installationen, auf Demo-, Test- oder Produktionsumgebungen von Nguyen Development und
  Lasttests gegen sie.
