# Serverinstallation und GitHub Pages

Diese Anleitung beschreibt die manuelle Installation des PHP-Backends und die anschließende Veröffentlichung des statischen Frontends. Das Backend und GitHub Pages sind getrennte Dienste: Pages liefert HTML/CSS/JavaScript aus; die PHP-API muss auf einem PHP-fähigen Server laufen.

> **Wichtig:** Der Stabilisierungscode ist in PR [#3](https://github.com/benjamin-lam/bingo/pull/3) vorgeschlagen. Der PR ist noch nicht gemergt und es wurde nichts auf dem Server deployed. Die folgenden Schritte erst ausführen, wenn die gewünschte Version in den für Pages ausgewählten Branch übernommen wurde. Der aktuelle CI-Lauf ist erfolgreich; ein Test auf dem tatsächlichen Hosting steht noch aus.

## 1. Was im ZIP enthalten ist

Das Server-Installations-ZIP enthält nur die beiden Backend-Dateien und diese Anleitung:

```text
bingo-server-installation/
├── README.md
├── private/
│   ├── src/Game.php
│   └── data/                 (leer; hier liegt später die SQLite-Datei)
└── public/
    └── bingo/api/index.php
```

Das ZIP enthält **keine** Datenbank, Tokens, Geheimnisse oder Git-Daten. Entpacke es zuerst lokal. Lade **nicht** den ganzen Paketordner in den Webroot hoch: `private/` muss außerhalb des öffentlich erreichbaren Webroots bleiben.

Voraussetzungen beim Hoster:

- PHP 8.2 oder neuer mit `pdo_sqlite` und `json`
- `flock`, PHP-`proc_open` wird nur für die Tests benötigt
- HTTPS, ein beschreibbares privates Datenverzeichnis und konfigurierbare PHP-Umgebungsvariablen
- FTP/SFTP oder der Dateimanager des Hosting-Kontrollpanels

## 2. Backend per FTP/SFTP installieren

Die folgenden Pfade sind Beispiele. Ersetze `BENUTZER` und `WEBROOT` durch die tatsächlichen Werte deines Hostingkontos. Frage den Hoster nach dem absoluten Pfad zum Home-Verzeichnis und zum Webroot, falls diese unbekannt sind.

Beispielhafte Zielstruktur:

```text
/home/BENUTZER/bingo-private/                  # außerhalb des Webroots
├── src/Game.php
└── data/                                       # beschreibbar, nicht öffentlich

/home/BENUTZER/public_html/bingo/api/           # im Webroot
└── index.php
```

1. Lege außerhalb des Webroots `/home/BENUTZER/bingo-private/src/` und `/home/BENUTZER/bingo-private/data/` an.
2. Lade aus dem entpackten ZIP `private/src/Game.php` nach `/home/BENUTZER/bingo-private/src/Game.php` hoch.
3. Lege im Webroot `/bingo/api/` an und lade `public/bingo/api/index.php` dorthin als `/home/BENUTZER/public_html/bingo/api/index.php` hoch.
4. Überprüfe, dass weder der private Ordner noch `Game.php` oder `data/` über eine öffentliche URL erreichbar sind. Nur `public/bingo/api/index.php` gehört in den Webroot.
5. Stelle sicher, dass PHP die private Datei lesen und das Verzeichnis `data/` beschreiben kann. Als Ausgangspunkt eignen sich private Verzeichnisse mit `700` oder `750`, die PHP-Datei mit `640` oder `600` (abhängig vom PHP-Benutzer) und öffentliche Ordner/Dateien mit üblichen restriktiven Leserechten. **Niemals pauschal `777` verwenden.** Die Anwendung setzt Datenbank-, SQLite-Sidecar- und Rate-Limit-Dateien auf `0600`; der Hoster muss das unterstützen.
6. Die URL `https://benjamin-lam.de/bingo/api/` muss auf `index.php` zeigen. Wenn der Hoster Verzeichnis-URLs nicht automatisch auf `index.php` routet, frage nach einer sicheren PHP-Index-Konfiguration.

## 3. PHP-Umgebungsvariablen setzen

Trage diese Variablen im Hosting-Kontrollpanel für PHP-FPM/Apache ein. Passe die Beispielpfade an dein Konto an:

```text
BINGO_APP_PATH=/home/BENUTZER/bingo-private
BINGO_DB_PATH=/home/BENUTZER/bingo-private/data/bingo.sqlite
BINGO_CORS_ORIGINS=https://benjamin-lam.github.io
```

- `BINGO_APP_PATH` ist der private Ordner, der `src/Game.php` enthält.
- `BINGO_DB_PATH` ist ein absoluter Pfad zur Datenbankdatei; die Datei wird bei der ersten Anfrage angelegt.
- `BINGO_CORS_ORIGINS` enthält den **Origin des Frontends**, nicht den API-Host. Für die Standard-GitHub-Pages-Adresse ist das `https://benjamin-lam.github.io` (ohne `/bingo/` und ohne abschließenden Pfad).
- Falls du später eine eigene Domain für GitHub Pages verwendest, trage deren HTTPS-Origin ein. Bei mehreren Frontend-Origins sind sie komma-separiert einzutragen. Keine Wildcard verwenden.

Lege diese Werte nicht in einer öffentlich abrufbaren PHP-, JavaScript- oder `.env`-Datei ab. Der private Ordner und die Datenbank müssen außerhalb des Webroots liegen. Nicht konfigurierte oder ungeprüfte `X-Forwarded-For`-Header werden nicht verwendet; falls ein Proxy vorgeschaltet ist, muss der Hoster `REMOTE_ADDR` sicher setzen.

## 4. API-Verbindung und CSP abstimmen

Die Frontend-API-Adresse ist im Repository in `docs/config.js` eingetragen und lautet derzeit:

```text
https://benjamin-lam.de/bingo/api/
```

Die API-Basisadresse muss auf den Ordner `/bingo/api/` zeigen, nicht direkt auf `index.php`. Die Anwendung sendet JSON-POST-Anfragen an diese Adresse.

Wenn die API unter einem anderen Host liegt, müssen **alle drei** Einstellungen zusammenpassen:

1. `apiBaseUrl` in `docs/config.js`: vollständige HTTPS-Adresse der API, einschließlich API-Pfad und abschließendem `/`.
2. `connect-src` in der Content-Security-Policy im `<head>` von `docs/index.html`: den API-Host ergänzen (nur Schema und Host, kein API-Pfad).
3. `BINGO_CORS_ORIGINS` beim PHP-Backend: den Origin der veröffentlichten GitHub-Pages-Seite eintragen (Schema und Host, kein Pfad).

Beispiel bei anderem API-Host: `apiBaseUrl` könnte `https://api.example.de/bingo/api/` sein; die CSP enthält `https://api.example.de`, während die CORS-Allowlist weiterhin den Frontend-Origin enthält, beispielsweise `https://benjamin-lam.github.io`. Nicht den API-Origin versehentlich als CORS-Frontend-Origin eintragen.

## 5. GitHub Pages einschalten

Das Repository ist `benjamin-lam/bingo`; die Standard-Projektseite wäre `https://benjamin-lam.github.io/bingo/`. Die Pages-Origin für CORS bleibt dabei `https://benjamin-lam.github.io`.

1. Zuerst muss die gewünschte Anwendungsversion in dem Branch liegen, von dem Pages veröffentlichen soll. Der aktuelle PR zielt auf `copilot/develop-bingo-game`; er muss zunächst von einem Repository-Maintainer geprüft und gemergt werden. Der PR wird durch diese Anleitung **nicht** automatisch gemergt.
2. Öffne auf GitHub das Repository **Settings → Pages**.
3. Unter **Build and deployment** wähle **Deploy from a branch**.
4. Wähle als Branch den Branch, in dem die veröffentlichbare Version tatsächlich liegt, und als Ordner **`/docs`**, dann speichere. Für einen Test nach dem Merge in den aktuellen Entwicklungsbranch wäre das `copilot/develop-bingo-game`. Für den späteren Regelbetrieb ist normalerweise `main` geeigneter, sobald die geprüfte Version auch dorthin übernommen wurde.
5. Warte, bis GitHub Pages die Seite bereitstellt, und öffne die angezeigte URL. Prüfe, dass die Bingo-Startseite geladen wird und keine CSP-Fehler in der Browserkonsole erscheinen.

GitHub Pages führt PHP nicht aus. Das Backend muss unabhängig davon auf dem Hosting laufen, HTTPS verwenden und unter der in `docs/config.js` eingetragenen API-Adresse erreichbar sein.

## 6. Verbindung testen

Nach dem Backend-Upload:

- Rufe `https://benjamin-lam.de/bingo/api/` direkt im Browser auf. Ein `405 Method Not Allowed` für GET ist erwartbar, weil die API nur POST und OPTIONS annimmt. `404` deutet meist auf ein falsches Verzeichnis/Routing; `500` auf PHP-Konfiguration, Umgebungsvariablen, Pfade oder Dateirechte hin.
- Prüfe im Browser-Netzwerk-Tab beim Laden/Benutzen der Pages-Seite, dass API-Anfragen an den erwarteten Host gehen. Eine CORS-Preflight-Anfrage soll `204` liefern und `Access-Control-Allow-Origin` muss exakt zum Frontend-Origin passen.
- Erstelle zunächst ein Testspiel, tritt mit einem zweiten Browser/Profil bei, starte und beende eine Runde. Teile Admin- oder Spielertokens nicht öffentlich.
- Kontrolliere PHP-Fehlerprotokoll und Hosting-Limits. Die API schreibt bei internen Fehlern keine Pfade oder SQL-Details in die HTTP-Antwort.

## 7. Vor öffentlichem Spielbetrieb

Der CI-Lauf besteht, aber der reale Shared-Hosting-Tarif, seine PHP-Konfiguration, Dateirechte, Proxy-IP und Last wurden noch nicht geprüft. Vor dem Einladen vieler Mitspielender sind deshalb mindestens ein repräsentativer Test mit 30 Mitspielenden plus Spielleitung für mindestens eine Minute sowie eine manuelle Tastatur-/Screenreader-Prüfung erforderlich. Die aktuelle Produktivfreigabe bleibt bis dahin offen.
