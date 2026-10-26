# Beitragen zu Basalto

Danke für Ihr Interesse. Dieses Repository wird aus der Entwicklungsumgebung von Nguyen Development **erzeugt** und
bei jeder Änderung nachgeführt. Darum gilt:

- **Fragen und Ideen** gehören ins [Forum](https://basaltocms.com/community) — auf Deutsch, Englisch, Französisch oder
  Italienisch.
- **Fehler** melden Sie als Issue hier: Version, Umgebung (PHP, Datenbank, Hosting), Schritte, erwartetes und
  tatsächliches Ergebnis.
- **Sicherheitslücken** nie öffentlich, sondern nach [SECURITY.md](SECURITY.md).
- **Pull Requests** sind willkommen, werden aber nicht hier zusammengeführt: Ein angenommener Vorschlag wird in die
  Entwicklungsumgebung übernommen und erscheint mit dem nächsten Stand in diesem Repository. Die Regeln für Beiträge
  Dritter (Rechte am Beitrag) werden noch festgelegt; bis dahin übernehmen wir nur Fehlerbehebungen nach Rücksprache.

## Wenn Sie Code vorschlagen

- **Sprache:** Bezeichner, Datenbank und Befehle englisch; Kommentare und Doku deutsch mit echten Umlauten.
- **Qualität:** `vendor/bin/pint`, `vendor/bin/phpstan analyse`, `npm run check` und `npm run types:check` laufen
  ohne Befund.
- **Themes** sind reines Lava ohne PHP; prüfen mit `php artisan basalto:check --theme=themes/<name>`.
- **Module und Plugins** nutzen die Erweiterungs-Schnittstelle des Kerns — siehe
  [Erweiterungen](docs/entwickler/erweiterungen.md). Eigene Themes und Plugins gehören in den
  [Marktplatz](https://basaltocms.com/marktplatz), nicht in dieses Repository.

## Umgang miteinander

Es gilt der [Verhaltenskodex](CODE_OF_CONDUCT.md).
