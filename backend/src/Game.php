<?php
declare(strict_types=1);

final class ApiError extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 400)
    {
        parent::__construct($message);
    }
}

final class BingoGame
{
    private PDO $db;

    public function __construct(string $databasePath)
    {
        if ($databasePath === '' || $databasePath[0] !== DIRECTORY_SEPARATOR) {
            throw new RuntimeException('BINGO_DB_PATH muss ein absoluter Pfad sein.');
        }
        $directory = realpath(dirname($databasePath));
        if ($directory === false || !is_writable($directory)) {
            throw new RuntimeException('Das Datenbankverzeichnis ist nicht verfügbar.');
        }
        $databasePath = $directory . DIRECTORY_SEPARATOR . basename($databasePath);
        $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        if ($documentRoot !== false
            && str_starts_with($databasePath, rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Die Datenbank muss außerhalb des Webroots liegen.');
        }

        $this->db = new PDO('sqlite:' . $databasePath, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        if (!chmod($databasePath, 0600)) {
            throw new RuntimeException('Die Datenbankdatei kann nicht geschützt werden.');
        }
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->db->exec('PRAGMA busy_timeout = 5000');
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS games (
                id TEXT PRIMARY KEY,
                admin_hash TEXT NOT NULL,
                status TEXT NOT NULL CHECK (status IN ("lobby", "playing", "finished")),
                round INTEGER NOT NULL DEFAULT 1,
                drawn TEXT NOT NULL DEFAULT "[]",
                current_number INTEGER,
                winner_id INTEGER,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS players (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                game_id TEXT NOT NULL REFERENCES games(id) ON DELETE CASCADE,
                name TEXT NOT NULL,
                token_hash TEXT NOT NULL,
                card TEXT NOT NULL,
                marks TEXT NOT NULL DEFAULT "[]",
                UNIQUE(game_id, token_hash)
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS players_game ON players(game_id)');
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS request_limits (
                client_hash TEXT PRIMARY KEY,
                window_start INTEGER NOT NULL,
                request_count INTEGER NOT NULL
            )'
        );
        $this->db->exec('DELETE FROM games WHERE updated_at < ' . (time() - 2592000));
    }

    public function rateLimit(string $clientAddress): void
    {
        if (PHP_SAPI === 'cli' || $clientAddress === '') {
            return;
        }
        $hash = hash('sha256', $clientAddress);
        $now = time();
        $this->transaction(function () use ($hash, $now): void {
            $statement = $this->db->prepare(
                'SELECT window_start, request_count FROM request_limits WHERE client_hash = ?'
            );
            $statement->execute([$hash]);
            $limit = $statement->fetch();
            if ($limit === false || (int) $limit['window_start'] <= $now - 60) {
                $statement = $this->db->prepare(
                    'INSERT INTO request_limits(client_hash, window_start, request_count) VALUES (?, ?, 1)
                     ON CONFLICT(client_hash) DO UPDATE SET window_start = excluded.window_start, request_count = 1'
                );
                $statement->execute([$hash, $now]);
                return;
            }
            if ((int) $limit['request_count'] >= 180) {
                throw new ApiError('Zu viele Anfragen. Bitte kurz warten.', 429);
            }
            $statement = $this->db->prepare(
                'UPDATE request_limits SET request_count = request_count + 1 WHERE client_hash = ?'
            );
            $statement->execute([$hash]);
        });
        if (random_int(1, 100) === 1) {
            $statement = $this->db->prepare('DELETE FROM request_limits WHERE window_start < ?');
            $statement->execute([$now - 3600]);
        }
    }

    public function create(): array
    {
        $adminToken = bin2hex(random_bytes(32));
        $now = time();
        return $this->transaction(function () use ($adminToken, $now): array {
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $id = $this->newGameId();
                $statement = $this->db->prepare(
                    'INSERT OR IGNORE INTO games(id, admin_hash, status, created_at, updated_at)
                     VALUES (?, ?, "lobby", ?, ?)'
                );
                $statement->execute([$id, hash('sha256', $adminToken), $now, $now]);
                if ($statement->rowCount() === 1) {
                    return ['game_id' => $id, 'token' => $adminToken, 'role' => 'admin'];
                }
            }
            throw new RuntimeException('Spiel-ID konnte nicht erzeugt werden.');
        });
    }

    public function join(string $gameId, string $name, ?string $existingToken = null): array
    {
        $gameId = $this->validateGameId($gameId);
        $name = $this->validateName($name);
        if ($existingToken !== null && $existingToken !== '') {
            $player = $this->findPlayerByToken($gameId, $existingToken);
            if ($player !== null) {
                return ['game_id' => $gameId, 'token' => $existingToken, 'role' => 'player'];
            }
        }

        $token = bin2hex(random_bytes(32));
        return $this->transaction(function () use ($gameId, $name, $token, $existingToken): array {
            $game = $this->getGame($gameId);
            if ($game['status'] !== 'lobby') {
                throw new ApiError('Diese Runde läuft bereits. Ein Beitritt ist nicht mehr möglich.', 409);
            }
            if ($existingToken !== null && $existingToken !== ''
                && hash_equals($game['admin_hash'], hash('sha256', $existingToken))) {
                throw new ApiError('Die Spielleitung kann nicht als Spieler beitreten.', 403);
            }
            $statement = $this->db->prepare(
                'INSERT INTO players(game_id, name, token_hash, card) VALUES (?, ?, ?, ?)'
            );
            $statement->execute([$gameId, $name, hash('sha256', $token), json_encode($this->newCard())]);
            $this->touchGame($gameId);
            return ['game_id' => $gameId, 'token' => $token, 'role' => 'player'];
        });
    }

    public function state(string $gameId, string $token): array
    {
        $game = $this->getGame($this->validateGameId($gameId));
        $role = $this->authorize($game, $token);
        $statement = $this->db->prepare('SELECT id, name, card, marks FROM players WHERE game_id = ? ORDER BY id');
        $statement->execute([$gameId]);
        $players = $statement->fetchAll();
        $winner = null;
        $ownCard = null;
        $ownMarks = [];
        foreach ($players as &$player) {
            $player['id'] = (int) $player['id'];
            if ($game['winner_id'] !== null && $player['id'] === (int) $game['winner_id']) {
                $winner = $player['name'];
            }
            unset($player['card'], $player['marks']);
        }
        unset($player);
        if ($role === 'player') {
            $statement = $this->db->prepare('SELECT card, marks FROM players WHERE game_id = ? AND token_hash = ?');
            $statement->execute([$gameId, hash('sha256', $token)]);
            $own = $statement->fetch();
            $ownCard = json_decode($own['card'], true);
            $ownMarks = json_decode($own['marks'], true);
        }
        $drawn = json_decode($game['drawn'], true);
        return [
            'game' => [
                'id' => $game['id'],
                'status' => $game['status'],
                'round' => (int) $game['round'],
                'current_number' => $game['current_number'] === null ? null : (int) $game['current_number'],
                'drawn' => $drawn,
                'winner' => $winner,
                'role' => $role,
            ],
            'players' => $players,
            'card' => $ownCard,
            'marks' => $ownMarks,
        ];
    }

    public function start(string $gameId, string $token): array
    {
        return $this->transaction(function () use ($gameId, $token): array {
            $game = $this->authorizedGame($gameId, $token, 'admin');
            if ($game['status'] !== 'lobby') {
                throw new ApiError('Das Spiel kann gerade nicht gestartet werden.', 409);
            }
            $statement = $this->db->prepare('SELECT COUNT(*) FROM players WHERE game_id = ?');
            $statement->execute([$gameId]);
            if ((int) $statement->fetchColumn() === 0) {
                throw new ApiError('Mindestens eine Person muss beitreten.', 409);
            }
            $this->updateGame($gameId, 'status = "playing"', []);
            return ['ok' => true];
        });
    }

    public function draw(string $gameId, string $token): array
    {
        return $this->transaction(function () use ($gameId, $token): array {
            $game = $this->authorizedGame($gameId, $token, 'admin');
            if ($game['status'] !== 'playing') {
                throw new ApiError('Es läuft keine Runde.', 409);
            }
            $drawn = json_decode($game['drawn'], true);
            $remaining = array_values(array_diff(range(1, 75), $drawn));
            if ($remaining === []) {
                throw new ApiError('Alle Zahlen wurden bereits gezogen.', 409);
            }
            $number = $remaining[random_int(0, count($remaining) - 1)];
            $drawn[] = $number;
            $this->updateGame($gameId, 'drawn = ?, current_number = ?', [json_encode($drawn), $number]);
            return ['number' => $number];
        });
    }

    public function mark(string $gameId, string $token, int $number, bool $marked): array
    {
        return $this->transaction(function () use ($gameId, $token, $number, $marked): array {
            $game = $this->authorizedGame($gameId, $token, 'player');
            if ($game['status'] !== 'playing') {
                throw new ApiError('Es läuft keine Runde.', 409);
            }
            $drawn = json_decode($game['drawn'], true);
            if (!in_array($number, $drawn, true)) {
                throw new ApiError('Nur gezogene Zahlen dürfen markiert werden.', 400);
            }
            $statement = $this->db->prepare(
                'SELECT id, card, marks FROM players WHERE game_id = ? AND token_hash = ?'
            );
            $statement->execute([$gameId, hash('sha256', $token)]);
            $player = $statement->fetch();
            $numbers = array_merge(...json_decode($player['card'], true));
            if (!in_array($number, $numbers, true)) {
                throw new ApiError('Diese Zahl ist nicht auf deiner Karte.', 400);
            }
            $marks = json_decode($player['marks'], true);
            $marks = array_values(array_unique(array_filter(
                $marks,
                static fn (int $value): bool => $value !== $number
            )));
            if ($marked) {
                $marks[] = $number;
                sort($marks);
            }
            $statement = $this->db->prepare('UPDATE players SET marks = ? WHERE id = ?');
            $statement->execute([json_encode($marks), $player['id']]);
            $this->touchGame($gameId);
            return ['marks' => $marks];
        });
    }

    public function bingo(string $gameId, string $token): array
    {
        return $this->transaction(function () use ($gameId, $token): array {
            $game = $this->authorizedGame($gameId, $token, 'player');
            if ($game['status'] !== 'playing') {
                throw new ApiError('Es läuft keine Runde.', 409);
            }
            $statement = $this->db->prepare(
                'SELECT id, name, card, marks FROM players WHERE game_id = ? AND token_hash = ?'
            );
            $statement->execute([$gameId, hash('sha256', $token)]);
            $player = $statement->fetch();
            $marked = array_fill_keys(json_decode($player['marks'], true), true);
            $drawn = array_fill_keys(json_decode($game['drawn'], true), true);
            $card = json_decode($player['card'], true);
            $lines = [];
            for ($index = 0; $index < 5; $index++) {
                $lines[] = array_map(static fn (int $column): mixed => $card[$index][$column], range(0, 4));
                $lines[] = array_map(static fn (int $row): mixed => $card[$row][$index], range(0, 4));
            }
            $lines[] = array_map(static fn (int $index): mixed => $card[$index][$index], range(0, 4));
            $lines[] = array_map(static fn (int $index): mixed => $card[$index][4 - $index], range(0, 4));
            foreach ($lines as $line) {
                $valid = true;
                foreach ($line as $number) {
                    if ($number !== null && (!isset($marked[$number]) || !isset($drawn[$number]))) {
                        $valid = false;
                        break;
                    }
                }
                if ($valid) {
                    $this->updateGame($gameId, 'status = "finished", winner_id = ?', [(int) $player['id']]);
                    return ['valid' => true, 'winner' => $player['name']];
                }
            }
            throw new ApiError('Noch keine vollständige Reihe markiert.', 400);
        });
    }

    public function newRound(string $gameId, string $token): array
    {
        return $this->transaction(function () use ($gameId, $token): array {
            $game = $this->authorizedGame($gameId, $token, 'admin');
            if ($game['status'] !== 'finished') {
                throw new ApiError('Eine neue Runde ist erst nach Bingo möglich.', 409);
            }
            $statement = $this->db->prepare('SELECT id FROM players WHERE game_id = ?');
            $statement->execute([$gameId]);
            $playerIds = $statement->fetchAll(PDO::FETCH_COLUMN);
            $update = $this->db->prepare('UPDATE players SET card = ?, marks = "[]" WHERE id = ?');
            foreach ($playerIds as $playerId) {
                $update->execute([json_encode($this->newCard()), $playerId]);
            }
            $this->updateGame($gameId, 'status = "lobby", round = round + 1, drawn = "[]", current_number = NULL, winner_id = NULL', []);
            return ['ok' => true];
        });
    }

    private function authorizedGame(string $gameId, string $token, string $expectedRole): array
    {
        $game = $this->getGame($this->validateGameId($gameId));
        if ($this->authorize($game, $token) !== $expectedRole) {
            throw new ApiError('Keine Berechtigung für diese Aktion.', 403);
        }
        return $game;
    }

    private function authorize(array $game, string $token): string
    {
        if ($token === '' || strlen($token) > 128) {
            throw new ApiError('Token fehlt oder ist ungültig.', 401);
        }
        $hash = hash('sha256', $token);
        if (hash_equals($game['admin_hash'], $hash)) {
            return 'admin';
        }
        $statement = $this->db->prepare('SELECT 1 FROM players WHERE game_id = ? AND token_hash = ?');
        $statement->execute([$game['id'], $hash]);
        if ($statement->fetchColumn() !== false) {
            return 'player';
        }
        throw new ApiError('Token fehlt oder ist ungültig.', 401);
    }

    private function findPlayerByToken(string $gameId, string $token): ?array
    {
        $statement = $this->db->prepare('SELECT id FROM players WHERE game_id = ? AND token_hash = ?');
        $statement->execute([$gameId, hash('sha256', $token)]);
        $player = $statement->fetch();
        return $player === false ? null : $player;
    }

    private function getGame(string $gameId): array
    {
        $statement = $this->db->prepare('SELECT * FROM games WHERE id = ?');
        $statement->execute([$gameId]);
        $game = $statement->fetch();
        if ($game === false) {
            throw new ApiError('Spiel nicht gefunden.', 404);
        }
        return $game;
    }

    private function updateGame(string $gameId, string $changes, array $values): void
    {
        $statement = $this->db->prepare("UPDATE games SET $changes, updated_at = ? WHERE id = ?");
        $statement->execute([...$values, time(), $gameId]);
    }

    private function touchGame(string $gameId): void
    {
        $statement = $this->db->prepare('UPDATE games SET updated_at = ? WHERE id = ?');
        $statement->execute([time(), $gameId]);
    }

    private function transaction(callable $callback): mixed
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $result = $callback();
            $this->db->exec('COMMIT');
            return $result;
        } catch (Throwable $exception) {
            try {
                $this->db->exec('ROLLBACK');
            } catch (PDOException) {
            }
            throw $exception;
        }
    }

    private function newGameId(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $id = '';
        for ($index = 0; $index < 6; $index++) {
            $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $id;
    }

    private function newCard(): array
    {
        $card = [];
        for ($column = 0; $column < 5; $column++) {
            $numbers = range($column * 15 + 1, $column * 15 + 15);
            shuffle($numbers);
            $card[$column] = array_slice($numbers, 0, 5);
        }
        $card[2][2] = null;
        return $card;
    }

    private function validateGameId(string $gameId): string
    {
        $gameId = strtoupper(trim($gameId));
        if (!preg_match('/^[A-HJ-NP-Z2-9]{6}$/', $gameId)) {
            throw new ApiError('Die Spiel-ID muss aus sechs gültigen Zeichen bestehen.');
        }
        return $gameId;
    }

    private function validateName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'Spieler';
        }
        if (strlen($name) > 80 || preg_match('//u', $name) !== 1) {
            throw new ApiError('Der Name ist ungültig oder zu lang.');
        }
        return $name;
    }
}
