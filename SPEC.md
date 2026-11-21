# Verbindliche Spezifikation

## Spielregeln

- Eine Spiel-ID besteht aus sechs leicht unterscheidbaren Großbuchstaben oder Ziffern.
- Karten enthalten 5 × 5 Felder: B 1–15, I 16–30, N 31–45, G 46–60 und O 61–75.
- Die Zahlen einer Spalte sind auf der jeweiligen Karte eindeutig; das mittlere Feld ist frei.
- Eine Runde beginnt in der Lobby. Der Spielleiter zieht Zahlen ohne Wiederholung.
- Spieler dürfen nur bereits gezogene Zahlen ihrer Karte markieren.
- Bingo ist eine vollständige horizontale, vertikale oder diagonale Linie einschließlich des Freifelds.
- Nach einem gültigen Bingo endet die Runde. Die Spielleitung kann eine neue Runde vorbereiten; Mitspielende und Spiel-ID bleiben erhalten und Karten werden neu verteilt.
- Die Spielleitung kann eine laufende Runde auch manuell ohne Gewinner beenden. Alle Mitspielenden sehen das Rundenende; anschließend kann die Spielleitung eine neue Runde vorbereiten. Dabei bleiben Spiel-ID und Mitspielende erhalten und Karten werden neu verteilt.
- Pro Spiel sind höchstens 30 Mitspielende zulässig; die Spielleitung zählt nicht mit. Insgesamt werden höchstens 100 Spiele gespeichert. Abgelaufene Spiele ohne Zustandsänderung seit 30 Tagen werden beim Erstellen eines Spiels bereinigt.

## API und Berechtigungen

JSON-Aktionen: `create`, `join`, `state`, `start`, `draw`, `mark`, `bingo`,
`new_round` und `end_round`. Spielleitung und Spieler authentifizieren sich mit
ihrem geheimen Token. Nur die Spielleitung darf starten, ziehen und Runden
beenden oder zurücksetzen. Karten, Markierungen, gezogene Zahlen und Bingo
werden serverseitig verwaltet und geprüft. Ein manuelles `end_round` setzt den
Status auf beendet und den Gewinner auf `null`; gültige Bingo-Meldungen
bestimmen atomar höchstens einen Gewinner.

Polling erfolgt etwa alle zwei Sekunden. Die Antwort enthält Spielstatus,
Teilnehmernamen, gezogene Zahlen, aktuelle Zahl und den eigenen Kartenstatus.
Tokens werden nicht im Spielstand zurückgegeben; das Frontend speichert den
eigenen Token ausschließlich lokal im Browser.

Spiel-IDs werden an der API-Grenze getrimmt und in Großbuchstaben normalisiert.
Rate-Limits unterscheiden öffentliche Aktionen, authentifizierte
Statusabfragen und Schreibaktionen: pro Quell-IP gelten 5 Spielerstellungen,
60 Beitritte, 1.800 Statusabfragen und 1.200 Schreibaktionen je Minute; dazu
kommen 45 Statusabfragen bzw. 120 Schreibaktionen je Token und Minute. Eine
zusätzliche IP-Gesamtgrenze beträgt 3.600 Anfragen pro Minute. Fehlversuche
werden je Quell-IP begrenzt. Es werden keine nicht explizit konfigurierten
Proxy-Header zur Bestimmung der Client-IP vertraut. Rate-Zähler liegen in einer
per `flock` gesperrten Datei im privaten Datenverzeichnis, nicht in SQLite;
Status-Polling startet daher keine SQLite-Schreibtransaktion. Der Request-Body
wird auf 4 KiB begrenzt; globale und aktionsspezifische Limits werden atomar
erfasst, und fehlerhafte Bodies unterliegen ebenfalls dem globalen und dem
separaten Invalid-Request-Limit.

## Architekturentscheidungen

- Das Frontend ist statisch und unabhängig vom PHP-Backend; es benötigt keine
  Build-Tools oder Drittanbieter.
- PHP/PDO SQLite speichert Spielstände dauerhaft. Die Datenbankdatei muss
  außerhalb des Webroots liegen und wird vom Backend abgelehnt, wenn sie im
  erkannten Webroot liegt.
- Datenbankdatei und SQLite-Sidecar-Dateien müssen außerhalb des realen Webroots
  liegen; symbolische Links für Datenbank-, Sidecar- und Rate-Limit-Dateien
  werden abgewiesen.
- Die Datenbank benötigt weder Cronjob noch dauerhaften Prozess. Abgelaufene
  Spiele werden bei der nächsten Spielerstellung opportunistisch gelöscht.
- Es gibt keine Konten. Ein nicht rücksetzbares Spieler- oder Admin-Token ist
  die Berechtigung und muss wie ein Passwort behandelt werden.
- Eine Runde ist beendet, sobald der erste gültige Bingo-Ruf atomar bestätigt
  ist. Bei mehreren gleichzeitigen gültigen Rufen gewinnt der zuerst bestätigte.
