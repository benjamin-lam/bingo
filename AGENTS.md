# Entwicklungsregeln

- Frontend: HTML5, CSS3 und Vanilla JavaScript unter `/docs`; keine Frameworks,
  Build-Schritte oder externen Laufzeitdienste.
- Backend: PHP 8.2+, PDO SQLite, keine Composer-Abhängigkeiten.
- Alle Spielaktionen serverseitig validieren; Spieler- und Admin-Berechtigungen
  strikt trennen. Niemals Token oder private Konfiguration ins Frontend committen.
- SQL-Werte parametrisieren und Schreibaktionen, die den Spielzustand ändern,
  in SQLite-Transaktionen ausführen.
- Deutsche, barrierearme UI-Texte und semantisches HTML beibehalten.
- Bestehende Tests mit `php tests/run.php` ausführen. Neue Regeln mit fokussierten
  Tests abdecken.
- Commit-Nachrichten auf Deutsch verfassen.
