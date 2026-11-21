<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/src/Game.php';
require_once __DIR__ . '/http.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    global $assertions;
    $assertions++;
}

function expectApiError(callable $operation, int $status, string $message): void
{
    try {
        $operation();
        throw new RuntimeException($message . ': Fehler wurde nicht ausgelöst');
    } catch (ApiError $exception) {
        check($exception->httpStatus === $status, $message);
    }
}

function expectRuntimeException(callable $operation, string $message): void
{
    try {
        $operation();
        throw new RuntimeException($message . ': Fehler wurde nicht ausgelöst');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === $message . ': Fehler wurde nicht ausgelöst') throw $exception;
        check(true, $message);
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        if (file_exists($path) || is_link($path)) unlink($path);
        return;
    }
    foreach (new DirectoryIterator($path) as $entry) {
        if ($entry->isDot()) continue;
        removeTree($entry->getPathname());
    }
    rmdir($path);
}

function runParallelWorkers(string $directory, array $workers): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('Pflicht-Konkurrenztests benötigen proc_open; Tests werden nicht übersprungen.');
    }
    $barrier = $directory . '/barrier-' . bin2hex(random_bytes(5));
    mkdir($barrier, 0700, true);
    $processes = [];
    try {
        foreach ($workers as $index => $worker) {
            $command = [
                PHP_BINARY,
                '-d',
                'memory_limit=64M',
                __DIR__ . '/concurrency-worker.php',
                $worker['database'],
                $worker['game_id'] ?? '-',
                $worker['token'] ?? '-',
                $worker['action'],
                $barrier,
                (string) $index,
                $worker['name'] ?? '',
            ];
            $pipes = [];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Konkurrenz-Testprozess konnte nicht gestartet werden.');
            }
            fclose($pipes[0]);
            $processes[] = ['process' => $process, 'pipes' => $pipes, 'index' => $index, 'closed' => false];
        }

        $deadline = microtime(true) + 30;
        do {
            $ready = true;
            foreach ($processes as $worker) {
                if (!is_file($barrier . '/' . $worker['index'] . '.ready')) {
                    $ready = false;
                    break;
                }
            }
            if ($ready) break;
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Konkurrenz-Testprozesse haben die Startbarriere nicht erreicht.');
            }
            usleep(10000);
        } while (true);

        file_put_contents($barrier . '/go', 'go', LOCK_EX);
        $results = [];
        foreach ($processes as $workerIndex => $worker) {
            $stdout = stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            $exitCode = proc_close($worker['process']);
            $processes[$workerIndex]['closed'] = true;
            if ($exitCode !== 0) {
                throw new RuntimeException('Konkurrenz-Testprozess fehlgeschlagen: ' . trim((string) $stderr));
            }
            $result = json_decode((string) $stdout, true, 16, JSON_THROW_ON_ERROR);
            check(is_array($result) && array_key_exists('ok', $result), 'Konkurrenzprozess liefert ein Ergebnis');
            $results[] = $result;
        }
        return $results;
    } finally {
        foreach ($processes as $worker) {
            if (!$worker['closed'] && is_resource($worker['process'])) {
                if (proc_get_status($worker['process'])['running']) proc_terminate($worker['process']);
                foreach ($worker['pipes'] as $pipe) {
                    if (is_resource($pipe)) fclose($pipe);
                }
                @proc_close($worker['process']);
            }
        }
        removeTree($barrier);
    }
}

$directory = sys_get_temp_dir() . '/bingo-tests-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$game = new BingoGame($directory . '/games.sqlite');
$assertions = 0;

