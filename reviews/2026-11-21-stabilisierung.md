# Stabilisierung nach unabhängigem Review – Bingo

- **Datum:** 2026-11-21
- **Repository:** `benjamin-lam/bingo`
- **Arbeitsbranch:** `codex/stabilisierung-bingo`
- **Vorgesehener PR-Zielbranch:** `copilot/develop-bingo-game` (der Entwicklungsbranch mit der App, nicht `main`)
- **Ausgangsstand:** sauberer lokaler `main` bei `fcc6fb8`; Anwendung auf `origin/copilot/develop-bingo-game` bei `90f4c73`; unabhängiger Review auf `origin/review/2026-11-20-multiplayer-bingo` bei `6782da9`.
- **Artefaktgleichstand:** Die JSON-Fassung dieses Berichts enthält dieselben sieben IDs, Statuswerte, Testresultate, Risiken und offenen Aufgaben.

## Ergebnis in Kürze

Alle sieben Review-Befunde wurden im Code bearbeitet und neue Regressionstests, Integrations- und Konkurrenztest-Harnesses sowie eine GitHub-Actions-Pipeline ergänzt. **BINGO-REV-001–005 und BINGO-REV-007 sind `fixed_verified`** durch den erfolgreichen vollständigen PHP-8.2-/SQLite-/HTTP-/Konkurrenzlauf auf Commit `86fb68a`; BINGO-REV-006 bleibt `implemented_unverified`, bis echte Browser-/Screenreader-Prüfungen erfolgen. Der erste CI-Lauf deckte eine falsche Erwartung im Rate-Limit-Test auf; die Testisolation wurde korrigiert, danach bestanden sowohl der Push- als auch der PR-Lauf. PHP ist lokal unter Windows nicht installiert. Die Node-Frontend- und Dokumentationstests bestanden lokal ebenfalls.

**Produktivfreigabe für 30 gleichzeitige Mitspielende: Nein, noch nicht nachgewiesen.** Der CI-Lauf ist erfolgreich; offen bleiben ein produktionsnaher 30-Client-Test auf dem vorgesehenen Hosting, die konkrete Proxy-/CORS-Konfiguration sowie eine echte Browser-/Tastatur-/Screenreader-Prüfung. Das Hosting und seine PHP-, SQLite-, Verzeichnis- und Proxy-Konfiguration wurden nicht geprüft.

## Statuswerte

- `fixed_verified`: Änderung implementiert und durch passende automatisierte oder nachvollziehbar dokumentierte manuelle Prüfungen erfolgreich verifiziert.
- `implemented_unverified`: Änderung und vorgesehene Tests sind versioniert, mindestens eine notwendige Verifikation wurde jedoch noch nicht erfolgreich ausgeführt.
- `open`: Es wurde keine ausreichende Implementierung vorgenommen; der Befund bleibt bestehen.
- `blocked`: Bearbeitung oder Verifikation ist durch eine konkret dokumentierte externe Einschränkung blockiert.

## Ausgangszustand und Prüfungen

