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
5. Tests mit `php tests/run.php` ausführen.

Die API akzeptiert JSON-POST-Anfragen unter `/` (lokal) oder `/api/` (Hosting).
Die konkrete Aktion steht im JSON-Feld `action`. Die API-Adresse des Frontends
ist in `docs/config.js` konfigurierbar.

## Deployment

Eine vollständige Anleitung für GitHub Pages und manuelles FTP/SFTP-Deployment
des PHP-Backends einschließlich CORS, Dateirechten, Datenbank-Schutz,
Spielbereinigung und Fehlerdiagnose steht in [backend/README.md](backend/README.md).

Funktionale Regeln sind in [SPEC.md](SPEC.md) verbindlich dokumentiert;
Entwicklungsregeln stehen in [AGENTS.md](AGENTS.md).
