# Backend installieren und betreiben

## Voraussetzungen

- PHP 8.2 oder neuer mit `pdo_sqlite` und `json`
- Ein beschreibbares Verzeichnis für die SQLite-Datei **außerhalb des Webroots**
- HTTPS für die öffentliche API

Es werden weder Composer, SSH, Cronjobs noch zusätzliche Dienste benötigt.

## Shared Hosting per FTP/SFTP

1. Lege im Hosting-Kontrollpanel ein nicht öffentliches Verzeichnis an, zum
   Beispiel `/home/benutzer/bingo-private`. Lade die Datei
   `backend/src/Game.php` als
   `/home/benutzer/bingo-private/src/Game.php` dorthin hoch.
2. Lege daneben ein beschreibbares Datenverzeichnis an, beispielsweise
   `/home/benutzer/bingo-private/data`. Berechtigungen müssen dem PHP-Prozess
   Zugriff gewähren. Die Anwendung schützt Datenbank-, Sidecar- und
   Rate-Limit-Dateien mit Modus `0600`; das Verzeichnis muss für den PHP-Prozess
   beschreibbar und für Unbefugte unzugänglich sein. Übliche Verzeichnisrechte
   sind `700` oder `750`, abhängig vom Hostingkonto. Niemals pauschal `777`
   verwenden.
3. Lege im Webroot `/bingo/api/` an und lade `backend/public/index.php` als
   `/bingo/api/index.php` hoch. Veröffentliche weder
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
6. Richte GitHub Pages erst ein, wenn der veröffentlichbare Stand in den
   ausgewählten Branch übernommen wurde. Die passende Branch-/`/docs`-Auswahl
   sowie das Vorgehen für Entwicklungs- und Hauptbranch stehen in der
   [Serverinstallations- und Pages-Anleitung](../SERVER-INSTALLATION.md).

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

Wird das Backend auf einem anderen Host betrieben, sind drei Einstellungen
gemeinsam zu ändern und zu prüfen: `apiBaseUrl` in `docs/config.js`, der Host in
der `connect-src`-Direktive der Content Security Policy in `docs/index.html` und
der API-Origin in `BINGO_CORS_ORIGINS` (CORS-Allowlist des Backends). Der
Frontend-Origin in der CORS-Allowlist ist die GitHub-Pages-Adresse; sie ist
nicht die API-Adresse. Origins enthalten Schema, Host und optional Port, aber
keinen Pfad.

### Rate-Limits, Kapazität und Bereinigung

Authentifizierte Statusabfragen sind auf 1.800 pro Quell-IP und Minute sowie 45
pro Token und Minute begrenzt. Schreibaktionen haben Grenzen von 1.200 pro IP
und 120 pro Token je Minute. Spielerstellung ist auf fünf, Beitritte auf 60
Anfragen pro IP und Minute begrenzt; ein IP-Gesamtlimit von 3.600 Anfragen je
Minute und ein Invalid-Request-Limit von 60 je Minute ergänzen den Schutz. Diese
Werte erlauben 31 Clients mit einem Statuspoll alle zwei Sekunden hinter einer
IP-Adresse. Bei vertrauenswürdigen Proxy-Setups muss der Webserver die echte
Client-IP sicher als `REMOTE_ADDR` bereitstellen; nicht konfigurierte
Forwarded-Header werden nicht verwendet. POST-Bodies sind auf 4 KiB begrenzt.
Das globale und das aktionsspezifische Limit werden in einem gesperrten Update
erfasst; fehlerhafte und überdimensionierte Bodies zählen ebenfalls zum
IP-Limit und zum eigenen Invalid-Request-Limit.

Rate-Limit-Zähler werden im selben privaten Verzeichnis wie die SQLite-Datei
gespeichert. Eine separate, stabile `.bingo-rate-limits.lock`-Datei serialisiert
Änderungen; `.bingo-rate-limits.json` wird über eine temporäre Datei atomar
ersetzt. Das Verzeichnis muss beschreibbar und außerhalb des Webroots sein.
Polling schreibt nicht in SQLite. Es können maximal 100 Spiele und 30
Mitspielende pro Lobby/Spiel gespeichert werden; die Spielleitung zählt nicht
als Mitspielende. Abgelaufene Spiele werden beim Anlegen eines weiteren Spiels
bereinigt.

### Daten, Bereinigung und Diagnose

Spiele ohne Zustandsänderung seit 30 Tagen werden beim nächsten Anlegen eines
Spiels gelöscht; ein Cronjob ist nicht erforderlich. Die Rate-Limit-Datei
enthält gehashte IP-Adressen und Token-Hashes, wird bei jedem Request bereinigt
und ist auf 10.000 Einträge begrenzt. Es werden keine Token im Klartext in
Rate-Limit-Zählern gespeichert.

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

Die Tests verwenden temporäre SQLite-Datenbanken und starten einen lokalen
PHP-Testserver für HTTP-/CORS-Tests. `proc_open`, `pdo_sqlite`, `json` und
`flock` müssen verfügbar sein. Konkurrenztests und der Test mit 30 Mitspielenden
werden verpflichtend ausgeführt und niemals bedingt übersprungen.
