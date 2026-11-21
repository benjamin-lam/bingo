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
    private string $rateLimitFile;
    private string $rateLimitLockFile;

    private const MAX_PLAYERS = 30;
    private const MAX_STORED_GAMES = 100;
    private const GAME_TTL_SECONDS = 2592000;
    private const MAX_RATE_LIMIT_ENTRIES = 10000;

    public function __construct(string $databasePath)
    {
        if ($databasePath === '' || !$this->isAbsolutePath($databasePath)) {
            throw new RuntimeException('BINGO_DB_PATH muss ein absoluter Pfad sein.');
        }
        $directory = realpath(dirname($databasePath));
        if ($directory === false || !is_writable($directory)) {
            throw new RuntimeException('Das Datenbankverzeichnis ist nicht verfügbar.');
        }
        $databasePath = $directory . DIRECTORY_SEPARATOR . basename($databasePath);
        $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $databasePath . '-journal'] as $path) {
            if (is_link($path)) {
                throw new RuntimeException('Datenbank und SQLite-Sidecar-Dateien dürfen keine symbolischen Links sein.');
            }
            $resolvedPath = realpath($path);
            if ($documentRoot !== false
                && $this->isPathWithin($resolvedPath === false ? $path : $resolvedPath, $documentRoot)) {
                throw new RuntimeException('Datenbank und SQLite-Sidecar-Dateien müssen außerhalb des Webroots liegen.');
            }
            $fileStat = @stat($path);
            if ($fileStat !== false && (int) $fileStat['nlink'] > 1) {
                throw new RuntimeException('Datenbank und SQLite-Sidecar-Dateien dürfen keine zusätzlichen Dateiverweise haben.');
            }
        }
        if (file_exists($databasePath) && !is_file($databasePath)) {
            throw new RuntimeException('Der Datenbankpfad muss auf eine reguläre Datei zeigen.');
        }
        $this->rateLimitFile = $directory . DIRECTORY_SEPARATOR . '.bingo-rate-limits.json';
        $this->rateLimitLockFile = $directory . DIRECTORY_SEPARATOR . '.bingo-rate-limits.lock';
        foreach ([$this->rateLimitFile, $this->rateLimitLockFile] as $privatePath) {
            if (is_link($privatePath)) {
                throw new RuntimeException('Rate-Limit-Dateien dürfen keine symbolischen Links sein.');
            }
            $resolvedPath = realpath($privatePath);
            if ($documentRoot !== false
                && $this->isPathWithin($resolvedPath === false ? $privatePath : $resolvedPath, $documentRoot)) {
                throw new RuntimeException('Rate-Limit-Dateien müssen außerhalb des Webroots liegen.');
            }
            $fileStat = @lstat($privatePath);
            if ($fileStat !== false
                && ((int) $fileStat['nlink'] > 1 || (($fileStat['mode'] & 0170000) !== 0100000))) {
                throw new RuntimeException('Rate-Limit-Dateien müssen reguläre Dateien ohne weitere Dateiverweise sein.');
            }
        }

        $this->db = new PDO('sqlite:' . $databasePath, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->protectFile($databasePath);
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->db->exec('PRAGMA busy_timeout = 5000');
        $journalMode = strtolower((string) $this->db->query('PRAGMA journal_mode')->fetchColumn());
        if ($journalMode !== 'wal') {
            $this->db->exec('PRAGMA journal_mode = WAL');
        }
        foreach ([$databasePath . '-wal', $databasePath . '-shm', $databasePath . '-journal'] as $sidecarPath) {
            if (is_file($sidecarPath) && !is_link($sidecarPath)) {
                $this->protectFile($sidecarPath);
            }
        }
        $this->ensureSchema();
    }

    public function rateLimit(
        string $clientAddress,
        string $action,
        ?string $gameId = null,
        ?string $token = null,
        ?int $now = null,
        bool $includeGlobal = true
    ): void {
        $now ??= time();
        $addressHash = hash('sha256', $clientAddress === '' ? 'unknown' : $clientAddress);
        $limits = $includeGlobal ? [[
            'key' => 'ip:' . $addressHash . ':all',
            'limit' => 3600,
        ]] : [];

        if ($action === 'invalid') {
            $limits[] = ['key' => 'ip:' . $addressHash . ':invalid', 'limit' => 60];
            $this->consumeRateLimits($limits, $now);
            return;
        }

        if ($action === 'create' || $action === 'join') {
            $limits[] = [
                'key' => 'ip:' . $addressHash . ':' . $action,
                'limit' => $action === 'create' ? 5 : 60,
            ];
            $this->consumeRateLimits($limits, $now);
            return;
        }

        $expectedRole = match ($action) {
            'state' => null,
            'start', 'draw', 'new_round', 'end_round' => 'admin',
            'mark', 'bingo' => 'player',
            default => null,
        };
        if (!in_array($action, ['state', 'start', 'draw', 'mark', 'bingo', 'new_round', 'end_round'], true)) {
            $limits[] = ['key' => 'ip:' . $addressHash . ':invalid', 'limit' => 60];
            $this->consumeRateLimits($limits, $now);
            throw new ApiError('Unbekannte Aktion.');
        }
        if ($gameId === null || $token === null) {
            $limits[] = ['key' => 'ip:' . $addressHash . ':invalid', 'limit' => 60];
            $this->consumeRateLimits($limits, $now);
            throw new ApiError('Token fehlt oder ist ungültig.', 401);
        }
        try {
            $game = $this->getGame($this->validateGameId($gameId));
            $role = $this->authorize($game, $token);
        } catch (ApiError $exception) {
            $limits[] = ['key' => 'ip:' . $addressHash . ':invalid', 'limit' => 60];
            $this->consumeRateLimits($limits, $now);
            throw $exception;
        }
        if ($expectedRole !== null && $role !== $expectedRole) {
            $limits[] = ['key' => 'ip:' . $addressHash . ':invalid', 'limit' => 60];
            $this->consumeRateLimits($limits, $now);
            throw new ApiError('Keine Berechtigung für diese Aktion.', 403);
        }

        $tokenHash = hash('sha256', $token);
        if ($action === 'state') {
            $limits[] = ['key' => 'ip:' . $addressHash . ':state', 'limit' => 1800];
            $limits[] = ['key' => 'token:' . $tokenHash . ':state', 'limit' => 45];
        } else {
            $limits[] = ['key' => 'ip:' . $addressHash . ':write', 'limit' => 1200];
            $limits[] = ['key' => 'token:' . $tokenHash . ':write', 'limit' => 120];
        }
        $this->consumeRateLimits($limits, $now);
    }

    public function create(): array
    {
        $adminToken = bin2hex(random_bytes(32));
        $now = time();
        $created = $this->transaction(function () use ($adminToken, $now): ?array {
            $cleanup = $this->db->prepare('DELETE FROM games WHERE updated_at < ?');
            $cleanup->execute([$now - self::GAME_TTL_SECONDS]);
            $gameCount = (int) $this->db->query('SELECT COUNT(*) FROM games')->fetchColumn();
            if ($gameCount >= self::MAX_STORED_GAMES) {
                return null;
            }
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
        if ($created === null) {
            throw new ApiError('Es sind bereits 100 Spiele gespeichert. Bitte später erneut versuchen.', 503);
        }
        return $created;
    }

    private function ensureSchema(): void
    {
        $statement = $this->db->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('games', 'players')"
        );
        if ((int) $statement->fetchColumn() === 2) {
            return;
        }
        $this->transaction(function (): void {
            $statement = $this->db->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('games', 'players')"
            );
            if ((int) $statement->fetchColumn() === 2) {
                return;
            }
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
            $statement = $this->db->prepare('SELECT COUNT(*) FROM players WHERE game_id = ?');
            $statement->execute([$gameId]);
            if ((int) $statement->fetchColumn() >= self::MAX_PLAYERS) {
                throw new ApiError('Ein Spiel kann höchstens 30 Mitspielende aufnehmen.', 409);
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
        $gameId = $this->validateGameId($gameId);
        $game = $this->getGame($gameId);
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
        $gameId = $this->validateGameId($gameId);
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
        $gameId = $this->validateGameId($gameId);
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
        $gameId = $this->validateGameId($gameId);
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
        $gameId = $this->validateGameId($gameId);
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
        $gameId = $this->validateGameId($gameId);
        return $this->transaction(function () use ($gameId, $token): array {
            $game = $this->authorizedGame($gameId, $token, 'admin');
            if ($game['status'] !== 'finished') {
                throw new ApiError('Eine neue Runde ist erst nach einem Rundenende möglich.', 409);
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

    public function endRound(string $gameId, string $token): array
    {
        $gameId = $this->validateGameId($gameId);
        return $this->transaction(function () use ($gameId, $token): array {
            $game = $this->authorizedGame($gameId, $token, 'admin');
            if ($game['status'] !== 'playing') {
                throw new ApiError('Es läuft keine Runde, die beendet werden kann.', 409);
            }
            $this->updateGame($gameId, 'status = "finished", winner_id = NULL', []);
            return ['ok' => true, 'winner' => null];
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

    private function isPathWithin(string $path, string $directory): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = str_replace('/', '\\', $path);
            $directory = str_replace('/', '\\', $directory);
            $path = strtolower(rtrim($path, '\\'));
            $directory = strtolower(rtrim($directory, '\\'));
            if (preg_match('/^[a-z]:$/', $path) === 1) {
                $path .= '\\';
            }
            if (preg_match('/^[a-z]:$/', $directory) === 1) {
                $directory .= '\\';
            }
            return $path === $directory || str_starts_with($path, rtrim($directory, '\\') . '\\');
        }
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        if ($directory === '') {
            return str_starts_with($path, DIRECTORY_SEPARATOR);
        }
        return $path === $directory || str_starts_with($path, $directory . DIRECTORY_SEPARATOR);
    }

    private function isAbsolutePath(string $path): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return preg_match('~^[a-z]:[\\\\/]~i', $path) === 1
                || str_starts_with($path, '\\\\')
                || str_starts_with($path, '//');
        }
        return str_starts_with($path, DIRECTORY_SEPARATOR);
    }

    private function protectFile(string $path): void
    {
        $fileStat = @lstat($path);
        $permissions = @fileperms($path);
        if (is_link($path) || !is_file($path) || $fileStat === false
            || (($fileStat['mode'] & 0170000) !== 0100000) || (int) $fileStat['nlink'] !== 1
            || $permissions === false
            || (($permissions & 0777) !== 0600 && !chmod($path, 0600))) {
            throw new RuntimeException('Eine private Datei kann nicht sicher geschützt werden.');
        }
    }

    private function consumeRateLimits(array $limits, int $now): void
    {
        $lockHandle = @fopen($this->rateLimitLockFile, 'c+b');
        if ($lockHandle === false) throw new RuntimeException('Die Rate-Limit-Sperrdatei kann nicht geöffnet werden.');
        $dataHandle = null;
        $temporaryHandle = null;
        $temporaryPath = null;
        try {
            if (!flock($lockHandle, LOCK_EX)) {
                throw new RuntimeException('Die Rate-Limit-Datei kann nicht gesperrt werden.');
            }
            $this->verifyPrivateFileHandle($this->rateLimitLockFile, $lockHandle);
            $dataHandle = $this->openPrivateRateFile($this->rateLimitFile, true);
            $contents = stream_get_contents($dataHandle, 4194305);
            if ($contents === false || strlen($contents) > 4194304) {
                throw new RuntimeException('Die Rate-Limit-Datei ist zu groß oder kann nicht gelesen werden.');
            }
            try {
                $entries = $contents === '' ? [] : json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Die Rate-Limit-Datei enthält ungültige Daten.', previous: $exception);
            }
            if (!is_array($entries)) {
                throw new RuntimeException('Die Rate-Limit-Datei ist beschädigt.');
            }
            foreach ($entries as $key => $entry) {
                if (!is_array($entry) || !isset($entry['window_start'], $entry['count'])
                    || (int) $entry['window_start'] <= $now - 3600) {
                    unset($entries[$key]);
                }
            }

            foreach ($limits as $limit) {
                $entry = $entries[$limit['key']] ?? null;
                if (is_array($entry)
                    && (int) $entry['window_start'] > $now - 60
                    && (int) $entry['count'] >= $limit['limit']) {
                    throw new ApiError('Zu viele Anfragen. Bitte kurz warten.', 429);
                }
            }
            foreach ($limits as $limit) {
                $entry = $entries[$limit['key']] ?? null;
                if (!is_array($entry) || (int) $entry['window_start'] <= $now - 60) {
                    $entries[$limit['key']] = ['window_start' => $now, 'count' => 1];
                } else {
                    $entries[$limit['key']]['count'] = (int) $entry['count'] + 1;
                }
            }
            if (count($entries) > self::MAX_RATE_LIMIT_ENTRIES) {
                throw new ApiError('Zu viele aktive Clients. Bitte später erneut versuchen.', 503);
            }
            $serialized = json_encode($entries, JSON_THROW_ON_ERROR);
            fclose($dataHandle);
            $dataHandle = null;

            $temporaryPath = tempnam(dirname($this->rateLimitFile), '.bingo-rate-limits-');
            $temporaryDirectory = $temporaryPath === false ? false : realpath(dirname($temporaryPath));
            $rateLimitDirectory = realpath(dirname($this->rateLimitFile));
            if ($temporaryPath === false || $temporaryDirectory === false || $rateLimitDirectory === false
                || !$this->isPathWithin($temporaryDirectory, $rateLimitDirectory)
                || !$this->isPathWithin($rateLimitDirectory, $temporaryDirectory)) {
                throw new RuntimeException('Eine temporäre Rate-Limit-Datei kann nicht sicher angelegt werden.');
            }
            $temporaryHandle = $this->openExistingPrivateFile($temporaryPath);
            $this->protectFile($temporaryPath);
            $offset = 0;
            $length = strlen($serialized);
            while ($offset < $length) {
                $written = fwrite($temporaryHandle, substr($serialized, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Die temporäre Rate-Limit-Datei kann nicht geschrieben werden.');
                }
                $offset += $written;
            }
            if (!fflush($temporaryHandle) || (function_exists('fsync') && !fsync($temporaryHandle))) {
                throw new RuntimeException('Die temporäre Rate-Limit-Datei kann nicht gespeichert werden.');
            }
            fclose($temporaryHandle);
            $temporaryHandle = null;
            if (!@rename($temporaryPath, $this->rateLimitFile)) {
                throw new RuntimeException('Die Rate-Limit-Datei kann nicht atomar aktualisiert werden.');
            }
            $temporaryPath = null;
            $this->protectFile($this->rateLimitFile);
        } finally {
            if (is_resource($temporaryHandle)) fclose($temporaryHandle);
            if (is_resource($dataHandle)) fclose($dataHandle);
            if ($temporaryPath !== null && file_exists($temporaryPath)) @unlink($temporaryPath);
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function openPrivateRateFile(string $path, bool $createIfMissing)
    {
        if (is_link($path)) throw new RuntimeException('Die Rate-Limit-Datei darf kein symbolischer Link sein.');
        $handle = @fopen($path, $createIfMissing ? 'c+b' : 'r+b');
        if ($handle === false) throw new RuntimeException('Die Rate-Limit-Datei kann nicht geöffnet werden.');
        try {
            $this->verifyPrivateFileHandle($path, $handle);
            return $handle;
        } catch (Throwable $exception) {
            fclose($handle);
            throw $exception;
        }
    }

    private function openExistingPrivateFile(string $path)
    {
        if (is_link($path)) throw new RuntimeException('Die temporäre Rate-Limit-Datei darf kein symbolischer Link sein.');
        $handle = @fopen($path, 'r+b');
        if ($handle === false) throw new RuntimeException('Die temporäre Rate-Limit-Datei kann nicht geöffnet werden.');
        try {
            $this->verifyPrivateFileHandle($path, $handle);
            return $handle;
        } catch (Throwable $exception) {
            fclose($handle);
            throw $exception;
        }
    }

    private function verifyPrivateFileHandle(string $path, $handle): void
    {
        $pathStat = @lstat($path);
        $handleStat = fstat($handle);
        if ($pathStat === false || $handleStat === false
            || $pathStat['dev'] !== $handleStat['dev'] || $pathStat['ino'] !== $handleStat['ino']
            || (($pathStat['mode'] & 0170000) !== 0100000)
            || (($handleStat['mode'] & 0170000) !== 0100000)
            || (int) $handleStat['nlink'] !== 1) {
            throw new RuntimeException('Die Rate-Limit-Datei wurde unerwartet ersetzt oder ist nicht privat.');
        }
        $this->protectFile($path);
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