| Prüfung | Ergebnis | Nachweis / Einschränkung |
|---|---|---|
| `git status`, Branches, Commit und Remote-Branches | Geprüft | `main` war sauber. App und Review waren getrennte Remote-Branches; Arbeitsbranch wurde auf dem Review-Branch mit beiden Inhalten angelegt. |
| Historische Ausgangstests lokal | Blockiert | `php tests/run.php` und `php -l` wurden versucht, aber `php`/`php.exe` ist nicht installiert bzw. nicht auf `PATH`; WSL ist nicht installiert. |
| Historische Review-Angabe | Nur Referenz | Der unabhängige Bericht vom 2026-11-20 hält 234 bestandene Assertions und PHP-Syntaxchecks für den damaligen Anwendungscode fest. Das ist kein erneuter Lauf auf diesem Arbeitsstand. |
| `node --check docs/app.js`, `node --check docs/config.js` | Bestanden | Node.js 26.7.0; Exit-Code 0. |
| `node --check tests/frontend-test.mjs` | Bestanden | Exit-Code 0. |
| `node tests/frontend-test.mjs` | Bestanden | `OK: Frontend DOM-/A11y-Prüfungen`; synthetischer DOM-Harness, kein echter Browser oder Screenreader. |
| `node tests/docs-test.mjs` | Bestanden | `OK: Berichts-, TODO- und Spezifikationskonsistenz`; Findings, Statuswerte und offene/erledigte TODO-IDs stimmen zwischen JSON und Markdown überein. |
| Statische Homepage im integrierten Browser geöffnet | Teilweise manuell geprüft | Titel, Startseite, Beschriftungen und sichtbares Layout wurden geladen. Die Spielansicht blieb verborgen; kein API-Spielablauf, keine echte Bingokarte und kein Screenreader wurden geprüft. |
| PHP-Suite lokal | Blockiert | PHP fehlt lokal. Die vollständige PHP-8.2-/SQLite-/HTTP-/Konkurrenzsuite bestand in GitHub Actions auf Commit `86fb68a`. |
| PHP-Syntaxprüfung | Bestanden | Alle PHP-Dateien wurden im CI mit PHP 8.2 gelintet. |
| HTTP-/CORS- und Konkurrenztests | Bestanden | Inklusive 31 barrier-synchronisierter HTTP-Statusabfragen gegen vier PHP-CLI-Server-Worker im CI. Der echte Shared Host wurde nicht geprüft. |
| GitHub Actions, erster Lauf | Fehlgeschlagen; behoben | PHP-Syntaxschritte bestanden. Die Suite stoppte an einer falschen Erwartung des individuellen Tokenzählers, weil dieselben Tokens zuvor in der synthetischen Pollsequenz verwendet wurden. Die Parallelprüfung wurde auf frische Tokens isoliert. |
| GitHub Actions, Folge-Läufe (Push und PR) | Bestanden | Beide vollständigen Läufe auf Commit `86fb68a` erfolgreich; PR [#3 gegen `copilot/develop-bingo-game`](https://github.com/benjamin-lam/bingo/pull/3). |
| Produktivhosting, realer 30-Client-Test, WCAG manuell | Nicht geprüft | Kein Hosting-Zugang, realer Browser-/Screenreaderlauf oder produktionsnaher Client-Test in dieser Arbeitsumgebung. |

## Befunde

### BINGO-REV-001 — IP-Limit blockiert Polling bei gemeinsam genutzter IP

- **Ursprüngliche Priorität:** P1
- **Status:** `fixed_verified`
- **Änderung:** Statusabfragen, Schreibaktionen, öffentliche Spiel-/Beitrittsaktionen und ungültige Requests verwenden getrennte Rate-Limits. Statusgrenzen: 1.800/min/IP und 45/min/Token; Schreibaktionen: 1.200/min/IP und 120/min/Token; Erstellung: 5/min/IP; Beitritt: 60/min/IP; IP-Gesamtlimit: 3.600/min. Eine separate stabile `flock`-Sperrdatei im privaten Datenverzeichnis serialisiert Änderungen; die JSON-Zählerdatei wird über eine temporäre Datei atomar ersetzt. Polling verursacht keine SQLite-Schreibtransaktion. Bodies sind auf 4 KiB begrenzt; globale und aktionsspezifische Zähler werden gemeinsam aktualisiert, fehlerhafte Bodies zählen ebenfalls zum IP- und Invalid-Request-Limit. Es werden keine nicht explizit konfigurierten Proxy-Header vertraut.
- **Dateien:** `.gitignore`, `backend/src/Game.php`, `backend/public/index.php`, `SPEC.md`, `backend/README.md`, `tests/run.php`, `tests/http.php`, `tests/concurrency-worker.php`.
- **Tests:** synthetische 31-Client-Pollsequenz über 62 Sekunden; 31 parallele Statusabfragen aus einer IP und anschließende JSON-Konsistenzprüfung; eigene Limits für Status, Schreiben und öffentliche Erstellung; HTTP-Limit für ungültige Bodies.
- **Testergebnis:** Der vollständige PHP-/HTTP-Testlauf bestand in GitHub Actions auf Commit `86fb68a`. Der reale Shared-Hosting-Durchsatz muss separat geprüft werden.
- **Verbleibende Risiken:** Dateisperren und Durchsatz müssen auf dem konkreten Shared Hosting gemessen werden. Ein Proxy muss `REMOTE_ADDR` sicher setzen.

### BINGO-REV-002 — Spiel-ID wird nicht konsistent normalisiert

- **Ursprüngliche Priorität:** P2
- **Status:** `fixed_verified`
- **Änderung:** Spiel-IDs werden am API-Eingang normalisiert; die Spielmethoden normalisieren ebenfalls vor nachfolgenden Abfragen und Änderungen.
- **Dateien:** `backend/public/index.php`, `backend/src/Game.php`, `tests/run.php`, `tests/http.php`.
- **Tests:** gemischte Kleinschreibung für `state`, `join`, `start`, `draw`, `mark`, `bingo`, `endRound`, `newRound`; HTTP-Regression für `join` und `state`.
- **Testergebnis:** ID-Normalisierungstests in der PHP- und HTTP-Suite bestanden in GitHub Actions auf Commit `86fb68a`.
- **Verbleibende Risiken:** Keine zusätzlichen Code-Risiken identifiziert; Laufzeitverifikation offen.

### BINGO-REV-003 — Symlink kann SQLite-Webroot-Prüfung umgehen

- **Ursprüngliche Priorität:** P2
- **Status:** `fixed_verified`
- **Änderung:** Kanonische Pfade von Datenbankdatei und SQLite-Sidecars werden gegen den realen Dokumentenpfad geprüft. Symlinks für DB, `-wal`, `-shm`, `-journal` und Rate-Limit-Datei sowie zusätzliche Hardlinks werden abgelehnt; nicht-reguläre DB-Ziele werden verworfen. Absolute Windows-Laufwerk- und UNC-Pfade werden unterstützt. Datenbank- und Sidecar-Dateien werden auf restriktive Rechte geprüft, ohne bei jedem Poll unnötig `chmod` auszuführen.
- **Dateien:** `backend/src/Game.php`, `tests/run.php`, `tests/http.php`.
- **Tests:** private DB, DB im Webroot, bestehende öffentliche DB-Datei, DB-Datei-Symlink, öffentlicher Hardlink, WAL-/SHM-/Journal-Symlink, Verzeichnis-Symlink auf den Webroot, Rate-Limit-Hardlink sowie HTTP-500 ohne Erzeugung einer öffentlichen DB-Datei.
- **Testergebnis:** Pfad-, Symlink-, Hardlink- und Webroot-Regressionen bestanden in GitHub Actions auf Commit `86fb68a`.
- **Verbleibende Risiken:** Tatsächliche Webserver-/Dateisystemrechte und Webroot-Konfiguration müssen beim Hosting verifiziert werden.

### BINGO-REV-004 — Keine Grenzen für gespeicherte Spiele und Teilnehmende

- **Ursprüngliche Priorität:** P2
- **Status:** `fixed_verified`
- **Änderung:** Höchstens 30 Mitspielende je Spiel und 100 gespeicherte Spiele. Die Spielleitung zählt nicht als Mitspielende. Beitritte und Spielerstellung prüfen die Grenze innerhalb einer `BEGIN IMMEDIATE`-Transaktion. Abgelaufene Spiele werden beim Anlegen eines Spiels bereinigt.
- **Dateien:** `backend/src/Game.php`, `tests/run.php`, `SPEC.md`, `README.md`.
- **Tests:** 31 parallele Beitritte (erwartet: 30 erfolgreich, einer `409`); konkurrierende Spielerstellung am 100-Spiele-Limit; Bereinigung abgelaufener Spiele auch bei einem weiterhin überfüllten Altbestand.
- **Testergebnis:** Konkurrierende 30/31-Beitritte, das 100-Spiele-Limit und die Bereinigungstests bestanden in GitHub Actions auf Commit `86fb68a`.
- **Verbleibende Risiken:** Tatsächliche Last und Speicherwirkung auf dem konkreten Shared-Hosting-Tarif sind nicht gemessen.

### BINGO-REV-005 — CSP und CORS passen nicht sicher zum API-Hostwechsel

- **Ursprüngliche Priorität:** P2
- **Status:** `fixed_verified`
- **Änderung:** Die Anleitung verlangt die gemeinsame Anpassung von `apiBaseUrl`, CSP-`connect-src` und Backend-CORS-Allowlist und trennt API-Origin von Frontend-Origin. Shared-Hosting-Pfade sind explizit: `backend/src/Game.php` in das private `src/`-Verzeichnis, `backend/public/index.php` nach `/bingo/api/index.php`.
- **Dateien:** `backend/README.md`, `tests/http.php`.
- **Tests:** vorgesehene HTTP-Tests für erlaubten/fremden Origin, Preflight, HTTP-Methoden und statischen CSP-/Konfigurationsabgleich.
- **Testergebnis:** CORS-, Preflight-, HTTP-Methoden- und CSP/API-Host-Prüfungen bestanden in GitHub Actions auf Commit `86fb68a`. Kein alternativer Host im echten Browser getestet.
- **Verbleibende Risiken:** DNS, HTTPS, Umgebungsvariablen, Verzeichnisrouting und Origin-Werte müssen im tatsächlichen Deployment geprüft werden.

### BINGO-REV-006 — Bingokarte ohne programmatische Zeilen-/Spaltenbeziehungen

- **Ursprüngliche Priorität:** P2
- **Status:** `implemented_unverified`
- **Änderung:** Semantische HTML-Tabelle mit Caption und Zeilen-/Spaltenüberschriften; Zellen verknüpfen die Header. Zahlenbuttons erhalten verständliche Koordinatenlabels und `aria-pressed`. Unveränderte Poll-Antworten lösen keinen DOM-Neuaufbau aus; Fokus wird nach tatsächlichen Updates anhand eines stabilen Schlüssels wiederhergestellt. Das manuelle Rundenende wird den Mitspielenden ohne Gewinner angezeigt.
- **Dateien:** `docs/index.html`, `docs/app.js`, `docs/style.css`, `tests/frontend-test.mjs`.
- **Tests:** `node tests/frontend-test.mjs` bestanden: Tabellenstruktur, Markierungszustand, kein DOM-Neuaufbau bei unverändertem Poll, Fokuswiederherstellung nach Update.
- **Testergebnis:** DOM-Harness und CI bestanden; kein echter Browser-, Screenreader- oder WCAG-2.2-AA-Test ausgeführt, daher bleibt der Befund `implemented_unverified`.
- **Verbleibende Risiken:** Tabellenansagen, Tastaturnavigation und Live-Regionen mit realen Browser-/Screenreader-Kombinationen manuell testen.

### BINGO-REV-007 — Konkurrenz- und Integrationstests fehlen

- **Ursprüngliche Priorität:** P2
- **Status:** `fixed_verified`
- **Änderung:** Der optionale `pcntl`-Test wurde durch verpflichtende `proc_open`-Worker mit gemeinsamer Startbarriere ersetzt; fehlendes `proc_open` führt zum Testfehler statt zum stillen Überspringen. Ergänzt sind parallele Ziehungen, parallele gültige Bingo-Rufe und Einzelgewinner, 30 Mitspielende, 100 Spiele, 31 direkte Poller, 31 barrier-synchronisierte HTTP-Statusabfragen über mehrere PHP-CLI-Server-Worker, Polling über 62 simulierte Sekunden, HTTP-/CORS-/Fehler-/Berechtigungstests, Reconnect/Rundenwechsel und GitHub Actions.
- **Dateien:** `tests/run.php`, `tests/http.php`, `tests/concurrency-worker.php`, `tests/frontend-test.mjs`, `tests/docs-test.mjs`, `.github/workflows/tests.yml`.
- **Tests:** Vollständige PHP-/SQLite-/HTTP-/Konkurrenzsuite, PHP-Lint, Frontend- und Dokumentationstests bestanden im CI auf Commit `86fb68a`; die beiden Node-Tests bestanden lokal zusätzlich.
- **Verbleibende Risiken:** Ein echter Hostinglasttest und parallele Requests im konkreten Hosting bleiben erforderlich; der lokale HTTP-Test nutzt den PHP-CLI-Server. `proc_open` muss im CI verfügbar bleiben.

## Offene Aufgaben

Die IDs und Statuswerte sind in [TODO.md](../TODO.md) und [TODO.json](../TODO.json) gleich:

- `STAB-TODO-004` — Konkretes Shared Hosting und einen mindestens einminütigen repräsentativen 30-Spieler- plus Spielleitungs-Pollinglauf prüfen.
- `STAB-TODO-005` — Echten Browser-, Tastatur-, Screenreader- und WCAG-2.2-AA-Test durchführen.
- `STAB-TODO-006` — CSP/CORS/API-Konfiguration für Produktions- und alternative Hosts im Browser prüfen.

Abgeschlossen sind `STAB-TODO-003` (PHP-Suite/Syntaxprüfungen in CI bestanden) und `STAB-TODO-007` (Branch gepusht; PR [#3](https://github.com/benjamin-lam/bingo/pull/3) gegen `copilot/develop-bingo-game` offen). Es wurde weder gemergt noch deployed.

## Serverinstallationsanleitung und ZIP

Für die manuelle Bereitstellung wurden [SERVER-INSTALLATION.md](../SERVER-INSTALLATION.md)
und [dist/bingo-server-installation.zip](../dist/bingo-server-installation.zip)
erstellt. Das Archiv enthält ausschließlich die Anleitung, `Game.php`, den
öffentlichen `index.php`-Endpunkt und einen leeren privaten Datenordner. Die
beiden PHP-Dateien sowie die im ZIP enthaltene README wurden byteweise mit den
Repository-Quellen verglichen. Datenbank, Tokens, `.env` und sonstige Geheimnisse
sind nicht enthalten.

- **SHA-256:** `47681681547FFAFABB97D1BAC7B91EB8AEBCF32D59267904C4D389CF6931E8BE`
- **Bereitstellung:** Die Anleitung erklärt getrennte private/Webroot-Pfade,
	PHP-Umgebungsvariablen, HTTPS, CORS/CSP, GitHub-Pages-Branch und `/docs` sowie
	erste Verbindungstests. GitHub Pages führt das PHP-Backend nicht aus.
- **Status:** Paket validiert und versioniert; noch kein Server-Upload und kein
	Pages-Deploy. Der PR [#3](https://github.com/benjamin-lam/bingo/pull/3) ist
	weiterhin offen und wird nicht automatisch gemergt.

## Gesamturteil

**Nicht freigegeben für produktiven Betrieb mit 30 gleichzeitigen Mitspielenden.** Die vorgesehene Begrenzung lässt 30 Spielende plus Spielleitung hinter einer gemeinsamen IP regulär pollen; PHP-/HTTP-/SQLite-Konkurrenztests bestanden in CI. Der echte Hostingbetrieb, die konkrete Proxy-/CORS-Konfiguration und manuelle Barrierefreiheit sind jedoch noch nicht nachgewiesen. Nach repräsentativem Hosting-Lasttest und Browser-/Screenreaderprüfung kann die Produktivfreigabe neu bewertet werden.
