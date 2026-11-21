# Bingo – Multiplayer-Browserspiel

Ein einfaches, responsives Bingo für eine Spielleitung und mehrere Mitspielende.
Das Frontend unter `/docs` besteht aus HTML, CSS und Vanilla JavaScript und kann
mit GitHub Pages veröffentlicht werden. Das Backend unter `/backend` benötigt
PHP 8.2+, PDO SQLite und gewöhnliches Shared Hosting.

## Lokal starten

1. PHP 8.2+ mit den Erweiterungen `pdo_sqlite` und `json` installieren.
2. Einen privaten, beschreibbaren Datenbankordner außerhalb des Webroots anlegen,
   beispielsweise `mkdir -p "$HOME/bingo-data"`.
3. API und Frontend konfigurieren und lokal starten:

   ```sh
   export BINGO_DB_PATH="$HOME/bingo-data/bingo.sqlite"
   export BINGO_CORS_ORIGINS="http://localhost:8000"
   php -S 127.0.0.1:8001 -t backend/public
   ```

4. In `/docs/config.js` `apiBaseUrl` auf `http://localhost:8001` setzen und
   `/docs` mit einem lokalen HTTP-Server bereitstellen (z. B.
   `python3 -m http.server 8000 --directory docs`).
5. Die PHP-, SQLite-Konkurrenz-, HTTP-/CORS- und Kapazitätstests mit
   `php tests/run.php` ausführen; zusätzlich JavaScript mit
   `node --check docs/app.js` und `node --check docs/config.js` prüfen.

Die API akzeptiert JSON-POST-Anfragen unter `/` (lokal) oder `/api/` (Hosting).
Die konkrete Aktion steht im JSON-Feld `action`. Die API-Adresse des Frontends
ist in `docs/config.js` konfigurierbar.

## Deployment

Die Schritt-für-Schritt-Anleitung für Serverinstallation, GitHub Pages, API/CSP/CORS
und Vorabtests steht in [SERVER-INSTALLATION.md](SERVER-INSTALLATION.md). Das
Serverpaket zum Entpacken und getrennten Upload liegt in
[dist/bingo-server-installation.zip](dist/bingo-server-installation.zip). Die
technischen Details zu Dateirechten, Datenbankschutz, Bereinigung und Diagnose
stehen zusätzlich in [backend/README.md](backend/README.md).

Funktionale Regeln sind in [SPEC.md](SPEC.md) verbindlich dokumentiert;
Entwicklungsregeln stehen in [AGENTS.md](AGENTS.md).

## Stabilisierung und Prüfberichte

Historische und aktuelle Review-Berichte liegen versioniert unter `reviews/`;
Aufgaben stehen in `TODO.md` und `TODO.json`. Die Anwendung begrenzt Spiele auf
100 gespeicherte Einträge und Lobbys auf 30 Mitspielende. Eine Freigabe für
produktiven Betrieb setzt zusätzlich einen Test auf dem tatsächlichen Hosting
und eine manuelle Tastatur-/Screenreader-Prüfung voraus.
