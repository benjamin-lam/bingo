# Backend installieren und betreiben

## Voraussetzungen

- PHP 8.2 oder neuer mit `pdo_sqlite` und `json`
- Ein beschreibbares Verzeichnis für die SQLite-Datei **außerhalb des Webroots**
- HTTPS für die öffentliche API

Es werden weder Composer, SSH, Cronjobs noch zusätzliche Dienste benötigt.

## Shared Hosting per FTP/SFTP

1. Lege im Hosting-Kontrollpanel ein nicht öffentliches Verzeichnis an, zum
   Beispiel `/home/benutzer/bingo-private`. Lade `src/Game.php` dorthin hoch.
2. Lege daneben ein beschreibbares Datenverzeichnis an, beispielsweise
   `/home/benutzer/bingo-private/data`. Berechtigungen müssen dem PHP-Prozess
   Zugriff gewähren (typischerweise Verzeichnis `750`, DB-Datei `640`; an den
   Hoster anpassen, niemals pauschal `777` verwenden).
3. Lade `public/index.php` als
   `https://benjamin-lam.de/bingo/api/index.php` hoch. Veröffentliche weder
   `src` noch das Datenverzeichnis im Webroot. Die Verzeichnis-URL `/bingo/api/`
   muss vom Hoster auf `index.php` leiten.
4. Konfiguriere für PHP diese Umgebungsvariablen über das Hosting-Kontrollpanel:

   ```text
   BINGO_APP_PATH=/home/benutzer/bingo-private
   BINGO_DB_PATH=/home/benutzer/bingo-private/data/bingo.sqlite
   BINGO_CORS_ORIGINS=https://benjamin-lam.github.io
   ```

   Siehe auch [config.example](config.example). Falls der Tarif keine
   Umgebungsvariablen unterstützt, frage den Hoster nach PHP-FPM-/Apache-
   Einstellungen. Lege Werte nicht in öffentlich abrufbaren Dateien ab.
5. Setze in `/docs/config.js` die API-Basisadresse (ohne `index.php`) auf
   `https://benjamin-lam.de/bingo/api/`.
6. Aktiviere GitHub Pages für den Main-Branch und das Verzeichnis `/docs`.

Die API prüft beim Start, dass der konfigurierte Datenbankpfad nicht im
erkannten `DOCUMENT_ROOT` liegt. Die Datei enthält ausschließlich Hashes der
Admin- und Spielertokens sowie den Spielstand. Tokens werden nur beim Erstellen
des Spiels beziehungsweise Beitritt ausgegeben.

### CORS und API-Adresse

`BINGO_CORS_ORIGINS` ist eine kommaseparierte Allowlist vollständiger Origins
(Schema, Host und optional Port, ohne Pfad). Nur diese Origins erhalten
CORS-Header. Der Wert muss exakt dem GitHub-Pages-Origin entsprechen. Für lokale
Entwicklung kann beispielsweise `http://localhost:8000` eingetragen werden.
Keine Wildcard verwenden. Änderungen an der Frontend-URL erfordern eine
entsprechende Änderung an der Allowlist.

### Daten, Bereinigung und Diagnose

Spiele ohne Zustandsänderung seit 30 Tagen werden bei API-Anfragen gelöscht;
ein Cronjob ist nicht erforderlich. Anfrage-Limits sind auf 180 Requests pro
Minute je Client-Adresse gesetzt. Die Adresse wird dafür nur als SHA-256-Hash
gespeichert und alte Zähler werden opportunistisch entfernt.

Bei HTTP 500 die PHP-Fehlerprotokolle des Hosters auf den generischen Eintrag
`Bingo-API: interner Fehler` prüfen. Antworttexte enthalten keine Dateipfade
oder SQL-Fehler. Bei HTTP 403 CORS-Origin überprüfen, bei Fehlern zur
Datenbankverbindung den absoluten Pfad und die Schreibrechte des PHP-Prozesses.
Eine nicht konfigurierte `BINGO_DB_PATH` oder ein Datenbankpfad im Webroot
verhindert den Start absichtlich.

## Tests

Vom Repository-Hauptverzeichnis aus:

```sh
php tests/run.php
```

Die Tests verwenden eine temporäre SQLite-Datenbank und benötigen weder Netzwerk
noch externe Dienste.