try {
    $games = [];
    for ($index = 0; $index < 12; $index++) {
        $created = $game->create();
        check(preg_match('/^[A-HJ-NP-Z2-9]{6}$/', $created['game_id']) === 1, 'Spiel-ID-Format');
        check(!isset($games[$created['game_id']]), 'Spiel-IDs müssen eindeutig sein');
        $games[$created['game_id']] = true;
    }
    $created = $game->create();
    $gameId = $created['game_id'];
    $adminToken = $created['token'];
    try {
        $game->join($gameId, 'Spielleitung', $adminToken);
        throw new RuntimeException('Die Spielleitung durfte als Spieler beitreten.');
    } catch (ApiError $exception) {
        check($exception->httpStatus === 403, 'Admin-Token kann nicht als Spieler verwendet werden');
    }
    $lowerGameId = strtolower($gameId);
    $joined = $game->join($gameId, '<script>alert(1)</script>');
    $playerToken = $joined['token'];
    $secondJoined = $game->join($lowerGameId, 'Gleichzeitige Person');
    $secondPlayerToken = $secondJoined['token'];
    $state = $game->state($lowerGameId, $playerToken);
    $secondState = $game->state($lowerGameId, $secondPlayerToken);
    check($state['game']['role'] === 'player', 'Spielertoken wird erkannt');
    check(!isset($state['token']), 'Spielstand enthält keine Tokens');
    check($state['players'][0]['name'] === '<script>alert(1)</script>', 'Namen bleiben sicher als Text serialisiert');
    check(count($state['card']) === 5, 'Karte hat fünf Spalten');
    foreach ($state['card'] as $column => $numbers) {
        check(count($numbers) === 5, 'Spalte hat fünf Felder');
        $expectedCount = $column === 2 ? 4 : 5;
        check(count(array_filter($numbers, static fn (mixed $number): bool => $number !== null)) === $expectedCount, 'Freifeld liegt nur in der mittleren Spalte');
        $values = array_filter($numbers, static fn (mixed $number): bool => $number !== null);
        check(count($values) === count(array_unique($values)), 'Zahlen einer Spalte sind eindeutig');
        foreach ($values as $number) {
            check($number >= $column * 15 + 1 && $number <= ($column + 1) * 15, 'Zahl liegt im Spaltenbereich');
        }
    }
    check($state['card'][2][2] === null, 'Mittelfeld ist frei');

    $rejoined = $game->join($lowerGameId, 'anderer Name', $playerToken);
    check($rejoined['token'] === $playerToken, 'Bestehende Spielende können sich erneut verbinden');
    try {
        $game->start($lowerGameId, $playerToken);
        throw new RuntimeException('Spielertoken durfte die Runde nicht starten.');
    } catch (ApiError $exception) {
        check($exception->httpStatus === 403, 'Spieler dürfen nicht administrieren');
    }
    check($game->start($lowerGameId, $adminToken)['ok'], 'Spielleitung startet Runde');
    expectApiError(fn () => $game->state($lowerGameId, 'ungueltiges-token'), 401, 'Ungültige Tokens werden abgewiesen');
    expectApiError(fn () => $game->endRound($lowerGameId, $playerToken), 403, 'Mitspielende dürfen Runden nicht manuell beenden');
    try {
        $game->join($lowerGameId, 'Zu spät');
        throw new RuntimeException('Beitritt während einer Runde wurde akzeptiert.');
    } catch (ApiError $exception) {
        check($exception->httpStatus === 409, 'Beitritt während einer laufenden Runde wird abgewiesen');
    }
    try {
        $game->mark($lowerGameId, $playerToken, 1, true);
        throw new RuntimeException('Eine nicht gezogene Zahl wurde markiert.');
    } catch (ApiError $exception) {
        check(str_contains($exception->getMessage(), 'gezogene Zahlen'), 'Nicht gezogene Zahl wird zurückgewiesen');
    }
    try {
        $game->bingo($lowerGameId, $playerToken);
        throw new RuntimeException('Ungültiges Bingo wurde akzeptiert.');
    } catch (ApiError $exception) {
        check($exception->httpStatus === 400, 'Ungültiges Bingo wird zurückgewiesen');
    }

    $targetLine = array_slice($state['card'][0], 0, 5);
    $allDrawn = [];
    $drawWorkers = array_fill(0, 2, [
        'database' => $directory . '/games.sqlite',
        'game_id' => $lowerGameId,
        'token' => $adminToken,
        'action' => 'draw',
    ]);
    $drawResults = runParallelWorkers($directory, $drawWorkers);
    check(count(array_filter($drawResults, static fn (array $result): bool => $result['ok'])) === 2, 'Gleichzeitige Zahlenziehungen werden verarbeitet');
    $allDrawn = $game->state($lowerGameId, $adminToken)['game']['drawn'];
    check(count($allDrawn) === 2 && count(array_unique($allDrawn)) === 2, 'Gleichzeitige Ziehungen erzeugen zwei eindeutige Zahlen');
    foreach ($allDrawn as $number) {
        if (in_array($number, $targetLine, true)) {
            $game->mark($lowerGameId, $playerToken, $number, true);
        }
    }
    for ($index = count($allDrawn); $index < 75; $index++) {
        $draw = $game->draw($lowerGameId, $adminToken);
        check(!in_array($draw['number'], $allDrawn, true), 'Zahlenziehung ohne Wiederholung');
        check($draw['number'] >= 1 && $draw['number'] <= 75, 'Gezogene Zahl liegt im erlaubten Bereich');
        $allDrawn[] = $draw['number'];
        if (in_array($draw['number'], $targetLine, true)) {
            $game->mark($lowerGameId, $playerToken, $draw['number'], true);
        }
    }
    check(count(array_unique($allDrawn)) === 75, 'Alle 75 Zahlen werden genau einmal gezogen');
    $cardNumbers = array_merge(...$state['card']);
    $numberNotOnCard = current(array_diff(range(1, 75), $cardNumbers));
    try {
        $game->mark($lowerGameId, $playerToken, $numberNotOnCard, true);
        throw new RuntimeException('Eine nicht auf der eigenen Karte stehende Zahl wurde markiert.');
    } catch (ApiError $exception) {
        check(str_contains($exception->getMessage(), 'nicht auf deiner Karte'), 'Zahl außerhalb der Karte wird zurückgewiesen');
    }
    try {
        $game->draw($lowerGameId, $adminToken);
        throw new RuntimeException('Eine 76. Zahl wurde gezogen.');
    } catch (ApiError $exception) {
        check($exception->httpStatus === 409, 'Ziehen nach 75 Zahlen wird abgewiesen');
    }
    foreach ([[$state, $playerToken], [$secondState, $secondPlayerToken]] as [$playerState, $cardToken]) {
        foreach (array_merge(...$playerState['card']) as $number) {
            if ($number !== null) $game->mark($lowerGameId, $cardToken, $number, true);
        }
    }
    $bingoWorkers = [
        ['database' => $directory . '/games.sqlite', 'game_id' => $lowerGameId, 'token' => $playerToken, 'action' => 'bingo'],
        ['database' => $directory . '/games.sqlite', 'game_id' => $lowerGameId, 'token' => $secondPlayerToken, 'action' => 'bingo'],
    ];
    $bingoResults = runParallelWorkers($directory, $bingoWorkers);
    check(count(array_filter($bingoResults, static fn (array $result): bool => $result['ok'])) === 1, 'Bei gleichzeitigen gültigen Bingo-Meldungen gibt es genau einen Gewinner');
    check(count(array_filter($bingoResults, static fn (array $result): bool => !$result['ok'] && $result['http_status'] === 409)) === 1, 'Der konkurrierende Bingo-Ruf nach dem Gewinner erhält Konfliktstatus');
    $finishedState = $game->state($lowerGameId, $adminToken);
    check($finishedState['game']['status'] === 'finished', 'Runde endet serverseitig');
    check(in_array($finishedState['game']['winner'], ['<script>alert(1)</script>', 'Gleichzeitige Person'], true), 'Der bestätigte Gewinner ist einer der gültigen Bingo-Rufe');

    check($game->newRound($lowerGameId, $adminToken)['ok'], 'Spielleitung kann neue Runde vorbereiten');
    $newState = $game->state($lowerGameId, $playerToken);
    check($newState['game']['status'] === 'lobby' && $newState['game']['round'] === 2, 'Neue Runde kehrt in Lobby zurück');
    check($newState['game']['drawn'] === [] && $newState['marks'] === [], 'Neue Runde setzt Ziehungen und Markierungen zurück');
    check($newState['card'][2][2] === null, 'Neue Karte hat weiterhin ein Freifeld');

    check($game->start($lowerGameId, $adminToken)['ok'], 'Neue Runde lässt sich starten');
    check($game->endRound($lowerGameId, $adminToken)['winner'] === null, 'Spielleitung beendet eine Runde ohne Gewinner');
    $endedState = $game->state($lowerGameId, $playerToken);
    check($endedState['game']['status'] === 'finished' && $endedState['game']['winner'] === null, 'Mitspielende sehen ein Rundenende ohne Gewinner');
    check($game->newRound($lowerGameId, $adminToken)['ok'], 'Nach manuellem Rundenende kann eine neue Runde vorbereitet werden');
    check($game->state($lowerGameId, $secondPlayerToken)['game']['round'] === 3, 'Spielende bleiben in der folgenden Runde erhalten');

    $rateGame = new BingoGame($directory . '/rate.sqlite');
    $rateCreated = $rateGame->create();
    $ratePlayers = [];
    for ($index = 0; $index < 30; $index++) {
        $rateGame->rateLimit('203.0.113.20', 'join', $rateCreated['game_id'], null, time());
        $ratePlayers[] = $rateGame->join($rateCreated['game_id'], 'Rate-Spieler ' . $index)['token'];
    }
    $rateObserver = new PDO('sqlite:' . $directory . '/rate.sqlite');
    $databaseVersionBeforePolling = (int) $rateObserver->query('PRAGMA data_version')->fetchColumn();
    $firstPoll = time();
    for ($poll = 0; $poll <= 30; $poll++) {
        $pollTime = $firstPoll + $poll * 2;
        $rateGame->rateLimit('203.0.113.20', 'state', strtolower($rateCreated['game_id']), $rateCreated['token'], $pollTime);
        foreach ($ratePlayers as $token) {
            $rateGame->rateLimit('203.0.113.20', 'state', $rateCreated['game_id'], $token, $pollTime);
        }
    }
    check(true, '31 Clients hinter derselben IP pollten 62 Sekunden ohne Rate-Limit-Blockade');
    check((int) $rateObserver->query('PRAGMA data_version')->fetchColumn() === $databaseVersionBeforePolling, 'Status-Polling verursacht keine SQLite-Schreibtransaktionen');
    $parallelPollWorkers = [[
        'database' => $directory . '/rate.sqlite',
        'game_id' => $rateCreated['game_id'],
        'token' => $rateCreated['token'],
        'action' => 'poll',
    ]];
    foreach ($ratePlayers as $token) {
        $parallelPollWorkers[] = [
            'database' => $directory . '/rate.sqlite',
            'game_id' => $rateCreated['game_id'],
            'token' => $token,
            'action' => 'poll',
        ];
    }
    $parallelPollResults = runParallelWorkers($directory, $parallelPollWorkers);
    check(count(array_filter($parallelPollResults, static fn (array $result): bool => $result['ok'])) === 31, '31 gleichzeitige Statusabfragen hinter derselben IP werden verarbeitet');
    check((int) $rateObserver->query('PRAGMA data_version')->fetchColumn() === $databaseVersionBeforePolling, 'Paralleles Status-Polling verursacht keine SQLite-Schreibtransaktionen');
    $rateLimitState = json_decode((string) file_get_contents($directory . '/.bingo-rate-limits.json'), true, 32, JSON_THROW_ON_ERROR);
    check(is_array($rateLimitState) && is_file($directory . '/.bingo-rate-limits.lock'), 'Parallele Rate-Limit-Updates hinterlassen gültiges JSON und verwenden eine stabile Sperrdatei');
    $parallelAddressHash = hash('sha256', '203.0.113.21');
    check(($rateLimitState['ip:' . $parallelAddressHash . ':all']['count'] ?? 0) === 31, 'Alle gleichzeitigen Polls werden im gemeinsamen IP-Zähler atomar erfasst');
    foreach ([$rateCreated['token'], ...$ratePlayers] as $parallelToken) {
        $parallelTokenHash = hash('sha256', $parallelToken);
        check(($rateLimitState['token:' . $parallelTokenHash . ':state']['count'] ?? 0) === 1, 'Jeder parallele Poll bleibt im individuellen Tokenzähler erhalten');
    }
    for ($index = 0; $index < 5; $index++) {
        $rateGame->rateLimit('198.51.100.10', 'create', now: $firstPoll + 200);
    }
    expectApiError(
        fn () => $rateGame->rateLimit('198.51.100.10', 'create', now: $firstPoll + 200),
        429,
        'Spielerstellung hat ein eigenes Missbrauchslimit'
    );
    for ($index = 0; $index < 45; $index++) {
        $rateGame->rateLimit('198.51.100.11', 'state', $rateCreated['game_id'], $ratePlayers[0], $firstPoll + 200);
    }
    expectApiError(
        fn () => $rateGame->rateLimit('198.51.100.11', 'state', $rateCreated['game_id'], $ratePlayers[0], $firstPoll + 200),
        429,
        'Ein einzelnes Token kann das Statuslimit nicht umgehen'
    );
    for ($index = 0; $index < 120; $index++) {
        $rateGame->rateLimit('198.51.100.12', 'start', $rateCreated['game_id'], $rateCreated['token'], $firstPoll + 400);
    }
    expectApiError(
        fn () => $rateGame->rateLimit('198.51.100.12', 'start', $rateCreated['game_id'], $rateCreated['token'], $firstPoll + 400),
        429,
        'Schreibaktionen haben ein eigenes pro-Token-Limit'
    );

    $capacityGame = new BingoGame($directory . '/player-cap.sqlite');
    $capacityCreated = $capacityGame->create();
    $joinWorkers = [];
    for ($index = 0; $index < 31; $index++) {
        $joinWorkers[] = [
            'database' => $directory . '/player-cap.sqlite',
            'game_id' => $capacityCreated['game_id'],
            'action' => 'join',
            'name' => 'Gleichzeitig ' . $index,
        ];
    }
    $joinResults = runParallelWorkers($directory, $joinWorkers);
    check(count(array_filter($joinResults, static fn (array $result): bool => $result['ok'])) === 30, '30 gleichzeitige Beitritte werden angenommen');
    check(count(array_filter($joinResults, static fn (array $result): bool => !$result['ok'] && $result['http_status'] === 409)) === 1, 'Ein 31. gleichzeitiger Beitritt wird abgewiesen');
    check(count($capacityGame->state($capacityCreated['game_id'], $capacityCreated['token'])['players']) === 30, 'Die Spielleitung zählt nicht zu den 30 Mitspielenden');

    $gameCap = new BingoGame($directory . '/game-cap.sqlite');
    $gameIds = [];
    for ($index = 0; $index < 99; $index++) {
        $gameIds[] = $gameCap->create()['game_id'];
    }
    $createResults = runParallelWorkers($directory, [
        ['database' => $directory . '/game-cap.sqlite', 'action' => 'create'],
        ['database' => $directory . '/game-cap.sqlite', 'action' => 'create'],
    ]);
    check(count(array_filter($createResults, static fn (array $result): bool => $result['ok'])) === 1, 'Bei konkurrierender Spielerstellung wird nur ein 100. Spiel angelegt');
    check(count(array_filter($createResults, static fn (array $result): bool => !$result['ok'] && $result['http_status'] === 503)) === 1, 'Konkurrierende Spielerstellung hält die globale Grenze ein');
    $capDatabase = new PDO('sqlite:' . $directory . '/game-cap.sqlite');
    $expire = $capDatabase->prepare('UPDATE games SET updated_at = ? WHERE id = ?');
    $expire->execute([time() - 2592001, $gameIds[0]]);
    $gameCap->create();
    $gameCount = (int) $capDatabase->query('SELECT COUNT(*) FROM games')->fetchColumn();
    check($gameCount === 100, 'Abgelaufene Spiele werden beim Anlegen entfernt und die Grenze bleibt bei 100');
    expectApiError(fn () => $gameCap->create(), 503, 'Mehr als 100 gespeicherte Spiele werden verhindert');
    $expire->execute([time() - 2592001, $gameIds[1]]);
    do {
        $legacyGameId = strtoupper(bin2hex(random_bytes(3)));
        $exists = $capDatabase->prepare('SELECT 1 FROM games WHERE id = ?');
        $exists->execute([$legacyGameId]);
    } while ($exists->fetchColumn() !== false);
    $legacyGame = $capDatabase->prepare(
        'INSERT INTO games(id, admin_hash, status, created_at, updated_at) VALUES (?, ?, "lobby", ?, ?)'
    );
    $legacyGame->execute([$legacyGameId, str_repeat('0', 64), time(), time()]);
    expectApiError(fn () => $gameCap->create(), 503, 'Ein überfüllter Altbestand blockiert keine Bereinigung abgelaufener Spiele');
    $expiredCount = $capDatabase->prepare('SELECT COUNT(*) FROM games WHERE id = ?');
    $expiredCount->execute([$gameIds[1]]);
    check((int) $expiredCount->fetchColumn() === 0, 'Bereinigung wird auch bei weiterhin überschrittener Altbestandsgrenze festgeschrieben');
    check((int) $capDatabase->query('SELECT COUNT(*) FROM games')->fetchColumn() === 100, 'Überfüllter Altbestand wird nach Bereinigung auf 100 Spiele begrenzt');

    $pathRoot = $directory . '/path-tests';
    $webRoot = $pathRoot . '/public';
    $privateRoot = $pathRoot . '/private';
    mkdir($webRoot, 0700, true);
    mkdir($privateRoot, 0700, true);
    $hadDocumentRoot = array_key_exists('DOCUMENT_ROOT', $_SERVER);
    $previousDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
    $_SERVER['DOCUMENT_ROOT'] = realpath($webRoot);
    try {
        new BingoGame($privateRoot . '/safe.sqlite');
        check(true, 'Datenbank außerhalb des Webroots wird akzeptiert');
        expectRuntimeException(fn () => new BingoGame($webRoot . '/public.sqlite'), 'Datenbank im Webroot wird abgewiesen');
        file_put_contents($webRoot . '/existing.sqlite', 'not-a-database');
        expectRuntimeException(fn () => new BingoGame($webRoot . '/existing.sqlite'), 'Vorhandene Datenbankdatei im Webroot wird abgewiesen');
        file_put_contents($webRoot . '/target.sqlite', '');
        check(symlink($webRoot . '/target.sqlite', $privateRoot . '/database-link.sqlite'), 'Datei-Symlink-Test wird eingerichtet');
        expectRuntimeException(fn () => new BingoGame($privateRoot . '/database-link.sqlite'), 'Datenbank-Symlink wird abgewiesen');
        file_put_contents($webRoot . '/hardlink-target.sqlite', '');
        check(link($webRoot . '/hardlink-target.sqlite', $privateRoot . '/database-hardlink.sqlite'), 'Datenbank-Hardlink-Test wird eingerichtet');
        expectRuntimeException(fn () => new BingoGame($privateRoot . '/database-hardlink.sqlite'), 'Datenbank mit öffentlichem Hardlink wird abgewiesen');
        foreach (['-wal', '-shm', '-journal'] as $sidecarSuffix) {
            file_put_contents($webRoot . '/sidecar-target' . $sidecarSuffix, '');
            check(symlink($webRoot . '/sidecar-target' . $sidecarSuffix, $privateRoot . '/sidecar' . $sidecarSuffix), 'Sidecar-Symlink-Test ' . $sidecarSuffix . ' wird eingerichtet');
            expectRuntimeException(fn () => new BingoGame($privateRoot . '/sidecar'), 'SQLite-Sidecar-Symlink ' . $sidecarSuffix . ' wird abgewiesen');
            unlink($privateRoot . '/sidecar' . $sidecarSuffix);
        }
        check(symlink($webRoot, $pathRoot . '/public-link'), 'Webroot-Verzeichnis-Symlink-Test wird eingerichtet');
        expectRuntimeException(fn () => new BingoGame($pathRoot . '/public-link/linked.sqlite'), 'Realer Webroot-Pfad über Verzeichnis-Symlink wird abgewiesen');
        $rateGuard = new BingoGame($privateRoot . '/rate-guard.sqlite');
        $rateGuard->rateLimit('192.0.2.90', 'create');
        check(link($privateRoot . '/.bingo-rate-limits.json', $webRoot . '/public-rate-limits.json'), 'Rate-Limit-Hardlink-Test wird eingerichtet');
        expectRuntimeException(fn () => $rateGuard->rateLimit('192.0.2.90', 'create'), 'Öffentlicher Hardlink auf Rate-Limit-Datei wird abgewiesen');
    } finally {
        if ($hadDocumentRoot) $_SERVER['DOCUMENT_ROOT'] = $previousDocumentRoot;
        else unset($_SERVER['DOCUMENT_ROOT']);
    }

    runBingoHttpTests($directory);
    echo "OK: $assertions Assertions\n";
} finally {
    removeTree($directory);
}
