# Verbleibende Aufgaben

Stand: 2026-11-21. Die JSON-Entsprechung liegt in [TODO.json](TODO.json). Aufgaben-IDs sind in beiden Dateien stabil und identisch; erledigte Arbeit bleibt hier sichtbar.

## Offen

- [ ] STAB-TODO-003 (open): PHP-Suite und Syntaxprüfungen mit PHP 8.2+, PDO SQLite, `json`, `flock` und `proc_open` ausführen. Lokal fehlt PHP und WSL; nach dem Push zusätzlich den tatsächlichen CI-Lauf beobachten und Fehler beheben.
- [ ] STAB-TODO-004 (open): Auf dem tatsächlichen Shared Hosting Umgebungsvariablen, Verzeichnisrouting, private DB-/Rate-Limit-Pfade, `REMOTE_ADDR` hinter Proxies sowie einen mindestens einminütigen repräsentativen 30-Spieler- plus Spielleitungs-Pollinglauf prüfen.
- [ ] STAB-TODO-005 (open): Manuelle Browser-/Tastatur-/Screenreader-Prüfung der Bingokarte und WCAG-2.2-AA-Verhalten durchführen. Der lokale DOM-Test ersetzt keinen echten Browser oder Screenreader.
- [ ] STAB-TODO-006 (open): Für Produktions- und alternative API-Hosts `docs/config.js`, CSP `connect-src` und Backend-CORS-Allowlist gemeinsam im echten Browser verifizieren.
- [ ] STAB-TODO-007 (open): Änderungen committen und nach `codex/stabilisierung-bingo` pushen, GitHub-Actions-Ergebnis beobachten, verbleibende CI-Fehler beheben und einen PR gegen `copilot/develop-bingo-game` öffnen. Nicht mergen oder deployen.

## Erledigt

- [x] STAB-TODO-001 (completed): Lokalen/Remote-Zustand geprüft, auf dem Review-Branch mit App und historischen Prüfergebnissen gearbeitet und unveränderte historische Reviews nach `reviews/` verschoben.
- [x] STAB-TODO-002 (completed): Sieben Befunde implementiert, Regressionstest- und HTTP-/Konkurrenztest-Harness ergänzt, CI, Spezifikation und Installationsdokumentation erweitert. PHP-Verifikation bleibt separat offen.
