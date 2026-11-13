# Verbindliche Spezifikation

## Spielregeln

- Eine Spiel-ID besteht aus sechs leicht unterscheidbaren Großbuchstaben oder Ziffern.
- Karten enthalten 5 × 5 Felder: B 1–15, I 16–30, N 31–45, G 46–60 und O 61–75.
- Die Zahlen einer Spalte sind auf der jeweiligen Karte eindeutig; das mittlere Feld ist frei.
- Eine Runde beginnt in der Lobby. Der Spielleiter zieht Zahlen ohne Wiederholung.
- Spieler dürfen nur bereits gezogene Zahlen ihrer Karte markieren.
- Bingo ist eine vollständige horizontale, vertikale oder diagonale Linie einschließlich des Freifelds.
- Nach einem gültigen Bingo endet die Runde. Die Spielleitung kann eine neue Runde vorbereiten; Mitspielende und Spiel-ID bleiben erhalten und Karten werden neu verteilt.

## API und Berechtigungen

JSON-Aktionen: `create`, `join`, `state`, `start`, `draw`, `mark`,
`bingo` und `new_round`. Spielleitung und Spieler authentifizieren sich mit
ihrem geheimen Token. Nur die Spielleitung darf starten, ziehen und Runden
zurücksetzen. Karten, Markierungen, gezogene Zahlen und Bingo werden serverseitig
verwaltet und geprüft.

Polling erfolgt etwa alle zwei Sekunden. Die Antwort enthält Spielstatus,
Teilnehmernamen, gezogene Zahlen, aktuelle Zahl und den eigenen Kartenstatus.
Tokens werden nicht im Spielstand zurückgegeben; das Frontend speichert den
eigenen Token ausschließlich lokal im Browser.

## Architekturentscheidungen

- Das Frontend ist statisch und unabhängig vom PHP-Backend; es benötigt keine
  Build-Tools oder Drittanbieter.
- PHP/PDO SQLite speichert Spielstände dauerhaft. Die Datenbankdatei muss
  außerhalb des Webroots liegen und wird vom Backend abgelehnt, wenn sie im
  erkannten Webroot liegt.
- Die Datenbank benötigt weder Cronjob noch dauerhaften Prozess. Abgelaufene
  Spiele werden bei API-Anfragen opportunistisch gelöscht.
- Es gibt keine Konten. Ein nicht rücksetzbares Spieler- oder Admin-Token ist
  die Berechtigung und muss wie ein Passwort behandelt werden.
- Eine Runde ist beendet, sobald der erste gültige Bingo-Ruf atomar bestätigt
  ist. Bei mehreren gleichzeitigen gültigen Rufen gewinnt der zuerst bestätigte.
