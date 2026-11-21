# Verbleibende Aufgaben

Stand: 2026-11-21. Die JSON-Entsprechung liegt in [TODO.json](TODO.json). Aufgaben-IDs sind in beiden Dateien stabil und identisch; erledigte Arbeit bleibt hier sichtbar.

## Offen

- [ ] STAB-TODO-004 (open): Auf dem tatsächlichen Shared Hosting Umgebungsvariablen, Verzeichnisrouting, private DB-/Rate-Limit-Pfade, `REMOTE_ADDR` hinter Proxies sowie einen mindestens einminütigen repräsentativen 30-Spieler- plus Spielleitungs-Pollinglauf prüfen.
- [ ] STAB-TODO-005 (open): Manuelle Browser-/Tastatur-/Screenreader-Prüfung der Bingokarte und WCAG-2.2-AA-Verhalten durchführen. Der lokale DOM-Test ersetzt keinen echten Browser oder Screenreader.
- [ ] STAB-TODO-006 (open): Für Produktions- und alternative API-Hosts `docs/config.js`, CSP `connect-src` und Backend-CORS-Allowlist gemeinsam im echten Browser verifizieren.

## Erledigt

- [x] STAB-TODO-001 (completed): Lokalen/Remote-Zustand geprüft, auf dem Review-Branch mit App und historischen Prüfergebnissen gearbeitet und unveränderte historische Reviews nach `reviews/` verschoben.
- [x] STAB-TODO-002 (completed): Sieben Befunde implementiert, Regressionstest- und HTTP-/Konkurrenztest-Harness ergänzt, CI, Spezifikation und Installationsdokumentation erweitert. PHP-Syntax und Suite wurden in CI erfolgreich geprüft.
- [x] STAB-TODO-003 (completed): Vollständige PHP-8.2-/SQLite-/HTTP-/Konkurrenzsuite und Syntaxchecks bestanden in GitHub Actions auf Commit `86fb68a`; PHP fehlt weiterhin lokal.
- [x] STAB-TODO-007 (completed): Branch `codex/stabilisierung-bingo` gepusht; CI push- und PR-Checks bestanden; PR [#3](https://github.com/benjamin-lam/bingo/pull/3) gegen `copilot/develop-bingo-game` eröffnet. Nicht gemergt oder deployed.
- [x] STAB-TODO-008 (completed): [Serverinstallationsanleitung](SERVER-INSTALLATION.md) und [Server-ZIP](dist/bingo-server-installation.zip) erstellt. ZIP-Inhalt, Pfade und Bytegleichheit mit den Quelldateien geprüft; keine Datenbank, Tokens, Umgebungsdateien oder Geheimnisse enthalten. SHA-256: `47681681547FFAFABB97D1BAC7B91EB8AEBCF32D59267904C4D389CF6931E8BE`.
